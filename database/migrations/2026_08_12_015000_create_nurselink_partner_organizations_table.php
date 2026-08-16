<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nurselink_partner_organizations')) {
            Schema::create('nurselink_partner_organizations', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 190);
                $table->string('organization_type', 80)->default('hospital');
                $table->string('country', 120);
                $table->string('city', 120)->nullable();
                $table->string('website', 512)->nullable();
                $table->string('status', 30)->default('pending');
                $table->string('verified_by', 191)->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'organization_type']);
                $table->index(['country', 'city']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nurselink_partner_organizations');
    }
};
