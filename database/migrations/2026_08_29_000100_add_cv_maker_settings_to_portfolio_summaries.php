<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void { Schema::table('portfolio_summaries', function (Blueprint $table) { if (!Schema::hasColumn('portfolio_summaries','cv_maker_settings')) $table->longText('cv_maker_settings')->nullable()->after('professional_summary'); }); }
 public function down(): void { Schema::table('portfolio_summaries', function (Blueprint $table) { if (Schema::hasColumn('portfolio_summaries','cv_maker_settings')) $table->dropColumn('cv_maker_settings'); }); }
};
