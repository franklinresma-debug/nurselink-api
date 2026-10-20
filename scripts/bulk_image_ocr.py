from PIL import Image, ImageOps
import subprocess,json,pathlib,csv,io,sys,tempfile,re,os

def read(image, psm, folder, label):
    path=pathlib.Path(folder)/(label+'.png');image.save(path)
    run=subprocess.run(['tesseract',str(path),'stdout','--psm',str(psm),'-l','eng','tsv'],capture_output=True,text=True,timeout=12,env={**os.environ,'OMP_THREAD_LIMIT':'1'})
    if run.returncode: return {'text':'','score':0,'mean_confidence':0}
    lines={}; weighted=0; total=0; reliable=0
    for row in csv.DictReader(io.StringIO(run.stdout),delimiter='\t',quoting=csv.QUOTE_NONE):
        word=(row.get('text') or '').strip()
        if not word:continue
        try: conf=float(row['conf'])
        except (ValueError,KeyError):continue
        key=tuple(row[k] for k in ['page_num','block_num','par_num','line_num'])
        lines.setdefault(key,[]).append(word)
        n=len(re.sub(r'[^A-Za-z0-9]','',word));total+=n;weighted+=n*max(0,conf)
        if conf>=65: reliable+=n
    return {'text':'\n'.join(' '.join(v) for v in lines.values()),'score':round(reliable*(weighted/max(total,1)/100)**2,2),'mean_confidence':round(weighted/max(total,1),2)}

def main():
    im=ImageOps.exif_transpose(Image.open(sys.argv[1])).convert('RGB')
    with tempfile.TemporaryDirectory(prefix='lbs-ocr-') as folder:
        variants=[]
        for angle in [0,90,180,270]:
            rotated=im.rotate(angle,expand=True)
            result=read(rotated,6,folder,'r'+str(angle));result.update(rotation=angle,psm=6);variants.append(result)
        best=max(variants,key=lambda v:v['score'])
        # Baseline layout mode is especially valuable for multi-column CVs.
        baseline=read(im,1,folder,'baseline');baseline.update(rotation=0,psm=1);variants.append(baseline)
        rotated=im.rotate(best['rotation'],expand=True)
        for psm in [3,11]:
            result=read(rotated,psm,folder,'p'+str(psm));result.update(rotation=best['rotation'],psm=psm);variants.append(result)
        winner=max(variants,key=lambda v:v['score'])
        print(json.dumps({'ok':True,**winner,'variants':variants},ensure_ascii=False))
if __name__=='__main__':main()
