<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Tymon\JWTAuth\Facades\JWTAuth;

class AuthController extends Controller
{
    // ─────────────────────────────────────────────
    // POST /api/auth/register
    // ─────────────────────────────────────────────

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password, // hashed via cast
        ]);

        $token = JWTAuth::fromUser($user);

        return response()->json([
            'message'    => 'Đăng ký thành công',
            'user'       => $user,
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ], 201);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/login
    // ─────────────────────────────────────────────

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        if (! $token = Auth::guard('api')->attempt($credentials)) {
            return response()->json([
                'message' => 'Email hoặc mật khẩu không đúng',
            ], 401);
        }

        /** @var User $user */
        $user = Auth::guard('api')->user();

        $this->logActivity($user->id, 'login', 'Đăng nhập thành công', $request);

        return response()->json([
            'message'    => 'Đăng nhập thành công',
            'user'       => $user,
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/logout
    // ─────────────────────────────────────────────

    public function logout(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        $this->logActivity($user->id, 'logout', 'Đăng xuất', request());

        Auth::guard('api')->logout();

        return response()->json(['message' => 'Đăng xuất thành công']);
    }

    // ─────────────────────────────────────────────
    // GET /api/auth/me
    // ─────────────────────────────────────────────

    public function me(): JsonResponse
    {
        return response()->json([
            'user' => Auth::guard('api')->user(),
        ]);
    }

    // ─────────────────────────────────────────────
    // POST /api/auth/refresh
    // ─────────────────────────────────────────────

    public function refresh(): JsonResponse
    {
        try {
            $token = JWTAuth::refresh(JWTAuth::getToken());
        } catch (\Tymon\JWTAuth\Exceptions\JWTException $e) {
            return response()->json([
                'message' => 'Token không hợp lệ hoặc đã hết hạn',
            ], 401);
        }

        return response()->json([
            'token'      => $token,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ]);
    }

    // ─────────────────────────────────────────────
    // Helper: ghi activity log
    // ─────────────────────────────────────────────

    private function logActivity(int $userId, string $action, string $description, $request): void
    {
        ActivityLog::create([
            'user_id'    => $userId,
            'action'     => $action,
            'description'=> $description,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
        ]);
    }
}
