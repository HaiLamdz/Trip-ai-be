<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateTripRequest;
use App\Jobs\GenerateTripJob;
use App\Models\ActivityLog;
use App\Models\Trip;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class TripController extends Controller
{
    // ─────────────────────────────────────────────
    // POST /api/trips
    // ─────────────────────────────────────────────

    public function store(CreateTripRequest $request, GeocodingService $geocodingService): JsonResponse
    {
        $user = Auth::guard('api')->user();

        // Geocode destination to get lat/lng
        $coords = $geocodingService->geocode($request->destination);

        $trip = Trip::create([
            'user_id'            => $user->id,
            'destination'        => $request->destination,
            'origin'             => $request->origin,
            'destination_lat'    => $coords['lat'] ?? null,
            'destination_lng'    => $coords['lng'] ?? null,
            'start_date'         => $request->start_date,
            'duration_days'      => $request->duration_days,
            'budget'             => $request->budget,
            'num_people'         => $request->num_people,
            'travel_type'        => $request->travel_type,
            'transport_mode'     => $request->transport_mode,
            'accommodation_type' => $request->accommodation_type,
            'accommodation_area' => $request->accommodation_area,
            'arrival_time'       => $request->arrival_time,
            'preferences'        => $request->preferences ?? [],
            'notes'              => $request->notes,
            'status'             => 'processing',
        ]);

        // Dispatch async job
        GenerateTripJob::dispatch($trip->id);

        // Invalidate user trips cache (all pages)
        for ($page = 1; $page <= 20; $page++) {
            Cache::forget("trips:user:{$user->id}:page:{$page}");
        }

        // Log activity
        ActivityLog::create([
            'user_id'     => $user->id,
            'action'      => 'create_trip',
            'description' => "Tạo lịch trình đến {$trip->destination}",
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'created_at'  => now(),
        ]);

        return response()->json([
            'message' => 'Đang tạo lịch trình, vui lòng chờ.',
            'trip_id' => $trip->id,
            'status'  => $trip->status,
        ], 202);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips
    // ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user    = Auth::guard('api')->user();
        $cacheKey = "trips:user:{$user->id}:page:" . ($request->query('page', 1));

        $trips = Cache::remember($cacheKey, 300, function () use ($user) {
            return Trip::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate(10);
        });

        return response()->json($trips);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/{id}
    // ─────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $trip = Trip::with(['days.places', 'budget'])->find($id);

        if (! $trip) {
            return response()->json([
                'message' => 'Không tìm thấy tài nguyên'
            ], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json([
                'message' => 'Bạn không có quyền truy cập tài nguyên này'
            ], 403);
        }

        $tripArray = $trip->toArray();

        // Lấy giá trị cột budget gốc
        $tripArray['budget'] = (float) $trip->getRawOriginal('budget');

        // Lấy relation budget thay vì attribute budget
        $tripArray['budget_data'] = optional(
            $trip->getRelation('budget')
        )->toArray();

        return response()->json([
            'trip' => $tripArray
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/{id}/status
    // ─────────────────────────────────────────────

    public function status(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::select('id', 'user_id', 'status', 'destination')->find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $messages = [
            'processing' => 'AI đang tạo lịch trình cho bạn...',
            'completed'  => 'Lịch trình đã sẵn sàng!',
            'failed'     => 'Tạo lịch trình thất bại. Vui lòng thử lại.',
            'draft'      => 'Lịch trình đang ở trạng thái nháp.',
        ];

        return response()->json([
            'status'           => $trip->status,
            'progress_message' => $messages[$trip->status] ?? '',
        ]);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/trips/{id}
    // ─────────────────────────────────────────────

    public function destroy(int $id, Request $request): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);
        // dd($trip);
        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $destination = $trip->destination;
        $trip->delete(); // cascade via DB constraints

        // Invalidate all paginated cache pages for this user
        for ($page = 1; $page <= 20; $page++) {
            Cache::forget("trips:user:{$user->id}:page:{$page}");
        }

        // Log activity
        ActivityLog::create([
            'user_id'     => $user->id,
            'action'      => 'delete_trip',
            'description' => "Xóa lịch trình đến {$destination}",
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'created_at'  => now(),
        ]);

        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────
    // POST /api/trips/{id}/duplicate
    // ─────────────────────────────────────────────

    public function duplicate(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $copy = $trip->replicate();
        $copy->status = 'draft';
        $copy->save();

        return response()->json(['message' => 'Đã tạo bản sao lịch trình.', 'trip' => $copy], 201);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/suggestions
    // ─────────────────────────────────────────────

    public function suggestions(): JsonResponse
    {
        $user      = Auth::guard('api')->user();
        $cacheKey  = "suggestions:user:{$user->id}";

        $suggestions = Cache::remember($cacheKey, 3600, function () use ($user) {
            $prefs = $user->preferences?->preferences ?? [];

            // Return up to 5 completed trips from other users with matching preferences
            return Trip::where('user_id', '!=', $user->id)
                ->where('status', 'completed')
                ->when(! empty($prefs), function ($q) use ($prefs) {
                    // Simple overlap: trips whose preferences JSON contains any of user's prefs
                    foreach ($prefs as $pref) {
                        $q->orWhereJsonContains('preferences', $pref);
                    }
                })
                ->select('id', 'destination', 'duration_days', 'budget', 'preferences', 'start_date')
                ->latest()
                ->limit(5)
                ->get();
        });

        return response()->json(['suggestions' => $suggestions]);
    }

    // ─────────────────────────────────────────────
    // POST /api/trips/{id}/favorites
    // ─────────────────────────────────────────────

    public function toggleFavorite(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $existing = \App\Models\Favorite::where('user_id', $user->id)->where('trip_id', $id)->first();

        if ($existing) {
            $existing->delete();
            return response()->json(['message' => 'Đã bỏ yêu thích.', 'favorited' => false]);
        }

        \App\Models\Favorite::create(['user_id' => $user->id, 'trip_id' => $id]);
        return response()->json(['message' => 'Đã thêm vào yêu thích.', 'favorited' => true]);
    }

    // ─────────────────────────────────────────────
    // GET /api/favorites
    // ─────────────────────────────────────────────

    public function favorites(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $trips = $user->favoriteTrips()->with('budget')->paginate(10);

        return response()->json($trips);
    }

    // ─────────────────────────────────────────────
    // PUT /api/trips/{id}/notes  — lưu ghi chú & bí kíp
    // ─────────────────────────────────────────────

    public function updateNotes(int $id, Request $request): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $request->validate(['notes' => 'nullable|string|max:10000']);

        $trip->update(['user_notes' => $request->input('notes', '')]);

        return response()->json(['message' => 'Đã lưu ghi chú.', 'user_notes' => $trip->user_notes]);
    }

    // ─────────────────────────────────────────────
    // POST /api/trips/{id}/share  — tạo / lấy public link
    // ─────────────────────────────────────────────

    public function share(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        if (! $trip->share_token) {
            $trip->share_token = \Illuminate\Support\Str::random(48);
            $trip->is_public   = true;
            $trip->save();
        } else {
            // Toggle: nếu đã có token thì bật/tắt public
            $trip->is_public = ! $trip->is_public;
            $trip->save();
        }

        return response()->json([
            'share_token' => $trip->share_token,
            'is_public'   => $trip->is_public,
            'share_url'   => $trip->is_public ? (config('app.frontend_url') . '/trips/share/' . $trip->share_token) : null,
        ]);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/share/{token}  — xem trip công khai (không cần auth)
    // ─────────────────────────────────────────────

    public function showPublic(string $token): JsonResponse
    {
        $trip = Trip::with(['days.places', 'budget'])
            ->where('share_token', $token)
            ->where('is_public', true)
            ->where('status', 'completed')
            ->first();

        if (! $trip) {
            return response()->json(['message' => 'Lịch trình không tồn tại hoặc chưa được chia sẻ công khai'], 404);
        }

        $tripArray = $trip->toArray();
        $tripArray['budget']      = (float) $trip->getRawOriginal('budget');
        $tripArray['budget_data'] = $trip->budget?->toArray();

        return response()->json(['trip' => $tripArray]);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/{id}/packing-list  — AI generate danh sách đồ cần mang
    // ─────────────────────────────────────────────

    public function packingList(int $id, \App\Services\AI\AIServiceInterface $aiService): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::with('days.places')->find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        if ($trip->status !== 'completed') {
            return response()->json(['message' => 'Lịch trình chưa hoàn thành'], 422);
        }

        $cacheKey = "packing_list:trip:{$id}";

        $list = Cache::remember($cacheKey, 3600 * 24, function () use ($trip, $aiService) {
            return $aiService->generatePackingList($trip);
        });

        return response()->json(['packing_list' => $list]);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/{id}/nearby?place_name=...&lat=...&lng=...&type=...
    // ─────────────────────────────────────────────

    public function nearby(int $id, Request $request, \App\Services\NearbyService $nearbyService): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $lat  = (float) $request->query('lat', 0);
        $lng  = (float) $request->query('lng', 0);
        $type = (string) $request->query('type', 'food');

        if (! $lat || ! $lng) {
            return response()->json(['message' => 'Thiếu tọa độ lat/lng'], 422);
        }

        $cacheKey = "nearby:{$lat}:{$lng}:{$type}";
        $results  = Cache::remember($cacheKey, 3600 * 6, function () use ($lat, $lng, $type, $nearbyService) {
            return $nearbyService->findNearby($lat, $lng, $type);
        });

        return response()->json(['places' => $results]);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/{id}/cost-split
    // ─────────────────────────────────────────────

    public function costSplit(int $id): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::with('budget')->find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $numPeople = max(1, $trip->num_people);
        $budget    = $trip->budget_data ?? $trip->budget;

        $totalEstimated = $trip->budget?->total_estimated ?? (float) $trip->budget;
        $totalActual    = $trip->budget?->total_actual ?? 0;

        $categories = [
            'food'          => ['label' => 'Ẩm thực',   'emoji' => '🍜'],
            'transport'     => ['label' => 'Di chuyển',  'emoji' => '🚗'],
            'attraction'    => ['label' => 'Tham quan',  'emoji' => '🏛️'],
            'accommodation' => ['label' => 'Lưu trú',    'emoji' => '🏨'],
            'other'         => ['label' => 'Khác',       'emoji' => '🛍️'],
        ];

        $breakdown = [];
        foreach ($categories as $key => $meta) {
            $estimated = (float) ($trip->budget?->{$key} ?? 0);
            $actual    = (float) ($trip->budget?->{$key . '_actual'} ?? 0);
            $breakdown[] = [
                'category'          => $key,
                'label'             => $meta['label'],
                'emoji'             => $meta['emoji'],
                'total_estimated'   => $estimated,
                'total_actual'      => $actual,
                'per_person_estimated' => $numPeople > 0 ? round($estimated / $numPeople) : 0,
                'per_person_actual'    => $numPeople > 0 ? round($actual / $numPeople) : 0,
            ];
        }

        return response()->json([
            'num_people'              => $numPeople,
            'total_estimated'         => $totalEstimated,
            'total_actual'            => $totalActual,
            'per_person_estimated'    => $numPeople > 0 ? round($totalEstimated / $numPeople) : 0,
            'per_person_actual'       => $numPeople > 0 ? round($totalActual / $numPeople) : 0,
            'breakdown'               => $breakdown,
        ]);
    }
}
