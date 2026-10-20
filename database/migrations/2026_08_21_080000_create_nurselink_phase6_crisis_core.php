<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        if (!Schema::hasTable('nurselink_welfare_locations')) {
            Schema::create('nurselink_welfare_locations', function (Blueprint $table): void {
                $table->id();
                $table->uuid('user_id')->index();
                $table->decimal('latitude',10,7);
                $table->decimal('longitude',10,7);
                $table->decimal('accuracy_meters',10,2)->nullable();
                $table->boolean('consent_confirmed')->default(false);
                $table->timestamp('captured_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('nurselink_crisis_alerts')) {
            Schema::create('nurselink_crisis_alerts', function (Blueprint $table): void {
                $table->id();
                $table->uuid('user_id')->index();
                $table->string('alert_type',40)->default('panic');
                $table->string('severity',30)->default('critical');
                $table->string('status',40)->default('open')->index();
                $table->text('message')->nullable();
                $table->decimal('latitude',10,7)->nullable();
                $table->decimal('longitude',10,7)->nullable();
                $table->decimal('accuracy_meters',10,2)->nullable();
                $table->string('assigned_to',191)->nullable()->index();
                $table->unsignedTinyInteger('escalation_level')->default(1);
                $table->timestamp('acknowledged_at')->nullable();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('nurselink_crisis_updates')) {
            Schema::create('nurselink_crisis_updates', function (Blueprint $table): void {
                $table->id();
                $table->string('title',220);
                $table->text('body');
                $table->string('severity',30)->default('info');
                $table->string('country',120)->nullable()->index();
                $table->string('region',160)->nullable();
                $table->string('status',30)->default('published')->index();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->uuid('created_by')->nullable()->index();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('nurselink_safe_locations')) {
            Schema::create('nurselink_safe_locations', function (Blueprint $table): void {
                $table->id();
                $table->string('name',220);
                $table->string('location_type',50)->default('safe_center');
                $table->string('country',120)->nullable()->index();
                $table->string('city',160)->nullable();
                $table->string('address',500)->nullable();
                $table->decimal('latitude',10,7)->nullable();
                $table->decimal('longitude',10,7)->nullable();
                $table->string('contact',220)->nullable();
                $table->text('instructions')->nullable();
                $table->string('status',30)->default('active')->index();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('nurselink_crisis_escalations')) {
            Schema::create('nurselink_crisis_escalations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('alert_id')->nullable()->constrained('nurselink_crisis_alerts')->nullOnDelete();
                $table->unsignedBigInteger('support_case_id')->nullable()->index();
                $table->unsignedTinyInteger('level')->default(1);
                $table->string('status',40)->default('open')->index();
                $table->string('assigned_to',191)->nullable()->index();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable()->index();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();
            });
        }
    }
    public function down(): void {
        Schema::dropIfExists('nurselink_crisis_escalations');
        Schema::dropIfExists('nurselink_safe_locations');
        Schema::dropIfExists('nurselink_crisis_updates');
        Schema::dropIfExists('nurselink_crisis_alerts');
        Schema::dropIfExists('nurselink_welfare_locations');
    }
};
