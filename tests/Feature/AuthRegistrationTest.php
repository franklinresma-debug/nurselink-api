<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_normalizes_email_and_assigns_applicant_role(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $response = $this->postJson('/register', [
            'name' => 'Maria Santos',
            'email' => '  MARIA@EXAMPLE.COM ',
            'password' => 'Very-Strong-Password-2026!',
            'password_confirmation' => 'Very-Strong-Password-2026!',
        ]);

        $response->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'maria@example.com']);
        $this->assertTrue(Role::where('code', 'applicant')->firstOrFail()->users()->where('email', 'maria@example.com')->exists());
    }

    public function test_pilot_registration_accepts_only_allowlisted_email_addresses(): void
    {
        $this->seed(RolePermissionSeeder::class);
        config()->set('registration.mode', 'pilot');
        config()->set('registration.pilot_emails', ['invited@example.com']);

        $this->postJson('/register', [
            'name' => 'Not Invited',
            'email' => 'other@example.com',
            'password' => 'Very-Strong-Password-2026!',
            'password_confirmation' => 'Very-Strong-Password-2026!',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson('/register', [
            'name' => 'Invited Pilot',
            'email' => ' INVITED@EXAMPLE.COM ',
            'password' => 'Very-Strong-Password-2026!',
            'password_confirmation' => 'Very-Strong-Password-2026!',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'invited@example.com']);
    }

    public function test_closed_registration_rejects_new_accounts(): void
    {
        config()->set('registration.mode', 'closed');

        $this->postJson('/register', [
            'name' => 'Closed Registration',
            'email' => 'closed@example.com',
            'password' => 'Very-Strong-Password-2026!',
            'password_confirmation' => 'Very-Strong-Password-2026!',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseMissing('users', ['email' => 'closed@example.com']);
    }
}
