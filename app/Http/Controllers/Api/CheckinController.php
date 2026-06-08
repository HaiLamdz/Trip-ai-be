<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CheckinController extends Controller
{
    // ─────────────────────────────────────────────
    // POST /api/trips/{tripId}/places/{placeId}/checkin
    // ─────────────────────────────────────────────

    public function checkin(Request $request, int $tripId, int $placeId): JsonResponse
    {
        $request->validate([
            'photo'       => ['nullable', 'image', 'max:8192'],   // max 8 MB
            'note'        => ['nullable', 'string', 'max:1000'],
            'actual_time' => ['nullable', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        $user  = Auth::guard('api')->user();
        $trip  = Trip::find($tripId);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy lịch trình'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Không có quyền truy cập'], 403);
        }

        $place = TripPlace::where('trip_id', $tripId)->find($placeId);

        if (! $place) {
            return response()->json(['message' => 'Không tìm thấy địa điểm'], 404);
        }

        // Upload ảnh nếu có
        $photoPath = $place->checkin_photo; // giữ ảnh cũ nếu không upload ảnh mới
        if ($request->hasFile('photo')) {
            // Xóa ảnh cũ nếu có
            if ($place->checkin_photo) {
                Storage::disk('public')->delete($place->checkin_photo);
            }
            $photoPath = $request->file('photo')->store("checkins/{$tripId}", 'public');
        }

        $place->update([
            'checked_in_at' => now(),
            'checkin_photo' => $photoPath,
            'checkin_note'  => $request->input('note'),
            'actual_time'   => $request->input('actual_time'),
        ]);

        return response()->json([
            'message' => 'Check-in thành công!',
            'place'   => array_merge($place->fresh()->toArray(), [
                'checkin_photo_url' => $place->fresh()->checkin_photo_url,
            ]),
        ]);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/trips/{tripId}/places/{placeId}/checkin
    // Xóa check-in (undo)
    // ─────────────────────────────────────────────

    public function undo(int $tripId, int $placeId): JsonResponse
    {
        $user  = Auth::guard('api')->user();
        $trip  = Trip::find($tripId);

        if (! $trip || $trip->user_id !== $user->id) {
            return response()->json(['message' => 'Không có quyền truy cập'], 403);
        }

        $place = TripPlace::where('trip_id', $tripId)->find($placeId);

        if (! $place) {
            return response()->json(['message' => 'Không tìm thấy địa điểm'], 404);
        }

        // Xóa ảnh nếu có
        if ($place->checkin_photo) {
            Storage::disk('public')->delete($place->checkin_photo);
        }

        $place->update([
            'checked_in_at' => null,
            'checkin_photo' => null,
            'checkin_note'  => null,
            'actual_time'   => null,
        ]);

        return response()->json(['message' => 'Đã hủy check-in.']);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/{tripId}/checkins
    // Lấy tất cả địa điểm đã check-in trong trip (dùng cho map overlay)
    // ─────────────────────────────────────────────

    public function index(int $tripId): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($tripId);

        if (! $trip || $trip->user_id !== $user->id) {
            return response()->json(['message' => 'Không có quyền truy cập'], 403);
        }

        $checkins = TripPlace::where('trip_id', $tripId)
            ->whereNotNull('checked_in_at')
            ->orderBy('checked_in_at')
            ->get()
            ->map(fn ($p) => array_merge($p->toArray(), [
                'checkin_photo_url' => $p->checkin_photo_url,
            ]));

        return response()->json(['checkins' => $checkins]);
    }
}
