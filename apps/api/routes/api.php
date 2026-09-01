<?php

use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\AssessmentController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CampaignController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\EligibilityController;
use App\Http\Controllers\Api\V1\HardCopyController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\HelpdeskController;
use App\Http\Controllers\Api\V1\InterviewController;
use App\Http\Controllers\Api\V1\MedicalController;
use App\Http\Controllers\Api\V1\OfficialArtifactController;
use App\Http\Controllers\Api\V1\OfflineSyncController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SelectionController;
use App\Http\Controllers\Api\V1\TrainingController;
use App\Http\Controllers\Api\V1\VerificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.')->group(function (): void {
    Route::get('health/live', [HealthController::class, 'live'])->name('health.live');
    Route::get('health/ready', [HealthController::class, 'ready'])->name('health.ready');
    Route::get('campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
    Route::get('official-artifacts/verify', [OfficialArtifactController::class, 'verify'])
        ->middleware('throttle:status-lookup')->name('artifacts.verify');
    Route::post('auth/register', [AuthController::class, 'register'])->middleware('throttle:registration')->name('auth.register');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('auth.login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('auth/mfa/enrol', [AuthController::class, 'enrolMfa'])->name('auth.mfa.enrol');
        Route::post('auth/mfa/confirm', [AuthController::class, 'confirmMfa'])->name('auth.mfa.confirm');

        Route::middleware('mfa')->group(function (): void {

            Route::get('applications', [ApplicationController::class, 'index'])->name('applications.index');
            Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
            Route::get('applications/{application}', [ApplicationController::class, 'show'])->name('applications.show');
            Route::put('applications/{application}', [ApplicationController::class, 'update'])->name('applications.update');
            Route::post('applications/{application}/submit', [ApplicationController::class, 'submit'])->middleware('throttle:status-lookup')->name('applications.submit');
            Route::get('applications/{application}/acknowledgement', [ApplicationController::class, 'acknowledgement'])->name('applications.acknowledgement');
            Route::post('applications/{application}/documents', [DocumentController::class, 'store'])->middleware('throttle:uploads')->name('documents.store');
            Route::get('documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
            Route::get('documents/{document}/download', [DocumentController::class, 'download'])->name('documents.download');

            Route::get('helpdesk/tickets', [HelpdeskController::class, 'index'])->name('helpdesk.index');
            Route::post('helpdesk/tickets', [HelpdeskController::class, 'store'])->name('helpdesk.store');
            Route::get('helpdesk/tickets/{ticket}', [HelpdeskController::class, 'show'])->name('helpdesk.show');
            Route::post('helpdesk/tickets/{ticket}/messages', [HelpdeskController::class, 'message'])->name('helpdesk.messages.store');
            Route::post('applications/{application}/appeals', [HelpdeskController::class, 'appeal'])->name('appeals.store');

            Route::middleware('role:hq_recruitment_administrator,system_administrator')->group(function (): void {
                Route::post('campaigns', [CampaignController::class, 'store'])->name('campaigns.store');
                Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->name('campaigns.update');
                Route::post('campaigns/{campaign}/publish', [CampaignController::class, 'publish'])->name('campaigns.publish');
            });

            Route::get('applications/{application}/verification-workbench', [VerificationController::class, 'show'])->name('verification.show');
            Route::post('applications/{application}/compare-evidence', [VerificationController::class, 'compare'])->name('verification.compare');
            Route::post('documents/{document}/verification', [VerificationController::class, 'store'])->name('verification.store');
            Route::post('applications/{application}/hard-copy-receipts', [HardCopyController::class, 'store'])->name('hard-copy.store');
            Route::post('applications/{application}/eligibility-runs', [EligibilityController::class, 'store'])->name('eligibility.store');

            Route::post('posts/{post}/interview-assignments', [InterviewController::class, 'assign'])->name('interviews.assign');
            Route::put('interview-assignments/{assignment}', [InterviewController::class, 'adjust'])->name('interviews.adjust');
            Route::put('interview-assignments/{assignment}/attendance', [InterviewController::class, 'attendance'])->name('attendance.store');
            Route::post('assessment-scores', [AssessmentController::class, 'store'])->name('assessments.store');
            Route::get('interview-assignments/{assignment}/aggregate', [AssessmentController::class, 'aggregate'])->name('assessments.aggregate');
            Route::post('assessment-scores/{score}/adjustments', [AssessmentController::class, 'adjust'])->name('assessments.adjust');
            Route::post('panels/{panel}/close', [AssessmentController::class, 'closePanel'])->name('panels.close');

            Route::post('offline/devices', [OfflineSyncController::class, 'registerDevice'])->name('offline.devices.store');
            Route::post('offline/packages', [OfflineSyncController::class, 'issue'])->name('offline.packages.store');
            Route::get('offline/packages/{offlinePackage}', [OfflineSyncController::class, 'show'])->name('offline.packages.show');
            Route::post('offline/packages/{offlinePackage}/sync', [OfflineSyncController::class, 'sync'])->name('offline.packages.sync');
            Route::post('offline/conflicts/{conflict}/resolve', [OfflineSyncController::class, 'resolveConflict'])->name('offline.conflicts.resolve');

            Route::post('rankings', [SelectionController::class, 'rank'])->name('rankings.store');
            Route::get('selection-runs', [SelectionController::class, 'index'])->name('selection.index');
            Route::post('selection-runs', [SelectionController::class, 'store'])->name('selection.store');
            Route::get('selection-runs/{selectionRun}', [SelectionController::class, 'show'])->name('selection.show');
            Route::post('selection-runs/{selectionRun}/certify', [SelectionController::class, 'certify'])->name('selection.certify');
            Route::post('selection-runs/{selectionRun}/overrides', [SelectionController::class, 'override'])->name('selection.overrides.store');

            Route::post('medical/schedules', [MedicalController::class, 'schedule'])->name('medical.schedules.store');
            Route::post('medical/results', [MedicalController::class, 'store'])->name('medical.results.store');
            Route::get('medical/results/{medicalResult}', [MedicalController::class, 'show'])->name('medical.results.show');
            Route::post('final-selections', [MedicalController::class, 'approveFinalSelection'])->name('final-selections.store');
            Route::post('training/invitations', [TrainingController::class, 'invite'])->name('training.invites.store');
            Route::post('training/reporting', [TrainingController::class, 'report'])->name('training.reporting.store');
            Route::post('training/replacements', [TrainingController::class, 'replace'])->name('training.replacements.store');

            Route::get('reports/dashboard', [ReportController::class, 'dashboard'])->name('reports.dashboard');
            Route::get('operations/metrics', [HealthController::class, 'metrics'])
                ->middleware('role:system_administrator,hq_recruitment_administrator,auditor')->name('operations.metrics');
            Route::post('exports', [ReportController::class, 'export'])->name('exports.store');
            Route::get('exports/{export}/download', [ReportController::class, 'download'])->name('exports.download');
            Route::get('audit-logs', [AuditController::class, 'index'])->name('audit.index');
            Route::get('audit-logs/verify-chain', [AuditController::class, 'verify'])->name('audit.verify');
            Route::post('integrity-flags/{flag}/review', [AuditController::class, 'reviewFlag'])->name('integrity-flags.review');
        });
    });
});
