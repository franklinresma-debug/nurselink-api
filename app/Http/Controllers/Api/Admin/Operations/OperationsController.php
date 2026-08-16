<?php
namespace App\Http\Controllers\Api\Admin\Operations;
use App\Http\Controllers\Controller; use App\Models\OperationalCheckRun; use App\Services\Operations\ReadinessCheckService;
class OperationsController extends Controller { public function show(ReadinessCheckService $s){return response()->json(['current'=>$s->check(false),'history'=>OperationalCheckRun::query()->latest('checked_at')->limit(20)->get()]);} public function run(ReadinessCheckService $s){return response()->json($s->check(true));} }
