<?php

namespace Database\Seeders;

use App\Models\ProfessionalCredential;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoMemberQualificationSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'dev-admin@lbs-nurselink.local')->firstOrFail();
        $member = $user->member()->firstOrFail();

        ProfessionalCredential::query()->updateOrCreate(
            [
                'member_id' => $member->getKey(),
                'credential_type' => 'nursing_degree',
                'title' => 'Bachelor of Science in Nursing',
            ],
            [
                'category' => 'education',
                'issuing_authority' => 'NurseLink Demo University',
                'country' => 'PH',
                'issued_on' => '2012-03-30',
                'does_not_expire' => true,
                'credential_status' => 'active',
                'verification_status' => 'verified',
                'verified_by' => $user->getKey(),
                'verified_at' => now(),
                'verification_note' => 'Reviewed seeded demonstration record. Indicative PQF Level 6; not an official equivalency determination.',
            ]
        );
    }
}
