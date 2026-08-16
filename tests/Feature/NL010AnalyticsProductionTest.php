<?php
namespace Tests\Feature;
use Tests\TestCase;
class NL010AnalyticsProductionTest extends TestCase {
 public function test_liveness_endpoint_reports_nl010():void{$r=$this->getJson('/api/health');$r->assertOk()->assertJsonPath('build','NL-010');}
 public function test_readiness_endpoint_exists():void{$this->getJson('/api/health/ready')->assertStatus(fn($s)=>in_array($s,[200,503],true));}
}
