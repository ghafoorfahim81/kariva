<?php

namespace App\Policies;

use App\Models\Actor;
use App\Models\User;

/** The access matrix of spec §11. Decisions come only from the actor_members row of the user. */
class ActorPolicy
{
    private const ALL = ['owner', 'admin', 'editor', 'viewer'];

    private const EDITORS = ['owner', 'admin', 'editor'];

    private const MANAGERS = ['owner', 'admin'];

    /** See orders, messages and the dashboard of the actor. */
    public function view(User $user, Actor $actor): bool
    {
        return $this->roleIn($user, $actor, self::ALL);
    }

    /** Edit profile and services, answer orders (price, mark done). */
    public function update(User $user, Actor $actor): bool
    {
        return $this->roleIn($user, $actor, self::EDITORS);
    }

    /** Invite and manage members. Admins never manage owners; that is enforced where the member changes. */
    public function manageMembers(User $user, Actor $actor): bool
    {
        return $this->roleIn($user, $actor, self::MANAGERS);
    }

    /** Ask for the page to be deleted: any owner. */
    public function requestDeletion(User $user, Actor $actor): bool
    {
        return $actor->type === 'business' && $this->roleIn($user, $actor, ['owner']);
    }

    /** Final delete and primary-ownership transfer: only the primary owner. */
    public function confirmDeletion(User $user, Actor $actor): bool
    {
        return $actor->type === 'business' && $this->isPrimary($user, $actor);
    }

    public function transferPrimary(User $user, Actor $actor): bool
    {
        return $actor->type === 'business' && $this->isPrimary($user, $actor);
    }

    private function roleIn(User $user, Actor $actor, array $roles): bool
    {
        $member = $actor->membershipOf($user);

        return $member !== null && in_array($member->role, $roles, true);
    }

    private function isPrimary(User $user, Actor $actor): bool
    {
        return (bool) $actor->membershipOf($user)?->is_primary;
    }
}
