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
}
