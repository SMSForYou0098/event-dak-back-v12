<?php

namespace App\Observers;

use App\Models\Artist;
use App\Models\Event;

class ArtistObserver
{
    /**
     * Handle the Artist "updated" event.
     */
    public function updated(Artist $artist): void
    {
        $this->touchRelatedEvents($artist);
    }

    /**
     * Handle the Artist "deleted" event.
     */
    public function deleted(Artist $artist): void
    {
        $this->touchRelatedEvents($artist);
    }

    /**
     * Handle the Artist "restored" event.
     */
    public function restored(Artist $artist): void
    {
        $this->touchRelatedEvents($artist);
    }

    /**
     * Touch events that have this artist.
     */
    protected function touchRelatedEvents(Artist $artist): void
    {
        // Find events that include this artist in their comma-separated artist_id field
        Event::whereRaw("FIND_IN_SET(?, artist_id)", [$artist->id])
            ->get()
            ->each(function ($event) {
                $event->touch();
            });
    }
}
