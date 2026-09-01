<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class DeliverNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 300, 1800];

    public function __construct(public string $notificationId) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $notification = DB::table('notifications')->where('id', $this->notificationId)->first();
        if ($notification === null || $notification->status === 'delivered') {
            return;
        }

        DB::table('notifications')->where('id', $this->notificationId)->update([
            'attempt_count' => DB::raw('attempt_count + 1'),
            'updated_at' => now(),
        ]);
        if ($notification->channel === 'in_portal') {
            DB::table('notifications')->where('id', $this->notificationId)->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }
        if ($notification->channel === 'email') {
            Mail::raw('A new UPS e-Recruit update is available in your secure applicant portal.', function ($message) use ($notification): void {
                $message->to($notification->recipient)->subject('UPS e-Recruit update');
            });
            DB::table('notifications')->where('id', $this->notificationId)->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        throw new RuntimeException("Notification channel [{$notification->channel}] is not configured.");
    }

    public function failed(?Throwable $exception): void
    {
        DB::table('notifications')->where('id', $this->notificationId)->update([
            'status' => 'failed',
            'last_error' => $exception?->getMessage(),
            'next_attempt_at' => null,
            'updated_at' => now(),
        ]);
    }
}
