<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    // GET /api/notifications
    public function index(): JsonResponse
    {
        $user          = Auth::guard('api')->user();
        $notifications = Notification::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($notifications);
    }

    // GET /api/notifications/unread-count
    public function unreadCount(): JsonResponse
    {
        $user  = Auth::guard('api')->user();
        $count = Notification::where('user_id', $user->id)->whereNull('read_at')->count();

        return response()->json(['unread_count' => $count]);
    }

    // PUT /api/notifications/{id}/read
    public function markRead(int $id): JsonResponse
    {
        $user         = Auth::guard('api')->user();
        $notification = Notification::find($id);

        if (! $notification) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($notification->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'Đã đánh dấu đã đọc.']);
    }

    // PUT /api/notifications/read-all
    public function markAllRead(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        Notification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'Đã đánh dấu tất cả đã đọc.']);
    }
}
