<?php

namespace App\Actions\Fortify;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Rules\Password;

class CreateNewUser implements CreatesNewUsers
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $input): User
    {
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));

        $registrationMode = (string) config('registration.mode', 'open');
        $pilotEmails = (array) config('registration.pilot_emails', []);

        Validator::make([
            ...$input,
            'email' => $email,
        ], [
            'name' => ['required', 'string', 'max:160'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                static function (string $attribute, mixed $value, \Closure $fail) use ($registrationMode, $pilotEmails): void {
                    $normalized = mb_strtolower(trim((string) $value));

                    if ($registrationMode === 'closed') {
                        $fail('New member registration is temporarily closed.');
                    }

                    if ($registrationMode === 'pilot' && ! in_array($normalized, $pilotEmails, true)) {
                        $fail('New member registration is currently limited to invited pilot participants.');
                    }
                },
                Rule::unique(User::class),
            ],
            'password' => ['required', 'string', new Password],
        ])->validate();

        $user = User::query()->create([
            'name' => trim($input['name']),
            'email' => $email,
            'password' => Hash::make($input['password']),
            'status' => 'active',
            'mfa_required' => false,
        ]);

        $applicant = Role::query()->where('code', 'applicant')->firstOrFail();
        $user->roles()->syncWithoutDetaching([
            $applicant->id => ['assigned_at' => now()],
        ]);

        $this->audit->write('auth.registered', $user, 'user', $user->id, ['role' => 'applicant'], request());

        return $user;
    }
}
