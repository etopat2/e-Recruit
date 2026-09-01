<?php

namespace App\Policies;

use App\Models\SelectionRun;
use App\Models\User;

class SelectionRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('hq_recruitment_administrator', 'prisons_council_secretariat', 'executive_viewer', 'auditor');
    }

    public function view(User $user, SelectionRun $selectionRun): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole('hq_recruitment_administrator', 'prisons_council_secretariat');
    }

    public function certify(User $user, SelectionRun $selectionRun): bool
    {
        return $user->hasRole('prisons_council_secretariat') && $selectionRun->status === 'draft';
    }

    public function update(User $user, SelectionRun $selectionRun): bool
    {
        return false;
    }

    public function delete(User $user, SelectionRun $selectionRun): bool
    {
        return false;
    }
}
