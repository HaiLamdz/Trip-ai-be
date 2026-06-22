<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Trip;
use App\Models\TripBudget;
use App\Models\TripDay;
use App\Models\TripPlace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CommunityController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/community  — danh sách trip đã publish
    // ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $search     = $request->query('search', '');
        $preference = $request->query('preference', '');
        $sort       = $request->query('sort', 'latest'); // latest | popular | budget_asc | budget_desc
        $page       = max(1, (int) $request->query('page', 1));

        $query = Trip::with(['user:id,name,avatar'])
            ->where('is_published', true)
            ->where('status', 'completed');

        if ($search) {
            $query->where('destination', 'like', "%{$search}%");
        }

        if ($preference) {
            $query->whereJsonContains('preferences', $preference);
        }

        $query->when($sort === 'popular', fn ($q) => $q->orderByDesc('clone_count')->orderByDesc('view_count'))
            ->when($sort === 'budget_asc', fn ($q) => $q->orderBy('budget'))
            ->when($sort === 'budget_desc', fn ($q) => $q->orderByDesc('budget'))
            ->when($sort === 'latest', fn ($q) => $q->orderByDesc('published_at'));

        $trips = $query->select([
            'id', 'user_id', 'destination', 'origin', 'start_date', 'duration_days',
            'budget', 'num_people', 'travel_type', 'preferences',
            'publish_description', 'clone_count', 'view_count',
            'published_at', 'cover_image_url',
        ])->paginate(12);

        return response()->json($trips);
    }

    // ─────────────────────────────────────────────
    // GET /api/community/{id}  — xem chi tiết trip publish
    // ─────────────────────────────────────────────

    public function show(int $id): JsonResponse
    {
        $trip = Trip::with(['days.places', 'budget', 'user:id,name,avatar'])
            ->where('id', $id)
            ->where('is_published', true)
            ->where('status', 'completed')
            ->first();

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy lịch trình'], 404);
        }

        // Tăng view count (không cache để đảm bảo chính xác)
        $trip->increment('view_count');

        $tripArray             = $trip->toArray();
        $tripArray['budget']   = (float) $trip->getRawOriginal('budget');
        $tripArray['budget_data'] = $trip->budget?->toArray();
        // Ẩn user_notes khi xem public
        unset($tripArray['user_notes']);

        // Kiểm tra auth user đã clone chưa
        $hasCloned = false;
        $user      = Auth::guard('api')->user();
        if ($user) {
            $hasCloned = Trip::where('user_id', $user->id)
                ->where('cloned_from_id', $id)
                ->exists();
        }

        return response()->json([
            'trip'       => $tripArray,
            'has_cloned' => $hasCloned,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/trips/{id}/publish  — publish / unpublish
    // ─────────────────────────────────────────────

    public function publish(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy lịch trình'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền thực hiện thao tác này'], 403);
        }

        if ($trip->status !== 'completed') {
            return response()->json(['message' => 'Chỉ có thể publish lịch trình đã hoàn thành'], 422);
        }

        if ($trip->is_published) {
            // Unpublish
            $trip->update(['is_published' => false, 'published_at' => null]);
            Cache::forget("community:feed");

            return response()->json([
                'message'      => 'Đã gỡ khỏi cộng đồng.',
                'is_published' => false,
            ]);
        }

        // Publish
        $trip->update([
            'is_published'       => true,
            'published_at'       => now(),
            'publish_description' => $request->input('description'),
        ]);

        Cache::forget("community:feed");

        return response()->json([
            'message'      => 'Lịch trình đã được chia sẻ với cộng đồng.',
            'is_published' => true,
            'published_at' => $trip->published_at,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/community/{id}/clone  — clone về account mình
    // ─────────────────────────────────────────────

    public function clone(int $id, Request $request): JsonResponse
    {
        $user   = Auth::guard('api')->user();
        $source = Trip::with(['days.places', 'budget'])->find($id);

        if (! $source || ! $source->is_published || $source->status !== 'completed') {
            return response()->json(['message' => 'Không tìm thấy lịch trình'], 404);
        }

        if ($source->user_id === $user->id) {
            return response()->json(['message' => 'Không thể clone lịch trình của chính mình'], 422);
        }

        DB::beginTransaction();
        try {
            // Tạo trip mới
            $newTrip = Trip::create([
                'user_id'            => $user->id,
                'destination'        => $source->destination,
                'origin'             => $source->origin,
                'destination_lat'    => $source->destination_lat,
                'destination_lng'    => $source->destination_lng,
                'start_date'         => $source->start_date,
                'duration_days'      => $source->duration_days,
                'budget'             => $source->budget,
                'num_people'         => $source->num_people,
                'travel_type'        => $source->travel_type,
                'transport_mode'     => $source->transport_mode,
                'accommodation_type' => $source->accommodation_type,
                'accommodation_area' => $source->accommodation_area,
                'arrival_time'       => $source->arrival_time,
                'preferences'        => $source->preferences,
                'notes'              => $source->notes,
                'status'             => 'completed',
                'cover_image_url'    => $source->cover_image_url,
                'cloned_from_id'     => $source->id,
                'is_published'       => false,
            ]);

            // Clone các ngày + địa điểm
            foreach ($source->days as $day) {
                $newDay = TripDay::create([
                    'trip_id'    => $newTrip->id,
                    'day_number' => $day->day_number,
                    'date'       => $day->date,
                    'weather'    => $day->weather,
                ]);

                foreach ($day->places as $place) {
                    TripPlace::create([
                        'trip_id'              => $newTrip->id,
                        'trip_day_id'          => $newDay->id,
                        'time'                 => $place->time,
                        'title'                => $place->title,
                        'description'          => $place->description,
                        'place_name'           => $place->place_name,
                        'place_type'           => $place->place_type,
                        'estimated_cost'       => $place->estimated_cost,
                        'duration_minutes'     => $place->duration_minutes,
                        'transport_to_next'    => $place->transport_to_next,
                        'distance_to_next_km'  => $place->distance_to_next_km,
                        'latitude'             => $place->latitude,
                        'longitude'            => $place->longitude,
                        'sort_order'           => $place->sort_order,
                    ]);
                }
            }

            // Clone budget
            if ($source->budget) {
                TripBudget::create([
                    'trip_id'       => $newTrip->id,
                    'food'          => $source->budget->food,
                    'transport'     => $source->budget->transport,
                    'attraction'    => $source->budget->attraction,
                    'accommodation' => $source->budget->accommodation,
                    'other'         => $source->budget->other,
                    'total_estimated' => $source->budget->total_estimated,
                    // actual fields bắt đầu từ 0
                    'food_actual'          => 0,
                    'transport_actual'     => 0,
                    'attraction_actual'    => 0,
                    'accommodation_actual' => 0,
                    'other_actual'         => 0,
                    'total_actual'         => 0,
                ]);
            }

            // Tăng clone_count của source
            $source->increment('clone_count');

            DB::commit();

            // Log activity
            ActivityLog::create([
                'user_id'     => $user->id,
                'action'      => 'clone_trip',
                'description' => "Clone lịch trình {$source->destination} từ cộng đồng",
                'ip_address'  => $request->ip(),
                'user_agent'  => $request->userAgent(),
                'created_at'  => now(),
            ]);

            return response()->json([
                'message' => 'Đã clone lịch trình về tài khoản của bạn.',
                'trip_id' => $newTrip->id,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Có lỗi xảy ra khi clone lịch trình'], 500);
        }
    }
}
