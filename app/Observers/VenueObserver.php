<?php

namespace App\Observers;

use App\Models\Venue;

class VenueObserver
{
    /**
     * Handle the Venue "updated" event.
     */
    public function updated(Venue $venue): void
    {
        // Touch all related events to invalidate their cache
        $venue->events()->touch();
    }

    /**
     * Handle the Venue "deleted" event.
     */
    public function deleted(Venue $venue): void
    {
        // Touch all related events to invalidate their cache
        $venue->events()->touch();
    }

    /**
     * Handle the Venue "restored" event.
     */
    public function restored(Venue $venue): void
    {
        // Touch all related events to invalidate their cache
        $venue->events()->touch();
    }
}
