<?php

namespace Tests\Feature;

use App\Jobs\GenerateTripJob;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Tests for the complete trip creation → status polling → detail flow.
 * Ensures POST /api/trips returns trip_id immediately (non-blocking),
 * job is queued (not run sync), and status/detail endpoints work correctly.
 */
class TripCreationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user  = User::factory()->create();
        $this->token = auth('api')->login($this->user);
    }

    // ─────────────────────────────────────────────────────────────────
    // 1. POST /api/trips → 202 ngay lập tức, trả về trip_id
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_create_trip_returns_202_with_trip_id_immediately(): void
    {
        Queue::fake(); // không chạy job thật

        $res = $this->withToken($this->token)->postJson('/api/trips', $this->validPayload());

        $res->assertStatus(202)
            ->assertJsonStructure(['trip_id', 'status', 'message'])
            ->assertJsonPath('status', 'processing');

        $this->assertNotNull($res->json('trip_id'));
        $this->assertIsInt($res->json('trip_id'));
    }

    /** @test */
    public function test_create_trip_dispatches_generate_job(): void
    {
        Queue::fake();

        $res = $this->withToken($this->token)->postJson('/api/trips', $this->validPayload());

        $res->assertStatus(202);

        // Đảm bảo đúng 1 GenerateTripJob được dispatch
        Queue::assertPushed(GenerateTripJob::class, 1);
    }

    /** @test */
    public function test_create_trip_stores_processing_status_in_db(): void
    {
        Queue::fake();

        $res = $this->withToken($this->token)->postJson('/api/trips', $this->validPayload());

        $tripId = $res->json('trip_id');
        $trip   = Trip::find($tripId);

        $this->assertNotNull($trip);
        $this->assertEquals('processing', $trip->status);
        $this->assertEquals($this->user->id, $trip->user_id);
    }

    // ─────────────────────────────────────────────────────────────────
    // 2. GET /api/trips/{id}/status — polling endpoint
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_status_endpoint_returns_processing_while_job_runs(): void
    {
        Queue::fake();

        $res    = $this->withToken($this->token)->postJson('/api/trips', $this->validPayload());
        $tripId = $res->json('trip_id');

        $statusRes = $this->withToken($this->token)->getJson("/api/trips/{$tripId}/status");

        $statusRes->assertStatus(200)
            ->assertJsonStructure(['status', 'progress_message'])
            ->assertJsonPath('status', 'processing');

        $this->assertNotEmpty($statusRes->json('progress_message'));
    }

    /** @test */
    public function test_status_endpoint_returns_completed_after_job_done(): void
    {
        Queue::fake();

        $trip = Trip::factory()->create([
            'user_id' => $this->user->id,
            'status'  => 'completed',
        ]);

        $this->withToken($this->token)->getJson("/api/trips/{$trip->id}/status")
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('progress_message', 'Lịch trình đã sẵn sàng!');
    }

    /** @test */
    public function test_status_endpoint_returns_failed_on_job_failure(): void
    {
        Queue::fake();

        $trip = Trip::factory()->create([
            'user_id' => $this->user->id,
            'status'  => 'failed',
        ]);

        $this->withToken($this->token)->getJson("/api/trips/{$trip->id}/status")
            ->assertStatus(200)
            ->assertJsonPath('status', 'failed');
    }

    /** @test */
    public function test_status_endpoint_requires_auth(): void
    {
        Queue::fake();
        $trip = Trip::factory()->create(['user_id' => $this->user->id]);

        $this->getJson("/api/trips/{$trip->id}/status")->assertStatus(401);
    }

    /** @test */
    public function test_status_endpoint_denies_other_users(): void
    {
        Queue::fake();
        $otherUser = User::factory()->create();
        $trip      = Trip::factory()->create(['user_id' => $otherUser->id]);

        $this->withToken($this->token)->getJson("/api/trips/{$trip->id}/status")
            ->assertStatus(403);
    }

    // ─────────────────────────────────────────────────────────────────
    // 3. GET /api/trips/{id} — detail endpoint sau khi completed
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_trip_detail_accessible_after_completed(): void
    {
        $trip = Trip::factory()->create([
            'user_id' => $this->user->id,
            'status'  => 'completed',
        ]);

        $this->withToken($this->token)->getJson("/api/trips/{$trip->id}")
            ->assertStatus(200)
            ->assertJsonStructure(['trip' => ['id', 'destination', 'status', 'days']]);
    }

    /** @test */
    public function test_trip_detail_accessible_while_processing(): void
    {
        // Frontend cần load detail ngay khi navigate sang trang
        Queue::fake();
        $res    = $this->withToken($this->token)->postJson('/api/trips', $this->validPayload());
        $tripId = $res->json('trip_id');

        $this->withToken($this->token)->getJson("/api/trips/{$tripId}")
            ->assertStatus(200)
            ->assertJsonPath('trip.status', 'processing');
    }

    /** @test */
    public function test_trip_detail_returns_404_for_nonexistent(): void
    {
        $this->withToken($this->token)->getJson('/api/trips/99999')
            ->assertStatus(404);
    }

    // ─────────────────────────────────────────────────────────────────
    // 4. Validation — POST /api/trips
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_create_trip_requires_destination(): void
    {
        Queue::fake();
        $payload = $this->validPayload();
        unset($payload['destination']);

        $this->withToken($this->token)->postJson('/api/trips', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.destination.0', fn ($v) => !empty($v));
    }

    /** @test */
    public function test_create_trip_requires_valid_start_date(): void
    {
        Queue::fake();
        $payload                = $this->validPayload();
        $payload['start_date']  = '2020-01-01'; // date in the past

        $this->withToken($this->token)->postJson('/api/trips', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['start_date']]);
    }

    /** @test */
    public function test_create_trip_requires_positive_budget(): void
    {
        Queue::fake();
        $payload           = $this->validPayload();
        $payload['budget'] = 0;

        $this->withToken($this->token)->postJson('/api/trips', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['budget']]);
    }

    /** @test */
    public function test_create_trip_requires_auth(): void
    {
        $this->postJson('/api/trips', $this->validPayload())->assertStatus(401);
    }

    // ─────────────────────────────────────────────────────────────────
    // 5. Full flow — simulate job completion
    // ─────────────────────────────────────────────────────────────────

    /** @test */
    public function test_full_flow_create_then_status_changes_to_completed(): void
    {
        Queue::fake();

        // Step 1: Create trip
        $res = $this->withToken($this->token)->postJson('/api/trips', $this->validPayload());
        $res->assertStatus(202);
        $tripId = $res->json('trip_id');

        // Step 2: Status = processing
        $this->withToken($this->token)->getJson("/api/trips/{$tripId}/status")
            ->assertJsonPath('status', 'processing');

        // Step 3: Simulate job completion (update DB directly like GenerateTripJob does)
        Trip::where('id', $tripId)->update(['status' => 'completed']);

        // Step 4: Poll lại → status = completed
        $this->withToken($this->token)->getJson("/api/trips/{$tripId}/status")
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('progress_message', 'Lịch trình đã sẵn sàng!');

        // Step 5: Detail endpoint trả về trip completed
        $this->withToken($this->token)->getJson("/api/trips/{$tripId}")
            ->assertStatus(200)
            ->assertJsonPath('trip.status', 'completed');
    }

    // ─────────────────────────────────────────────────────────────────
    // Helper
    // ─────────────────────────────────────────────────────────────────

    private function validPayload(): array
    {
        return [
            'destination'   => 'Đà Lạt, Lâm Đồng',
            'start_date'    => now()->addDays(7)->format('Y-m-d'),
            'duration_days' => 3,
            'budget'        => 5000000,
            'num_people'    => 2,
            'travel_type'   => 'couple',
            'transport_mode' => 'car',
            'accommodation_type' => 'hotel',
        ];
    }
}
