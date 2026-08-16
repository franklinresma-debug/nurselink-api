<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AdminManagementController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_administrator_can_update_a_database_query_target(): void
    {
        $super = User::factory()->create();
        $target = User::factory()->create();

        DB::table('nurselink_super_admin_access')->insert([
            'user_id' => $super->getKey(),
            'active' => true,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $session = new Store('admin-management-test', new ArraySessionHandler(120));
        $session->start();
        $session->put([
            'nurselink_admin_elevated_user_id' => (string) $super->getKey(),
            'nurselink_admin_elevated_at' => time(),
            'nurselink_admin_expires_at' => time() + 3600,
        ]);

        $request = Request::create('/api/nurselink/admin/management', 'PATCH', [
            'department_unit' => 'Membership Operations',
            'roles' => ['membership_administrator'],
            'reason' => 'Regression test for database query targets.',
        ]);
        $request->setUserResolver(fn (): User => $super);
        $request->setLaravelSession($session);

        $response = app(AdminManagementController::class)->update($request, (string) $target->getKey());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('nurselink_admin_role_assignments', [
            'user_id' => $target->getKey(),
            'role_key' => 'membership_administrator',
            'active' => true,
        ]);
    }
}
