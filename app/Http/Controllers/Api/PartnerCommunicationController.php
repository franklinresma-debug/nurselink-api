<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerCommunicationController extends Controller
{
    public function show(Request $request, int $application): JsonResponse
    {
        $scope = $this->partnerApplicationScope($request, $application);

        return response()->json([
            'data' => $this->conversationPayload($scope),
        ]);
    }

    public function sendMessage(Request $request, int $application): JsonResponse
    {
        $scope = $this->partnerApplicationScope($request, $application, true);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $id = DB::table('nurselink_application_messages')->insertGetId([
            'job_application_id' => $scope['application']->application_id,
            'partner_organization_id' => $scope['organization']->id,
            'user_id' => (string) $request->user()->getKey(),
            'sender_type' => 'partner',
            'body' => trim($data['body']),
            'read_by_candidate_at' => null,
            'read_by_partner_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->notifyCandidate(
            (string) $scope['application']->candidate_user_id,
            'New employer message',
            $scope['organization']->name . ' sent you a message about '
                . $scope['application']->job_title . '.',
            '/applications'
        );

        return response()->json([
            'message' => 'Message sent.',
            'data' => DB::table('nurselink_application_messages')->where('id', $id)->first(),
        ], 201);
    }

    public function markMessagesRead(Request $request, int $application): JsonResponse
    {
        $scope = $this->partnerApplicationScope($request, $application);

        DB::table('nurselink_application_messages')
            ->where('job_application_id', $scope['application']->application_id)
            ->where('partner_organization_id', $scope['organization']->id)
            ->where('sender_type', 'candidate')
            ->whereNull('read_by_partner_at')
            ->update([
                'read_by_partner_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json(['message' => 'Candidate messages marked as read.']);
    }

    public function scheduleInterview(Request $request, int $application): JsonResponse
    {
        $scope = $this->partnerApplicationScope($request, $application, true);

        abort_if(
            in_array($scope['application']->application_status, ['withdrawn', 'declined'], true),
            422,
            'Interview scheduling is unavailable for withdrawn or declined applications.'
        );

        $data = $this->validateInterview($request);

        $id = DB::table('nurselink_interviews')->insertGetId([
            'job_application_id' => $scope['application']->application_id,
            'partner_organization_id' => $scope['organization']->id,
            'user_id' => (string) $request->user()->getKey(),
            'scheduled_start' => $data['scheduled_start'],
            'scheduled_end' => $data['scheduled_end'] ?? null,
            'timezone' => $data['timezone'],
            'mode' => $data['mode'],
            'location_or_link' => $data['location_or_link'] ?? null,
            'status' => 'proposed',
            'partner_notes' => $data['partner_notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! in_array($scope['application']->application_status, ['offer'], true)) {
            DB::table('nurselink_job_applications')
                ->where('id', $scope['application']->application_id)
                ->update([
                    'status' => 'interview',
                    'partner_reviewed_by' => (string) $request->user()->getKey(),
                    'partner_reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $this->audit(
            $request,
            $scope,
            'interview.scheduled',
            'interview',
            (string) $id,
            null,
            DB::table('nurselink_interviews')->where('id', $id)->first()
        );

        $this->notifyCandidate(
            (string) $scope['application']->candidate_user_id,
            'Interview invitation',
            $scope['organization']->name . ' proposed an interview for '
                . $scope['application']->job_title . '.',
            '/applications'
        );

        return response()->json([
            'message' => 'Interview proposed.',
            'data' => DB::table('nurselink_interviews')->where('id', $id)->first(),
        ], 201);
    }

    public function updateInterview(
        Request $request,
        int $application,
        int $interview
    ): JsonResponse {
        $scope = $this->partnerApplicationScope($request, $application, true);

        $before = DB::table('nurselink_interviews')
            ->where('id', $interview)
            ->where('job_application_id', $scope['application']->application_id)
            ->where('partner_organization_id', $scope['organization']->id)
            ->first();

        abort_unless($before, 404);

        $data = $request->validate([
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],
            'timezone' => ['required', 'string', 'max:80'],
            'mode' => ['required', 'string', Rule::in(['video', 'phone', 'onsite'])],
            'location_or_link' => ['nullable', 'string', 'max:512'],
            'status' => ['required', 'string', Rule::in([
                'proposed',
                'confirmed',
                'completed',
                'cancelled',
            ])],
            'partner_notes' => ['nullable', 'string', 'max:3000'],
        ]);

        $scheduleChanged =
            (string) $before->scheduled_start !== (string) $data['scheduled_start']
            || (string) ($before->scheduled_end ?? '') !== (string) ($data['scheduled_end'] ?? '')
            || (string) $before->timezone !== (string) $data['timezone']
            || (string) $before->mode !== (string) $data['mode']
            || (string) ($before->location_or_link ?? '') !== (string) ($data['location_or_link'] ?? '');

        $status = $scheduleChanged ? 'proposed' : $data['status'];

        $update = [
            'scheduled_start' => $data['scheduled_start'],
            'scheduled_end' => $data['scheduled_end'] ?? null,
            'timezone' => $data['timezone'],
            'mode' => $data['mode'],
            'location_or_link' => $data['location_or_link'] ?? null,
            'status' => $status,
            'partner_notes' => $data['partner_notes'] ?? null,
            'updated_at' => now(),
        ];

        if ($scheduleChanged) {
            $update['confirmed_at'] = null;
            $update['reschedule_requested_at'] = null;
            $update['cancelled_at'] = null;
        } elseif ($status === 'confirmed') {
            $update['confirmed_at'] = $before->confirmed_at ?: now();
        } elseif ($status === 'completed') {
            $update['completed_at'] = now();
        } elseif ($status === 'cancelled') {
            $update['cancelled_at'] = now();
        }

        DB::table('nurselink_interviews')->where('id', $interview)->update($update);

        $after = DB::table('nurselink_interviews')->where('id', $interview)->first();

        $this->audit(
            $request,
            $scope,
            $scheduleChanged ? 'interview.rescheduled' : 'interview.updated',
            'interview',
            (string) $interview,
            $before,
            $after
        );

        $this->notifyCandidate(
            (string) $scope['application']->candidate_user_id,
            $scheduleChanged ? 'Interview schedule updated' : 'Interview status updated',
            $scope['organization']->name . ' updated your interview for '
                . $scope['application']->job_title . '.',
            '/applications'
        );

        return response()->json([
            'message' => $scheduleChanged
                ? 'Interview rescheduled and returned to proposed status.'
                : 'Interview updated.',
            'data' => $after,
        ]);
    }

    private function partnerApplicationScope(
        Request $request,
        int $application,
        bool $write = false
    ): array {
        $user = $request->user();
        abort_unless($user, 401);

        $access = DB::table('nurselink_partner_access')
            ->where('user_id', $user->getKey())
            ->where('active', true)
            ->first();

        abort_unless($access, 403, 'NurseLink partner access is required.');

        $organization = DB::table('nurselink_partner_organizations')
            ->where('id', $access->partner_organization_id)
            ->where('status', 'verified')
            ->first();

        abort_unless($organization, 403, 'Verified partner organization access is required.');

        $role = strtolower((string) $access->role);

        if ($write) {
            abort_unless(
                in_array($role, ['recruiter', 'manager'], true),
                403,
                'Recruiter or manager access is required.'
            );
        } else {
            abort_unless(
                in_array($role, ['viewer', 'recruiter', 'manager'], true),
                403
            );
        }

        $row = DB::table('nurselink_job_applications as a')
            ->join('nurselink_job_opportunities as j', 'j.id', '=', 'a.job_opportunity_id')
            ->where('a.id', $application)
            ->where('j.partner_organization_id', $organization->id)
            ->first([
                'a.id as application_id',
                'a.user_id as candidate_user_id',
                'a.status as application_status',
                'j.title as job_title',
                'j.reference_code',
            ]);

        abort_unless($row, 404);

        return [
            'role' => $role,
            'organization' => $organization,
            'application' => $row,
        ];
    }

    private function conversationPayload(array $scope): array
    {
        $messages = DB::table('nurselink_application_messages')
            ->where('job_application_id', $scope['application']->application_id)
            ->where('partner_organization_id', $scope['organization']->id)
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
            ->where('job_application_id', $scope['application']->application_id)
            ->where('partner_organization_id', $scope['organization']->id)
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
                'id' => (int) $scope['application']->application_id,
                'status' => $scope['application']->application_status,
                'job_title' => $scope['application']->job_title,
                'reference_code' => $scope['application']->reference_code,
                'partner_name' => $scope['organization']->name,
            ],
            'messages' => $messages,
            'interviews' => $interviews,
        ];
    }

    private function validateInterview(Request $request): array
    {
        return $request->validate([
            'scheduled_start' => ['required', 'date', 'after:now'],
            'scheduled_end' => ['nullable', 'date', 'after:scheduled_start'],
            'timezone' => ['required', 'string', 'max:80'],
            'mode' => ['required', 'string', Rule::in(['video', 'phone', 'onsite'])],
            'location_or_link' => ['nullable', 'string', 'max:512'],
            'partner_notes' => ['nullable', 'string', 'max:3000'],
        ]);
    }

    private function notifyCandidate(
        string $candidateUserId,
        string $title,
        string $message,
        string $actionUrl
    ): void {
        DB::table('nurselink_notifications')->insert([
            'user_id' => $candidateUserId,
            'type' => 'partner_communication',
            'severity' => 'info',
            'title' => $title,
            'message' => $message,
            'action_url' => $actionUrl,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function audit(
        Request $request,
        array $scope,
        string $action,
        string $targetType,
        string $targetId,
        mixed $before,
        mixed $after
    ): void {
        DB::table('nurselink_partner_audit')->insert([
            'user_id' => (string) $request->user()->getKey(),
            'partner_organization_id' => (int) $scope['organization']->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'before_state' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_state' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
        ]);
    }
}
