<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Venue;

class VenuePolicy
{
    /**
     * Perform pre-authorization checks.
     * Admin has full access to all venues.
     */
    public function before(User $user, string $ability): bool|null
    {
        if ($user->hasRole('Admin')) {
            return true;
        }

        return null;
    }

    /**
     * Determine whether the user can view any venues.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the venue.
     */
    public function view(User $user, Venue $venue): bool
    {
        return (int) $user->id === (int) $venue->org_id;
    }

    /**
     * Determine whether the user can create venues.
     */
    public function create(User $user, ?int $orgId = null): bool
    {
        if (!$user->hasPermissionTo('Create Venue')) {
            return false;
        }

        if ($orgId !== null) {
            return (int) $user->id === (int) $orgId;
        }

        return true;
    }

    /**
     * Determine whether the user can update the venue.
     */
    public function update(User $user, Venue $venue): bool
    {
        return $user->hasPermissionTo('Update Venue')
            && (int) $user->id === (int) $venue->org_id;
    }

    /**
     * Determine whether the user can delete the venue.
     */
    public function delete(User $user, Venue $venue): bool
    {
        return $user->hasPermissionTo('Delete Venue')
            && (int) $user->id === (int) $venue->org_id;
    }

    /**
     * Determine whether the user can restore the venue.
     */
    public function restore(User $user, Venue $venue): bool
    {
        return $user->hasPermissionTo('Restore Venue')
            && (int) $user->id === (int) $venue->org_id;
    }

    /**
     * Determine whether the user can permanently delete the venue.
     */
    public function forceDelete(User $user, Venue $venue): bool
    {
        return false;
    }

    /**
     * Determine whether the user can export venues.
     */
    public function export(User $user): bool
    {
        return $user->hasPermissionTo('Export Venues');
    }

    /**
     * Determine whether the user can view the venue location/map.
     */
    public function viewLocation(User $user, Venue $venue): bool
    {
        return $user->hasPermissionTo('View Location');
    }
}