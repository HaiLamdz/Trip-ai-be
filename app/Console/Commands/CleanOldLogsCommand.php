<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CleanOldLogsCommand extends Command
{
    protected $signature   = 'tripai:clean-old-logs';
    protected $description = 'Xóa activity_logs cũ hơn 90 ngày';

    public function handle(): int
    {
        try {
            $deleted = ActivityLog::where('created_at', '<', now()->subDays(90))->delete();
            $this->info("Đã xóa {$deleted} activity logs cũ hơn 90 ngày.");
            Log::info("CleanOldLogsCommand: deleted {$deleted} records.");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            Log::error('CleanOldLogsCommand failed: ' . $e->getMessage());
            $this->notifyAdmin('CleanOldLogsCommand thất bại: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function notifyAdmin(string $message): void
    {
        $adminEmail = config('mail.admin_email', env('ADMIN_EMAIL'));
        if ($adminEmail) {
            try {
                Mail::raw($message, fn ($m) => $m->to($adminEmail)->subject('[TripAI] Scheduled Job Failed'));
            } catch (\Throwable) {
                // Silently fail if mail not configured
            }
        }
    }
}
