<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_applicant_cannot_list_users(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create(['email_verified_at' => now()]);
        $role = Role::where('code', 'applicant')->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admin_without_confirmed_mfa_is_blocked_from_admin_area(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'mfa_required' => true,
            'two_factor_confirmed_at' => null,
        ]);
        $role = Role::where('code', 'administrator')->firstOrFail();
        $user->roles()->attach($role->id, ['assigned_at' => now()]);
        Sanctum::actingAs($user);

        $this->getJson('/api/admin/users')
            ->assertStatus(423)
            ->assertJsonPath('code', 'mfa_setup_required');
    }
}
