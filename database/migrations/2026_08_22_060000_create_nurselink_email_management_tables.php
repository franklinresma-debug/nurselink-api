<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('nurselink_email_threads', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->string('contact_email')->index();
            $table->foreignUuid('member_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category', 60)->default('member_support')->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('status', 30)->default('open')->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('nurselink_email_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('nurselink_email_threads')->cascadeOnDelete();
            $table->string('direction', 12);
            $table->string('provider_message_id')->nullable()->unique();
            $table->string('from_email');
            $table->string('to_email');
            $table->longText('body_text');
            $table->json('attachments')->nullable();
            $table->foreignUuid('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('delivery_status', 30)->default('received');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_email_messages');
        Schema::dropIfExists('nurselink_email_threads');
    }
};
