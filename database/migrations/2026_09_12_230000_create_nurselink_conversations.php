<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void {
  Schema::create('nurselink_conversations',function(Blueprint $t){$t->id();$t->string('member_id',36)->index();$t->string('subject',190);$t->uuid('client_id');$t->unsignedBigInteger('member_read_id')->default(0);$t->unsignedBigInteger('admin_read_id')->default(0);$t->timestamps();$t->unique(['member_id','client_id']);$t->index('updated_at');});
  Schema::create('nurselink_conversation_messages',function(Blueprint $t){$t->id();$t->foreignId('conversation_id')->constrained('nurselink_conversations');$t->string('sender_id',36);$t->string('sender_name',190);$t->string('sender_role',10);$t->uuid('client_id');$t->text('body');$t->timestamp('created_at');$t->unique(['conversation_id','sender_id','sender_role','client_id'],'nl_conversation_message_retry');$t->index(['conversation_id','id']);});
 }
 public function down():void{Schema::dropIfExists('nurselink_conversation_messages');Schema::dropIfExists('nurselink_conversations');}
};
