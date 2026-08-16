<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ApplicationCommunicationController extends Controller
{
    public function show(Request $request, int $application): JsonResponse
    {
        $scope = $this->candidateScope($request, $application);

        return response()->json([
            'data' => $this->conversationPayload($scope),
        ]);
    }

    public function sendMessage(Request $request, int $application): JsonResponse
    {
        $scope = $this->candidateScope($request, $application);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $id = DB::table('nurselink_application_messages')->insertGetId([
            'job_application_id' => $scope->application_id,
            'partner_organization_id' => $scope->partner_organization_id,
            'user_id' => (string) $request->user()->getKey(),
            'sender_type' => 'candidate',
            'body' => trim($data['body']),
            'read_by_candidate_at' => now(),
            'read_by_partner_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->notifyPartnerUsers(
            (int) $scope->partner_organization_id,
            'New candidate message',
            'A nurse sent a message about an application to ' . $scope->job_title . '.',
            '/nurselink-partner-portal.html?application=' . $scope->application_id
        );

        return response()->json([
            'message' => 'Message sent.',
            'data' => DB::table('nurselink_application_messages')->where('id', $id)->first(),
        ], 201);
    }

    public function markMessagesRead(Request $request, int $application): JsonResponse
    {
        $scope = $this->candidateScope($request, $application);

        DB::table('nurselink_application_messages')
            ->where('job_application_id', $scope->application_id)
            ->where('partner_organization_id', $scope->partner_organization_id)
            ->where('sender_type', 'partner')
            ->whereNull('read_by_candidate_at')
            ->update([
                'read_by_candidate_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Partner messages marked as read.']);
    }

    public function respondInterview(
        Request $request,
        int $application,
        int $interview
    ): JsonResponse {
        $scope = $this->candidateScope($request, $application);

        $row = DB::table('nurselink_interviews')
            ->where('id', $interview)
            ->where('job_application_id', $scope->application_id)
            ->where('partner_organization_id', $scope->partner_organization_id)
            ->first();

        abort_unless($row, 404);

        $data = $request->validate([
            'status' => ['required', 'string', Rule::in([
                'confirmed',
                'reschedule_requested',
                'cancelled',
            ])],
            'candidate_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $update = [
            'status' => $data['status'],
            'candidate_notes' => $data['candidate_notes'] ?? null,
            'updated_at' => now(),
        ];

        if ($data['status'] === 'confirmed') {
            $update['confirmed_at'] = now();
            $update['reschedule_requested_at'] = null;
            $update['cancelled_at'] = null;
        } elseif ($data['status'] === 'reschedule_requested') {
            $update['reschedule_requested_at'] = now();
            $update['confirmed_at'] = null;
        } else {
            $update['cancelled_at'] = now();
            $update['confirmed_at'] = null;
        }

        DB::table('nurselink_interviews')
            ->where('id', $interview)
            ->update($update);

        $this->notifyPartnerUsers(
            (int) $scope->partner_organization_id,
            'Interview response received',
            'A nurse responded to an interview invitation for ' . $scope->job_title . ': '
                . str_replace('_', ' ', $data['status']) . '.',
            '/nurselink-partner-portal.html?application=' . $scope->application_id
        );

        return response()->json([
            'message' => 'Interview response saved.',
            'data' => DB::table('nurselink_interviews')->where('id', $interview)->first(),
        ]);
    }

    private function candidateScope(Request $request, int $application): object
    {
        $row = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->join('nurselink_partner_organizations as o', 'o.id', '=', 'j.partner_organization_id')
            ->where('a.id', $application)
            ->where('a.user_id', $request->user()->getKey())
            ->where('o.status', 'verified')
            ->first([
                'a.id as application_id',
                'a.user_id',
                'a.status as application_status',
                'j.id as job_id',
                'j.title as job_title',
                'j.employer_name',
                'j.partner_organization_id',
                'o.name as partner_name',
            ]);

        abort_unless($row, 404, 'Partner communication is not available for this application.');

        return $row;
    }

    private function conversationPayload(object $scope): array
    {
        $messages = DB::table('nurselink_application_messages')
            ->where('job_application_id', $scope->application_id)
            ->where('partner_organization_id', $scope->partner_organization_id)
            ->orderBy('created_at')
            ->limit(300)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'sender_type' => $row->sender_type,
                'body' => $row->body,
                'read_by_candidate_at' => $row->read_by_candidate_at,
                'read_by_partner_at' => $row->read_by_partner_at,
                'created_at' => $row->created_at,
            ])
            ->values();

        $interviews = DB::table('nurselink_interviews')
            ->where('job_application_id', $scope->application_id)
            ->where('partner_organization_id', $scope->partner_organization_id)
            ->orderByDesc('scheduled_start')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'scheduled_start' => $row->scheduled_start,
                'scheduled_end' => $row->scheduled_end,
                'timezone' => $row->timezone,
                'mode' => $row->mode,
                'location_or_link' => $row->location_or_link,
                'status' => $row->status,
                'partner_notes' => $row->partner_notes,
                'candidate_notes' => $row->candidate_notes,
                'confirmed_at' => $row->confirmed_at,
                'reschedule_requested_at' => $row->reschedule_requested_at,
                'cancelled_at' => $row->cancelled_at,
                'completed_at' => $row->completed_at,
            ])
            ->values();

        return [
            'application' => [
                'id' => (int) $scope->application_id,
                'status' => $scope->application_status,
                'job_title' => $scope->job_title,
                'employer_name' => $scope->employer_name,
                'partner_name' => $scope->partner_name,
            ],
            'messages' => $messages,
            'interviews' => $interviews,
        ];
    }

    private function notifyPartnerUsers(
        int $partnerOrganizationId,
        string $title,
        string $message,
        string $actionUrl
    ): void {
        $userIds = DB::table('nurselink_partner_access')
            ->where('partner_organization_id', $partnerOrganizationId)
            ->where('active', true)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            DB::table('nurselink_notifications')->insert([
                'user_id' => $userId,
                'type' => 'partner_communication',
                'severity' => 'info',
                'title' => $title,
                'message' => $message,
                'action_url' => $actionUrl,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
