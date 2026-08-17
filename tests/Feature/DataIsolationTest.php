<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_member_privacy_center_returns_only_their_requests(): void
    {
        $memberRole = Role::query()->where('code', 'member')->firstOrFail();
        $member = User::factory()->create(['email_verified_at' => now()]);
        $otherMember = User::factory()->create(['email_verified_at' => now()]);
        $member->roles()->attach($memberRole->id, ['assigned_at' => now()]);
        $otherMember->roles()->attach($memberRole->id, ['assigned_at' => now()]);

        $this->actingAs($member)
            ->postJson('/api/privacy/requests', [
                'request_type' => 'access_export',
                'details' => 'Provide a copy of my NurseLink information.',
            ])
            ->assertCreated();

        $this->actingAs($otherMember)
            ->postJson('/api/privacy/requests', [
                'request_type' => 'correction',
                'details' => 'Correct my NurseLink information.',
            ])
            ->assertCreated();

        $this->actingAs($member)
            ->getJson('/api/privacy/requests')
            ->assertOk()
            ->assertJsonCount(1, 'requests')
            ->assertJsonPath('requests.0.user_id', (string) $member->getKey())
            ->assertJsonMissing(['user_id' => (string) $otherMember->getKey()]);
    }

    public function test_partner_cannot_read_or_update_another_organizations_opportunity(): void
    {
        $partner = User::factory()->create(['email_verified_at' => now()]);
        $organizationId = $this->createPartnerOrganization('Partner A');
        $otherOrganizationId = $this->createPartnerOrganization('Partner B');

        DB::table('nurselink_partner_access')->insert([
            'user_id' => $partner->getKey(),
            'partner_organization_id' => $organizationId,
            'role' => 'manager',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ownOpportunityId = $this->createOpportunity($organizationId, 'NLP-A', 'Partner A Nurse');
        $otherOpportunityId = $this->createOpportunity($otherOrganizationId, 'NLP-B', 'Partner B Nurse');

        $this->actingAs($partner)
            ->getJson('/api/partner/opportunities')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownOpportunityId)
            ->assertJsonMissing(['id' => $otherOpportunityId]);

        $this->actingAs($partner)
            ->putJson('/api/partner/opportunities/'.$otherOpportunityId, $this->opportunityPayload('Changed title'))
            ->assertNotFound();

        $this->assertDatabaseHas('nurselink_job_opportunities', [
            'id' => $otherOpportunityId,
            'title' => 'Partner B Nurse',
        ]);
    }

    private function createPartnerOrganization(string $name): int
    {
        return DB::table('nurselink_partner_organizations')->insertGetId([
            'name' => $name,
            'organization_type' => 'hospital',
            'country' => 'Philippines',
            'status' => 'verified',
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOpportunity(int $organizationId, string $reference, string $title): int
    {
        return DB::table('nurselink_job_opportunities')->insertGetId([
            'partner_organization_id' => $organizationId,
            'reference_code' => $reference,
            'title' => $title,
            'employer_name' => 'Hospital',
            'country' => 'Philippines',
            'minimum_experience_years' => 0,
            'overseas_opportunity' => false,
            'status' => 'paused',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function opportunityPayload(string $title): array
    {
        return [
            'title' => $title,
            'country' => 'Philippines',
            'minimum_experience_years' => 0,
            'overseas_opportunity' => false,
            'partner_status' => 'paused',
        ];
    }
}
