<?php

namespace App\Observers;

use App\Models\Category;

class CategoryObserver
{
    /**
     * Handle the Category "updated" event.
     */
    public function updated(Category $category): void
    {
        // Touch all related events to invalidate their cache
        $category->EventData()->touch();
    }

    /**
     * Handle the Category "deleted" event.
     */
    public function deleted(Category $category): void
    {
        // Touch all related events to invalidate their cache
        $category->EventData()->touch();
    }

    /**
     * Handle the Category "restored" event.
     */
    public function restored(Category $category): void
    {
        // Touch all related events to invalidate their cache
        $category->EventData()->touch();
    }
}
