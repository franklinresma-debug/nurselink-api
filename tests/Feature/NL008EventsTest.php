<?php
namespace Tests\Feature;
use App\Models\Event; use App\Models\Member; use App\Models\User; use App\Services\Events\EventRegistrationService; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class NL008EventsTest extends TestCase { use RefreshDatabase;
 public function test_capacity_places_second_member_on_waitlist():void{$admin=User::factory()->create();$a=Member::query()->create(['user_id'=>User::factory()->create()->id,'member_no'=>'NL-T-A','status'=>'active','joined_at'=>now()]);$b=Member::query()->create(['user_id'=>User::factory()->create()->id,'member_no'=>'NL-T-B','status'=>'active','joined_at'=>now()]);$event=Event::query()->create(['event_no'=>'NLEV-T1','title'=>'Test Event','format'=>'online','event_type'=>'seminar','status'=>'published','starts_at'=>now()->addDays(3),'ends_at'=>now()->addDays(3)->addHour(),'capacity'=>1,'waitlist_enabled'=>true,'created_by'=>$admin->id]);$service=app(EventRegistrationService::class);$this->assertSame('registered',$service->register($a,$event)->status);$this->assertSame('waitlisted',$service->register($b,$event)->status);}
}
