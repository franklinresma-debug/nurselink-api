<?php
namespace App\Services\SmartRegistration;

use Symfony\Component\Process\Process;

/** Shared bounded local recognition for uploads and scanned PDF pages. */
class AccurateImageOcrService
{
    public const VERSION='lbs-ppocrv6-20261010-v1';

    public function extract(string $path): array
    {
        if (!config('smart_registration.ocr.accuracy_enabled',true)) return [];
        $engine=(string)config('smart_registration.ocr.engine','paddleocr_v6');
        if ($engine==='paddleocr_v6') {
            $result=$this->recognize($path,'paddleocr_v6');
            if ($result!==[] && trim($result['text'])!=='') return $result;
            if (!config('smart_registration.ocr.tesseract_safety_fallback',true)) return [];
            $fallback=$this->recognize($path,'tesseract_best');
            if ($fallback!==[]) {
                $fallback['quality']['primary_engine']='paddleocr';
                $fallback['quality']['fallback_engine']='tesseract_best';
                $fallback['quality']['review_required']=true;
            }
            return $fallback;
        }
        return $engine==='tesseract_best' ? $this->recognize($path,'tesseract_best') : [];
    }

    private function recognize(string $path,string $engine):array
    {
        $python=(string)config('smart_registration.ocr.paddle_python',base_path('.venv-ocr/bin/python'));
        $paddle=$engine==='paddleocr_v6';
        $script=$paddle ? base_path('scripts/paddle_v6_ocr.py') : base_path('scripts/accurate_image_ocr.py');
        if (!is_executable($python) || !is_file($script)) return [];
        $arguments=$paddle
            ? [$python,$script,$path,(string)config('smart_registration.ocr.paddle_v6_model_dir',storage_path('app/ocr-models/ppocr-v6'))]
            : [$python,$script,$path,(string)config('smart_registration.ocr.accuracy_model_dir',storage_path('app/ocr-models/tessdata-best')),(string)config('smart_registration.ocr.language','eng')];
        try {
            $process=new Process($arguments,base_path(),['OMP_THREAD_LIMIT'=>'2','OMP_NUM_THREADS'=>'2','OPENBLAS_NUM_THREADS'=>'1']);
            $process->setTimeout($paddle ? max(10,min(45,(int)config('smart_registration.ocr.paddle_v6_timeout',45))) : 28);
            $process->setIdleTimeout(null);$process->run();
            $result=json_decode(trim($process->getOutput()),true);
            if (!$process->isSuccessful() || !is_array($result) || empty($result['ok'])) return [];
            $quality=array_intersect_key($result,array_flip(['version','engine','model','mean_confidence','low_confidence_fraction','rotation','deskew','scale','psm','review_required','seconds']));
            $quality['engine']=$paddle?'paddleocr':'tesseract';
            return ['text'=>(string)($result['text']??''),'quality'=>$quality];
        } catch (\Throwable $exception) { return []; }
    }

    public function annotate(array &$fields,array &$records,array $pages): void
    {
        $quality=[];foreach($pages as $page) if(isset($page['ocr_quality']))$quality[$page['page']]=$page['ocr_quality'];
        foreach($fields as &$field) {
            if(isset($quality[$field['source_page']??0])) {
                $field['ocr_quality']=$quality[$field['source_page']];
                if($field['ocr_quality']['review_required']??true){$field['confidence']=min((float)($field['confidence']??0),.65);$field['review_required']=true;}
            }
        }unset($field);
        foreach($records as &$group)foreach($group as &$record) {
            $sourceQuality=array_intersect_key($quality,array_flip($record['source_pages']??[]));
            if($sourceQuality){$record['ocr_quality']=array_values($sourceQuality);foreach($sourceQuality as $item)if($item['review_required']??true){$record['confidence']=min((float)($record['confidence']??0),.65);$record['review_required']=true;}}
        }unset($record,$group);
    }
}
