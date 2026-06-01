<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\UserPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    // ─────────────────────────────────────────────
    // GET /api/profile
    // ─────────────────────────────────────────────

    public function show(): JsonResponse
    {
        $user = Auth::guard('api')->user()->load('preferences');

        return response()->json(['user' => $user]);
    }

    // ─────────────────────────────────────────────
    // PUT /api/profile
    // ─────────────────────────────────────────────

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'phone'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'bio'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            'avatar' => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,webp', 'max:5120'],
        ]);

        $user = Auth::guard('api')->user();
        $data = $request->only(['name', 'phone', 'bio']);

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');

            // Try Cloudinary if configured, else local storage
            if (config('services.cloudinary.cloud_name')) {
                $avatarUrl = $this->uploadToCloudinary($file);
            } else {
                $path      = $file->store('avatars', 'public');
                $avatarUrl = Storage::url($path);
            }

            $data['avatar'] = $avatarUrl;
        }

        $user->update($data);

        // Log activity
        ActivityLog::create([
            'user_id'     => $user->id,
            'action'      => 'update_profile',
            'description' => 'Cập nhật hồ sơ cá nhân',
            'ip_address'  => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'created_at'  => now(),
        ]);

        return response()->json(['message' => 'Cập nhật hồ sơ thành công.', 'user' => $user->fresh()]);
    }

    // ─────────────────────────────────────────────
    // PUT /api/profile/preferences
    // ─────────────────────────────────────────────

    public function updatePreferences(Request $request): JsonResponse
    {
        $request->validate([
            'preferences'   => ['required', 'array'],
            'preferences.*' => ['string', 'in:food,cafe,nature,culture,adventure,shopping,nightlife,budget,luxury'],
        ]);

        $user = Auth::guard('api')->user();

        UserPreference::updateOrCreate(
            ['user_id' => $user->id],
            [
                'preferences' => $request->preferences,
                'updated_at'  => now(),
            ]
        );

        return response()->json(['message' => 'Cập nhật sở thích thành công.']);
    }

    // ─────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────

    private function uploadToCloudinary(\Illuminate\Http\UploadedFile $file): string
    {
        // Simple Cloudinary upload via REST API
        $cloudName = config('services.cloudinary.cloud_name');
        $apiKey    = config('services.cloudinary.api_key');
        $apiSecret = config('services.cloudinary.api_secret');
        $timestamp = time();
        $signature = sha1("timestamp={$timestamp}{$apiSecret}");

        $response = \Illuminate\Support\Facades\Http::attach(
            'file', file_get_contents($file->getRealPath()), $file->getClientOriginalName()
        )->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'folder'    => 'tripai/avatars',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Cloudinary upload failed');
        }

        return $response->json('secure_url');
    }
}
