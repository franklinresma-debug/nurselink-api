<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AdminManagementController;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_cannot_list_users(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
            'mfa_required' => false,
        ]);

        $role = Role::where('code', 'applicant')->firstOrFail();

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_admin_without_confirmed_mfa_is_blocked_from_admin_area(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
            'mfa_required' => true,
            'two_factor_confirmed_at' => null,
        ]);

        $role = Role::where('code', 'administrator')->firstOrFail();

        $user->roles()->attach($role->id, [
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/admin/users')
            ->assertStatus(423)
            ->assertJsonPath('code', 'mfa_setup_required');
    }

    public function test_ordinary_member_is_blocked_from_admin_surfaces(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
            'mfa_required' => false,
        ]);

        $memberRole = Role::where('code', 'member')->first();

        if ($memberRole) {
            $user->roles()->attach($memberRole->id, [
                'assigned_at' => now(),
            ]);
        }

        Sanctum::actingAs($user);

        foreach ($this->adminSurfaceUrls() as $url) {
            $this->getJson($url)
                ->assertForbidden();
        }
    }

    public function test_super_administrator_can_access_admin_surfaces(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
            'mfa_required' => false,
        ]);

        $coreRole = Role::where('code', 'super_administrator')->first()
            ?? Role::where('code', 'administrator')->firstOrFail();

        $user->roles()->attach($coreRole->id, [
            'assigned_at' => now(),
        ]);

        DB::table('nurselink_super_admin_access')->insert([
            'user_id' => $user->getKey(),
            'active' => true,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        foreach ($this->adminSurfaceUrls() as $url) {
            $this->getJson($url)
                ->assertOk();
        }
    }

    public function test_membership_administrator_has_least_privilege_access(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $super = $this->makeSuperAdministrator();

        $target = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
            'mfa_required' => false,
        ]);

        $this->assignNurseLinkRole(
            $super,
            $target,
            'membership_administrator'
        );

        Sanctum::actingAs($target);

        $this->getJson('/api/admin/members')
            ->assertOk();

        $this->getJson('/api/admin/applications')
            ->assertOk();

        $this->getJson('/api/admin/events')
            ->assertForbidden();

        $this->getJson('/api/admin/policies')
            ->assertForbidden();

        $this->getJson('/api/admin/analytics/executive')
            ->assertForbidden();
    }

    public function test_verification_officer_has_least_privilege_access(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $super = $this->makeSuperAdministrator();

        $target = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
            'mfa_required' => false,
        ]);

        $this->assignNurseLinkRole(
            $super,
            $target,
            'verification_officer'
        );

        Sanctum::actingAs($target);

        $this->getJson('/api/admin/members')
            ->assertOk();

        $this->getJson('/api/admin/applications')
            ->assertOk();

        $this->getJson('/api/admin/events')
            ->assertForbidden();

        $this->getJson('/api/admin/policies')
            ->assertForbidden();

        $this->getJson('/api/admin/analytics/executive')
            ->assertForbidden();
    }

    private function makeSuperAdministrator(): User
    {
        $super = User::factory()->create([
            'email_verified_at' => now(),
            'status' => 'active',
            'mfa_required' => false,
        ]);

        DB::table('nurselink_super_admin_access')->insert([
            'user_id' => $super->getKey(),
            'active' => true,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $super;
    }

    private function assignNurseLinkRole(
        User $super,
        User $target,
        string $role
    ): void {
        $session = new Store(
            'authorization-admin-test',
            new ArraySessionHandler(120)
        );

        $session->start();

        $session->put([
            'nurselink_admin_elevated_user_id' => (string) $super->getKey(),
            'nurselink_admin_elevated_at' => time(),
            'nurselink_admin_expires_at' => time() + 3600,
        ]);

        $request = Request::create(
            '/api/nurselink/admin/management/' . $target->getKey(),
            'PATCH',
            [
                'department_unit' => 'Automated Authorization Test',
                'roles' => [$role],
                'reason' => 'Automated regression test for NurseLink administrator authorization.',
            ]
        );

        $request->setUserResolver(fn (): User => $super);
        $request->setLaravelSession($session);

        $response = app(AdminManagementController::class)
            ->update($request, (string) $target->getKey());

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Unable to assign NurseLink administrator role during test setup.'
        );

        $this->assertDatabaseHas(
            'nurselink_admin_role_assignments',
            [
                'user_id' => $target->getKey(),
                'role_key' => $role,
                'active' => true,
            ]
        );
    }

    private function adminSurfaceUrls(): array
    {
        return [
            '/api/admin/members',
            '/api/admin/applications',
            '/api/admin/events',
            '/api/admin/policies',
            '/api/admin/analytics/executive',
        ];
    }
}
