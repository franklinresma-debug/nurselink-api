<?php
namespace App\Jobs;
use App\Models\ReportExportJob; use App\Services\Reporting\ReportExportService; use Illuminate\Bus\Queueable; use Illuminate\Contracts\Queue\ShouldQueue; use Illuminate\Foundation\Bus\Dispatchable; use Illuminate\Queue\InteractsWithQueue; use Illuminate\Queue\SerializesModels;
class GenerateReportExport implements ShouldQueue { use Dispatchable,InteractsWithQueue,Queueable,SerializesModels; public int $tries=3; public function __construct(public string $jobId){} public function handle(ReportExportService $service):void{$job=ReportExportJob::query()->findOrFail($this->jobId);if($job->status==='completed')return;$service->generate($job);} }
