<?php

namespace Tests\Unit;

use App\Services\AI\DTOs\ActivityDTO;
use App\Services\AI\DTOs\TimelineDTO;
use App\Services\AI\DTOs\TripDayDTO;
use App\Services\AI\DTOs\WeatherDTO;
use App\Services\AI\TimelineParser;
use Tests\TestCase;

class TimelineParserTest extends TestCase
{
    private TimelineParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new TimelineParser();
    }

    // ─────────────────────────────────────────────
    // Property 1: Timeline Round-Trip
    // ─────────────────────────────────────────────

    /**
     * @test
     * Feature: trip-ai, Property 1: Timeline round-trip parse→serialize→parse preserves all fields
     */
    public function test_timeline_round_trip_preserves_all_fields(): void
    {
        // Run 100 iterations with random data
        for ($i = 0; $i < 100; $i++) {
            $original = $this->generateRandomTimeline();
            $serialized = $this->parser->serialize($original);
            $reparsed = $this->parser->parse($serialized);

            $this->assertSameTimeline($original, $reparsed, "Iteration {$i}");
        }
    }

    /** @test */
    public function test_parse_valid_json_returns_timeline_dto(): void
    {
        $json = json_encode([
            'days' => [[
                'date' => '2025-06-01',
                'weather' => null,
                'activities' => [[
                    'time' => '08:00', 'title' => 'Ăn sáng', 'description' => 'Phở',
                    'place_name' => 'Phở Bát Đàn', 'place_type' => 'food',
                    'estimated_cost' => 50000, 'duration_minutes' => 30,
                    'transport_to_next' => null, 'distance_to_next_km' => 0,
                    'latitude' => 21.0285, 'longitude' => 105.8542,
                ]],
            ]],
        ]);

        $timeline = $this->parser->parse($json);
        $this->assertCount(1, $timeline->days);
        $this->assertEquals('2025-06-01', $timeline->days[0]->date);
        $this->assertCount(1, $timeline->days[0]->activities);
        $this->assertEquals('Ăn sáng', $timeline->days[0]->activities[0]->title);
    }

    /** @test */
    public function test_parse_invalid_json_throws_exception(): void
    {
        $this->expectException(\App\Exceptions\TimelineParseException::class);
        $this->parser->parse('not valid json {{{');
    }

    /** @test */
    public function test_parse_missing_days_throws_exception(): void
    {
        $this->expectException(\App\Exceptions\TimelineParseException::class);
        $this->parser->parse(json_encode(['foo' => 'bar']));
    }

    /** @test */
    public function test_pretty_print_uses_2_space_indent(): void
    {
        $timeline = new TimelineDTO([
            new TripDayDTO('2025-06-01', null, []),
        ]);
        $pretty = $this->parser->prettyPrint($timeline);
        $this->assertStringContainsString('  "days"', $pretty);
    }

    /** @test */
    public function test_parse_strips_markdown_code_blocks(): void
    {
        $json = "```json\n" . json_encode(['days' => [['date' => '2025-06-01', 'activities' => []]]]) . "\n```";
        $timeline = $this->parser->parse($json);
        $this->assertCount(1, $timeline->days);
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    private function generateRandomTimeline(): TimelineDTO
    {
        $days = [];
        $numDays = rand(1, 5);
        for ($d = 0; $d < $numDays; $d++) {
            $date = date('Y-m-d', strtotime("+{$d} days"));
            $activities = [];
            $numActs = rand(3, 7);
            for ($a = 0; $a < $numActs; $a++) {
                $hour = str_pad(8 + $a, 2, '0', STR_PAD_LEFT);
                $activities[] = new ActivityDTO(
                    time:              "{$hour}:00",
                    title:             "Activity {$a}",
                    description:       "Description {$a}",
                    placeName:         "Place {$a}",
                    placeType:         ['food', 'attraction', 'hotel', 'cafe', 'transport', 'other'][rand(0, 5)],
                    estimatedCost:     (float) rand(0, 500000),
                    durationMinutes:   rand(30, 120),
                    transportToNext:   rand(0, 1) ? 'Xe máy' : null,
                    distanceToNextKm:  (float) rand(0, 20),
                    latitude:          rand(0, 1) ? (float) (10 + rand(0, 10) / 10) : null,
                    longitude:         rand(0, 1) ? (float) (105 + rand(0, 10) / 10) : null,
                );
            }
            $days[] = new TripDayDTO($date, null, $activities);
        }
        return new TimelineDTO($days);
    }

    private function assertSameTimeline(TimelineDTO $a, TimelineDTO $b, string $msg = ''): void
    {
        $this->assertCount(count($a->days), $b->days, $msg);
        foreach ($a->days as $i => $dayA) {
            $dayB = $b->days[$i];
            $this->assertEquals($dayA->date, $dayB->date, $msg);
            $this->assertCount(count($dayA->activities), $dayB->activities, $msg);
            foreach ($dayA->activities as $j => $actA) {
                $actB = $dayB->activities[$j];
                $this->assertEquals($actA->time, $actB->time, $msg);
                $this->assertEquals($actA->title, $actB->title, $msg);
                $this->assertEquals($actA->placeName, $actB->placeName, $msg);
                $this->assertEquals($actA->estimatedCost, $actB->estimatedCost, $msg);
                $this->assertEquals($actA->latitude, $actB->latitude, $msg);
                $this->assertEquals($actA->longitude, $actB->longitude, $msg);
            }
        }
    }
}
