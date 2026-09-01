<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;
use App\Services\ScopeAuthorizer;

class ApplicationPolicy
{
    public function __construct(private ScopeAuthorizer $scopeAuthorizer) {}

    public function viewAny(User $user): bool
    {
        return $user->status === 'active';
    }

    public function view(User $user, Application $application): bool
    {
        return $this->scopeAuthorizer->canViewApplication($user, $application);
    }

    public function create(User $user): bool
    {
        return $user->status === 'active' && $user->hasRole('applicant', 'assisted_application_officer');
    }

    public function update(User $user, Application $application): bool
    {
        if ($application->status !== Application::StatusDraft) {
            return false;
        }

        return $this->scopeAuthorizer->canPerform($user, 'application:update', $application)
            || ($user->user_type === 'applicant' && $this->scopeAuthorizer->canViewApplication($user, $application));
    }

    public function submit(User $user, Application $application): bool
    {
        return $this->update($user, $application)
            || ($application->submitted_at !== null
                && $this->scopeAuthorizer->canViewApplication($user, $application));
    }

    public function delete(User $user, Application $application): bool
    {
        return false;
    }

    public function restore(User $user, Application $application): bool
    {
        return false;
    }

    public function forceDelete(User $user, Application $application): bool
    {
        return false;
    }
}
