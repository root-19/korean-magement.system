<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pay rates
    |--------------------------------------------------------------------------
    |
    | Hourly rate in pesos per teaching method. A session pays
    | rate * (learning_time / 60), so a 25-minute audio class pays
    | 190 * 25/60 = 79.17.
    |
    | These were private constants inside the legacy Earnings model
    | (AUDIO_RATE / VIDEO_KIDS_RATE / VIDEO_ADULT_RATE). They live here so a
    | rate change is a config edit and not a code deploy.
    |
    */

    'rates' => [
        'audio' => (float) env('RATE_AUDIO', 190),
        'video_kids' => (float) env('RATE_VIDEO_KIDS', 220),
        'video_adults' => (float) env('RATE_VIDEO_ADULTS', 210),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feedback requirement
    |--------------------------------------------------------------------------
    |
    | Teachers are only paid for a session once they have filed a report for
    | it. Sessions taught strictly BEFORE this date predate the rule and are
    | paid unconditionally, which is what keeps historical payouts intact.
    |
    */

    'feedback_required_from' => env('FEEDBACK_REQUIRED_FROM', '2024-01-01'),

    /*
    |--------------------------------------------------------------------------
    | Feedback-exempt instructors
    |--------------------------------------------------------------------------
    |
    | Temporary carve-out: these instructors are paid without a filed report.
    | Carried over from the legacy FEEDBACK_EXEMPT_TEACHER_IDS constant, which
    | held legacy user IDs 66, 67 and 82 — the importer maps those to new IDs,
    | so set this to the NEW ids after importing, or leave empty to enforce the
    | rule for everyone.
    |
    */

    'feedback_exempt_instructor_ids' => array_filter(
        array_map('intval', explode(',', (string) env('FEEDBACK_EXEMPT_INSTRUCTOR_IDS', '')))
    ),

    /*
    |--------------------------------------------------------------------------
    | Payout week
    |--------------------------------------------------------------------------
    |
    | The payout week runs Saturday -> Friday inclusive, in the app timezone.
    | `week_starts_on` is an ISO-8601 day number (1=Mon .. 7=Sun), so 6=Saturday.
    |
    | The legacy code had two contradictory definitions of this window — an
    | "Sun 12:00 -> Sat 23:00" variant in getPayoutWindowForDate() and a
    | "Sat 00:00 -> Fri 23:59" variant in getCurrentPayoutWindow(). The
    | Saturday->Friday one governs the earnings report the instructors are
    | actually paid from, so it is the one kept here.
    |
    */

    'payout' => [
        'week_starts_on' => 6,
    ],

    /*
    |--------------------------------------------------------------------------
    | Session durations offered
    |--------------------------------------------------------------------------
    */

    'learning_times' => [20, 25, 30, 45, 60],

];
