<?php

use App\Http\Controllers\Api\Admin\Application\ApplicationReviewController;
use App\Http\Controllers\Api\Admin\Member\MemberDirectoryController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\UserRoleController;
use App\Http\Controllers\Api\Admin\UserStatusController;
use App\Http\Controllers\Api\Application\ApplicationActionController;
use App\Http\Controllers\Api\Application\MyApplicationController;
use App\Http\Controllers\Api\Application\SmartRegistrationController;
use App\Http\Controllers\Api\Admin\Application\SmartRegistrationReviewController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NurseLinkRegistrationController;
use App\Http\Controllers\Api\PolicyConsentController;
use App\Http\Controllers\Api\Member\MyMemberController;
use Illuminate\Support\Facades\Route;

Route::post('/send-email-link', function (\Illuminate\Http\Request $request) {
    if ($request->user()->hasVerifiedEmail()) {
        return response()->json(['message' => 'Email already verified.']);
    }
    $request->user()->sendEmailVerificationNotification();
    return response()->json(['message' => 'Verification link sent.']);
})->middleware(['auth:sanctum', 'throttle:6,1']);
use App\Http\Controllers\Api\Member\Portfolio\MyPortfolioController;
use App\Http\Controllers\Api\Member\Portfolio\EducationController;
use App\Http\Controllers\Api\Member\Portfolio\EmploymentController;
use App\Http\Controllers\Api\Member\Portfolio\PortfolioItemController;
use App\Http\Controllers\Api\Admin\Member\MemberPortfolioController;
use App\Http\Controllers\Api\Admin\Member\PortfolioVerificationController;
use App\Http\Controllers\Api\Member\Credentials\MyCredentialDashboardController;
use App\Http\Controllers\Api\Member\Credentials\CredentialController;
use App\Http\Controllers\Api\Member\Credentials\MemberDocumentController;
use App\Http\Controllers\Api\Member\Credentials\ProfessionalDevelopmentController;
use App\Http\Controllers\Api\Admin\Member\CredentialVerificationController;
use App\Http\Controllers\Api\Admin\Member\MemberCredentialController;
use App\Http\Controllers\Api\Member\Qualifications\FrameworkCatalogController;
use App\Http\Controllers\Api\Member\Qualifications\MyQualificationAssessmentController;
use App\Http\Controllers\Api\Admin\Qualifications\AssessmentReviewController;
use App\Http\Controllers\Api\Admin\Qualifications\FrameworkAdminController;
use App\Http\Controllers\Api\Member\Communications\InboxController;
use App\Http\Controllers\Api\Member\Communications\NotificationPreferenceController;
use App\Http\Controllers\Api\Member\Events\EventCatalogController;
use App\Http\Controllers\Api\Admin\Communications\CampaignController;
use App\Http\Controllers\Api\Admin\Communications\TemplateController;
use App\Http\Controllers\Api\Admin\Events\EventAdminController;
use App\Http\Controllers\Api\Admin\Events\EventAttendanceController;


Route::get('/health', fn () => ['status' => 'ok', 'service' => 'NurseLink API', 'build' => config('operations.build')]);
Route::get('/registration-status', function () {
    $mode = (string) config('registration.mode', 'open');

    return response()->json([
        'data' => [
            'mode' => in_array($mode, ['open', 'pilot', 'closed'], true) ? $mode : 'closed',
            'accepting_registrations' => $mode !== 'closed',
        ],
    ]);
})->middleware('throttle:60,1');


/* NURSELINK_TEMPORARY_ENCODER_SESSION_V590_START */
Route::post(
    '/nurselink/encoder/session-login',
    [\App\Http\Controllers\Api\TemporaryEncoderSessionController::class, 'login']
);

Route::middleware(
    \App\Http\Middleware\EnsureTemporaryEncoder::class
)->group(function () {
    Route::get(
        '/nurselink/encoder/me',
        [\App\Http\Controllers\Api\TemporaryEncoderSessionController::class, 'me']
    );

    Route::post(
        '/nurselink/encoder/logout',
        [\App\Http\Controllers\Api\TemporaryEncoderSessionController::class, 'logout']
    );
});
/* NURSELINK_TEMPORARY_ENCODER_SESSION_V590_END */

Route::post(
    '/nurselink/register',
    [NurseLinkRegistrationController::class, 'store']
)->middleware(\App\Http\Middleware\ThrottlePublicRegistration::class);

Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/me', MeController::class);
    Route::get('/policy-consent', [PolicyConsentController::class, 'show']);
    Route::post('/policy-consent', [PolicyConsentController::class, 'accept'])->middleware('throttle:6,1');

    Route::middleware('permission:application.manage.own')->group(function () {
        Route::get('/applications/me', [MyApplicationController::class, 'show']);
        Route::post('/applications', [MyApplicationController::class, 'store']);
        Route::patch('/applications/{application}/profile', [MyApplicationController::class, 'updateProfile']);
        Route::post('/applications/{application}/ready', [ApplicationActionController::class, 'ready']);
        Route::post('/applications/{application}/submit', [ApplicationActionController::class, 'submit']);
        Route::post('/applications/{application}/resubmit', [ApplicationActionController::class, 'resubmit']);

        Route::post('/applications/{application}/documents', [SmartRegistrationController::class, 'upload']);
        Route::post('/documents/{document}/extract', [SmartRegistrationController::class, 'extract'])->middleware('permission:document.extract.own');
        Route::post('/extracted-facts/{fact}/confirm', [SmartRegistrationController::class, 'confirmFact']);
        Route::post('/extracted-facts/{fact}/reject', [SmartRegistrationController::class, 'rejectFact']);
        Route::post('/applications/{application}/missing-fields/refresh', [SmartRegistrationController::class, 'refreshMissing']);
    });

    Route::get('/members/me', MyMemberController::class)->middleware('permission:profile.manage.own');
    Route::patch('/members/me/profile', [MyMemberController::class, 'updateProfile'])->middleware('permission:profile.manage.own');

    Route::middleware('permission:message.view.own')->group(function () {
        Route::get('/messages', [InboxController::class, 'index']);
        Route::get('/messages/{message}', [InboxController::class, 'show']);
        Route::post('/messages/{message}/read', [InboxController::class, 'read']);
        Route::post('/messages/{message}/archive', [InboxController::class, 'archive']);
    });
    Route::middleware('permission:notification_preferences.manage.own')->group(function () {
        Route::get('/notification-preferences', [NotificationPreferenceController::class, 'show']);
        Route::patch('/notification-preferences', [NotificationPreferenceController::class, 'update']);
    });
    Route::middleware('permission:event.view')->group(function () {
        Route::get('/events', [EventCatalogController::class, 'index']);
        Route::get('/events/{event}', [EventCatalogController::class, 'show']);
    });
    Route::middleware('permission:event.register.own')->group(function () {
        Route::post('/events/{event}/register', [EventCatalogController::class, 'register']);
        Route::post('/event-registrations/{registration}/cancel', [EventCatalogController::class, 'cancel']);
    });

    Route::middleware('permission:qualification.view')->group(function () {
        Route::get('/qualification-frameworks', [FrameworkCatalogController::class, 'index']);
        Route::get('/qualification-frameworks/compare', [FrameworkCatalogController::class, 'compare']);
    });
    Route::middleware('permission:qualification.assessment.manage.own')->group(function () {
        Route::get('/qualification-assessments', [MyQualificationAssessmentController::class, 'index']);
        Route::post('/qualification-assessments', [MyQualificationAssessmentController::class, 'store']);
        Route::get('/qualification-assessments/{assessment}', [MyQualificationAssessmentController::class, 'show']);
        Route::post('/qualification-assessments/{assessment}/refresh', [MyQualificationAssessmentController::class, 'refresh']);
        Route::post('/qualification-assessments/{assessment}/submit', [MyQualificationAssessmentController::class, 'submit']);
    });

    Route::middleware('permission:credential.manage.own')->group(function () {
        Route::get('/credentials/dashboard', MyCredentialDashboardController::class);
        Route::get('/credentials', [CredentialController::class, 'index']);
        Route::post('/credentials', [CredentialController::class, 'store']);
        Route::patch('/credentials/{credential}', [CredentialController::class, 'update']);
        Route::delete('/credentials/{credential}', [CredentialController::class, 'destroy']);
        Route::post('/credentials/{credential}/documents', [CredentialController::class, 'linkDocument']);
    });

    Route::middleware('permission:document.manage.own')->group(function () {
        Route::get('/documents', [MemberDocumentController::class, 'index']);
        Route::post('/documents', [MemberDocumentController::class, 'store']);
        Route::get('/documents/{document}/download', [MemberDocumentController::class, 'download']);
        Route::delete('/documents/{document}', [MemberDocumentController::class, 'destroy']);
    });

    Route::middleware('permission:professional_development.manage.own')->group(function () {
        Route::get('/professional-development', [ProfessionalDevelopmentController::class, 'index']);
        Route::post('/professional-development', [ProfessionalDevelopmentController::class, 'store']);
        Route::patch('/professional-development/{record}', [ProfessionalDevelopmentController::class, 'update']);
        Route::delete('/professional-development/{record}', [ProfessionalDevelopmentController::class, 'destroy']);
    });

    Route::middleware('permission:profile.manage.own')->group(function () {
        Route::get('/portfolio/me', [MyPortfolioController::class, 'show']);
        Route::patch('/portfolio/me/summary', [MyPortfolioController::class, 'updateSummary']);
        Route::post('/portfolio/education', [EducationController::class, 'store']);
        Route::patch('/portfolio/education/{education}', [EducationController::class, 'update']);
        Route::delete('/portfolio/education/{education}', [EducationController::class, 'destroy']);
        Route::post('/portfolio/employment', [EmploymentController::class, 'store']);
        Route::patch('/portfolio/employment/{employment}', [EmploymentController::class, 'update']);
        Route::delete('/portfolio/employment/{employment}', [EmploymentController::class, 'destroy']);
        Route::post('/portfolio/specialties', [PortfolioItemController::class, 'specialty']);
        Route::post('/portfolio/competencies', [PortfolioItemController::class, 'competency']);
        Route::post('/portfolio/technology', [PortfolioItemController::class, 'technology']);
        Route::post('/portfolio/languages', [PortfolioItemController::class, 'language']);
    });

    Route::prefix('admin')->middleware(['mfa.required'])->group(function () {

        Route::middleware('permission:broadcast.send')->group(function () {
            Route::get('/communication-campaigns', [CampaignController::class, 'index']);
            Route::post('/communication-campaigns', [CampaignController::class, 'store']);
            Route::get('/communication-campaigns/{campaign}', [CampaignController::class, 'show']);
            Route::post('/communication-campaigns/audience-preview', [CampaignController::class, 'previewAudience']);
            Route::post('/communication-campaigns/{campaign}/schedule', [CampaignController::class, 'schedule']);
            Route::post('/communication-campaigns/{campaign}/send-now', [CampaignController::class, 'sendNow']);
            Route::post('/communication-campaigns/{campaign}/cancel', [CampaignController::class, 'cancel']);
        });
        Route::middleware('permission:communication.template.manage')->group(function () {
            Route::get('/message-templates', [TemplateController::class, 'index']);
            Route::post('/message-templates', [TemplateController::class, 'store']);
            Route::patch('/message-templates/{template}', [TemplateController::class, 'update']);
        });
        Route::middleware('permission:event.manage')->group(function () {
            Route::get('/events', [EventAdminController::class, 'index']);
            Route::post('/events', [EventAdminController::class, 'store']);
            Route::get('/events/{event}', [EventAdminController::class, 'show']);
            Route::patch('/events/{event}', [EventAdminController::class, 'update']);
            Route::post('/events/{event}/cancel', [EventAdminController::class, 'cancel']);
        });
        Route::post('/event-registrations/{registration}/attendance', [EventAttendanceController::class, 'record'])->middleware('permission:event.attendance.manage');

        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.assign');
        Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.manage');
        Route::patch('/users/{user}/roles', [UserRoleController::class, 'update'])->middleware('permission:roles.assign');
        Route::patch('/users/{user}/status', [UserStatusController::class, 'update'])->middleware('permission:users.manage');

        Route::middleware('permission:application.review')->group(function () {
            Route::get('/applications', [ApplicationReviewController::class, 'index']);
            Route::get('/applications/{application}', [ApplicationReviewController::class, 'show']);
            Route::post('/applications/{application}/start-review', [ApplicationReviewController::class, 'start']);
            Route::post('/applications/{application}/return', [ApplicationReviewController::class, 'returnForInformation']);
            Route::post('/applications/{application}/approve', [ApplicationReviewController::class, 'approve']);
            Route::post('/applications/{application}/reject', [ApplicationReviewController::class, 'reject']);

            Route::get('/applications/{application}/smart-registration', [SmartRegistrationReviewController::class, 'show']);
            Route::post('/extracted-facts/{fact}/verify', [SmartRegistrationReviewController::class, 'verifyFact'])->middleware('permission:extraction.verify');
        });

        Route::middleware('permission:assessment.perform')->group(function () {
            Route::get('/qualification-assessments', [AssessmentReviewController::class, 'index']);
            Route::get('/qualification-assessments/{assessment}', [AssessmentReviewController::class, 'show']);
            Route::post('/qualification-assessments/{assessment}/start-review', [AssessmentReviewController::class, 'start']);
            Route::post('/qualification-assessments/{assessment}/decision', [AssessmentReviewController::class, 'decision']);
        });
        Route::middleware('permission:qualification.framework.manage')->group(function () {
            Route::get('/qualification-frameworks', [FrameworkAdminController::class, 'index']);
            Route::patch('/qualification-frameworks/{framework}', [FrameworkAdminController::class, 'updateFramework']);
            Route::post('/qualification-frameworks/{framework}/levels', [FrameworkAdminController::class, 'storeLevel']);
            Route::post('/qualification-frameworks/{framework}/requirements', [FrameworkAdminController::class, 'storeRequirement']);
            Route::patch('/qualification-requirements/{requirement}', [FrameworkAdminController::class, 'updateRequirement']);
            Route::post('/qualification-crosswalks', [FrameworkAdminController::class, 'storeCrosswalk']);
        });

        Route::middleware('permission:directory.view')->group(function () {
            Route::get('/members', [MemberDirectoryController::class, 'index']);
            Route::get('/members/{member}', [MemberDirectoryController::class, 'show']);
            Route::get('/members/{member}/portfolio', [MemberPortfolioController::class, 'show']);
            Route::post('/portfolio/education/{education}/verify', [PortfolioVerificationController::class, 'education'])->middleware('permission:portfolio.verify');
            Route::post('/portfolio/employment/{employment}/verify', [PortfolioVerificationController::class, 'employment'])->middleware('permission:portfolio.verify');
            Route::post('/portfolio/competencies/{competency}/verify', [PortfolioVerificationController::class, 'competency'])->middleware('permission:portfolio.verify');

            Route::get('/members/{member}/credentials', [MemberCredentialController::class, 'show']);
            Route::post('/credentials/{credential}/verify', [CredentialVerificationController::class, 'credential'])->middleware('permission:credential.verify');
            Route::post('/professional-development/{record}/verify', [CredentialVerificationController::class, 'professionalDevelopment'])->middleware('permission:credential.verify');
        });
    });
});


// NL-009 Programs, Projects, Policy & Advocacy
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::middleware('permission:program.view')->group(function () {
        Route::get('/organization/initiatives', [\App\Http\Controllers\Api\Member\Organization\OrganizationCatalogController::class, 'initiatives']);
        Route::get('/organization/initiatives/{initiative}', [\App\Http\Controllers\Api\Member\Organization\OrganizationCatalogController::class, 'initiative']);
    });
    Route::middleware('permission:policy.view')->group(function () {
        Route::get('/organization/policies', [\App\Http\Controllers\Api\Member\Organization\OrganizationCatalogController::class, 'policies']);
        Route::get('/organization/policies/{policy}', [\App\Http\Controllers\Api\Member\Organization\OrganizationCatalogController::class, 'policy']);
    });

    Route::prefix('admin')->middleware(['mfa.required'])->group(function () {
        Route::get('/organization/dashboard', \App\Http\Controllers\Api\Admin\Organization\OrganizationDashboardController::class)
            ->middleware('permission:program.manage');

        Route::middleware('permission:program.manage')->group(function () {
            Route::get('/initiatives', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'index']);
            Route::post('/initiatives', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'store']);
            Route::get('/initiatives/{initiative}', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'show']);
            Route::patch('/initiatives/{initiative}', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'update']);
            Route::post('/initiatives/{initiative}/publish', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'publish']);
            Route::post('/initiatives/{initiative}/refresh', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'refresh']);
            Route::post('/initiatives/{initiative}/milestones', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'milestone']);
            Route::patch('/initiative-milestones/{milestone}', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'updateMilestone']);
            Route::post('/initiatives/{initiative}/partners', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'partner']);
            Route::post('/initiatives/{initiative}/beneficiaries', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'beneficiary']);
            Route::post('/initiatives/{initiative}/budget-lines', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'budgetLine']);
            Route::post('/initiatives/{initiative}/updates', [\App\Http\Controllers\Api\Admin\Organization\InitiativeAdminController::class, 'updatePost']);
        });

        Route::middleware('permission:policy.manage')->group(function () {
            Route::get('/policies', [\App\Http\Controllers\Api\Admin\Organization\PolicyAdminController::class, 'index']);
            Route::post('/policies', [\App\Http\Controllers\Api\Admin\Organization\PolicyAdminController::class, 'store']);
            Route::get('/policies/{policy}', [\App\Http\Controllers\Api\Admin\Organization\PolicyAdminController::class, 'show']);
            Route::patch('/policies/{policy}', [\App\Http\Controllers\Api\Admin\Organization\PolicyAdminController::class, 'update']);
            Route::post('/policies/{policy}/transition', [\App\Http\Controllers\Api\Admin\Organization\PolicyAdminController::class, 'transition']);
            Route::post('/policies/{policy}/stakeholders', [\App\Http\Controllers\Api\Admin\Organization\PolicyAdminController::class, 'stakeholder']);
            Route::post('/policies/{policy}/publish', [\App\Http\Controllers\Api\Admin\Organization\PolicyAdminController::class, 'publish']);
        });

        Route::middleware('permission:organization.document.manage')->group(function () {
            Route::post('/initiatives/{initiative}/documents', [\App\Http\Controllers\Api\Admin\Organization\OrganizationDocumentController::class, 'initiative']);
            Route::post('/policies/{policy}/documents', [\App\Http\Controllers\Api\Admin\Organization\OrganizationDocumentController::class, 'policy']);
            Route::get('/initiative-documents/{document}/download', [\App\Http\Controllers\Api\Admin\Organization\OrganizationDocumentController::class, 'initiativeDownload']);
            Route::get('/policy-documents/{document}/download', [\App\Http\Controllers\Api\Admin\Organization\OrganizationDocumentController::class, 'policyDownload']);
        });
    });
});

// NL-010 Analytics, Reporting, Privacy & Production Readiness
Route::get('/health/live', fn () => response()->json(['status'=>'ok','service'=>'NurseLink API','build'=>config('operations.build'),'release'=>config('operations.release')]));
Route::get('/health/ready', function (\App\Services\Operations\ReadinessCheckService $service) {
    $result = $service->check(false);
    return response()->json($result, $result['status'] === 'fail' ? 503 : 200);
});

Route::middleware(['auth:sanctum','verified','active.user'])->group(function () {
    Route::middleware('permission:privacy.manage.own')->group(function () {
        Route::get('/privacy/requests', [\App\Http\Controllers\Api\Member\Privacy\PrivacyCenterController::class,'index']);
        Route::post('/privacy/requests', [\App\Http\Controllers\Api\Member\Privacy\PrivacyCenterController::class,'store']);
    });

    Route::prefix('admin')->middleware(['mfa.required'])->group(function () {
        Route::get('/analytics/executive', \App\Http\Controllers\Api\Admin\Analytics\ExecutiveDashboardController::class)->middleware('permission:analytics.view');
        Route::middleware('permission:reports.export')->group(function () {
            Route::get('/reports', [\App\Http\Controllers\Api\Admin\Analytics\ReportExportController::class,'index']);
            Route::post('/reports', [\App\Http\Controllers\Api\Admin\Analytics\ReportExportController::class,'store']);
            Route::get('/reports/{report}/download', [\App\Http\Controllers\Api\Admin\Analytics\ReportExportController::class,'download']);
        });
        Route::middleware('permission:operations.view')->group(function () {
            Route::get('/operations/readiness', [\App\Http\Controllers\Api\Admin\Operations\OperationsController::class,'show']);
            Route::post('/operations/readiness/run', [\App\Http\Controllers\Api\Admin\Operations\OperationsController::class,'run']);
        });
        Route::middleware('permission:privacy.manage')->group(function () {
            Route::get('/privacy/requests', [\App\Http\Controllers\Api\Admin\Privacy\PrivacyAdminController::class,'index']);
            Route::patch('/privacy/requests/{privacyRequest}', [\App\Http\Controllers\Api\Admin\Privacy\PrivacyAdminController::class,'update']);
        });
    });
});

/* NURSELINK_PROFILE_PHOTO_V141_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/profile-photo', [\App\Http\Controllers\Api\ProfilePhotoController::class, 'show']);
    Route::get('/profile-photo/image', [\App\Http\Controllers\Api\ProfilePhotoController::class, 'image']);
    Route::post('/profile-photo', [\App\Http\Controllers\Api\ProfilePhotoController::class, 'store']);
    Route::delete('/profile-photo', [\App\Http\Controllers\Api\ProfilePhotoController::class, 'destroy']);
});
/* NURSELINK_PROFILE_PHOTO_V141_END */

/* NURSELINK_EMPLOYMENT_HISTORY_V150_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/employment-history', [\App\Http\Controllers\Api\EmploymentHistoryController::class, 'index']);
    Route::post('/employment-history', [\App\Http\Controllers\Api\EmploymentHistoryController::class, 'store']);
    Route::put('/employment-history/{id}', [\App\Http\Controllers\Api\EmploymentHistoryController::class, 'update'])->whereNumber('id');
    Route::delete('/employment-history/{id}', [\App\Http\Controllers\Api\EmploymentHistoryController::class, 'destroy'])->whereNumber('id');
});
/* NURSELINK_EMPLOYMENT_HISTORY_V150_END */

/* NURSELINK_CREDENTIAL_REGISTRY_V160_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/credential-registry', [\App\Http\Controllers\Api\CredentialRegistryController::class, 'index']);
    Route::post('/credential-registry', [\App\Http\Controllers\Api\CredentialRegistryController::class, 'store']);
    Route::put('/credential-registry/{id}', [\App\Http\Controllers\Api\CredentialRegistryController::class, 'update'])->whereNumber('id');
    Route::delete('/credential-registry/{id}', [\App\Http\Controllers\Api\CredentialRegistryController::class, 'destroy'])->whereNumber('id');
});
/* NURSELINK_CREDENTIAL_REGISTRY_V160_END */

/* NURSELINK_PORTFOLIO_ITEMS_V190_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user', \App\Http\Middleware\EnsureApprovedNurseLinkMember::class])->group(function () {
    Route::get('/portfolio-items', [\App\Http\Controllers\Api\PortfolioItemController::class, 'index']);
    Route::post('/portfolio-items', [\App\Http\Controllers\Api\PortfolioItemController::class, 'store']);
    Route::put('/portfolio-items/{id}', [\App\Http\Controllers\Api\PortfolioItemController::class, 'update'])->whereNumber('id');
    Route::delete('/portfolio-items/{id}', [\App\Http\Controllers\Api\PortfolioItemController::class, 'destroy'])->whereNumber('id');
});
/* NURSELINK_PORTFOLIO_ITEMS_V190_END */

/* NURSELINK_CAREER_PREFERENCES_V200_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user', \App\Http\Middleware\EnsureApprovedNurseLinkMember::class])->group(function () {
    Route::get('/career-preferences', [\App\Http\Controllers\Api\CareerPreferenceController::class, 'show']);
    Route::put('/career-preferences', [\App\Http\Controllers\Api\CareerPreferenceController::class, 'upsert']);
});
/* NURSELINK_CAREER_PREFERENCES_V200_END */

/* NURSELINK_LEARNING_RECORDS_V200_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user', \App\Http\Middleware\EnsureApprovedNurseLinkMember::class])->group(function () {
    Route::get('/learning-records', [\App\Http\Controllers\Api\LearningRecordController::class, 'index']);
    Route::post('/learning-records', [\App\Http\Controllers\Api\LearningRecordController::class, 'store']);
    Route::put('/learning-records/{id}', [\App\Http\Controllers\Api\LearningRecordController::class, 'update'])->whereNumber('id');
    Route::delete('/learning-records/{id}', [\App\Http\Controllers\Api\LearningRecordController::class, 'destroy'])->whereNumber('id');
});
/* NURSELINK_LEARNING_RECORDS_V200_END */

/* NURSELINK_JOB_MATCHING_V220_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user', \App\Http\Middleware\EnsureApprovedNurseLinkMember::class])->group(function () {
    Route::get('/job-opportunities', [\App\Http\Controllers\Api\JobOpportunityController::class, 'index']);
    Route::get('/job-opportunities/{id}', [\App\Http\Controllers\Api\JobOpportunityController::class, 'show'])->whereNumber('id');

    Route::get('/saved-jobs', [\App\Http\Controllers\Api\SavedJobController::class, 'index']);
    Route::post('/saved-jobs/{jobId}', [\App\Http\Controllers\Api\SavedJobController::class, 'store'])->whereNumber('jobId');
    Route::delete('/saved-jobs/{jobId}', [\App\Http\Controllers\Api\SavedJobController::class, 'destroy'])->whereNumber('jobId');

    Route::get('/job-applications', [\App\Http\Controllers\Api\JobApplicationController::class, 'index']);
    Route::post('/job-applications', [\App\Http\Controllers\Api\JobApplicationController::class, 'store']);
    Route::patch('/job-applications/{id}/withdraw', [\App\Http\Controllers\Api\JobApplicationController::class, 'withdraw'])->whereNumber('id');
});
/* NURSELINK_JOB_MATCHING_V220_END */

/* NURSELINK_REVIEW_CENTER_V230_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user', \App\Http\Middleware\EnsureNurseLinkAdminPermission::class])->prefix('reviewer')->group(function () {
    Route::get('/summary', [\App\Http\Controllers\Api\ReviewCenterController::class, 'summary']);

    Route::get('/credentials', [\App\Http\Controllers\Api\ReviewCenterController::class, 'credentials']);
    Route::patch('/credentials/{id}', [\App\Http\Controllers\Api\ReviewCenterController::class, 'reviewCredential'])->whereUuid('id');
    Route::get('/credentials/{id}/evidence/{documentId}', [\App\Http\Controllers\Api\ReviewCenterController::class, 'downloadCredentialEvidence'])->whereUuid('id')->whereUuid('documentId');

    Route::get('/job-applications', [\App\Http\Controllers\Api\ReviewCenterController::class, 'jobApplications']);
    Route::patch('/job-applications/{id}', [\App\Http\Controllers\Api\ReviewCenterController::class, 'reviewJobApplication'])->whereNumber('id');

    Route::get('/job-opportunities', [\App\Http\Controllers\Api\ReviewCenterController::class, 'jobOpportunities']);
    Route::post('/job-opportunities', [\App\Http\Controllers\Api\ReviewCenterController::class, 'storeJobOpportunity']);
    Route::patch('/job-opportunities/{id}', [\App\Http\Controllers\Api\ReviewCenterController::class, 'updateJobOpportunity'])->whereNumber('id');

    Route::get('/audit-log', [\App\Http\Controllers\Api\ReviewCenterController::class, 'auditLog']);
});
/* NURSELINK_REVIEW_CENTER_V230_END */

/* NURSELINK_MEMBERSHIP_IDENTITY_V250_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/membership/me', [\App\Http\Controllers\Api\MembershipController::class, 'me']);

    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::patch('/notifications/{id}/read', [\App\Http\Controllers\Api\NotificationController::class, 'read'])->whereNumber('id');
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'readAll']);
});

Route::get('/membership/verify/{code}', [\App\Http\Controllers\Api\MembershipController::class, 'verify']);

Route::middleware(['auth:sanctum', 'verified', 'active.user', \App\Http\Middleware\EnsureNurseLinkAdminPermission::class])->prefix('reviewer')->group(function () {
    Route::get('/membership-applications', [\App\Http\Controllers\Api\MembershipReviewController::class, 'index']);
    Route::get('/membership-applications/{id}/evidence', [\App\Http\Controllers\Api\MembershipReviewController::class, 'evidence'])->whereNumber('id');
    Route::get('/membership-applications/{id}/evidence/{documentId}', [\App\Http\Controllers\Api\MembershipReviewController::class, 'downloadEvidence'])->whereNumber('id')->whereNumber('documentId');
    Route::patch('/membership-applications/{id}', [\App\Http\Controllers\Api\MembershipReviewController::class, 'review'])->whereNumber('id');
});
/* NURSELINK_MEMBERSHIP_IDENTITY_V250_END */

/* NURSELINK_PUBLIC_PROFILE_V260_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/public-profile/settings', [\App\Http\Controllers\Api\PublicProfileController::class, 'settings']);
    Route::put('/public-profile/settings', [\App\Http\Controllers\Api\PublicProfileController::class, 'updateSettings']);
});

Route::get('/public-profile/{slug}', [\App\Http\Controllers\Api\PublicProfileController::class, 'show']);
Route::get('/public-profile/{slug}/photo', [\App\Http\Controllers\Api\PublicProfileController::class, 'photo']);
/* NURSELINK_PUBLIC_PROFILE_V260_END */

/* NURSELINK_SESSION_BOOTSTRAP_V265_START */
Route::get(
    '/nurselink/session-bootstrap',
    [\App\Http\Controllers\Api\SessionBootstrapController::class, 'show']
);
/* NURSELINK_SESSION_BOOTSTRAP_V265_END */

/* NURSELINK_SESSION_LOGIN_V266_START */
Route::post(
    '/nurselink/session-login',
    [\App\Http\Controllers\Api\SessionLoginController::class, 'login']
);
/* NURSELINK_SESSION_LOGIN_V266_END */

/* NURSELINK_PARTNER_PORTAL_V270_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->prefix('partner')->group(function () {
    Route::get('/me', [\App\Http\Controllers\Api\PartnerPortalController::class, 'me']);
    Route::get('/summary', [\App\Http\Controllers\Api\PartnerPortalController::class, 'summary']);
    Route::get('/opportunities', [\App\Http\Controllers\Api\PartnerPortalController::class, 'opportunities']);
    Route::post('/opportunities', [\App\Http\Controllers\Api\PartnerPortalController::class, 'storeOpportunity']);
    Route::put('/opportunities/{id}', [\App\Http\Controllers\Api\PartnerPortalController::class, 'updateOpportunity'])->whereNumber('id');
    Route::get('/applications', [\App\Http\Controllers\Api\PartnerPortalController::class, 'applications']);
    Route::patch('/applications/{id}', [\App\Http\Controllers\Api\PartnerPortalController::class, 'updateApplication'])->whereNumber('id');
    Route::get('/audit-log', [\App\Http\Controllers\Api\PartnerPortalController::class, 'auditLog']);
});

Route::middleware(['auth:sanctum', 'verified', 'active.user', \App\Http\Middleware\EnsureNurseLinkAdminPermission::class])->prefix('reviewer')->group(function () {
    Route::get('/partner-organizations', [\App\Http\Controllers\Api\PartnerAdminController::class, 'organizations']);
    Route::post('/partner-organizations', [\App\Http\Controllers\Api\PartnerAdminController::class, 'storeOrganization']);
    Route::patch('/partner-organizations/{id}', [\App\Http\Controllers\Api\PartnerAdminController::class, 'updateOrganization'])->whereNumber('id');
    Route::get('/partner-access', [\App\Http\Controllers\Api\PartnerAdminController::class, 'access']);
    Route::post('/partner-access', [\App\Http\Controllers\Api\PartnerAdminController::class, 'grantAccess']);
    Route::patch('/job-opportunities/{id}/partner', [\App\Http\Controllers\Api\PartnerAdminController::class, 'linkOpportunity'])->whereNumber('id');
});
/* NURSELINK_PARTNER_PORTAL_V270_END */

/* NURSELINK_PARTNER_COMMUNICATIONS_V280_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user', \App\Http\Middleware\EnsureApprovedNurseLinkMember::class])->group(function () {
    Route::get('/job-applications/{application}/communications', [\App\Http\Controllers\Api\ApplicationCommunicationController::class, 'show'])->whereNumber('application');
    Route::post('/job-applications/{application}/messages', [\App\Http\Controllers\Api\ApplicationCommunicationController::class, 'sendMessage'])->whereNumber('application');
    Route::post('/job-applications/{application}/messages/read', [\App\Http\Controllers\Api\ApplicationCommunicationController::class, 'markMessagesRead'])->whereNumber('application');
    Route::patch('/job-applications/{application}/interviews/{interview}/respond', [\App\Http\Controllers\Api\ApplicationCommunicationController::class, 'respondInterview'])->whereNumber('application')->whereNumber('interview');
});

Route::middleware(['auth:sanctum', 'verified', 'active.user'])->prefix('partner')->group(function () {
    Route::get('/applications/{application}/communications', [\App\Http\Controllers\Api\PartnerCommunicationController::class, 'show'])->whereNumber('application');
    Route::post('/applications/{application}/messages', [\App\Http\Controllers\Api\PartnerCommunicationController::class, 'sendMessage'])->whereNumber('application');
    Route::post('/applications/{application}/messages/read', [\App\Http\Controllers\Api\PartnerCommunicationController::class, 'markMessagesRead'])->whereNumber('application');
    Route::post('/applications/{application}/interviews', [\App\Http\Controllers\Api\PartnerCommunicationController::class, 'scheduleInterview'])->whereNumber('application');
    Route::patch('/applications/{application}/interviews/{interview}', [\App\Http\Controllers\Api\PartnerCommunicationController::class, 'updateInterview'])->whereNumber('application')->whereNumber('interview');
});
/* NURSELINK_PARTNER_COMMUNICATIONS_V280_END */

/* NURSELINK_INSTITUTIONAL_ANALYTICS_V290_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/partner/analytics', [\App\Http\Controllers\Api\PartnerAnalyticsController::class, 'show']);
    Route::get('/reviewer/institutional-analytics', [\App\Http\Controllers\Api\InstitutionalAnalyticsController::class, 'show'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_INSTITUTIONAL_ANALYTICS_V290_END */

/* NURSELINK_PRODUCTION_READINESS_V320_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/reviewer/production-readiness', [\App\Http\Controllers\Api\ProductionReadinessController::class, 'show'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_PRODUCTION_READINESS_V320_END */

/* NURSELINK_OPERATIONS_CENTER_V410_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/reviewer/operations-center', [\App\Http\Controllers\Api\OperationsCenterController::class, 'show'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/reviewer/operations-center/snapshot', [\App\Http\Controllers\Api\OperationsCenterController::class, 'snapshot'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/reviewer/operations-center/incidents', [\App\Http\Controllers\Api\OperationsCenterController::class, 'storeIncident'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/reviewer/operations-center/incidents/{incident}', [\App\Http\Controllers\Api\OperationsCenterController::class, 'updateIncident'])->whereNumber('incident')->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_OPERATIONS_CENTER_V410_END */

/* NURSELINK_CAREER_INTELLIGENCE_V420_START */
Route::middleware([
    'auth:sanctum',
    'verified',
    'active.user',
    \App\Http\Middleware\EnsureApprovedNurseLinkMember::class,
])->group(function () {
    Route::get('/career-intelligence', [\App\Http\Controllers\Api\CareerIntelligenceController::class, 'show']);
    Route::post('/career-intelligence/snapshot', [\App\Http\Controllers\Api\CareerIntelligenceController::class, 'snapshot']);
});
/* NURSELINK_CAREER_INTELLIGENCE_V420_END */

/* NURSELINK_SESSION_IDENTITY_V421_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/session-identity', [\App\Http\Controllers\Api\SessionIdentityController::class, 'show']);
});
/* NURSELINK_SESSION_IDENTITY_V421_END */

/* NURSELINK_ADMIN_PORTAL_V430_START */
Route::post('/nurselink/admin/session-login', [\App\Http\Controllers\Api\AdminSessionLoginController::class, 'login']);
Route::post('/nurselink/admin/logout', [\App\Http\Controllers\Api\AdminSessionLoginController::class, 'logout']);

Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/session', [\App\Http\Controllers\Api\AdminPortalController::class, 'session'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/dashboard', [\App\Http\Controllers\Api\AdminPortalController::class, 'dashboard'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/users', [\App\Http\Controllers\Api\AdminPortalController::class, 'users'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/service-scans', [\App\Http\Controllers\Api\AdminServiceScanController::class, 'index'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/service-scans/resolve', [\App\Http\Controllers\Api\AdminServiceScanController::class, 'resolve'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/service-scans', [\App\Http\Controllers\Api\AdminServiceScanController::class, 'record'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/users/grant', [\App\Http\Controllers\Api\AdminPortalController::class, 'grant'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::delete('/nurselink/admin/users/{userId}', [\App\Http\Controllers\Api\AdminPortalController::class, 'revoke'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ADMIN_PORTAL_V430_END */

/* NURSELINK_MEMBERSHIP_COMMAND_CENTER_V440_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/membership-command/summary', [\App\Http\Controllers\Api\AdminMembershipCommandController::class, 'summary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-command', [\App\Http\Controllers\Api\AdminMembershipCommandController::class, 'index'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-command/{id}', [\App\Http\Controllers\Api\AdminMembershipCommandController::class, 'show'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-command/{id}/smart-document/{documentId}', [\App\Http\Controllers\Api\AdminMembershipCommandController::class, 'smartDocument'])->whereNumber('id')->whereNumber('documentId')->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-command/{id}/history', [\App\Http\Controllers\Api\AdminMembershipCommandController::class, 'history'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/membership-command/{id}/transition', [\App\Http\Controllers\Api\AdminMembershipCommandController::class, 'transition'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/membership-command/{id}/notification-deliveries/{deliveryId}/retry', [\App\Http\Controllers\Api\AdminMembershipCommandController::class, 'retryNotification'])->whereNumber('id')->whereNumber('deliveryId')->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_MEMBERSHIP_COMMAND_CENTER_V440_END */

/* NURSELINK_MEMBERSHIP_CYCLE_HEALTH_V559_COMPAT_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/membership-cycle-health/{id}', [\App\Http\Controllers\Api\MembershipCycleHealthController::class, 'show'])
        ->whereNumber('id')
        ->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/membership-cycle-health/{id}/reconcile', [\App\Http\Controllers\Api\MembershipCycleHealthController::class, 'reconcile'])
        ->whereNumber('id')
        ->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_MEMBERSHIP_CYCLE_HEALTH_V559_COMPAT_END */

/* NURSELINK_MEMBER_REGISTRY_V450_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/member-registry/summary', [\App\Http\Controllers\Api\AdminMemberRegistryController::class, 'summary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/member-registry', [\App\Http\Controllers\Api\AdminMemberRegistryController::class, 'index'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/member-registry/{membershipId}', [\App\Http\Controllers\Api\AdminMemberRegistryController::class, 'show'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_MEMBER_REGISTRY_V450_END */

/* NURSELINK_SUPER_ADMIN_TEST_MODE_V453_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/test-mode/session', [\App\Http\Controllers\Api\SuperAdminTestModeController::class, 'session'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/test-mode/start', [\App\Http\Controllers\Api\SuperAdminTestModeController::class, 'start'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/test-mode/stop', [\App\Http\Controllers\Api\SuperAdminTestModeController::class, 'stop'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/test-mode/checks', [\App\Http\Controllers\Api\SuperAdminTestModeController::class, 'checks'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_SUPER_ADMIN_TEST_MODE_V453_END */

/* NURSELINK_MEMBERSHIP_LIFECYCLE_V460_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/membership-lifecycle/summary', [\App\Http\Controllers\Api\AdminMembershipLifecycleController::class, 'summary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-lifecycle/{membershipId}', [\App\Http\Controllers\Api\AdminMembershipLifecycleController::class, 'show'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/membership-lifecycle/{membershipId}/standing', [\App\Http\Controllers\Api\AdminMembershipLifecycleController::class, 'transition'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_MEMBERSHIP_LIFECYCLE_V460_END */

/* NURSELINK_CREDENTIAL_RENEWAL_V461_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/credential-renewal', [\App\Http\Controllers\Api\CredentialRenewalController::class, 'member'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/credential-renewal/{credentialId}', [\App\Http\Controllers\Api\CredentialRenewalController::class, 'start'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::patch('/credential-renewal/{credentialId}/{renewalId}', [\App\Http\Controllers\Api\CredentialRenewalController::class, 'update'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::get('/nurselink/admin/credential-renewal/summary', [\App\Http\Controllers\Api\CredentialRenewalController::class, 'adminSummary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/credential-renewal', [\App\Http\Controllers\Api\CredentialRenewalController::class, 'adminIndex'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/credential-renewal/{renewalId}', [\App\Http\Controllers\Api\CredentialRenewalController::class, 'adminUpdate'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_CREDENTIAL_RENEWAL_V461_END */

/* NURSELINK_EVENTS_PROGRAMS_V471_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/events', [\App\Http\Controllers\Api\EventsController::class, 'memberIndex'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/events/{eventId}/register', [\App\Http\Controllers\Api\EventsController::class, 'register'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::delete('/events/{eventId}/registration', [\App\Http\Controllers\Api\EventsController::class, 'cancel'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/nurselink/admin/events', [\App\Http\Controllers\Api\EventsController::class, 'adminIndex'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/events', [\App\Http\Controllers\Api\EventsController::class, 'adminStore'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/events/{eventId}', [\App\Http\Controllers\Api\EventsController::class, 'adminUpdate'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/events/{eventId}/registrations', [\App\Http\Controllers\Api\EventsController::class, 'adminRegistrations'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/events/{eventId}/registrations/{registrationId}', [\App\Http\Controllers\Api\EventsController::class, 'adminRegistrationStatus'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_EVENTS_PROGRAMS_V471_END */

/* NURSELINK_CHAPTERS_COMMUNITIES_V472_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/chapters', [\App\Http\Controllers\Api\ChaptersController::class, 'memberIndex'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/chapters/{chapterId}/request', [\App\Http\Controllers\Api\ChaptersController::class, 'requestJoin'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::delete('/chapters/{chapterId}/membership', [\App\Http\Controllers\Api\ChaptersController::class, 'withdraw'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/nurselink/admin/chapters', [\App\Http\Controllers\Api\ChaptersController::class, 'adminIndex'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/chapters', [\App\Http\Controllers\Api\ChaptersController::class, 'adminStore'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/chapters/{chapterId}', [\App\Http\Controllers\Api\ChaptersController::class, 'adminUpdate'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/chapters/{chapterId}/members', [\App\Http\Controllers\Api\ChaptersController::class, 'adminMembers'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/chapters/{chapterId}/members/{membershipId}', [\App\Http\Controllers\Api\ChaptersController::class, 'adminMembershipStatus'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_CHAPTERS_COMMUNITIES_V472_END */

/* NURSELINK_MENTORING_V473_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/mentoring/profile', [\App\Http\Controllers\Api\MentoringController::class, 'profile'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::put('/mentoring/profile', [\App\Http\Controllers\Api\MentoringController::class, 'updateProfile'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::get('/mentoring/directory', [\App\Http\Controllers\Api\MentoringController::class, 'directory'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::get('/mentoring/requests', [\App\Http\Controllers\Api\MentoringController::class, 'requests'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/mentoring/requests', [\App\Http\Controllers\Api\MentoringController::class, 'sendRequest'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::patch('/mentoring/requests/{requestId}', [\App\Http\Controllers\Api\MentoringController::class, 'updateRequest'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::get('/nurselink/admin/mentoring/summary', [\App\Http\Controllers\Api\MentoringController::class, 'adminSummary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_MENTORING_V473_END */

/* NURSELINK_ENGAGEMENT_HUB_V480_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/engagement', [\App\Http\Controllers\Api\EngagementController::class, 'memberSummary'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::get('/nurselink/admin/engagement/summary', [\App\Http\Controllers\Api\EngagementController::class, 'adminSummary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ENGAGEMENT_HUB_V480_END */

/* NURSELINK_MEMBER_BENEFITS_V482_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/benefits', [\App\Http\Controllers\Api\MemberBenefitsController::class, 'memberIndex'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/benefits/{benefitId}/request', [\App\Http\Controllers\Api\MemberBenefitsController::class, 'requestBenefit'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::delete('/benefits/{benefitId}/request', [\App\Http\Controllers\Api\MemberBenefitsController::class, 'cancelRequest'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/nurselink/admin/benefits', [\App\Http\Controllers\Api\MemberBenefitsController::class, 'adminIndex'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/benefits', [\App\Http\Controllers\Api\MemberBenefitsController::class, 'adminStore'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/benefits/{benefitId}', [\App\Http\Controllers\Api\MemberBenefitsController::class, 'adminUpdate'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/benefits/{benefitId}/requests', [\App\Http\Controllers\Api\MemberBenefitsController::class, 'adminRequests'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/benefits/{benefitId}/requests/{requestId}', [\App\Http\Controllers\Api\MemberBenefitsController::class, 'adminRequestStatus'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_MEMBER_BENEFITS_V482_END */

/* NURSELINK_BENEFIT_INTELLIGENCE_V483_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/benefits/intelligence', [\App\Http\Controllers\Api\BenefitIntelligenceController::class, 'memberSummary'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/benefits/{benefitId}/save', [\App\Http\Controllers\Api\BenefitIntelligenceController::class, 'save'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::delete('/benefits/{benefitId}/save', [\App\Http\Controllers\Api\BenefitIntelligenceController::class, 'unsave'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::get('/nurselink/admin/benefits/summary', [\App\Http\Controllers\Api\BenefitIntelligenceController::class, 'adminSummary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_BENEFIT_INTELLIGENCE_V483_END */

/* NURSELINK_BENEFIT_REMINDERS_V484_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/benefits/reminders', [\App\Http\Controllers\Api\BenefitReminderController::class, 'member'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::get('/nurselink/admin/benefits/reminders/summary', [\App\Http\Controllers\Api\BenefitReminderController::class, 'adminSummary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/benefits/reminders/generate', [\App\Http\Controllers\Api\BenefitReminderController::class, 'generate'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_BENEFIT_REMINDERS_V484_END */

/* NURSELINK_ENGAGEMENT_TIMELINE_V490_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/engagement/timeline', [\App\Http\Controllers\Api\EngagementTimelineController::class, 'member'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::get('/nurselink/admin/engagement/activity-summary', [\App\Http\Controllers\Api\EngagementTimelineController::class, 'adminSummary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ENGAGEMENT_TIMELINE_V490_END */

/* NURSELINK_ENTERPRISE_PLATFORM_V500_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/enterprise/me', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'memberMe'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/partner/enterprise', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'partnerSummary']);

    Route::get('/nurselink/admin/enterprise/summary', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'adminSummary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/enterprise/organizations', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'adminOrganizations'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/enterprise/cohorts', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'adminCohorts'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/enterprise/cohorts', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'adminStoreCohort'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/enterprise/cohorts/{cohortId}', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'adminCohortDetail'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/enterprise/cohorts/{cohortId}', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'adminUpdateCohort'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/enterprise/cohorts/{cohortId}/members', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'adminEnrollMember'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::delete('/nurselink/admin/enterprise/cohorts/{cohortId}/members/{userId}', [\App\Http\Controllers\Api\EnterprisePlatformController::class, 'adminRemoveMember'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ENTERPRISE_PLATFORM_V500_END */

/* NURSELINK_ENTERPRISE_GOALS_V501_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/enterprise/goals', [\App\Http\Controllers\Api\EnterpriseGoalsController::class, 'memberIndex'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::put('/enterprise/goals/{goalId}/progress', [\App\Http\Controllers\Api\EnterpriseGoalsController::class, 'memberUpdate'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/partner/enterprise/goals', [\App\Http\Controllers\Api\EnterpriseGoalsController::class, 'partnerGoals']);

    Route::get('/nurselink/admin/enterprise/cohorts/{cohortId}/goals', [\App\Http\Controllers\Api\EnterpriseGoalsController::class, 'adminGoals'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/enterprise/cohorts/{cohortId}/goals', [\App\Http\Controllers\Api\EnterpriseGoalsController::class, 'adminStoreGoal'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/enterprise/goals/{goalId}', [\App\Http\Controllers\Api\EnterpriseGoalsController::class, 'adminUpdateGoal'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/enterprise/goals/{goalId}/progress', [\App\Http\Controllers\Api\EnterpriseGoalsController::class, 'adminProgress'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::put('/nurselink/admin/enterprise/goals/{goalId}/progress/{userId}', [\App\Http\Controllers\Api\EnterpriseGoalsController::class, 'adminUpdateProgress'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ENTERPRISE_GOALS_V501_END */

/* NURSELINK_ENTERPRISE_ENROLLMENT_V503_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/enterprise/invitations', [\App\Http\Controllers\Api\EnterpriseEnrollmentController::class, 'memberInvitations'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/enterprise/invitations/{invitationId}/respond', [\App\Http\Controllers\Api\EnterpriseEnrollmentController::class, 'memberRespond'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/partner/enterprise/enrollment-summary', [\App\Http\Controllers\Api\EnterpriseEnrollmentController::class, 'partnerOrganizationReport']);

    Route::get('/nurselink/admin/enterprise/cohorts/{cohortId}/invitations', [\App\Http\Controllers\Api\EnterpriseEnrollmentController::class, 'adminInvitations'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/enterprise/cohorts/{cohortId}/invitations', [\App\Http\Controllers\Api\EnterpriseEnrollmentController::class, 'adminInvite'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::delete('/nurselink/admin/enterprise/invitations/{invitationId}', [\App\Http\Controllers\Api\EnterpriseEnrollmentController::class, 'adminCancelInvitation'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/enterprise/enrollment-summary', [\App\Http\Controllers\Api\EnterpriseEnrollmentController::class, 'adminOrganizationReport'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ENTERPRISE_ENROLLMENT_V503_END */

/* NURSELINK_ENTERPRISE_OUTCOMES_V504_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/enterprise/outcomes', [\App\Http\Controllers\Api\EnterpriseOutcomesController::class, 'memberIndex'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/partner/enterprise/outcomes', [\App\Http\Controllers\Api\EnterpriseOutcomesController::class, 'partnerOutcomes']);

    Route::get('/nurselink/admin/enterprise/cohorts/{cohortId}/outcomes', [\App\Http\Controllers\Api\EnterpriseOutcomesController::class, 'adminCohortOutcomes'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::put('/nurselink/admin/enterprise/cohorts/{cohortId}/outcomes/{userId}', [\App\Http\Controllers\Api\EnterpriseOutcomesController::class, 'adminUpdateOutcome'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ENTERPRISE_OUTCOMES_V504_END */

/* NURSELINK_ENTERPRISE_SUPPORT_V505_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/enterprise/support', [\App\Http\Controllers\Api\EnterpriseSupportController::class, 'memberIndex'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/enterprise/support', [\App\Http\Controllers\Api\EnterpriseSupportController::class, 'memberStore'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/partner/enterprise/support-summary', [\App\Http\Controllers\Api\EnterpriseSupportController::class, 'partnerSummary']);

    Route::get('/nurselink/admin/enterprise/support', [\App\Http\Controllers\Api\EnterpriseSupportController::class, 'adminIndex'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::put('/nurselink/admin/enterprise/support/{checkinId}', [\App\Http\Controllers\Api\EnterpriseSupportController::class, 'adminUpdate'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ENTERPRISE_SUPPORT_V505_END */

/* NURSELINK_MEMBERSHIP_ADMINISTRATION_V510_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/membership-administration/overview', [\App\Http\Controllers\Api\MembershipAdministrationController::class, 'overview'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-administration/queue', [\App\Http\Controllers\Api\MembershipAdministrationController::class, 'queue'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-administration/staff', [\App\Http\Controllers\Api\MembershipAdministrationController::class, 'staff'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-administration/export', [\App\Http\Controllers\Api\MembershipAdministrationController::class, 'export'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::put('/nurselink/admin/membership-administration/{membershipId}/assignment', [\App\Http\Controllers\Api\MembershipAdministrationController::class, 'assignReview'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-administration/activity', [\App\Http\Controllers\Api\MembershipAdministrationController::class, 'activity'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_MEMBERSHIP_ADMINISTRATION_V510_END */

/* NURSELINK_MEMBERSHIP_ONBOARDING_V511_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/membership/onboarding', [\App\Http\Controllers\Api\MembershipOnboardingController::class, 'memberIndex'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);
    Route::post('/membership/onboarding/progress', [\App\Http\Controllers\Api\MembershipOnboardingController::class, 'memberMark'])
        ->middleware(\App\Http\Middleware\EnsureApprovedNurseLinkMember::class);

    Route::get('/nurselink/admin/membership-onboarding/summary', [\App\Http\Controllers\Api\MembershipOnboardingController::class, 'adminSummary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/membership-onboarding', [\App\Http\Controllers\Api\MembershipOnboardingController::class, 'adminQueue'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::put('/nurselink/admin/membership-onboarding/{membershipId}', [\App\Http\Controllers\Api\MembershipOnboardingController::class, 'adminUpdate'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/membership-onboarding/{membershipId}/welcome', [\App\Http\Controllers\Api\MembershipOnboardingController::class, 'adminSendWelcome'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_MEMBERSHIP_ONBOARDING_V511_END */

/* NURSELINK_ADMIN_OPERATIONS_CENTER_V530_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/operations-center/summary', [\App\Http\Controllers\Api\AdministrationOperationsCenterController::class, 'summary'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/operations-center/support-cases', [\App\Http\Controllers\Api\AdministrationOperationsCenterController::class, 'supportCases'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/operations-center/support-cases', [\App\Http\Controllers\Api\AdministrationOperationsCenterController::class, 'storeSupportCase'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::put('/nurselink/admin/operations-center/support-cases/{caseId}', [\App\Http\Controllers\Api\AdministrationOperationsCenterController::class, 'updateSupportCase'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/operations-center/communications', [\App\Http\Controllers\Api\AdministrationOperationsCenterController::class, 'sendCommunication'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/operations-center/email', [\App\Http\Controllers\Api\AdminEmailManagementController::class, 'index'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/operations-center/email/{threadId}', [\App\Http\Controllers\Api\AdminEmailManagementController::class, 'show'])->whereNumber('threadId')->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/operations-center/email', [\App\Http\Controllers\Api\AdminEmailManagementController::class, 'send'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/operations-center/email/{threadId}', [\App\Http\Controllers\Api\AdminEmailManagementController::class, 'update'])->whereNumber('threadId')->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/operations-center/audit-log', [\App\Http\Controllers\Api\AdministrationOperationsCenterController::class, 'auditLog'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/operations-center/system-health', [\App\Http\Controllers\Api\AdministrationOperationsCenterController::class, 'systemHealth'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/operations-center/settings', [\App\Http\Controllers\Api\AdministrationOperationsCenterController::class, 'settings'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ADMIN_OPERATIONS_CENTER_V530_END */

/* NURSELINK_ADMIN_APPEARANCE_SETTINGS_V6331_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/operations-center/appearance-settings', [\App\Http\Controllers\Api\AdminAppearanceSettingsController::class, 'current'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/operations-center/appearance-settings/draft', [\App\Http\Controllers\Api\AdminAppearanceSettingsController::class, 'saveDraft'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/operations-center/appearance-settings/{version}/publish', [\App\Http\Controllers\Api\AdminAppearanceSettingsController::class, 'publish'])->whereNumber('version')->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/operations-center/appearance-settings/history', [\App\Http\Controllers\Api\AdminAppearanceSettingsController::class, 'history'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/operations-center/appearance-settings/{version}/restore', [\App\Http\Controllers\Api\AdminAppearanceSettingsController::class, 'restore'])->whereNumber('version')->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ADMIN_APPEARANCE_SETTINGS_V6331_END */

/* NURSELINK_ADMIN_MANAGEMENT_V553_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::post('/nurselink/admin/management/invitations/accept', [\App\Http\Controllers\Api\AdminManagementController::class, 'accept']);
    Route::get('/nurselink/admin/management/me', [\App\Http\Controllers\Api\AdminManagementController::class, 'myPermissions'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::get('/nurselink/admin/management', [\App\Http\Controllers\Api\AdminManagementController::class, 'index'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/management/invitations', [\App\Http\Controllers\Api\AdminManagementController::class, 'invite'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::post('/nurselink/admin/management/invitations/{invitationId}/resend', [\App\Http\Controllers\Api\AdminManagementController::class, 'resend'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::delete('/nurselink/admin/management/invitations/{invitationId}', [\App\Http\Controllers\Api\AdminManagementController::class, 'cancel'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::patch('/nurselink/admin/management/administrators/{userId}', [\App\Http\Controllers\Api\AdminManagementController::class, 'update'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
    Route::delete('/nurselink/admin/management/administrators/{userId}', [\App\Http\Controllers\Api\AdminManagementController::class, 'revoke'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ADMIN_MANAGEMENT_V553_END */


/* NURSELINK_BULK_ADMIN_IMPORT_QUEUE_V660_START */
Route::middleware([
    'auth:sanctum',
    'verified',
    'active.user',
    \App\Http\Middleware\EnsureNurseLinkAdminPermission::class,
])->group(function () {
    Route::get(
        '/nurselink/admin/bulk-nurse-import',
        [\App\Http\Controllers\Api\AdminBulkNurseImportController::class, 'index']
    );

    Route::get(
        '/nurselink/admin/bulk-nurse-import/{candidateId}',
        [\App\Http\Controllers\Api\AdminBulkNurseImportController::class, 'show']
    )->whereNumber('candidateId');

    Route::post(
        '/nurselink/admin/bulk-nurse-import/{candidateId}/import',
        [\App\Http\Controllers\Api\AdminBulkNurseImportController::class, 'import']
    )->whereNumber('candidateId');
});
/* NURSELINK_BULK_ADMIN_IMPORT_QUEUE_V660_END */

/* NURSELINK_ADMIN_GOVERNANCE_V554_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->group(function () {
    Route::get('/nurselink/admin/management/governance-history', [\App\Http\Controllers\Api\AdminManagementController::class, 'governanceHistory'])->middleware(\App\Http\Middleware\EnsureNurseLinkAdminPermission::class);
});
/* NURSELINK_ADMIN_GOVERNANCE_V554_END */


/* NURSELINK_TEMPORARY_ENCODER_MANAGEMENT_V590_START */
Route::middleware([
    'auth:sanctum',
    'verified',
    'active.user',
    \App\Http\Middleware\EnsureNurseLinkAdminPermission::class,
])->group(function () {
    Route::get(
        '/nurselink/admin/temporary-encoders',
        [\App\Http\Controllers\Api\TemporaryEncoderController::class, 'adminIndex']
    );

    Route::post(
        '/nurselink/admin/temporary-encoders',
        [\App\Http\Controllers\Api\TemporaryEncoderController::class, 'adminCreate']
    );

    Route::patch(
        '/nurselink/admin/temporary-encoders/{encoderId}',
        [\App\Http\Controllers\Api\TemporaryEncoderController::class, 'adminUpdate']
    )->whereNumber('encoderId');

    Route::post(
        '/nurselink/admin/temporary-encoders/{encoderId}/reset-password',
        [\App\Http\Controllers\Api\TemporaryEncoderController::class, 'adminResetPassword']
    )->whereNumber('encoderId');

    Route::get(
        '/nurselink/admin/temporary-encoders/{encoderId}/assignments',
        [\App\Http\Controllers\Api\TemporaryEncoderController::class, 'adminAssignments']
    )->whereNumber('encoderId');

    Route::post(
        '/nurselink/admin/temporary-encoders/{encoderId}/assignments',
        [\App\Http\Controllers\Api\TemporaryEncoderController::class, 'adminAssignNurse']
    )->whereNumber('encoderId');

    Route::delete(
        '/nurselink/admin/temporary-encoders/{encoderId}/assignments/{assignmentId}',
        [\App\Http\Controllers\Api\TemporaryEncoderController::class, 'adminRevokeAssignment']
    )
        ->whereNumber('encoderId')
        ->whereNumber('assignmentId');
});
/* NURSELINK_TEMPORARY_ENCODER_MANAGEMENT_V590_END */

/* NURSELINK_SMART_REGISTRATION_V557_START */
Route::middleware(['auth:sanctum', 'verified', 'active.user'])->prefix('smart-registration')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\SmartRegistrationController::class, 'show']);
    Route::post('/documents', [\App\Http\Controllers\Api\SmartRegistrationController::class, 'upload']);
    Route::delete('/documents/{id}', [\App\Http\Controllers\Api\SmartRegistrationController::class, 'destroyDocument'])->whereNumber('id');
    Route::patch('/personal', [\App\Http\Controllers\Api\SmartRegistrationController::class, 'savePersonal']);
    Route::patch('/professional', [\App\Http\Controllers\Api\SmartRegistrationController::class, 'saveProfessional']);
    Route::post('/submit', [\App\Http\Controllers\Api\SmartRegistrationController::class, 'submit']);
});
/* NURSELINK_SMART_REGISTRATION_V557_END */
