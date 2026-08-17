<?php

namespace App\Http\Controllers\Api\Member;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyMemberController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $member = Member::query()->where('user_id',$request->user()->id)->with('profile')->first();
        return response()->json(['data' => $member]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $member = Member::query()
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'middle_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['required', 'string', 'max:120'],
            'suffix' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'nationality' => ['nullable', 'string', 'max:120'],
            'mobile_phone' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:120'],
            'professional_title' => ['nullable', 'string', 'max:150'],
            'current_position' => ['nullable', 'string', 'max:150'],
            'current_employer' => ['nullable', 'string', 'max:190'],
            'years_experience' => ['nullable', 'integer', 'min:0', 'max:80'],
        ]);

        $profile = MemberProfile::query()->updateOrCreate(
            ['member_id' => $member->id],
            $data
        );

        return response()->json([
            'message' => 'Member profile saved.',
            'data' => $member->fresh()->load('profile'),
            'profile' => $profile,
        ]);
    }
}
