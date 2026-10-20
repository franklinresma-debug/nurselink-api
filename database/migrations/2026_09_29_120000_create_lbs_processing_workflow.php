<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
return new class extends Migration {
 public function up():void {
  Schema::create('lbs_processing_lock',function(Blueprint $t){$t->unsignedInteger('id')->primary();});
  DB::table('lbs_processing_lock')->insert(['id'=>1]);
  Schema::create('lbs_processing_offers',function(Blueprint $t){
   $t->uuid('id')->primary();$t->unsignedBigInteger('application_id');$t->foreign('application_id')->references('id')->on('nurselink_job_applications');
   $t->foreignUuid('user_id')->constrained('users');$t->unsignedInteger('revision');$t->uuid('request_id')->unique();
   $t->string('reference',200);$t->string('employer',200);$t->string('position',200);$t->string('destination',160);$t->text('terms');$t->text('evidence_reference');
   $t->string('response',20)->default('pending');$t->timestamp('responded_at')->nullable();$t->foreignUuid('created_by')->constrained('users');$t->timestamps();$t->unique(['application_id','revision']);
  });
  Schema::create('lbs_processing_cases',function(Blueprint $t){
   $t->uuid('id')->primary();$t->unsignedBigInteger('application_id')->unique();$t->foreign('application_id')->references('id')->on('nurselink_job_applications');
   $t->foreignUuid('user_id')->constrained('users');$t->foreignUuid('offer_id')->constrained('lbs_processing_offers');
   $t->foreignUuid('owner_id')->constrained('users');$t->foreignUuid('backup_id')->nullable()->constrained('users');
   $t->string('template_version',60)->default('lbs-placement-v1');$t->string('stage',40)->default('opened');$t->string('state',20)->default('active');
   $t->text('reason')->nullable();$t->date('planned_departure')->nullable();$t->date('next_review_on')->nullable();
   $t->foreignUuid('template_reviewed_by')->nullable()->constrained('users');$t->timestamp('template_reviewed_at')->nullable();
   $t->foreignUuid('ready_by')->nullable()->constrained('users');$t->timestamp('ready_at')->nullable();
   $t->unsignedInteger('version')->default(1);$t->timestamps();
  });
  Schema::create('lbs_processing_items',function(Blueprint $t){
   $t->uuid('id')->primary();$t->foreignUuid('case_id')->constrained('lbs_processing_cases');$t->string('title',200);$t->string('classification',24)->default('unreviewed');
   $t->string('status',32)->default('not_started');$t->foreignUuid('owner_id')->constrained('users');
   $t->text('requirement_source')->nullable();$t->text('applicability_reason')->nullable();$t->text('next_action');
   $t->date('due_on')->nullable();$t->string('due_basis',300)->nullable();$t->text('evidence_reference')->nullable();$t->date('issued_on')->nullable();$t->date('expires_on')->nullable();
   $t->text('reason')->nullable();$t->foreignUuid('verified_by')->nullable()->constrained('users');$t->timestamp('verified_at')->nullable();$t->timestamps();
  });
  Schema::create('lbs_processing_events',function(Blueprint $t){
   $t->id();$t->uuid('case_id')->nullable()->index();$t->uuid('offer_id')->nullable()->index();$t->foreignUuid('actor_id')->constrained('users');$t->string('action',80);
   $t->json('before_data')->nullable();$t->json('after_data')->nullable();$t->text('reason')->nullable();$t->timestamp('created_at');
  });
 }
 public function down():void {foreach(['lbs_processing_events','lbs_processing_items','lbs_processing_cases','lbs_processing_offers','lbs_processing_lock'] as $table)Schema::dropIfExists($table);}
};
