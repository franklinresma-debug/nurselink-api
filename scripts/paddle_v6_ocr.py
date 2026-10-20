#!/usr/bin/env python3
"""Private PP-OCRv6 recognition. Only the result JSON is written to stdout."""
import os,sys,json,time,re,math,statistics,contextlib,tempfile
os.environ.setdefault('OMP_NUM_THREADS','2');os.environ.setdefault('OPENBLAS_NUM_THREADS','1')
os.environ.setdefault('FLAGS_use_mkldnn','0');os.environ.setdefault('FLAGS_enable_pir_api','0')
os.environ.setdefault('PADDLE_PDX_DISABLE_MODEL_SOURCE_CHECK','True')
os.umask(0o077)
VERSION='lbs-ppocrv6-20261010-v1'
def summarize(data):
    texts=data.get('rec_texts',[]);scores=data.get('rec_scores',[]);boxes=data.get('rec_boxes',[])
    spans=[];total=weighted=low=0
    for i,(text,score) in enumerate(zip(texts,scores)):
        text=str(text).strip()
        try: score=float(score)
        except (ValueError,TypeError):continue
        if not text or not math.isfinite(score) or not 0<=score<=1:continue
        n=len(re.sub(r'[^\w]','',text));total+=n;weighted+=n*score
        if score<.65:low+=n
        box=boxes[i] if i<len(boxes) else [0,i*30,100,i*30+20]
        x,y,right,bottom=map(float,box);spans.append((x,(y+bottom)/2,right,max(1,bottom-y),text))
    height=statistics.median([s[3] for s in spans]) if spans else 20
    rows=[]
    for span in sorted(spans,key=lambda s:(s[1],s[0])):
        if rows and abs(span[1]-rows[-1][0])<=max(3,height*.55):rows[-1][1].append(span)
        else:rows.append([span[1],[span]])
    lines=[]
    for _,row in rows:
        line='';end=None
        for x,y,right,h,text in sorted(row,key=lambda s:s[0]):
            gap='   ' if end is not None and x-end>max(20,h*1.2) else ' '
            line+=('' if end is None else gap)+text;end=right
        lines.append(line)
    mean=weighted/max(total,1)*100;fraction=low/max(total,1) if total else 1
    return {'text':'\n'.join(lines),'mean_confidence':round(mean,2),'low_confidence_fraction':round(fraction,3),'characters':total,'line_count':len(lines),'review_required':total==0 or mean<85 or fraction>.12}
def main():
    start=time.monotonic()
    if len(sys.argv)!=3:return {'ok':False,'error':'OCR input unavailable'}
    path,models=sys.argv[1:]
    from PIL import Image,ImageOps
    with Image.open(path) as image:
        if image.width*image.height>30000000:raise ValueError('OCR image limit')
        prepared=ImageOps.exif_transpose(image).convert('RGB')
        if max(prepared.size)>3600:prepared.thumbnail((3600,3600),Image.Resampling.LANCZOS)
    from paddleocr import PaddleOCR
    pipeline=PaddleOCR(text_detection_model_name='PP-OCRv6_small_det',text_detection_model_dir=models+'/PP-OCRv6_small_det',text_recognition_model_name='PP-OCRv6_small_rec',text_recognition_model_dir=models+'/PP-OCRv6_small_rec',doc_orientation_classify_model_name='PP-LCNet_x1_0_doc_ori',doc_orientation_classify_model_dir=models+'/PP-LCNet_x1_0_doc_ori',use_doc_orientation_classify=True,use_doc_unwarping=False,use_textline_orientation=False,device='cpu',cpu_threads=2,enable_mkldnn=False,text_det_limit_side_len=1200,text_det_limit_type='max',text_rec_score_thresh=0.0)
    with tempfile.TemporaryDirectory(prefix='nurselink-ppocr-v6-') as temporary:
        normalized=os.path.join(temporary,'input.png');prepared.save(normalized);results=list(pipeline.predict(normalized))
    if len(results)!=1:raise ValueError('Unexpected OCR result')
    payload=results[0].json; data=payload.get('res',payload)
    return {'ok':True,**summarize(data),'version':VERSION,'engine':'paddleocr','model':'PP-OCRv6_small','rotation':int(data.get('doc_preprocessor_res',{}).get('angle',0)),'deskew':0,'scale':1,'seconds':round(time.monotonic()-start,2)}
if __name__=='__main__':
    # Some libraries write native progress messages to stdout; keep the API channel clean.
    saved=os.dup(1)
    try:
        os.dup2(2,1)
        with contextlib.redirect_stdout(sys.stderr):
            try: result=main()
            except Exception:result={'ok':False,'version':VERSION,'error':'PP-OCRv6 recognition failed'}
    finally:os.dup2(saved,1);os.close(saved)
    print(json.dumps(result,ensure_ascii=False));sys.exit(0 if result.get('ok') else 1)
