<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;

class EnsureVerifiedOrApplicantEditing extends EnsureEmailIsVerified
{
    public function handle($request, Closure $next, $redirectToRoute = null)
    {
        $user = $request->user();
        $route = $request->route()?->uri();
        $staffRoute = preg_match('~(?:^|/)(admin|reviewer|partner|encoder)(?:/|$)~', (string)$route);
        if ($user && $user->status === 'active' && $user->hasRole('applicant')
            && !config('nurselink.applicant_email_verification_required', true) && !$staffRoute) {
            return $next($request);
        }
        $allowed = [
            'api/me', 'api/policy-consent', 'api/nurselink/session-identity',
            'api/smart-registration', 'api/smart-registration/documents',
            'api/smart-registration/documents/{id}', 'api/smart-registration/personal',
            'api/smart-registration/professional', 'api/smart-registration/documents/{id}/reprocess',
            'api/credential-registry', 'api/credential-registry/{id}',
            'api/employment-history', 'api/employment-history/{id}',
            'api/profile-photo', 'api/profile-photo/image',
            'api/applications/me', 'api/applications', 'api/applications/{application}/profile',
            'api/applications/{application}/documents', 'api/documents/{document}/extract',
            'api/extracted-facts/{fact}/confirm', 'api/extracted-facts/{fact}/reject',
            'api/applications/{application}/missing-fields/refresh',
        ];
        if ($user && $user->status === 'active' && $user->hasRole('applicant')
            && in_array($route, $allowed, true)) {
            return $next($request);
        }
        return parent::handle($request, $next, $redirectToRoute);
    }
}
