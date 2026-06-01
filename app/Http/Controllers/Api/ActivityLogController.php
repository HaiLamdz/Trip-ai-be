<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ActivityLogController extends Controller
{
    // GET /api/activity-logs
    public function index(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $logs = ActivityLog::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json(['logs' => $logs]);
    }
}
