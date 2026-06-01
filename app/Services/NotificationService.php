<?php

namespace App\Services;

use App\Models\Notification;

class NotificationService
{
    public function tripCompleted(int $userId, int $tripId, string $destination): void
    {
        Notification::create([
            'user_id' => $userId,
            'type'    => 'trip_completed',
            'title'   => 'Lịch trình đã sẵn sàng!',
            'body'    => "Lịch trình cho chuyến đi đến {$destination} đã được tạo thành công.",
            'data'    => ['trip_id' => $tripId],
        ]);
    }

    public function tripFailed(int $userId, int $tripId, string $destination): void
    {
        Notification::create([
            'user_id' => $userId,
            'type'    => 'trip_failed',
            'title'   => 'Tạo lịch trình thất bại',
            'body'    => "Không thể tạo lịch trình cho chuyến đi đến {$destination}. Vui lòng thử lại.",
            'data'    => ['trip_id' => $tripId],
        ]);
    }

    public function budgetWarning(int $userId, int $tripId, string $destination): void
    {
        Notification::create([
            'user_id' => $userId,
            'type'    => 'budget_warning',
            'title'   => 'Cảnh báo ngân sách',
            'body'    => "Chi tiêu thực tế cho chuyến đi {$destination} đã vượt 90% ngân sách dự kiến.",
            'data'    => ['trip_id' => $tripId],
        ]);
    }

    public function weatherAlert(int $userId, int $tripId, string $destination, string $date): void
    {
        Notification::create([
            'user_id' => $userId,
            'type'    => 'weather_alert',
            'title'   => 'Cảnh báo thời tiết',
            'body'    => "Dự báo thời tiết xấu vào ngày {$date} trong chuyến đi đến {$destination}.",
            'data'    => ['trip_id' => $tripId, 'date' => $date],
        ]);
    }
}
