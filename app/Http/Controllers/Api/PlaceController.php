<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedPlace;
use App\Models\Trip;
use App\Models\TripDay;
use App\Models\TripBudget;
use App\Models\TripPlace;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlaceController extends Controller
{
    // ─────────────────────────────────────────────
    // Saved Places (existing)
    // ─────────────────────────────────────────────

    public function save(Request $request): JsonResponse
    {
        $request->validate([
            'place_name' => 'required|string|max:255',
            'place_type' => 'nullable|string|max:50',
            'latitude'   => 'nullable|numeric',
            'longitude'  => 'nullable|numeric',
        ]);

        $user = Auth::guard('api')->user();

        $place = SavedPlace::firstOrCreate(
            ['user_id' => $user->id, 'place_name' => $request->place_name],
            [
                'place_type' => $request->place_type,
                'latitude'   => $request->latitude,
                'longitude'  => $request->longitude,
            ]
        );

        return response()->json(['message' => 'Đã lưu địa điểm.', 'place' => $place], 201);
    }

    public function index(): JsonResponse
    {
        $user   = Auth::guard('api')->user();
        $places = SavedPlace::where('user_id', $user->id)->latest()->paginate(20);
        return response()->json($places);
    }

    public function destroy(int $id): JsonResponse
    {
        $user  = Auth::guard('api')->user();
        $place = SavedPlace::where('user_id', $user->id)->findOrFail($id);
        $place->delete();
        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────
    // Trip Activity CRUD
    // ─────────────────────────────────────────────

    /** POST /api/trips/{tripId}/days/{dayId}/places */
    public function storeActivity(Request $request, int $tripId, int $dayId, GeocodingService $geocodingService): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($tripId);

        if (! $trip || $trip->user_id !== $user->id) {
            return response()->json(['message' => 'Không tìm thấy hoặc không có quyền'], 403);
        }

        $day = TripDay::where('trip_id', $tripId)->find($dayId);
        if (! $day) {
            return response()->json(['message' => 'Không tìm thấy ngày'], 404);
        }

        $data = $request->validate([
            'time'                => 'required|string|max:10',
            'title'               => 'sometimes|nullable|string|max:255',
            'description'         => 'nullable|string|max:1000',
            'place_name'          => 'required|string|max:255',
            'place_type'          => 'nullable|string|in:food,cafe,attraction,hotel,transport,nightlife,shopping,other',
            'estimated_cost'      => 'nullable|numeric|min:0',
            'duration_minutes'    => 'nullable|integer|min:0',
            'transport_to_next'   => 'nullable|string|max:255',
            'distance_to_next_km' => 'nullable|numeric|min:0',
            'latitude'            => 'nullable|numeric',
            'longitude'           => 'nullable|numeric',
        ]);

        // Dùng place_name làm title nếu không có title
        if (empty($data['title'])) {
            $data['title'] = $data['place_name'];
        }

        // Tự động geocode nếu không có tọa độ
        if ((empty($data['latitude']) || empty($data['longitude'])) && ! empty($data['place_name'])) {
            try {
                $coords = $geocodingService->geocode($data['place_name'] . ', ' . $trip->destination);
                if ($coords) {
                    $data['latitude']  = $coords['lat'];
                    $data['longitude'] = $coords['lng'];
                }
            } catch (\Throwable) {
                // Geocoding thất bại → vẫn lưu, bản đồ sẽ không hiển thị pin
            }
        }

        // Append at end
        $maxOrder = TripPlace::where('trip_day_id', $dayId)->max('sort_order') ?? -1;

        $place = TripPlace::create(array_merge($data, [
            'trip_day_id' => $dayId,
            'trip_id'     => $tripId,
            'sort_order'  => $maxOrder + 1,
        ]));

        $this->recalcBudget($tripId);

        return response()->json(['place' => $place], 201);
    }

    /** PUT /api/trips/{tripId}/days/{dayId}/places/{placeId} */
    public function updateActivity(Request $request, int $tripId, int $dayId, int $placeId): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($tripId);

        if (! $trip || $trip->user_id !== $user->id) {
            return response()->json(['message' => 'Không tìm thấy hoặc không có quyền'], 403);
        }

        $place = TripPlace::where('trip_day_id', $dayId)->where('trip_id', $tripId)->find($placeId);
        if (! $place) {
            return response()->json(['message' => 'Không tìm thấy hoạt động'], 404);
        }

        $data = $request->validate([
            'time'                => 'sometimes|string|max:10',
            'title'               => 'sometimes|string|max:255',
            'description'         => 'nullable|string|max:1000',
            'place_name'          => 'nullable|string|max:255',
            'place_type'          => 'nullable|string|in:food,cafe,attraction,hotel,transport,nightlife,shopping,other',
            'estimated_cost'      => 'nullable|numeric|min:0',
            'duration_minutes'    => 'nullable|integer|min:0',
            'transport_to_next'   => 'nullable|string|max:255',
            'distance_to_next_km' => 'nullable|numeric|min:0',
            'latitude'            => 'nullable|numeric',
            'longitude'           => 'nullable|numeric',
        ]);

        $place->update($data);
        $this->recalcBudget($tripId);

        return response()->json(['place' => $place->fresh()]);
    }

    /** DELETE /api/trips/{tripId}/days/{dayId}/places/{placeId} */
    public function destroyActivity(int $tripId, int $dayId, int $placeId): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($tripId);

        if (! $trip || $trip->user_id !== $user->id) {
            return response()->json(['message' => 'Không tìm thấy hoặc không có quyền'], 403);
        }

        $place = TripPlace::where('trip_day_id', $dayId)->where('trip_id', $tripId)->find($placeId);
        if (! $place) {
            return response()->json(['message' => 'Không tìm thấy hoạt động'], 404);
        }

        $place->delete();
        $this->recalcBudget($tripId);

        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────
    // Recalculate TripBudget from TripPlace records
    // ─────────────────────────────────────────────

    private function recalcBudget(int $tripId): void
    {
        $places = TripPlace::where('trip_id', $tripId)->get();

        $cats = ['food' => 0, 'transport' => 0, 'attraction' => 0, 'accommodation' => 0, 'other' => 0];
        $total = 0;

        foreach ($places as $p) {
            $cost = (float) $p->estimated_cost;
            $total += $cost;
            $cat = match ($p->place_type) {
                'food', 'cafe'  => 'food',
                'transport'     => 'transport',
                'attraction'    => 'attraction',
                'hotel'         => 'accommodation',
                default         => 'other',
            };
            $cats[$cat] += $cost;
        }

        TripBudget::updateOrCreate(
            ['trip_id' => $tripId],
            array_merge($cats, ['total_estimated' => $total])
        );
    }
}
