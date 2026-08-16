<?php
namespace Tests\Feature;
use App\Models\CommunicationCampaign; use App\Models\InboxMessage; use App\Models\Member; use App\Models\NotificationPreference; use App\Models\User; use App\Services\Communications\DeliveryService; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class NL008CommunicationsTest extends TestCase { use RefreshDatabase;
 public function test_mandatory_service_notice_can_use_in_app_even_if_member_disabled_it():void{$u=User::factory()->create();NotificationPreference::query()->create(['user_id'=>$u->id,'in_app_enabled'=>false,'email_enabled'=>false]);app(DeliveryService::class)->deliver($u,'membership_status','Status update','Your status changed.',['in_app']);$this->assertDatabaseHas('inbox_messages',['user_id'=>$u->id,'category'=>'membership_status']);}
 public function test_optional_channel_can_be_suppressed_by_preference():void{$u=User::factory()->create();NotificationPreference::query()->create(['user_id'=>$u->id,'in_app_enabled'=>true,'email_enabled'=>false]);$r=app(DeliveryService::class)->deliver($u,'community','News','Hello',['email']);$this->assertSame('suppressed',$r['results']['email']['status']);}
}
