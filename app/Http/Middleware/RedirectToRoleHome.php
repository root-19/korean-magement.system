<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an authenticated user to the dashboard for their role.
 *
 * Used on `/` and on the login page so each role lands somewhere useful instead
 * of on a shared page that then has to branch.
 */
class RedirectToRoleHome
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            return redirect()->to(self::homeFor($user));
        }

        return $next($request);
    }

    /**
     * Resolves against the first registered route for the role.
     *
     * The candidate lists are ordered by preference and filtered on
     * Route::has(), so the admin area can be built out later without this
     * throwing RouteNotFoundException in the meantime — an admin currently
     * lands on the instructor dashboard, which they are authorised for.
     */
    public static function homeFor(User $user): string
    {
        $candidates = match ($user->role){
            Role::Admin => ['admin.dashboard'],
            Role::Instructor => ['instructor.dashboard'],
            Role::Student => ['student.dashboard', 'login'],
        };

        foreach ($candidates as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return url('/');
    }
}
