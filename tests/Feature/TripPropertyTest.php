<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\ActivityLog;
use App\Models\Notification;
use App\Models\SavedPlace;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TripPropertyTest extends TestCase
{
    use RefreshDatabase;

    // ─────────────────────────────────────────────
    // Property 2: Validation rejects invalid input
    // ─────────────────────────────────────────────

    /**
     * @test
     * Feature: trip-ai, Property 2: Validation rejects invalid trip params with HTTP 422
     */
    public function test_validation_rejects_invalid_trip_params(): void
    {
        $user  = User::factory()->create();
        $token = auth('api')->login($user);

        $invalidCases = [
            ['destination' => '',       'start_date' => '2025-06-01', 'duration_days' => 3,  'budget' => 1000000, 'num_people' => 2],
            ['destination' => 'Hanoi',  'start_date' => 'not-a-date', 'duration_days' => 3,  'budget' => 1000000, 'num_people' => 2],
            ['destination' => 'Hanoi',  'start_date' => '2025-06-01', 'duration_days' => 0,  'budget' => 1000000, 'num_people' => 2],
            ['destination' => 'Hanoi',  'start_date' => '2025-06-01', 'duration_days' => 31, 'budget' => 1000000, 'num_people' => 2],
            ['destination' => 'Hanoi',  'start_date' => '2025-06-01', 'duration_days' => 3,  'budget' => -1,      'num_people' => 2],
            ['destination' => 'Hanoi',  'start_date' => '2025-06-01', 'duration_days' => 3,  'budget' => 1000000, 'num_people' => 0],
            ['destination' => 'Hanoi',  'start_date' => '2025-06-01', 'duration_days' => 3,  'budget' => 1000000, 'num_people' => 21],
        ];

        foreach ($invalidCases as $i => $params) {
            $response = $this->withToken($token)->postJson('/api/trips', $params);
            $this->assertEquals(422, $response->status(), "Case {$i} should return 422: " . json_encode($params));
            $this->assertNotEmpty($response->json('errors'), "Case {$i} should have errors");
        }
    }

    // ─────────────────────────────────────────────
    // Property 3: Ownership isolation
    // ─────────────────────────────────────────────

    /**
     * @test
     * Feature: trip-ai, Property 3: User A cannot read/delete Trip of User B
     */
    public function test_ownership_isolation(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $userA = User::factory()->create();
            $userB = User::factory()->create();
            $tokenA = auth('api')->login($userA);

            $trip = Trip::factory()->create(['user_id' => $userB->id]);

            // User A tries to read User B's trip
            $this->withToken($tokenA)->getJson("/api/trips/{$trip->id}")
                ->assertStatus(403);

            // User A tries to delete User B's trip
            $this->withToken($tokenA)->deleteJson("/api/trips/{$trip->id}")
                ->assertStatus(403);
        }
    }

    // ─────────────────────────────────────────────
    // Property 4: Chat limit enforcement
    // ─────────────────────────────────────────────

    /**
     * @test
     * Feature: trip-ai, Property 4: Chat count never exceeds 50
     */
    public function test_chat_limit_enforcement(): void
    {
        $user  = User::factory()->create();
        $token = auth('api')->login($user);
        $trip  = Trip::factory()->create(['user_id' => $user->id, 'status' => 'completed', 'timeline' => ['days' => []]]);

        // Create exactly 50 conversations
        AiConversation::factory()->count(50)->create([
            'trip_id' => $trip->id,
            'user_id' => $user->id,
        ]);

        // 51st request should be rejected with 429
        $response = $this->withToken($token)->postJson("/api/trips/{$trip->id}/chat", [
            'message' => 'Test message',
        ]);

        $this->assertEquals(429, $response->status());
        $this->assertStringContainsString('giới hạn', $response->json('message'));
    }

    // ─────────────────────────────────────────────
    // Property 7: Notification unread count consistency
    // ─────────────────────────────────────────────

    /**
     * @test
     * Feature: trip-ai, Property 7: unread_count equals COUNT(read_at IS NULL)
     */
    public function test_notification_unread_count_consistency(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $user  = User::factory()->create();
            $token = auth('api')->login($user);

            $total  = rand(0, 15);
            $unread = rand(0, $total);
            $read   = $total - $unread;

            Notification::factory()->count($unread)->create(['user_id' => $user->id, 'read_at' => null]);
            Notification::factory()->count($read)->create(['user_id' => $user->id, 'read_at' => now()]);

            $response = $this->withToken($token)->getJson('/api/notifications/unread-count');
            $response->assertStatus(200);
            $this->assertEquals($unread, $response->json('unread_count'), "Iteration {$i}: expected {$unread} unread");
        }
    }

    // ─────────────────────────────────────────────
    // Property 8: Activity log completeness
    // ─────────────────────────────────────────────

    /**
     * @test
     * Feature: trip-ai, Property 8: Login action creates activity log entry
     */
    public function test_activity_log_completeness_on_login(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $user = User::factory()->create(['password' => bcrypt('password123')]);

            $before = ActivityLog::where('user_id', $user->id)->where('action', 'login')->count();

            $this->postJson('/api/auth/login', [
                'email'    => $user->email,
                'password' => 'password123',
            ])->assertStatus(200);

            $after = ActivityLog::where('user_id', $user->id)->where('action', 'login')->count();
            $this->assertEquals($before + 1, $after, "Iteration {$i}: login should create activity log");
        }
    }

    // ─────────────────────────────────────────────
    // Property 9: Saved place round-trip
    // ─────────────────────────────────────────────

    /**
     * @test
     * Feature: trip-ai, Property 9: Saved place data preserved after save/fetch
     */
    public function test_saved_place_round_trip(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $user  = User::factory()->create();
            $token = auth('api')->login($user);

            $placeData = [
                'place_name' => 'Place ' . $i,
                'place_type' => ['food', 'attraction', 'hotel', 'cafe', 'other'][rand(0, 4)],
                'latitude'   => round(10 + rand(0, 100) / 100, 4),
                'longitude'  => round(105 + rand(0, 100) / 100, 4),
                'notes'      => "Note {$i}",
            ];

            $this->withToken($token)->postJson('/api/places/save', $placeData)->assertStatus(201);

            $fetched = $this->withToken($token)->getJson('/api/places/saved')->json('data.0');

            $this->assertEquals($placeData['place_name'], $fetched['place_name'], "Iteration {$i}");
            $this->assertEquals($placeData['place_type'], $fetched['place_type'], "Iteration {$i}");
            $this->assertEquals($placeData['latitude'],   (float) $fetched['latitude'],  "Iteration {$i}");
            $this->assertEquals($placeData['longitude'],  (float) $fetched['longitude'], "Iteration {$i}");
        }
    }
}
