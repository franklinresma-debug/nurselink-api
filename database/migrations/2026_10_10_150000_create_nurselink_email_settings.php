<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('nurselink_email_settings', function(Blueprint $t) { $t->unsignedTinyInteger('id')->primary(); $t->text('encrypted_settings'); $t->timestamps(); }); }
    public function down(): void { Schema::dropIfExists('nurselink_email_settings'); }
};
