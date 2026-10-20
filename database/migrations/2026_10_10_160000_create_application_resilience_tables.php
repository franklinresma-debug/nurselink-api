<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('nurselink_application_support',function(Blueprint $t){$t->id();$t->uuid('user_id')->unique();$t->uuid('application_id')->index();$t->timestamp('last_activity_at')->index();$t->unsignedTinyInteger('progress_percent')->default(0);$t->unsignedInteger('draft_revision')->default(0);$t->json('draft_data')->nullable();$t->string('issue_code',60)->nullable();$t->string('support_status',30)->default('monitoring');$t->uuid('assigned_admin_user_id')->nullable();$t->timestamp('last_alert_activity_at')->nullable();$t->timestamp('started_notified_at')->nullable();$t->timestamps();});
  Schema::create('nurselink_sync_receipts',function(Blueprint $t){$t->id();$t->uuid('user_id');$t->uuid('operation_id');$t->string('fingerprint',64);$t->unsignedSmallInteger('status_code');$t->longText('response_json');$t->timestamps();$t->unique(['user_id','operation_id']);});
 }
 public function down():void {Schema::dropIfExists('nurselink_sync_receipts');Schema::dropIfExists('nurselink_application_support');}
};
