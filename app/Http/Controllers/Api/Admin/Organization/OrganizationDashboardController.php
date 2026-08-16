<?php
namespace App\Http\Controllers\Api\Admin\Organization;
use App\Http\Controllers\Controller; use App\Services\Organization\OrganizationDashboardService;
class OrganizationDashboardController extends Controller { public function __invoke(OrganizationDashboardService $service){return response()->json($service->snapshot());} }
