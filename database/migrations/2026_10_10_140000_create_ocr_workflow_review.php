<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('nurselink_ocr_review_issues',function(Blueprint $t){
   $t->id();$t->string('user_id',191)->index();$t->string('fingerprint',64);$t->string('code',80);
   $t->string('field',100)->nullable();$t->string('message',500);$t->string('status',30)->default('open');
   $t->json('evidence');$t->string('resolved_by',191)->nullable();$t->text('resolution_note')->nullable();
   $t->timestamp('resolved_at')->nullable();$t->timestamps();$t->unique(['user_id','fingerprint'],'ocr_issue_identity');
  });
  Schema::create('nurselink_email_intakes',function(Blueprint $t){
   $t->id();$t->string('source_key',64)->unique();$t->string('sender',255);$t->string('status',40)->default('received');
   $t->string('proposed_user_id',191)->nullable();$t->json('identity_result')->nullable();$t->string('message',500)->nullable();$t->timestamps();
  });
  Schema::create('nurselink_email_intake_documents',function(Blueprint $t){
   $t->id();$t->foreignId('intake_id')->constrained('nurselink_email_intakes')->cascadeOnDelete();
   $t->string('original_name');$t->string('storage_path');$t->string('sha256',64);$t->string('status',40)->default('queued');
   $t->string('security_status',40)->default('pending');$t->json('result')->nullable();$t->timestamps();
   $t->unique(['intake_id','sha256'],'email_intake_document_hash');
  });
 }
 public function down():void {Schema::dropIfExists('nurselink_email_intake_documents');Schema::dropIfExists('nurselink_email_intakes');Schema::dropIfExists('nurselink_ocr_review_issues');}
};
