<?php
namespace Tests\Feature;
use App\Models\Application;
use App\Models\ExtractedFact;
use App\Models\User;
use App\Services\SmartRegistration\FactReviewService;
use App\Services\SmartRegistration\MissingFieldService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
class SmartRegistrationTest extends TestCase
{
 use RefreshDatabase;
 public function test_applicant_can_upload_supported_document_to_private_storage(): void {
  Storage::fake('private'); [$user,$application]=$this->applicantWithApplication();
  $this->actingAs($user)->post("/api/applications/{$application->id}/documents",['category'=>'cv','file'=>UploadedFile::fake()->create('resume.pdf',200,'application/pdf')])->assertCreated();
  $this->assertDatabaseHas('application_documents',['application_id'=>$application->id,'category'=>'cv']);
 }
 public function test_member_confirmation_does_not_equal_officer_verification(): void {
  [$user,$application]=$this->applicantWithApplication();
  $fact=ExtractedFact::query()->create(['application_id'=>$application->id,'field_path'=>'first_name','proposed_value'=>'Maria','confidence'=>0.98,'member_status'=>'proposed','evidence_status'=>'document_supported','verification_status'=>'unverified']);
  app(FactReviewService::class)->confirm($fact,$application,$user); $fact->refresh();
  $this->assertSame('confirmed',$fact->member_status); $this->assertSame('unverified',$fact->verification_status);
 }
 public function test_missing_field_service_identifies_required_fields(): void {
  [$user,$application]=$this->applicantWithApplication(); $missing=app(MissingFieldService::class)->refresh($application);
  $this->assertGreaterThan(0,count($missing)); $this->assertDatabaseHas('application_data_reviews',['application_id'=>$application->id,'state'=>'missing']);
 }
 private function applicantWithApplication(): array {
  $user=User::query()->create(['name'=>'Maria Santos','email'=>uniqid().'@example.test','password'=>'password','status'=>'active','email_verified_at'=>now()]);
  $application=Application::query()->create(['application_no'=>'NLA-2026-'.str_pad((string)random_int(1,999999),6,'0',STR_PAD_LEFT),'user_id'=>$user->id,'status'=>'in_progress','progress_percent'=>0,'profile_data'=>[]]);
  return [$user,$application];
 }
}
