<?php

namespace App\Jobs;

use App\Services\BookingAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendBookingAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public $maxExceptions = 1;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [60, 120, 300]; // 1min, 2min, 5min

    /**
     * Delete the job if its models no longer exist.
     */
    public $deleteWhenMissing = true;

    /**
     * Booking IDs to send alerts for
     */
    protected $bookingIds;

    /**
     * Type of booking (agent, pos, sponsor, etc.)
     */
    protected $bookingType;

    /**
     * Create a new job instance.
     *
     * @param array $bookingIds - IDs of bookings to send alerts for
     * @param string $bookingType - Type of booking
     */
    public function __construct(array $bookingIds, string $bookingType = 'agent')
    {
        $this->bookingIds = $bookingIds;
        $this->bookingType = $bookingType;
    }

    /**
     * Execute the job.
     * 
     * This job is executed asynchronously from the queue.
     * Sending SMS/WhatsApp alerts will not block the main booking process.
     */
    public function handle(BookingAlertService $alertService): void
    {
        Log::info('SendBookingAlertJob started', [
            'booking_ids' => $this->bookingIds,
            'booking_type' => $this->bookingType
        ]);

        $success = $alertService->sendBookingAlerts($this->bookingIds, $this->bookingType);

        if ($success) {
            Log::info('SendBookingAlertJob completed successfully', [
                'booking_ids' => $this->bookingIds
            ]);
        } else {
            Log::warning('SendBookingAlertJob failed to send alerts', [
                'booking_ids' => $this->bookingIds
            ]);
            // Throw exception to allow retry
            throw new \Exception('Failed to send booking alerts');
        }
    }

    /**
     * Handle a job failure.
     * 
     * This is called when the job fails after all retry attempts.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendBookingAlertJob failed permanently', [
            'booking_ids' => $this->bookingIds,
            'booking_type' => $this->bookingType,
            'error' => $exception->getMessage()
        ]);

        // You could send a notification to admins here
        // Notification::route('mail', config('app.admin_email'))
        //     ->notify(new BookingAlertFailedNotification($this->bookingIds));
    }
}
