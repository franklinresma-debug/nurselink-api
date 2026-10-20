<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temporary_encoder_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('username', 80)->unique();
            $table->string('display_name', 160);
            $table->string('password');
            $table->boolean('active')->default(true)->index();
            $table->timestamp('expires_at')->index();
            $table->uuid('created_by_user_id');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 64)->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(
                ['active', 'expires_at'],
                'tmp_encoder_active_expiry_idx'
            );
        });

        Schema::create('temporary_encoder_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('encoder_account_id');
            $table->uuid('nurse_user_id');
            $table->boolean('active')->default(true)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->uuid('assigned_by_user_id');
            $table->text('assignment_reason')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by_user_id')->nullable();
            $table->timestamps();

            $table->foreign('encoder_account_id')
                ->references('id')
                ->on('temporary_encoder_accounts')
                ->cascadeOnDelete();

            $table->unique(
                ['encoder_account_id', 'nurse_user_id'],
                'tmp_encoder_nurse_unique'
            );
        });

        Schema::create('temporary_encoder_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('encoder_account_id');
            $table->uuid('nurse_user_id')->nullable();
            $table->string('action', 120);
            $table->string('target_type', 120)->nullable();
            $table->string('target_id', 191)->nullable();
            $table->json('before_state')->nullable();
            $table->json('after_state')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('encoder_account_id')
                ->references('id')
                ->on('temporary_encoder_accounts')
                ->cascadeOnDelete();

            $table->index(
                ['encoder_account_id', 'created_at'],
                'tmp_encoder_audit_actor_idx'
            );

            $table->index(
                ['nurse_user_id', 'created_at'],
                'tmp_encoder_audit_nurse_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temporary_encoder_audit');
        Schema::dropIfExists('temporary_encoder_assignments');
        Schema::dropIfExists('temporary_encoder_accounts');
    }
};
