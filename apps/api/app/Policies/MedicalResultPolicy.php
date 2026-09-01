<?php

namespace App\Policies;

use App\Models\MedicalResult;
use App\Models\User;
use App\Services\ScopeAuthorizer;

class MedicalResultPolicy
{
    public function __construct(private ScopeAuthorizer $scopeAuthorizer) {}

    public function viewAny(User $user): bool
    {
        return $user->hasRole('medical_officer', 'hq_recruitment_administrator', 'auditor');
    }

    public function view(User $user, MedicalResult $medicalResult): bool
    {
        return $this->scopeAuthorizer->canViewApplication($user, $medicalResult->application);
    }

    public function viewRestricted(User $user, MedicalResult $medicalResult): bool
    {
        return $this->scopeAuthorizer->canViewRestrictedMedical($user, $medicalResult->application);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('medical_officer');
    }

    public function update(User $user, MedicalResult $medicalResult): bool
    {
        return $user->hasRole('medical_officer')
            && $this->scopeAuthorizer->canViewApplication($user, $medicalResult->application);
    }

    public function delete(User $user, MedicalResult $medicalResult): bool
    {
        return false;
    }
}
