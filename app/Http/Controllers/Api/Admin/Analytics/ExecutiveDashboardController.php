<?php
namespace App\Http\Controllers\Api\Admin\Analytics;
use App\Http\Controllers\Controller; use App\Models\AnalyticsSnapshot; use App\Services\Analytics\ExecutiveAnalyticsService; use Illuminate\Http\Request;
class ExecutiveDashboardController extends Controller { public function __invoke(Request $request,ExecutiveAnalyticsService $service){return response()->json(['current'=>$service->snapshot($request->boolean('fresh')),'history'=>AnalyticsSnapshot::query()->where('scope','executive')->latest('snapshot_date')->limit(30)->get(['snapshot_date','metrics','captured_at'])]);} }
