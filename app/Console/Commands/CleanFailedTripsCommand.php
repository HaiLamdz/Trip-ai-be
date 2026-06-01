<?php

namespace App\Console\Commands;

use App\Models\Trip;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CleanFailedTripsCommand extends Command
{
    protected $signature   = 'tripai:clean-failed-trips';
    protected $description = 'Xóa trips status=failed cũ hơn 30 ngày';

    public function handle(): int
    {
        try {
            $deleted = Trip::where('status', 'failed')
                ->where('created_at', '<', now()->subDays(30))
                ->delete();

            $this->info("Đã xóa {$deleted} failed trips cũ hơn 30 ngày.");
            Log::info("CleanFailedTripsCommand: deleted {$deleted} records.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('CleanFailedTripsCommand failed: ' . $e->getMessage());
            $this->notifyAdmin('CleanFailedTripsCommand thất bại: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function notifyAdmin(string $message): void
    {
        $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
        if ($adminEmail) {
            try {
                Mail::raw($message, fn ($m) => $m->to($adminEmail)->subject('[TripAI] Scheduled Job Failed'));
            } catch (\Throwable) {}
        }
    }
}
