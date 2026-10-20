<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void {
  Schema::create('lbs_queue_lock',function(Blueprint $t){$t->unsignedInteger('id')->primary();});
  DB::table('lbs_queue_lock')->insert(['id'=>1]);
  Schema::create('lbs_queue_services',function(Blueprint $t){$t->id();$t->string('name',100);$t->boolean('active')->default(true);$t->boolean('online_enabled')->default(false);$t->timestamps();});
  Schema::create('lbs_queue_counters',function(Blueprint $t){$t->id();$t->string('name',100);$t->foreignId('service_id')->constrained('lbs_queue_services');$t->boolean('active')->default(true);$t->timestamps();});
  Schema::create('lbs_queue_tickets',function(Blueprint $t){
   $t->uuid('id')->primary();$t->string('number',32)->unique();$t->date('visit_date');$t->unsignedInteger('sequence');
   $t->foreignUuid('user_id')->constrained('users');$t->foreignId('service_id')->constrained('lbs_queue_services');$t->foreignId('counter_id')->nullable()->constrained('lbs_queue_counters');
   $t->string('source',12);$t->string('status',20);$t->uuid('request_id');$t->string('active_key',100)->nullable()->unique();
   $t->foreignUuid('operator_id')->nullable()->constrained('users');$t->unsignedInteger('version')->default(1);$t->timestamps();
   $t->unique(['user_id','request_id']);$t->unique(['visit_date','sequence']);$t->index(['visit_date','status','service_id']);
  });
  Schema::create('lbs_queue_events',function(Blueprint $t){$t->id();$t->foreignUuid('ticket_id')->constrained('lbs_queue_tickets');$t->foreignUuid('actor_id')->nullable()->constrained('users');$t->string('action',32);$t->string('from_status',20)->nullable();$t->string('to_status',20);$t->foreignId('counter_id')->nullable()->constrained('lbs_queue_counters');$t->foreignId('service_id')->constrained('lbs_queue_services');$t->text('reason')->nullable();$t->timestamp('created_at');});
 }
 public function down(): void {foreach(['lbs_queue_events','lbs_queue_tickets','lbs_queue_counters','lbs_queue_services','lbs_queue_lock'] as $t)Schema::dropIfExists($t);}
};
