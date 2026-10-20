#!/usr/bin/env python3
"""Bounded, private local OCR. Outputs one JSON result, never logs document text."""
import os,sys,json,tempfile,pathlib,subprocess,csv,io,re,time,statistics
os.environ.setdefault('OMP_THREAD_LIMIT','1')
os.environ.setdefault('OPENBLAS_NUM_THREADS','1')
from PIL import Image,ImageOps
import cv2
import numpy as np
cv2.setNumThreads(1)

VERSION='lbs-accuracy-20261010-v1'
def recognize(image,psm,folder,label,tessdata,remaining,language):
    path=pathlib.Path(folder)/(label+'.png');image.save(path)
    command=['tesseract',str(path),'stdout','--oem','1','--psm',str(psm),'-l',language,'-c','preserve_interword_spaces=1']
    if tessdata and all(pathlib.Path(tessdata,part+'.traineddata').is_file() for part in language.split('+')):command+=['--tessdata-dir',tessdata]
    command+=['-c','tessedit_create_tsv=1']
    try:run=subprocess.run(command,capture_output=True,text=True,timeout=max(1,min(12,remaining)),env={**os.environ,'OMP_THREAD_LIMIT':'1'})
    except subprocess.TimeoutExpired:return None
    if run.returncode:return None
    lines={};total=weighted=reliable=0;low=0
    for row in csv.DictReader(io.StringIO(run.stdout),delimiter='\t',quoting=csv.QUOTE_NONE):
        word=(row.get('text') or '').strip()
        if not word:continue
        try:confidence=float(row['conf']);left=int(row['left']);width=int(row['width']);height=int(row['height'])
        except (ValueError,KeyError):continue
        if confidence<0:continue
        key=tuple(row[k] for k in ['page_num','block_num','par_num','line_num'])
        lines.setdefault(key,[]).append((word,left,width,height))
        size=len(re.sub(r'[^\w]','',word));total+=size;weighted+=size*confidence
        if confidence>=65:reliable+=size
        else:low+=size
    text=[]
    for words in lines.values():
        line='';end=None
        for word,left,width,height in words:
            # Keep column gaps so the categorized parser can recognize parallel lanes.
            gap='   ' if end is not None and left-end>max(20,height*1.2) else ' '
            line+=('' if end is None else gap)+word;end=left+width
        text.append(line)
    confidence=weighted/max(total,1)
    return {'text':'\n'.join(text),'mean_confidence':round(confidence,2),'score':round(reliable*(confidence/100)**2,2),'low_confidence_fraction':round(low/max(total,1),3),'characters':total,'psm':psm}

def preprocess(image):
    gray=np.asarray(ImageOps.grayscale(image))
    background=cv2.morphologyEx(gray,cv2.MORPH_CLOSE,np.ones((41,41),np.uint8))
    if np.percentile(background,95)-np.percentile(background,5)>35:
        gray=cv2.divide(gray,np.maximum(background,10),scale=255)
        image=Image.fromarray(gray)
    _,binary=cv2.threshold(gray,0,255,cv2.THRESH_BINARY_INV+cv2.THRESH_OTSU)
    count,labels,stats,centroids=cv2.connectedComponentsWithStats(binary,8)
    heights=[int(s[cv2.CC_STAT_HEIGHT]) for s in stats[1:] if 3<=s[cv2.CC_STAT_HEIGHT]<=120 and 2<=s[cv2.CC_STAT_WIDTH]<=120 and s[cv2.CC_STAT_AREA]>=6]
    glyph=statistics.median(heights) if heights else 20
    scale=min(3.0, max(1.0,22/max(glyph,1)),3600/max(image.size))
    if scale>1.1:image=image.resize((int(image.width*scale),int(image.height*scale)),Image.Resampling.LANCZOS)
    image=ImageOps.autocontrast(ImageOps.grayscale(image),cutoff=1)
    gray=np.asarray(image);_,binary=cv2.threshold(gray,0,255,cv2.THRESH_BINARY_INV+cv2.THRESH_OTSU)
    coords=np.column_stack(np.where(binary>0))[:,::-1].astype(np.float32)
    angle=0
    if len(coords)>100:
        angle=float(cv2.minAreaRect(coords)[-1]);angle=angle-90 if angle>45 else angle
        if .7<=abs(angle)<=12:image=image.rotate(angle,expand=True,fillcolor=255)
        else:angle=0
    return ImageOps.expand(image,border=24,fill='white'),round(angle,2),round(scale,2)

def main():
    if len(sys.argv)<2:return 2
    path=sys.argv[1];tessdata=sys.argv[2] if len(sys.argv)>2 else '';start=time.monotonic();budget=24
    language=sys.argv[3] if len(sys.argv)>3 else 'eng'
    if not re.fullmatch(r'[a-z_]+(?:\+[a-z_]+)*',language):raise ValueError('Unsupported OCR language configuration')
    has_model=bool(tessdata) and all(pathlib.Path(tessdata,part+'.traineddata').is_file() for part in language.split('+'))
    image=Image.open(path)
    if image.width*image.height>30000000:raise ValueError('Image dimensions exceed the OCR limit')
    image=ImageOps.exif_transpose(image).convert('RGB')
    if max(image.size)>3600:image.thumbnail((3600,3600),Image.Resampling.LANCZOS)
    candidates=[]
    with tempfile.TemporaryDirectory(prefix='lbs-accurate-ocr-') as folder:
        prepared,angle,scale=preprocess(image)
        for label,variant,psm in [('prepared',prepared,3),('layout',prepared,1),('original',image,1)]:
            remaining=budget-(time.monotonic()-start)
            if remaining<2:break
            result=recognize(variant,psm,folder,label,tessdata,remaining,language)
            if result:result.update(rotation=0,deskew=angle if label!='original' else 0,scale=scale if label!='original' else 1,variant=label);candidates.append(result)
        best=max(candidates,key=lambda v:v['score']) if candidates else None
        # Explicit rotations rescue sparse cards when automatic orientation cannot decide.
        if best is None or best['mean_confidence']<82 or best['characters']<25:
            for rotation in [90,180,270]:
                remaining=budget-(time.monotonic()-start)
                if remaining<2:break
                result=recognize(prepared.rotate(rotation,expand=True,fillcolor=255),6,folder,'r'+str(rotation),tessdata,remaining,language)
                if result:result.update(rotation=rotation,deskew=angle,scale=scale,variant='rotated');candidates.append(result)
        if not candidates:print(json.dumps({'ok':False,'version':VERSION}));return 1
        winner=max(candidates,key=lambda v:v['score']); alternatives=[]
        for variant in candidates:
            alternatives.append({key:value for key,value in variant.items() if key!='text'})
        print(json.dumps({'ok':True,**winner,'version':VERSION,'model':'tessdata_best' if has_model else 'system','review_required':winner['mean_confidence']<85 or winner['low_confidence_fraction']>.12,'variants':alternatives,'seconds':round(time.monotonic()-start,2)},ensure_ascii=False))
    return 0
if __name__=='__main__':
    try:sys.exit(main())
    except Exception:print(json.dumps({'ok':False,'version':VERSION,'error':'OCR preprocessing or recognition failed'}));sys.exit(1)

