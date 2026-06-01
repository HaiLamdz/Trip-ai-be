<?php

namespace App\Services\AI;

use App\Services\AI\DTOs\TimelineDTO;
use App\Exceptions\TimelineParseException;

class TimelineParser
{
    // ─────────────────────────────────────────────
    // parse
    // ─────────────────────────────────────────────

    /**
     * Parse JSON string → TimelineDTO.
     *
     * @throws TimelineParseException
     */
    public function parse(string $json): TimelineDTO
    {
        // Strip markdown code blocks
        $json = preg_replace('/^```(?:json)?\s*/m', '', $json);
        $json = preg_replace('/\s*```$/m', '', $json);
        $json = trim($json);

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new TimelineParseException('Invalid JSON: ' . json_last_error_msg());
        }

        $result = $this->validate($data);
        if (! $result['valid']) {
            throw new TimelineParseException('Timeline validation failed: ' . implode(', ', $result['errors']));
        }

        return TimelineDTO::fromArray($data);
    }

    // ─────────────────────────────────────────────
    // validate
    // ─────────────────────────────────────────────

    /**
     * Validate array data against timeline schema.
     *
     * @param  array<string, mixed> $data
     * @return array{valid: bool, errors: string[]}
     */
    public function validate(array $data): array
    {
        $errors = [];

        if (! isset($data['days']) || ! is_array($data['days'])) {
            $errors[] = 'Missing or invalid "days" array';
            return ['valid' => false, 'errors' => $errors];
        }

        if (empty($data['days'])) {
            $errors[] = '"days" array must not be empty';
        }

        foreach ($data['days'] as $dayIndex => $day) {
            $prefix = "days[{$dayIndex}]";

            if (empty($day['date'])) {
                $errors[] = "{$prefix}: missing 'date'";
            } elseif (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day['date'])) {
                $errors[] = "{$prefix}: 'date' must be YYYY-MM-DD format";
            }

            if (! isset($day['activities']) || ! is_array($day['activities'])) {
                $errors[] = "{$prefix}: missing or invalid 'activities' array";
                continue;
            }

            foreach ($day['activities'] as $actIndex => $act) {
                $aPrefix = "{$prefix}.activities[{$actIndex}]";

                if (empty($act['time'])) {
                    $errors[] = "{$aPrefix}: missing 'time'";
                } elseif (! preg_match('/^\d{2}:\d{2}$/', $act['time'])) {
                    $errors[] = "{$aPrefix}: 'time' must be HH:MM format";
                }

                foreach (['title', 'place_name', 'place_type'] as $field) {
                    if (! isset($act[$field]) || $act[$field] === '') {
                        $errors[] = "{$aPrefix}: missing '{$field}'";
                    }
                }

                $validTypes = ['food', 'attraction', 'hotel', 'cafe', 'transport', 'other'];
                if (isset($act['place_type']) && ! in_array($act['place_type'], $validTypes, true)) {
                    $errors[] = "{$aPrefix}: invalid 'place_type' '{$act['place_type']}'";
                }

                if (isset($act['estimated_cost']) && ! is_numeric($act['estimated_cost'])) {
                    $errors[] = "{$aPrefix}: 'estimated_cost' must be numeric";
                }
            }
        }

        return ['valid' => empty($errors), 'errors' => $errors];
    }

    // ─────────────────────────────────────────────
    // serialize
    // ─────────────────────────────────────────────

    /**
     * TimelineDTO → compact JSON string.
     */
    public function serialize(TimelineDTO $timeline): string
    {
        return json_encode($timeline->toArray(), JSON_UNESCAPED_UNICODE);
    }

    // ─────────────────────────────────────────────
    // prettyPrint
    // ─────────────────────────────────────────────

    /**
     * TimelineDTO → pretty-printed JSON (2-space indent).
     */
    public function prettyPrint(TimelineDTO $timeline): string
    {
        return json_encode(
            $timeline->toArray(),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }
}
