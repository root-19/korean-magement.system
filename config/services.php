<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * KakaoTalk channel. Korean students are reached over KakaoTalk rather than
     * email — `student_profiles.kakaotalk_id` is asked for on every enrolment
     * and the public booking form makes it required — but nothing linked the
     * two ends together, so a prospect with a question had no way to start one.
     *
     * `channel_id` is the channel's PUBLIC ID, the `_xxxxx` handle from Kakao
     * Business, and it alone is enough: the buttons fall back to pf.kakao.com
     * links that need no SDK. `javascript_key` is the browser key from the Kakao
     * developer app; with it the SDK opens the chat in place instead. It is a
     * public, browser-side key by design — the REST and Admin keys are secrets
     * and must never appear here.
     */
    'kakao' => [
        'javascript_key' => env('KAKAO_JAVASCRIPT_KEY'),
        'channel_id' => env('KAKAO_CHANNEL_ID'),

        /*
         * Kakao Login, used for one thing: letting an instructor send their own
         * roster to their own KakaoTalk. The JS SDK cannot do it alone — v2
         * dropped the popup login, so `authorize` is a redirect and the code it
         * returns has to be exchanged here, with the REST key. That key IS a
         * secret, unlike javascript_key above.
         *
         * redirect_uri defaults to the callback route, so there is one source of
         * truth; Kakao matches it character for character against the console.
         */
        'rest_key' => env('KAKAO_REST_API_KEY'),
        'client_secret' => env('KAKAO_CLIENT_SECRET'),
        'redirect_uri' => env('KAKAO_REDIRECT_URI'),
    ],

];
