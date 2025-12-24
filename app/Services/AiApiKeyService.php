<?php

namespace App\Services;

use App\Models\AiApiKey;
use Illuminate\Support\Facades\Cache;

class AiApiKeyService
{
    private const CACHE_KEY = 'ai_api_key_round_robin_index';
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Get the next API key using round-robin strategy
     * Only returns keys with status = true
     */
    public function getNextApiKey(): ?AiApiKey
    {
        $activeKeys = AiApiKey::where('status', true)->get();

        if ($activeKeys->isEmpty()) {
            return null;
        }

        // Get current index from cache
        $currentIndex = Cache::get(self::CACHE_KEY, 0);

        // Get the key at current index
        $key = $activeKeys->get($currentIndex % $activeKeys->count());

        // Increment index for next call
        $nextIndex = ($currentIndex + 1) % $activeKeys->count();
        Cache::put(self::CACHE_KEY, $nextIndex, self::CACHE_TTL);

        return $key;
    }

    /**
     * Get API key by model name
     */
    public function getKeyByModel(string $model): ?AiApiKey
    {
        return AiApiKey::where('model', $model)
            ->where('status', true)
            ->first();
    }

    /**
     * Reset round-robin counter
     */
    public function resetRoundRobin(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
