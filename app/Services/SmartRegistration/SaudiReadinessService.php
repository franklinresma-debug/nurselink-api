<?php
namespace App\Services\SmartRegistration;
class SaudiReadinessService
{
 public const SOURCE='https://scfhs.org.sa/en/mumaris-faq';
 public function forDocuments(array $docs):array {
  $types=array_column($docs,'document_type');$checks=[];
  foreach($docs as $doc) foreach($doc['extraction_details']['document_segments']??[] as $segment) $types[]=$segment['document_type'];
  foreach(['qualification'=>['nursing_diploma','education'],'academic_record'=>['transcript'],'home_license'=>['prc_license','international_license'],
   'identity'=>['identity'],'employment_evidence'=>['employment_certificate'],'internship_or_training'=>['training_certificate']] as $code=>$accepted)
   $checks[]=['code'=>$code,'status'=>array_intersect($accepted,$types)?'document_detected_pending_review':'evidence_needed','review_required'=>true];
  $checks[]=['code'=>'primary_source_verification','status'=>'external_verification_required','review_required'=>true];
  $checks[]=['code'=>'scfhs_classification_and_evaluation','status'=>'external_decision_required','review_required'=>true];
  return ['country'=>'SA','stage'=>'classification_document_readiness','checks'=>$checks,'source_url'=>self::SOURCE,'reviewed_on'=>'2026-10-10',
   'official_decision'=>false,'note'=>'A recruiter must confirm applicability, qualification recognition, primary-source verification and SCFHS evaluation. Saudi registration follows classification and requires the applicable residency and identity evidence.'];
 }
}
