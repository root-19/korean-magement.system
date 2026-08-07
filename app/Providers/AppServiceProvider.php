<?php

namespace App\Providers;

use App\Support\Navigation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Fail loudly in development on N+1 queries and mass-assignment typos
        // rather than shipping them. The legacy app rendered tables inside
        // per-row query loops; this makes that a visible error, not a slow page.
        Model::preventLazyLoading($this->app->isLocal());
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());

        // Shared hosting terminates TLS upstream, so asset() must not downgrade
        // to http:// once APP_URL is https.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        $this->shareNavigation();
        $this->registerBladeDirectives();
    }

    /**
     * The app layout needs $navigation on every render; a composer keeps that
     * out of every controller.
     */
    private function shareNavigation(): void
    {
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();

            $view->with('navigation', $user ? Navigation::prune(Navigation::for($user)) : []);
        });
    }

    private function registerBladeDirectives(): void
    {
        // @money(79.17) => "₱79", @money2(79.17) => "₱79.17". Both delegate to the
        // helpers in app/Support/money.php, which is also what component
        // attributes must use — a directive inside `value="…"` is never compiled.
        Blade::directive('money', function (string $expression) {
            return "<?php echo money({$expression}); ?>";
        });

        Blade::directive('money2', function (string $expression) {
            return "<?php echo money2({$expression}); ?>";
        });
    }
}
