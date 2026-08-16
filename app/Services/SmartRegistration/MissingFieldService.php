<?php
namespace App\Services\SmartRegistration;
use App\Models\Application;
use App\Models\ApplicationDataReview;
use Illuminate\Support\Arr;
class MissingFieldService
{
    private const REQUIRED = [
        'first_name'=>'First name','last_name'=>'Last name','date_of_birth'=>'Date of birth',
        'mobile_phone'=>'Mobile number','country'=>'Current country','professional_title'=>'Professional title',
    ];
    public function refresh(Application $application): array
    {
        $profile = $application->profile_data ?? []; $missing = [];
        foreach (self::REQUIRED as $field=>$label) {
            $present = filled(Arr::get($profile,$field));
            $review = ApplicationDataReview::query()->updateOrCreate(
                ['application_id'=>$application->id,'field_path'=>$field],
                ['state'=>$present?'complete':'missing','message'=>$present?null:"{$label} is required before submission.",
                 'rule_meta'=>['required'=>true,'label'=>$label],'resolved_at'=>$present?now():null]
            );
            if (! $present) $missing[] = $review;
        }
        $base = max(0,count(self::REQUIRED)-count($missing));
        $fieldScore = (int) round(($base/count(self::REQUIRED))*70);
        $documentScore = $application->documents()->exists()?20:0;
        $reviewScore = $application->extractedFacts()->where('member_status','confirmed')->exists()?10:0;
        $application->update(['progress_percent'=>min(100,$fieldScore+$documentScore+$reviewScore)]);
        return $missing;
    }
}
