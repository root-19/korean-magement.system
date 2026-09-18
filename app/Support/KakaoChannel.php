<?php

namespace App\Support;

/**
 * The academy's KakaoTalk channel, and the three things a visitor can do with it.
 *
 * Korean students are reached over KakaoTalk, not email: `kakaotalk_id` is asked
 * for on every enrolment and is required on the public booking form. Until now
 * it was only ever stored and printed, so the academy could start a conversation
 * and the student could not.
 *
 * Two levels of configuration, because the channel id is the part that matters:
 *
 *   channel id only     every button is a plain pf.kakao.com link that works
 *                       with JavaScript off and no SDK at all;
 *   + JavaScript key    the Kakao SDK opens the same action in place, and the
 *                       link stays as the fallback when the CDN is blocked.
 *
 * Nothing renders with neither, so an unconfigured install shows no dead button.
 */
final class KakaoChannel
{
    /**
     * What a button can do.
     *
     *   chat    open a 1:1 chat with the channel — the one that answers questions
     *   add     add the channel, so the academy can message the student
     *   follow  same, through the API; needs the visitor signed in with Kakao
     *           Login, which this app does not do, so it falls back to the link
     *
     * @var array<string, string>
     */
    private const PATHS = [
        'chat' => '/chat',
        'add' => '',
        'follow' => '',
    ];

    /** Kakao's own wording for each action, in the language the channel uses. */
    private const LABELS = [
        'chat' => 'KakaoTalk 상담',
        'add' => '채널 추가',
        'follow' => '채널 추가',
    ];

    public static function publicId(): ?string
    {
        $id = trim((string) config('services.kakao.channel_id'));

        return $id === '' ? null : $id;
    }

    public static function javascriptKey(): ?string
    {
        $key = trim((string) config('services.kakao.javascript_key'));

        return $key === '' ? null : $key;
    }

    /** Whether any button can be drawn at all. */
    public static function isConfigured(): bool
    {
        return self::publicId() !== null;
    }

    /** Whether the SDK can take over from the plain links. */
    public static function isEnhanced(): bool
    {
        return self::isConfigured() && self::javascriptKey() !== null;
    }

    /**
     * Where the button points without JavaScript.
     *
     * `add` and `follow` land on the channel home, which carries Kakao's own add
     * control — a fallback that is certain to exist beats guessing at a deeper
     * path that may 404 on the day someone turns the SDK off.
     */
    public static function url(string $action): ?string
    {
        $id = self::publicId();

        if ($id === null || ! isset(self::PATHS[$action])) {
            return null;
        }

        return 'https://pf.kakao.com/'.rawurlencode($id).self::PATHS[$action];
    }

    public static function label(string $action): string
    {
        return self::LABELS[$action] ?? self::LABELS['chat'];
    }
}
