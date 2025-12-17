<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $authUser): bool
    {
        return $authUser->hasRole(['Admin', 'Organizer']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $authUser, User $targetUser): bool
    {
        // User can update their own profile
        if ($authUser->id === $targetUser->id) {
            return true;
        }

        // Admin can update anyone
        if ($authUser->hasRole('Admin')) {
            return true;
        }

        // Organizer can update users reporting to them
        if ($authUser->hasRole('Organizer') && $targetUser->reporting_user === $authUser->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function view(User $authUser, User $targetUser): bool
    {
        return $this->checkAccess($authUser, $targetUser);
    }

    /**
     * Determine if the user can update the target user.
     */
    public function update(User $authUser, User $targetUser): bool
    {
        return $this->checkAccess($authUser, $targetUser);
    }


    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $authUser, User $targetUser): bool
    {
        // Users cannot delete themselves
        if ($authUser->id === $targetUser->id) {
            return false;
        }

        // Only Admin can delete
        if ($authUser->hasRole('Admin')) {
            return true;
        }

        // Organizer can delete their reporting users
        if ($authUser->hasRole('Organizer')) {
            return $this->isInReportingChain($authUser, $targetUser);
        }

        return false;
    }

    private function checkAccess(User $authUser, User $targetUser): bool
    {
        // Self access
        if ($authUser->id === $targetUser->id) {
            return true;
        }

        // Admin
        if ($authUser->hasRole('Admin')) {
            return true;
        }

        // Organizer - check reporting chain
        if ($authUser->hasRole('Organizer')) {
            return $this->isInReportingChain($authUser, $targetUser);
        }

        return false;
    }
    private function isInReportingChain(User $authUser, User $targetUser, int $maxDepth = 5): bool
    {
        $currentUser = $targetUser;
        $depth = 0;

        while ($currentUser && $depth < $maxDepth) {
            if ($currentUser->reporting_user === $authUser->id) {
                return true;
            }
            $currentUser = $currentUser->reportingUser;
            $depth++;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model)
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model)
    {
        //
    }
}
