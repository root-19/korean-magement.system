<?php

namespace App\Services\Kakao;

use Illuminate\Support\Facades\Http;

/**
 * Kakao Login and the "send a message to me" API, from the server.
 *
 * Kakao's own sample does this in the browser: `Kakao.Auth.authorize()`, then
 * `Kakao.API.request('/v2/api/talk/memo/default/send')` against a token read out
 * of a cookie. That cookie is set by Kakao's demo server, not by the SDK — v2
 * removed the popup login, so `authorize` is a full redirect and the `code` it
 * hands back has to be exchanged for a token somewhere. Doing the exchange and
 * the send here keeps the REST key and the access token out of the browser
 * entirely, and the flow works with JavaScript off like the rest of the app.
 *
 * Every call needs a Kakao BUSINESS app with the `talk_message` permission
 * approved. Until it is, Kakao answers -402 for anyone outside the app's own
 * team, which is why `isConfigured()` gates the button rather than letting an
 * instructor press it and get an error.
 */
final class KakaoMessenger
{
    private const AUTHORIZE_URL = 'https://kauth.kakao.com/oauth/authorize';

    private const TOKEN_URL = 'https://kauth.kakao.com/oauth/token';

    private const MEMO_URL = 'https://kapi.kakao.com/v2/api/talk/memo/default/send';

    /** Sending a message to oneself needs this one permission and no more. */
    private const SCOPE = 'talk_message';

    /** Seconds shaved off a token's life, so one is never used as it expires. */
    private const EXPIRY_MARGIN = 30;

    public static function isConfigured(): bool
    {
        return self::restKey() !== null;
    }

    public static function restKey(): ?string
    {
        $key = trim((string) config('services.kakao.rest_key'));

        return $key === '' ? null : $key;
    }

    /**
     * Where Kakao sends the visitor back.
     *
     * Configurable because Kakao compares it literally with the console entry,
     * and a deployment behind a proxy can disagree with APP_URL about its own
     * address. Defaults to the route so there is nothing to keep in step.
     */
    public static function redirectUri(): string
    {
        $configured = trim((string) config('services.kakao.redirect_uri'));

        return $configured === '' ? route('instructor.kakao.callback') : $configured;
    }

    /** The consent screen. `$state` comes back untouched and is checked there. */
    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => self::restKey(),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
        ]);
    }

    /**
     * Trade the authorization code for an access token.
     *
     * @return array{token: string, expires_at: int}
     */
    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, array_filter([
            'grant_type' => 'authorization_code',
            'client_id' => self::restKey(),
            'client_secret' => trim((string) config('services.kakao.client_secret')) ?: null,
            'redirect_uri' => self::redirectUri(),
            'code' => $code,
        ]));

        $token = (string) $response->json('access_token');

        if ($response->failed() || $token === '') {
            throw KakaoException::from(
                'sign-in',
                $response->json('error_description') ?? $response->json('error'),
                $response->json('error_code'),
            );
        }

        return [
            'token' => $token,
            'expires_at' => now()->addSeconds(
                max(0, (int) $response->json('expires_in', 0) - self::EXPIRY_MARGIN)
            )->getTimestamp(),
        ];
    }

    /**
     * Send one message to the account that authorised — nobody else.
     *
     * @param  array<string, mixed>  $templateObject
     */
    public function sendToSelf(string $accessToken, array $templateObject): void
    {
        $response = Http::withToken($accessToken)
            ->asForm()
            ->post(self::MEMO_URL, [
                // Kakao wants the template as a JSON string in a form field,
                // not as a nested form payload.
                'template_object' => json_encode($templateObject, JSON_UNESCAPED_UNICODE),
            ]);

        if ($response->failed()) {
            throw KakaoException::from('send', $response->json('msg'), $response->json('code'));
        }
    }
}
