<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripBudget;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BudgetController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    // ─────────────────────────────────────────────
    // PUT /api/trips/{id}/budget/actual
    // ─────────────────────────────────────────────

    public function updateActual(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'food_actual'          => ['sometimes', 'numeric', 'min:0'],
            'transport_actual'     => ['sometimes', 'numeric', 'min:0'],
            'attraction_actual'    => ['sometimes', 'numeric', 'min:0'],
            'accommodation_actual' => ['sometimes', 'numeric', 'min:0'],
            'other_actual'         => ['sometimes', 'numeric', 'min:0'],
        ]);

        $user = Auth::guard('api')->user();
        $trip = Trip::find($id);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy tài nguyên'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Bạn không có quyền truy cập tài nguyên này'], 403);
        }

        $budget = TripBudget::firstOrCreate(['trip_id' => $id]);

        $fields = ['food_actual', 'transport_actual', 'attraction_actual', 'accommodation_actual', 'other_actual'];
        $data   = [];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        $budget->update($data);

        // Recalculate total_actual
        $totalActual = $budget->food_actual + $budget->transport_actual
            + $budget->attraction_actual + $budget->accommodation_actual + $budget->other_actual;
        $budget->update(['total_actual' => $totalActual]);

        // Warn if actual > 90% of estimated
        if ($budget->total_estimated > 0 && $totalActual >= $budget->total_estimated * 0.9) {
            $this->notificationService->budgetWarning($user->id, $id, $trip->destination);
        }

        return response()->json(['message' => 'Cập nhật chi tiêu thực tế thành công.', 'budget' => $budget->fresh()]);
    }
}
