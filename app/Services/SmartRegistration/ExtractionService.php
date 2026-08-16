<?php
namespace App\Services\SmartRegistration;
use App\Models\ApplicationDocument;
use App\Models\DocumentExtractionJob;
use App\Models\ExtractedFact;
use Illuminate\Support\Facades\DB;
use Throwable;
class ExtractionService
{
    public function __construct(private readonly DocumentExtractor $extractor) {}
    public function run(ApplicationDocument $document): DocumentExtractionJob
    {
        return DB::transaction(function () use ($document) {
            $job = DocumentExtractionJob::query()->create([
                'application_document_id'=>$document->id,'status'=>'processing',
                'extractor'=>$this->extractor->name(),'extractor_version'=>$this->extractor->version(),'started_at'=>now(),
            ]);
            $document->update(['extraction_status'=>'processing']);
            try {
                $facts = $this->extractor->extract($document);
                foreach ($facts as $fact) {
                    ExtractedFact::query()->create([
                        'application_id'=>$document->application_id,'application_document_id'=>$document->id,'extraction_job_id'=>$job->id,
                        'field_path'=>$fact['field_path'],'proposed_value'=>$fact['value'] ?? null,'confidence'=>$fact['confidence'] ?? null,
                        'source_page'=>$fact['source_page'] ?? null,'source_label'=>$fact['source_label'] ?? null,
                        'member_status'=>'proposed','evidence_status'=>'document_supported','verification_status'=>'unverified',
                    ]);
                }
                $job->update(['status'=>'completed','completed_at'=>now(),'result_meta'=>['fact_count'=>count($facts)]]);
                $document->update(['extraction_status'=>'completed','extracted_at'=>now()]);
            } catch (Throwable $e) {
                $job->update(['status'=>'failed','completed_at'=>now(),'error_message'=>mb_substr($e->getMessage(),0,2000)]);
                $document->update(['extraction_status'=>'failed']);
                throw $e;
            }
            return $job->fresh();
        });
    }
}
