<?php

namespace App\Services\SmartRegistration;

use App\Models\Application;
use App\Models\ExtractedFact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;

class FactReviewService
{
    public function confirm(ExtractedFact $fact, Application $application, User $user, ?string $value = null): ExtractedFact
    {
        if ($fact->application_id !== $application->id || $application->user_id !== $user->id) {
            throw new AuthorizationException;
        }
        $accepted = $value ?? $fact->proposed_value;
        $fact->update(['member_status' => 'confirmed', 'member_value' => $accepted, 'member_confirmed_by_user_id' => $user->id, 'member_confirmed_at' => now()]);
        $profile = $application->profile_data ?? [];
        Arr::set($profile, $fact->field_path, $accepted);
        $application->update(['profile_data' => $profile]);

        return $fact->fresh();
    }

    public function reject(ExtractedFact $fact, Application $application, User $user): ExtractedFact
    {
        if ($fact->application_id !== $application->id || $application->user_id !== $user->id) {
            throw new AuthorizationException;
        }
        $fact->update(['member_status' => 'rejected', 'member_confirmed_by_user_id' => $user->id, 'member_confirmed_at' => now()]);

        return $fact->fresh();
    }
}
