<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_admin_governance_audit')) {
            Schema::create('nurselink_admin_governance_audit', function (Blueprint $table): void {
                $table->id();
                $table->string('actor_user_id', 191);
                $table->string('action', 120);
                $table->string('subject_type', 60)->default('administrator');
                $table->string('subject_id', 191)->nullable();
                $table->string('subject_email', 255)->nullable();
                $table->text('reason')->nullable();
                $table->text('approval_notes')->nullable();
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->string('request_ip_hash', 64)->nullable();
                $table->string('user_agent_hash', 64)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['actor_user_id', 'created_at'], 'nl_admin_gov_actor_created_idx');
                $table->index(['subject_type', 'subject_id', 'created_at'], 'nl_admin_gov_subject_created_idx');
                $table->index(['action', 'created_at'], 'nl_admin_gov_action_created_idx');
                $table->index(['subject_email', 'created_at'], 'nl_admin_gov_email_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_admin_governance_audit');
    }
};
