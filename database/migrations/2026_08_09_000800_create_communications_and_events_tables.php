<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('notification_preferences', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('push_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->json('category_preferences')->nullable();
            $table->string('timezone')->default('Asia/Manila');
            $table->timestamps();
        });

        Schema::create('message_templates', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('category');
            $table->string('subject_template');
            $table->text('body_template');
            $table->json('channel_overrides')->nullable();
            $table->string('governance_status')->default('draft');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category','governance_status']);
        });

        Schema::create('communication_campaigns', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->string('campaign_no')->unique();
            $table->foreignUuid('template_id')->nullable()->constrained('message_templates')->nullOnDelete();
            $table->string('name');
            $table->string('category');
            $table->string('dedupe_key',191)->nullable()->unique();
            $table->string('subject');
            $table->text('body');
            $table->json('channels');
            $table->json('audience_filters')->nullable();
            $table->string('priority')->default('normal');
            $table->string('status')->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['status','scheduled_at']);
            $table->index(['category','created_at']);
        });

        Schema::create('campaign_recipients', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_id')->constrained('communication_campaigns')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->json('resolved_channels')->nullable();
            $table->json('suppressed_channels')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['campaign_id','user_id']);
            $table->index(['campaign_id','status']);
        });

        Schema::create('inbox_messages', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('campaign_id')->nullable()->constrained('communication_campaigns')->nullOnDelete();
            $table->string('source_type')->nullable();
            $table->uuid('source_id')->nullable();
            $table->string('category');
            $table->string('dedupe_key',191)->nullable()->unique();
            $table->string('subject');
            $table->text('body');
            $table->string('action_url')->nullable();
            $table->string('priority')->default('normal');
            $table->timestamp('published_at');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->index(['user_id','read_at','published_at']);
            $table->index(['source_type','source_id']);
        });

        Schema::create('delivery_attempts', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->foreignUuid('campaign_recipient_id')->nullable()->constrained('campaign_recipients')->nullOnDelete();
            $table->foreignUuid('inbox_message_id')->nullable()->constrained('inbox_messages')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('dedupe_key',191)->nullable();
            $table->string('provider');
            $table->string('status');
            $table->string('provider_message_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('delivered_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['user_id','channel','attempted_at']);
            $table->index(['status','attempted_at']);
            $table->unique(['channel','dedupe_key'],'delivery_attempt_dedupe_unique');
        });

        Schema::create('communication_trigger_events', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->string('source_type');
            $table->uuid('source_id');
            $table->json('payload')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('occurred_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->unique(['event_type','source_type','source_id','user_id'], 'trigger_event_unique');
            $table->index(['status','occurred_at']);
        });

        Schema::create('events', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->string('event_no')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('event_type')->default('seminar');
            $table->string('format')->default('online');
            $table->string('status')->default('draft');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('timezone')->default('Asia/Manila');
            $table->string('venue_name')->nullable();
            $table->text('venue_address')->nullable();
            $table->text('online_url')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('waitlist_enabled')->default(true);
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->boolean('certificate_enabled')->default(false);
            $table->json('audience_filters')->nullable();
            $table->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['status','starts_at']);
        });

        Schema::create('event_registrations', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('registered');
            $table->timestamp('registered_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('attended_at')->nullable();
            $table->foreignUuid('attendance_recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();
            $table->timestamps();
            $table->unique(['event_id','member_id']);
            $table->index(['event_id','status']);
        });

        Schema::create('event_certificates', function(Blueprint $table){
            $table->uuid('id')->primary();
            $table->foreignUuid('event_registration_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('certificate_no')->unique();
            $table->string('verification_token',64)->unique();
            $table->timestamp('issued_at');
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('event_certificates');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
        Schema::dropIfExists('communication_trigger_events');
        Schema::dropIfExists('delivery_attempts');
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('communication_campaigns');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('notification_preferences');
    }
};
