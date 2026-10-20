<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('nurselink_conversation_messages',function(Blueprint $t){$t->json('attachment')->nullable();});}
 public function down():void{Schema::table('nurselink_conversation_messages',function(Blueprint $t){$t->dropColumn('attachment');});}
};
