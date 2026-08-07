<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RedirectToRoleHome;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session authentication.
 *
 * The legacy AuthController hand-rolled all of this: raw $_POST reads, a manual
 * password_verify, its own `user_sessions` table with browser fingerprinting,
 * and no rate limiting or CSRF protection. Laravel's guard, the framework
 * session and a throttle replace the lot.
 *
 * Login accepts either an email address or the account name, because 222 of the
 * 256 legacy accounts have no email at all — students are enrolled by their
 * instructor and identified by a name like "A540 Hyun Seo".
 */
class LoginController extends Controller
{
    /** Attempts allowed per minute, per identifier+IP pair. */
    private const MAX_ATTEMPTS = 5;

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], [], [
            'login' => 'email or username',
        ]);

        $this->ensureIsNotRateLimited($request);

        $user = $this->resolveUser($credentials['login']);

        if ($user === null || ! Auth::attempt(
            ['id' => $user->id, 'password' => $credentials['password']],
            $request->boolean('remember')
        )) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'login' => 'Those credentials do not match our records.',
            ]);
        }

        if (! $user->is_active) {
            Auth::logout();

            throw ValidationException::withMessages([
                'login' => 'This account has been deactivated. Contact an administrator.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        // Rotate the session id so a pre-login session cannot be replayed.
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(RedirectToRoleHome::homeFor($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'You have been signed out.');
    }

    /**
     * Look the account up by email, falling back to an exact name match.
     *
     * Name is not a unique column — the live data has two colliding names — so
     * a duplicate must not hand back an arbitrary account. Returning null makes
     * it fail closed, and those users sign in with their email.
     */
    protected function resolveUser(string $login): ?User
    {
        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return User::where('email', $login)->first();
        }

        $matches = User::where('name', $login)->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    protected function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'login' => "Too many attempts. Try again in {$seconds} seconds.",
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        return 'login|'.mb_strtolower((string) $request->input('login')).'|'.$request->ip();
    }
}
