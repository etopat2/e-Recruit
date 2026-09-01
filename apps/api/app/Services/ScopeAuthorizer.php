<?php

namespace App\Services;

use App\Models\Application;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ScopeAuthorizer
{
    public function canViewApplication(User $user, Application $application): bool
    {
        if ($user->status !== 'active') {
            return false;
        }

        if ($user->user_type === 'applicant') {
            return $user->applicant()->whereKey($application->applicant_id)->exists();
        }

        if ($user->hasRole(...config('erecruit.security.national_roles'))) {
            return true;
        }

        $scopes = $user->scopes()->where(function ($query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->get();

        foreach ($scopes as $scope) {
            if ($scope->scope_type === 'campaign' && $scope->scope_id === $application->recruitment_campaign_id) {
                return true;
            }
            if ($scope->scope_type === 'post' && $scope->scope_id === $application->recruitment_post_id) {
                return true;
            }
            if ($scope->scope_type === 'panel' && $this->applicationBelongsToPanel($application, $scope->scope_id)) {
                return true;
            }
            if ($scope->scope_type === 'centre' && $this->applicationBelongsToCentre($application, $scope->scope_id)) {
                return true;
            }
            if ($scope->scope_type === 'region' && $this->applicationBelongsToRegion($application, $scope->scope_id)) {
                return true;
            }
        }

        return false;
    }

    public function canPerform(User $user, string $task, ?Application $application = null): bool
    {
        if (in_array($user->user_type, ['executive_viewer', 'auditor'], true)) {
            return str_starts_with($task, 'view:');
        }
        if ($user->user_type === 'system_administrator' && str_starts_with($task, 'decision:')) {
            return false;
        }
        if ($application !== null && ! $this->canViewApplication($user, $application)) {
            return false;
        }

        return $user->scopes()->where(function ($query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        })->get()->contains(function ($scope) use ($task): bool {
            $tasks = $scope->allowed_tasks ?? [];

            return in_array('*', $tasks, true) || in_array($task, $tasks, true);
        }) || $user->hasRole(...config('erecruit.security.national_roles'));
    }

    public function canViewRestrictedMedical(User $user, Application $application): bool
    {
        return $user->hasRole(...config('erecruit.security.medical_roles'))
            && $this->canViewApplication($user, $application);
    }

    private function applicationBelongsToPanel(Application $application, ?string $panelId): bool
    {
        return DB::table('interview_assignments')
            ->where('application_id', $application->id)
            ->where('panel_id', $panelId)
            ->exists();
    }

    private function applicationBelongsToCentre(Application $application, ?string $centreId): bool
    {
        return DB::table('interview_assignments')
            ->join('centre_sessions', 'centre_sessions.id', '=', 'interview_assignments.centre_session_id')
            ->where('interview_assignments.application_id', $application->id)
            ->where('centre_sessions.recruitment_centre_id', $centreId)
            ->exists();
    }

    private function applicationBelongsToRegion(Application $application, ?string $regionId): bool
    {
        return DB::table('interview_assignments')
            ->join('centre_sessions', 'centre_sessions.id', '=', 'interview_assignments.centre_session_id')
            ->join('recruitment_centres', 'recruitment_centres.id', '=', 'centre_sessions.recruitment_centre_id')
            ->where('interview_assignments.application_id', $application->id)
            ->where('recruitment_centres.prison_region_id', $regionId)
            ->exists();
    }
}
