<?php

namespace App\Jobs;

use App\Models\LoginHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class StoreLoginHistoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 5;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $userId,
        protected string $ipAddress,
        protected string $loginTime
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $locationData = [];

        try {
            $locationData = Http::timeout(5)->get("http://ip-api.com/json/{$this->ipAddress}")->json();
        } catch (\Exception $e) {
            $locationData = [];
        }

        LoginHistory::create([
            'user_id'    => $this->userId,
            'ip_address' => $this->ipAddress,
            'country'    => $locationData['country']     ?? 'Unknown',
            'state'      => $locationData['regionName']  ?? 'Unknown',
            'city'       => $locationData['city']        ?? 'Unknown',
            'location'   => isset($locationData['lat'], $locationData['lon'])
                ? $locationData['lat'] . ',' . $locationData['lon']
                : null,
            'login_time' => $this->loginTime,
        ]);
    }
}
