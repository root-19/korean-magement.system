<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate a route on the user's role: `->middleware('role:instructor,admin')`.
 *
 * The legacy equivalent lived inline in router.php, where a role mismatch
 * responded with a 403 JSON body regardless of what the client asked for — so a
 * browser hitting a forbidden page got raw JSON. This negotiates the response.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $this->deny($request, 'Please sign in to continue.', 401);
        }

        // An account switched off mid-session must not keep its access.
        if (! $user->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->deny($request, 'This account has been deactivated.', 403);
        }

        $allowed = array_filter(array_map(fn (string $r) => Role::tryFrom($r), $roles));

        if ($allowed !== [] && ! in_array($user->role, $allowed, true)) {
            return $this->deny($request, 'You do not have access to this page.', 403);
        }

        return $next($request);
    }

    protected function deny(Request $request, string $message, int $status): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        if ($status === 401) {
            return redirect()->guest(route('login'));
        }

        abort($status, $message);
    }
}
