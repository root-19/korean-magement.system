<?php

namespace Tests\Feature;

use App\Http\Middleware\SetAdminLocale;
use App\Models\StudentProfile;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin pages can be switched to Korean for the session; English is the default.
 * Instructor and student pages always stay English.
 */
class AdminLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function translations(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path("$locale.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    #[DataProvider('locales')]
    public function an_admin_can_switch_the_admin_pages_language(string $locale): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.dashboard'))
            ->post(route('admin.locale'), ['locale' => $locale])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas(SetAdminLocale::SESSION_KEY, $locale);

        $text = $this->translations($locale);

        $this->get(route('admin.attendance-audit.index'))
            ->assertOk()
            ->assertSee('lang="'.$locale.'"', false)
            ->assertSee($text['Audit System'])
            ->assertSee($text['Total earnings'])
            ->assertSee($text['Logout']);
    }

    public static function locales(): array
    {
        return ['Korean' => ['ko']];
    }

    #[Test]
    public function english_is_the_default_and_can_be_switched_back_to(): void
    {
        $this->actingAs($this->admin)
            ->withSession([SetAdminLocale::SESSION_KEY => 'ko'])
            ->post(route('admin.locale'), ['locale' => 'en']);

        $this->get(route('admin.attendance-audit.index'))
            ->assertSee('lang="en"', false)
            ->assertSee('Total earnings');
    }

    #[Test]
    public function an_unknown_language_is_refused(): void
    {
        foreach (['fr', 'tl'] as $locale) {
            $this->actingAs($this->admin)
                ->post(route('admin.locale'), ['locale' => $locale])
                ->assertSessionHasErrors('locale')
                ->assertSessionMissing(SetAdminLocale::SESSION_KEY);
        }
    }

    #[Test]
    public function instructor_pages_stay_english_whatever_the_session_says(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->post(route('admin.locale'), ['locale' => 'ko'])
            ->assertForbidden();

        $this->actingAs($instructor)
            ->withSession([SetAdminLocale::SESSION_KEY => 'ko'])
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Logout')
            ->assertDontSee('admin-locale');
    }

    #[Test]
    public function dates_follow_the_admin_language(): void
    {
        Carbon::setTestNow('2026-08-05 10:00:00');
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');

        $this->actingAs($this->admin)
            ->withSession([SetAdminLocale::SESSION_KEY => 'ko'])
            ->get(route('admin.attendance-audit.index'))
            ->assertSee('8월 1');
    }

    #[Test]
    public function every_admin_page_renders_in_korean(): void
    {
        $instructor = User::factory()->instructor()->create();
        $student = User::factory()->student()->create();
        StudentProfile::factory()->create(['user_id' => $student->id, 'instructor_id' => $instructor->id]);

        $pages = [
            route('admin.dashboard'),
            route('admin.instructors.index'),
            route('admin.instructors.create'),
            route('admin.instructors.show', $instructor),
            route('admin.students.index'),
            route('admin.students.create'),
            route('admin.students.show', $student),
            route('admin.students.edit', $student),
            route('admin.enrollments.index'),
            route('admin.deletions.index'),
            route('admin.classes.index'),
            route('admin.evaluations.index'),
            route('admin.evaluation-teachers.index'),
            route('admin.materials.index'),
            route('admin.schedules.index'),
            route('admin.bookings.index'),
            route('admin.audit.index'),
            route('admin.payouts.index'),
            route('admin.attendance-audit.index'),
        ];

        $this->actingAs($this->admin)->withSession([SetAdminLocale::SESSION_KEY => 'ko']);

        foreach ($pages as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('lang="ko"', false)
                ->assertSee('로그아웃');
        }
    }

    #[Test]
    #[DataProvider('locales')]
    public function every_admin_string_is_translated_with_the_same_placeholders(string $locale): void
    {
        $text = $this->translations($locale);
        $missing = [];
        $mismatched = [];

        foreach ($this->adminKeys() as $key) {
            if (! isset($text[$key]) || trim($text[$key]) === '') {
                $missing[] = $key;

                continue;
            }

            if ($this->placeholders($key) !== $this->placeholders($text[$key])
                || substr_count($key, '|') !== substr_count($text[$key], '|')) {
                $mismatched[] = $key;
            }
        }

        $this->assertSame([], $missing, "Missing from lang/$locale.json");
        $this->assertSame([], $mismatched, "Placeholders or plural forms differ in lang/$locale.json");
    }

    /** @return array<int, string> */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([a-z_]+)/', $text, $m);
        $found = array_unique($m[1]);
        sort($found);

        return $found;
    }

    /**
     * Every literal key the admin side asks to be translated: __() and
     * trans_choice() in admin views, shared components, the layout, enums and
     * admin controllers/services, plus the sidebar labels.
     *
     * @return array<int, string>
     */
    private function adminKeys(): array
    {
        $files = array_merge(
            glob(resource_path('views/admin/*.blade.php')),
            glob(resource_path('views/admin/*/*.blade.php')),
            glob(resource_path('views/components/*.blade.php')),
            [resource_path('views/layouts/app.blade.php')],
            glob(app_path('Enums/*.php')),
            glob(app_path('Http/Controllers/Admin/*.php')),
            [
                app_path('Services/Enrollment/EnrollmentService.php'),
                app_path('Services/Enrollment/StudentDeletionService.php'),
                app_path('Services/Enrollment/StudentUpdater.php'),
                app_path('Services/Earnings/PayoutService.php'),
            ],
        );

        $string = "'((?:[^'\\\\]|\\\\.)*)'";
        $keys = [];

        foreach ($files as $file) {
            preg_match_all('/(?:__|trans_choice)\(\s*'.$string.'/', (string) file_get_contents($file), $m);
            array_push($keys, ...$m[1]);
        }

        $navigation = (string) file_get_contents(app_path('Support/Navigation.php'));
        preg_match_all("/self::item\(\s*$string/", $navigation, $items);
        preg_match_all("/'label' => $string/", $navigation, $groups);
        array_push($keys, ...$items[1], ...$groups[1]);

        // BookingStatus and PayoutStatus label themselves with __(ucfirst($value)).
        array_push($keys, 'Pending', 'Confirmed', 'Cancelled', 'Paid');

        return array_values(array_unique(array_map(
            fn (string $key) => strtr($key, ["\\'" => "'", '\\\\' => '\\']),
            $keys,
        )));
    }
}
