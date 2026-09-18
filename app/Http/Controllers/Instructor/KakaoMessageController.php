<?php

namespace App\Http\Controllers\Instructor;

use App\Http\Controllers\Controller;
use App\Services\Kakao\KakaoException;
use App\Services\Kakao\KakaoMessenger;
use App\Services\Kakao\RosterMessage;
use App\Support\DayRoster;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * "Send today's roster to my KakaoTalk."
 *
 * One message, to the instructor's own KakaoTalk, containing their own schedule
 * — nothing is ever sent to a student. Kakao's friend messaging cannot reach
 * them anyway: its `friends` scope returns only friends who have authorised this
 * same app, and students have no account here to authorise with.
 *
 * The consent redirect and the send are two requests that cannot see each
 * other, so the pressed button is remembered in the session and replayed once
 * the token comes back. The token itself lives in the session too: it is good
 * for hours, so a second send the same afternoon skips Kakao entirely, and
 * signing out drops it with everything else.
 */
class KakaoMessageController extends Controller
{
    private const TOKEN = 'kakao.access_token';

    private const TOKEN_EXPIRES = 'kakao.expires_at';

    private const STATE = 'kakao.state';

    private const INTENT_DATE = 'kakao.intent_date';

    public function __construct(private readonly KakaoMessenger $kakao) {}

    /**
     * Send the roster, asking Kakao for permission first if we have no token.
     */
    public function store(Request $request): RedirectResponse
    {
        abort_unless(KakaoMessenger::isConfigured(), 404);

        $date = $this->resolveDate($request->input('date'));

        if ($this->token($request) !== null) {
            return $this->send($request, $date);
        }

        $state = Str::random(40);

        $request->session()->put(self::STATE, $state);
        $request->session()->put(self::INTENT_DATE, $date->toDateString());

        return redirect()->away($this->kakao->authorizeUrl($state));
    }

    /**
     * Kakao's redirect back. Exchanges the code, then finishes the send.
     */
    public function callback(Request $request): RedirectResponse
    {
        abort_unless(KakaoMessenger::isConfigured(), 404);

        $expected = $request->session()->pull(self::STATE);
        $date = $this->resolveDate($request->session()->pull(self::INTENT_DATE));

        // A callback nobody here started, or one replayed later: the state is
        // single-use and has to match the value this session sent.
        if ($expected === null || ! hash_equals($expected, (string) $request->query('state'))) {
            return $this->home()->with('error', 'That KakaoTalk sign-in did not match this session. Try again.');
        }

        if ($request->query('error') !== null) {
            return $this->home()->with(
                'error',
                'KakaoTalk permission was not granted, so nothing was sent.',
            );
        }

        $code = (string) $request->query('code');

        if ($code === '') {
            return $this->home()->with('error', 'KakaoTalk sent no authorization code back.');
        }

        try {
            $token = $this->kakao->exchangeCode($code);
        } catch (KakaoException $e) {
            return $this->home()->with('error', $e->getMessage());
        }

        $request->session()->put(self::TOKEN, $token['token']);
        $request->session()->put(self::TOKEN_EXPIRES, $token['expires_at']);

        return $this->send($request, $date);
    }

    // ---------------------------------------------------------------- internals

    private function send(Request $request, CarbonImmutable $date): RedirectResponse
    {
        $instructor = $request->user();

        $message = RosterMessage::forDay(
            DayRoster::for($instructor->id, $date),
            $date,
            route('instructor.dashboard', ['date' => $date->toDateString()]),
        );

        try {
            $this->kakao->sendToSelf((string) $this->token($request), $message);
        } catch (KakaoException $e) {
            // A rejected token is usually an expired one. Drop it so the next
            // press asks Kakao again instead of failing the same way.
            $request->session()->forget([self::TOKEN, self::TOKEN_EXPIRES]);

            return $this->home()->with('error', $e->getMessage());
        }

        return $this->home()->with('success', 'Sent to your KakaoTalk.');
    }

    /** The stored token, or null once it has expired. */
    private function token(Request $request): ?string
    {
        $token = $request->session()->get(self::TOKEN);
        $expires = (int) $request->session()->get(self::TOKEN_EXPIRES);

        if ($token === null || $expires <= now()->getTimestamp()) {
            return null;
        }

        return (string) $token;
    }

    private function home(): RedirectResponse
    {
        return redirect()->route('instructor.dashboard');
    }

    private function resolveDate(mixed $raw): CarbonImmutable
    {
        try {
            return $raw ? CarbonImmutable::parse((string) $raw)->startOfDay() : CarbonImmutable::today();
        } catch (\Throwable) {
            return CarbonImmutable::today();
        }
    }
}
