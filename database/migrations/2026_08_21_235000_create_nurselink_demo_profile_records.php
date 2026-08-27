<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('nurselink_demo_profile_records')) {
            Schema::create('nurselink_demo_profile_records', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('member_number',64)->index();
                $table->string('record_type',32)->index();
                $table->string('record_key',128);
                $table->json('payload');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['member_number','record_type','record_key'],'nl_demo_profile_record_unique');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive by design.
    }
};
