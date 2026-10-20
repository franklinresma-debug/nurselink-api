<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
return new class extends Migration {
 public function up():void {
  foreach([
   ['code'=>'PQF-REFERENCE','name'=>'Philippine Qualifications Framework','scope'=>'national','jurisdiction'=>'PH','source_url'=>'https://pqf.gov.ph/PhQuaR/Qualifications?SearchQualification=NURSING'],
   ['code'=>'SA-REFERENCE','name'=>'Saudi Arabia SCFHS Classification Reference','scope'=>'destination','jurisdiction'=>'SA','source_url'=>'https://scfhs.org.sa/en/mumaris-faq'],
  ] as $row) DB::table('qualification_frameworks')->insertOrIgnore($row+['id'=>(string)Str::uuid(),'governance_status'=>'reference_only','assessment_enabled'=>false,
    'description'=>'Reference catalog. Document-readiness guidance is available in Smart Registration. Official equivalence and licensing decisions require authorized assessment.',
    'governance_note'=>'Source reviewed 2026-10-10. Detailed governed assessment rules must be validated before activation.','created_at'=>now(),'updated_at'=>now()]);
 }
 public function down():void { /* Keep reference rows: rollback must not remove subsequently governed frameworks. */ }
};
