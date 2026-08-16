<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,code,name')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'description']);

        return response()->json(['data' => $roles]);
    }
}
