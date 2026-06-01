<?php

use Illuminate\Support\Facades\Schedule;

// ─────────────────────────────────────────────
// TripAI Scheduled Jobs
// ─────────────────────────────────────────────

// Clean activity logs older than 90 days — daily at 02:00 UTC
Schedule::command('tripai:clean-old-logs')->dailyAt('02:00')->timezone('UTC');

// Check weather alerts for upcoming trips — daily at 06:00 UTC
Schedule::command('tripai:check-weather-alerts')->dailyAt('06:00')->timezone('UTC');

// Clean failed trips older than 30 days — weekly on Sunday at 03:00 UTC
Schedule::command('tripai:clean-failed-trips')->weeklyOn(0, '03:00')->timezone('UTC');
