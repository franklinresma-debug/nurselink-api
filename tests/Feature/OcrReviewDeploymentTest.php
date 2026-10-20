<?php
namespace Tests\Feature;
use App\Services\SmartRegistration\DocumentPacketParser;
use App\Services\SmartRegistration\OcrSandboxService;
use Tests\TestCase;
class OcrReviewDeploymentTest extends TestCase {
 public function test_review_categories_preserve_explicit_contact_and_dates():void {
  $r=app(OcrSandboxService::class)->parse([['page'=>1,'method'=>'text','text'=>"Jane Example\nMobile: +63 917 123 4567\nDate of Birth: 08/08/90  Age: 36\nEXPERIENCE\nStaff Nurse- Example Hospital, Sample City, PH\nOctober 2024 - Present"]]);
  $this->assertSame(['Personal Details','Education & Trainings','Work Experiences'],array_keys($r['categories']));
  $this->assertSame('+639171234567',$r['debug']['fields']['phone']['value']);
  $this->assertSame('08/08/90',$r['debug']['fields']['birth_date']['value']);
  $this->assertTrue($r['debug']['fields']['birth_date']['review_required']);
  $this->assertTrue($r['debug']['records']['employment'][0]['is_current']);
 }
 public function test_mixed_packet_does_not_select_provider_contacts():void {
  $texts=[
   "Medical Services Division\nPERSONAL INFORMATION\nEmail: agency@example.test",
   "JANE MIDDLE EXAMPLE\nPhone: +63 917 123 4567\nEmail: jane@example.test\nPERSONAL INFORMATION\nName: Jane Middle Example\nDate of Birth: August 8, 1990",
   "EDUCATIONAL BACKGROUND\nTertiary\nCourse: Bachelor of Science in Nursing\nDates Attended: June 2018-July 2022\nSchool: Example University\nPROFESSIONAL EXPERIENCE\nEmployer: Example Hospital\nEmployment Dates: March 2025-Present\nPosition Held: Staff Nurse",
   "PROFESSIONAL IDENTIFICATION CARD\nLAST NAME EXAMPLE\nFIRST NAME JANE\nMIDDLE NAME MIDDLE\nREGISTRATION NO. 0123456\nREGISTRATION DATE04/26/2023\nVALID UNTIL 04/14/2029",
   "EXAMPLE TRAINING CENTER\nEmail: provider@example.test\nCERTIFICATE OF COMPLETION\nskills for\nBASIC ECG INTERPRETATION\nDate of completion: December 9, 2023"
  ];
  $pages=[];foreach($texts as $i=>$text)$pages[]=['page'=>$i+1,'method'=>'ocr','text'=>$text];
  $r=app(DocumentPacketParser::class)->parse($pages,'cv');
  $this->assertSame('jane@example.test',$r['fields']['email']['value']);
  $this->assertSame('JANE MIDDLE EXAMPLE',$r['fields']['full_name']['value']);
  $this->assertCount(1,$r['records']['employment']);
  $this->assertSame('0123456',$r['records']['credentials'][0]['credential_number']);
  $this->assertSame('EXAMPLE TRAINING CENTER',$r['records']['training'][0]['issuing_authority']);
 }
}
