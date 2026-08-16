<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('nurselink_notifications')
            ->where('user_id', $request->user()->getKey())
            ->orderByRaw('CASE WHEN read_at IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'type' => $row->type,
                'severity' => $row->severity,
                'title' => $row->title,
                'message' => $row->message,
                'action_url' => $row->action_url,
                'read_at' => $row->read_at,
                'created_at' => $row->created_at,
            ])
            ->values();

        return response()->json([
            'data' => $rows,
            'unread_count' => $rows->whereNull('read_at')->count(),
        ]);
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $updated = DB::table('nurselink_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->getKey())
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        abort_unless($updated, 404);

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function readAll(Request $request): JsonResponse
    {
        DB::table('nurselink_notifications')
            ->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}
