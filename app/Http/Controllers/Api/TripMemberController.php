<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripMember;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TripMemberController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/trips/{tripId}/members
    // ─────────────────────────────────────────────

    public function index(int $tripId): JsonResponse
    {
        $user = Auth::guard('api')->user();
        $trip = Trip::find($tripId);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy lịch trình'], 404);
        }

        if ($trip->user_id !== $user->id && ! $this->isMember($tripId, $user->id)) {
            return response()->json(['message' => 'Bạn không có quyền truy cập'], 403);
        }

        $members = TripMember::with('user:id,name,email,avatar')
            ->where('trip_id', $tripId)
            ->get()
            ->map(fn ($m) => [
                'id'           => $m->id,
                'user'         => $m->user,
                'role'         => $m->role,
                'status'       => $m->status,
                'invited_at'   => $m->invited_at,
                'accepted_at'  => $m->accepted_at,
            ]);

        // Thêm owner vào đầu
        $owner = User::select('id', 'name', 'email', 'avatar')->find($trip->user_id);

        return response()->json([
            'owner'   => $owner,
            'members' => $members,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/trips/{tripId}/members/invite
    // Mời thành viên qua email
    // ─────────────────────────────────────────────

    public function invite(Request $request, int $tripId): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'role'  => ['sometimes', 'in:editor,viewer'],
        ]);

        $user = Auth::guard('api')->user();
        $trip = Trip::find($tripId);

        if (! $trip) {
            return response()->json(['message' => 'Không tìm thấy lịch trình'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Chỉ chủ lịch trình mới có thể mời thành viên'], 403);
        }

        if ($trip->status !== 'completed') {
            return response()->json(['message' => 'Lịch trình chưa hoàn thành'], 422);
        }

        $invitee = User::where('email', $request->email)->first();

        if (! $invitee) {
            return response()->json(['message' => 'Không tìm thấy tài khoản với email này'], 404);
        }

        if ($invitee->id === $user->id) {
            return response()->json(['message' => 'Không thể mời chính mình'], 422);
        }

        // Kiểm tra đã là thành viên chưa
        $existing = TripMember::where('trip_id', $tripId)
            ->where('user_id', $invitee->id)
            ->first();

        if ($existing && $existing->status === 'accepted') {
            return response()->json(['message' => 'Người này đã là thành viên của lịch trình'], 409);
        }

        $member = TripMember::updateOrCreate(
            ['trip_id' => $tripId, 'user_id' => $invitee->id],
            [
                'role'         => $request->input('role', 'viewer'),
                'invite_token' => Str::random(48),
                'status'       => 'pending',
                'invited_at'   => now(),
            ]
        );

        return response()->json([
            'message' => "Đã mời {$invitee->name} vào lịch trình.",
            'member'  => [
                'id'          => $member->id,
                'user'        => $invitee->only(['id', 'name', 'email', 'avatar']),
                'role'        => $member->role,
                'status'      => $member->status,
                'invited_at'  => $member->invited_at,
            ],
        ], 201);
    }

    // ─────────────────────────────────────────────
    // POST /api/trips/members/accept/{token}
    // Chấp nhận lời mời
    // ─────────────────────────────────────────────

    public function accept(string $token): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $member = TripMember::where('invite_token', $token)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (! $member) {
            return response()->json(['message' => 'Lời mời không hợp lệ hoặc đã được xử lý'], 404);
        }

        $member->update([
            'status'      => 'accepted',
            'accepted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Đã tham gia lịch trình.',
            'trip_id' => $member->trip_id,
        ]);
    }

    // ─────────────────────────────────────────────
    // DELETE /api/trips/{tripId}/members/{memberId}
    // Xóa thành viên (chủ trip) hoặc rời nhóm (bản thân)
    // ─────────────────────────────────────────────

    public function remove(int $tripId, int $memberId): JsonResponse
    {
        $user   = Auth::guard('api')->user();
        $trip   = Trip::find($tripId);
        $member = TripMember::where('trip_id', $tripId)->find($memberId);

        if (! $trip || ! $member) {
            return response()->json(['message' => 'Không tìm thấy'], 404);
        }

        $isOwner = $trip->user_id === $user->id;
        $isSelf  = $member->user_id === $user->id;

        if (! $isOwner && ! $isSelf) {
            return response()->json(['message' => 'Không có quyền'], 403);
        }

        $member->delete();

        return response()->json(['message' => $isSelf ? 'Đã rời nhóm.' : 'Đã xóa thành viên.']);
    }

    // ─────────────────────────────────────────────
    // PUT /api/trips/{tripId}/members/{memberId}/role
    // Thay đổi role (chỉ owner)
    // ─────────────────────────────────────────────

    public function updateRole(Request $request, int $tripId, int $memberId): JsonResponse
    {
        $request->validate(['role' => ['required', 'in:editor,viewer']]);

        $user   = Auth::guard('api')->user();
        $trip   = Trip::find($tripId);
        $member = TripMember::where('trip_id', $tripId)->find($memberId);

        if (! $trip || ! $member) {
            return response()->json(['message' => 'Không tìm thấy'], 404);
        }

        if ($trip->user_id !== $user->id) {
            return response()->json(['message' => 'Chỉ chủ lịch trình mới có thể thay đổi vai trò'], 403);
        }

        $member->update(['role' => $request->role]);

        return response()->json(['message' => 'Đã cập nhật vai trò.', 'member' => $member->fresh()]);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/shared-with-me
    // Các trip mình được mời
    // ─────────────────────────────────────────────

    public function sharedWithMe(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $memberships = TripMember::with(['trip.budget', 'trip.user:id,name,avatar'])
            ->where('user_id', $user->id)
            ->where('status', 'accepted')
            ->latest()
            ->get()
            ->map(fn ($m) => [
                'member_id'   => $m->id,
                'role'        => $m->role,
                'accepted_at' => $m->accepted_at,
                'trip'        => $m->trip,
            ]);

        return response()->json(['trips' => $memberships]);
    }

    // ─────────────────────────────────────────────
    // GET /api/trips/pending-invites
    // Lời mời đang chờ
    // ─────────────────────────────────────────────

    public function pendingInvites(): JsonResponse
    {
        $user = Auth::guard('api')->user();

        $invites = TripMember::with(['trip:id,destination,start_date,duration_days,cover_image_url', 'trip.user:id,name,avatar'])
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->get()
            ->map(fn ($m) => [
                'id'           => $m->id,
                'invite_token' => $m->invite_token,
                'role'         => $m->role,
                'invited_at'   => $m->invited_at,
                'trip'         => $m->trip,
                'invited_by'   => $m->trip?->user,
            ]);

        return response()->json(['invites' => $invites]);
    }

    // ─────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────

    private function isMember(int $tripId, int $userId): bool
    {
        return TripMember::where('trip_id', $tripId)
            ->where('user_id', $userId)
            ->where('status', 'accepted')
            ->exists();
    }
}
