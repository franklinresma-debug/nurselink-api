<?php
namespace App\Services\Analytics;
use App\Models\AnalyticsSnapshot;
class AnalyticsSnapshotService {
 public function __construct(private ExecutiveAnalyticsService $analytics){}
 public function capture(?string $date=null):AnalyticsSnapshot{$d=$date?:now()->toDateString();$metrics=$this->analytics->snapshot(true);return AnalyticsSnapshot::query()->updateOrCreate(['scope'=>'executive','metric_version'=>config('analytics.metric_version','1.0'),'snapshot_date'=>$d],['metrics'=>$metrics,'dimensions'=>['minimum_group_size'=>config('analytics.minimum_group_size',5)],'captured_at'=>now()]);}
}
