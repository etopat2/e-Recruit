<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;
use App\Services\ScopeAuthorizer;

class DocumentPolicy
{
    public function __construct(private ScopeAuthorizer $scopeAuthorizer) {}

    public function view(User $user, Document $document): bool
    {
        return $this->scopeAuthorizer->canViewApplication($user, $document->application);
    }

    public function create(User $user): bool
    {
        return $user->status === 'active';
    }

    public function update(User $user, Document $document): bool
    {
        return $document->application->status === 'draft'
            && $this->scopeAuthorizer->canViewApplication($user, $document->application);
    }

    public function delete(User $user, Document $document): bool
    {
        return false;
    }
}
