<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the admin pages in the language the admin picked, kept in the session.
 *
 * Applied to the admin route group only, so instructor and student pages stay
 * in English whatever an admin chose. Carbon follows the app locale, so dates
 * written with translatedFormat() switch language too.
 */
class SetAdminLocale
{
    public const SESSION_KEY = 'admin_locale';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get(self::SESSION_KEY);

        if (is_string($locale) && array_key_exists($locale, config('app.admin_locales', []))) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
