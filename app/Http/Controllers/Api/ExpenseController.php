<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripExpense;
use App\Models\TripBudget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/trips/{tripId}/expenses
    // ─────────────────────────────────────────────

    public function index(int $tripId): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($tripId);

        if (! $trip || $trip->user_id !== $user->id) {
            return response()->json(['message' => 'Không có quyền truy cập'], 403);
        }

        $expenses = TripExpense::where('trip_id', $tripId)
            ->with('place:id,title,place_type,place_name')
            ->orderBy('expense_date')
            ->orderBy('created_at')
            ->get();

        $summary = $this->buildSummary($tripId);

        return response()->json([
            'expenses' => $expenses,
            'summary'  => $summary,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/trips/{tripId}/expenses
    // ─────────────────────────────────────────────

    public function store(Request $request, int $tripId): JsonResponse
    {
        $request->validate([
            'amount'        => ['required', 'numeric', 'min:0'],
            'category'      => ['required', 'in:food,transport,attraction,accommodation,shopping,other'],
            'note'          => ['nullable', 'string', 'max:500'],
            'paid_by'       => ['nullable', 'string', 'max:255'],
            'expense_date'  => ['required', 'date'],
            'trip_place_id' => ['nullable', 'integer'],
        ]);

        $user = Auth::guard('api')->user();
        $trip = Trip::find($tripId);

        if (! $trip || $trip->user_id !== $user->id) {
            return response()->json(['message' => 'Không có quyền truy cập'], 403);
        }

        $expense = TripExpense::create([
            'trip_id'       => $tripId,
            'user_id'       => $user->id,
            'trip_place_id' => $request->trip_place_id,
            'amount'        => $request->amount,
            'category'      => $request->category,
            'note'          => $request->note,
            'paid_by'       => $request->paid_by,
            'expense_date'  => $request->expense_date,
        ]);

        // Sync budget actual từ expenses
        $this->syncBudgetActual($tripId);

        return response()->json([
            'message' => 'Đã thêm chi phí.',
            'expense' => $expense->load('place:id,title,place_type,place_name'),
            'summary' => $this->buildSummary($tripId),
        ], 201);
    }

    // ─────────────────────────────────────────────
    // PUT /api/trips/{tripId}/expenses/{expenseId}
    // ─────────────────────────────────────────────

    public function update(Request $request, int $tripId, int $expenseId): JsonResponse
    {
        $request->validate([
            'amount'        => ['sometimes', 'numeric', 'min:0'],
            'category'      => ['sometimes', 'in:food,transport,attraction,accommodation,shopping,other'],
            'note'          => ['nullable', 'string', 'max:500'],
            'paid_by'       => ['nullable', 'string', 'max:255'],
            'expense_date'  => ['sometimes', 'date'],
            'trip_place_id' => ['nullable', 'integer'],
        ]);

        $user    = Auth::guard('api')->user();
        $expense = TripExpense::where('trip_id', $tripId)->find($expenseId);

        if (! $expense || $expense->user_id !== $user->id) {
            return response()->json(['message' => 'Không tìm thấy hoặc không có quyền'], 404);
        }

        $expense->update($request->only(['amount', 'category', 'note', 'paid_by', 'expense_date', 'trip_place_id']));
        $this->syncBudgetActual($tripId);

        return response()->json([
            'message' => 'Đã cập nhật chi phí.',
            'expense' => $expense->fresh()->load('place:id,title,place_type,place_name'),
            'summary' => $this->buildSummary($tripId),
        ]);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/trips/{tripId}/expenses/{expenseId}
    // ─────────────────────────────────────────────

    public function destroy(int $tripId, int $expenseId): JsonResponse
    {
        $user    = Auth::guard('api')->user();
        $expense = TripExpense::where('trip_id', $tripId)->find($expenseId);

        if (! $expense || $expense->user_id !== $user->id) {
            return response()->json(['message' => 'Không tìm thấy hoặc không có quyền'], 404);
        }

        $expense->delete();
        $this->syncBudgetActual($tripId);

        return response()->json(null, 204);
    }

    // ─────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────

    /**
     * Tổng hợp chi tiêu theo danh mục.
     *
     * @return array<string, mixed>
     */
    private function buildSummary(int $tripId): array
    {
        $expenses = TripExpense::where('trip_id', $tripId)->get();

        $categories = ['food', 'transport', 'attraction', 'accommodation', 'shopping', 'other'];
        $byCategory = [];
        foreach ($categories as $cat) {
            $byCategory[$cat] = (float) $expenses->where('category', $cat)->sum('amount');
        }

        return [
            'total'       => (float) $expenses->sum('amount'),
            'by_category' => $byCategory,
            'count'       => $expenses->count(),
        ];
    }

    /**
     * Cập nhật TripBudget actual columns từ tổng expenses thực tế.
     */
    private function syncBudgetActual(int $tripId): void
    {
        $expenses = TripExpense::where('trip_id', $tripId)->get();

        $catMap = [
            'food'          => 'food_actual',
            'transport'     => 'transport_actual',
            'attraction'    => 'attraction_actual',
            'accommodation' => 'accommodation_actual',
            'shopping'      => 'other_actual',
            'other'         => 'other_actual',
        ];

        $data = [
            'food_actual'          => 0,
            'transport_actual'     => 0,
            'attraction_actual'    => 0,
            'accommodation_actual' => 0,
            'other_actual'         => 0,
        ];

        foreach ($expenses as $expense) {
            $col = $catMap[$expense->category] ?? 'other_actual';
            $data[$col] += (float) $expense->amount;
        }

        $data['total_actual'] = array_sum($data);

        TripBudget::updateOrCreate(['trip_id' => $tripId], $data);
    }
}
