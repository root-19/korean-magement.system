<?php

namespace Tests\Feature;

use App\Enums\EnrollmentStatus;
use App\Models\StudentProfile;
use App\Models\StudentSchedule;
use App\Models\User;
use App\Services\Kakao\RosterMessage;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sending a day's roster to the instructor's own KakaoTalk.
 *
 * Kakao Login is a redirect, so the press and the send are two requests that
 * cannot see each other. What is asserted here is that the pressed day survives
 * the round trip, that a callback this session did not start is refused, and
 * that the feature is invisible rather than broken when no REST key is set.
 *
 * Nothing is ever sent to a student, and nothing can be: the message is built
 * from the signed-in instructor's own roster and goes to their own account.
 */
class KakaoRosterMessageTest extends TestCase
{
    use RefreshDatabase;

    private User $instructor;

    /** A Wednesday. */
    private string $today = '2026-09-16';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow($this->today.' 09:00:00');
        Carbon::setTestNow($this->today.' 09:00:00');

        $this->instructor = User::factory()->instructor()->create();

        config([
            'services.kakao.rest_key' => 'rest-key',
            'services.kakao.client_secret' => null,
            'services.kakao.redirect_uri' => null,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function student(string $name, string $time = '18:30:00'): User
    {
        $student = User::factory()->student()->create(['name' => $name]);

        StudentProfile::factory()->create([
            'user_id' => $student->id,
            'instructor_id' => $this->instructor->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 5,
            'start_date' => '2026-01-05',
        ]);

        StudentSchedule::create([
            'student_id' => $student->id,
            'day_of_week' => 3,
            'start_time' => $time,
        ]);

        return $student;
    }

    #[Test]
    public function the_button_is_hidden_until_a_rest_key_is_configured(): void
    {
        config(['services.kakao.rest_key' => null]);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertDontSee('Send my roster');
    }

    #[Test]
    public function the_endpoint_is_gone_too_when_unconfigured(): void
    {
        config(['services.kakao.rest_key' => null]);

        $this->actingAs($this->instructor)
            ->post(route('instructor.kakao.roster'))
            ->assertNotFound();
    }

    #[Test]
    public function the_dashboard_offers_the_button_once_configured(): void
    {
        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee('Send my roster');
    }

    #[Test]
    public function the_button_floats_on_every_page_not_just_the_dashboard(): void
    {
        // It lives in the app shell, so a roster checked from the class list or
        // the earnings page can be sent without navigating back.
        foreach (['instructor.classes.index', 'instructor.earnings.index'] as $route) {
            $this->actingAs($this->instructor)
                ->get(route($route))
                ->assertOk()
                ->assertSee('Send my roster');
        }
    }

    #[Test]
    public function an_admin_gets_no_send_button(): void
    {
        // Admins share the instructor routes but have no roster of their own,
        // so the button would send them an empty day.
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('Send my roster');
    }

    #[Test]
    public function pressing_it_without_a_token_asks_kakao_for_consent(): void
    {
        $response = $this->actingAs($this->instructor)
            ->post(route('instructor.kakao.roster'), ['date' => $this->today]);

        $response->assertRedirectContains('kauth.kakao.com/oauth/authorize');
        $response->assertRedirectContains('scope=talk_message');
        $response->assertRedirectContains('client_id=rest-key');

        // The pressed day is remembered for the request that comes back.
        $this->assertSame($this->today, session('kakao.intent_date'));
        $this->assertNotNull(session('kakao.state'));
    }

    #[Test]
    public function the_callback_exchanges_the_code_and_sends_the_roster(): void
    {
        $this->student('A540 Hyun Seo');

        Http::fake([
            'kauth.kakao.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 21600]),
            'kapi.kakao.com/*' => Http::response(['result_code' => 0]),
        ]);

        $this->actingAs($this->instructor)
            ->withSession([
                'kakao.state' => 'state-token',
                'kakao.intent_date' => $this->today,
            ])
            ->get(route('instructor.kakao.callback', ['code' => 'auth-code', 'state' => 'state-token']))
            ->assertRedirect(route('instructor.dashboard'))
            ->assertSessionHas('success', 'Sent to your KakaoTalk.');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'talk/memo/default/send')) {
                return false;
            }

            $template = json_decode($request['template_object'], true);

            return $template['object_type'] === 'text'
                && str_contains($template['text'], 'A540 Hyun Seo')
                && str_contains($template['text'], 'Wed, Sep 16');
        });
    }

    #[Test]
    public function a_callback_this_session_did_not_start_is_refused(): void
    {
        Http::fake();

        $this->actingAs($this->instructor)
            ->get(route('instructor.kakao.callback', ['code' => 'auth-code', 'state' => 'forged']))
            ->assertRedirect(route('instructor.dashboard'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    #[Test]
    public function a_mismatched_state_is_refused(): void
    {
        Http::fake();

        $this->actingAs($this->instructor)
            ->withSession(['kakao.state' => 'real-token', 'kakao.intent_date' => $this->today])
            ->get(route('instructor.kakao.callback', ['code' => 'auth-code', 'state' => 'other']))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    #[Test]
    public function a_refused_consent_says_so_and_sends_nothing(): void
    {
        Http::fake();

        $this->actingAs($this->instructor)
            ->withSession(['kakao.state' => 'tok', 'kakao.intent_date' => $this->today])
            ->get(route('instructor.kakao.callback', ['error' => 'access_denied', 'state' => 'tok']))
            ->assertSessionHas('error', 'KakaoTalk permission was not granted, so nothing was sent.');

        Http::assertNothingSent();
    }

    #[Test]
    public function a_missing_talk_message_permission_reaches_the_instructor(): void
    {
        // -402 is what Kakao answers while the app is not yet a business app
        // with the permission approved. It is the error that will actually
        // happen, so it has to name itself rather than read as a glitch.
        Http::fake([
            'kauth.kakao.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 21600]),
            'kapi.kakao.com/*' => Http::response(['msg' => 'insufficient scopes', 'code' => -402], 403),
        ]);

        $this->actingAs($this->instructor)
            ->withSession(['kakao.state' => 'tok', 'kakao.intent_date' => $this->today])
            ->get(route('instructor.kakao.callback', ['code' => 'c', 'state' => 'tok']))
            ->assertRedirect(route('instructor.dashboard'));

        $this->assertStringContainsString('insufficient scopes', session('error'));
        $this->assertStringContainsString('-402', session('error'));

        // The rejected token is dropped, so the next press asks Kakao again.
        $this->assertNull(session('kakao.access_token'));
    }

    #[Test]
    public function a_live_token_sends_straight_away_without_a_second_consent(): void
    {
        $this->student('A540 Hyun Seo');

        Http::fake(['kapi.kakao.com/*' => Http::response(['result_code' => 0])]);

        $this->actingAs($this->instructor)
            ->withSession([
                'kakao.access_token' => 'tok',
                'kakao.expires_at' => now()->addHour()->getTimestamp(),
            ])
            ->post(route('instructor.kakao.roster'), ['date' => $this->today])
            ->assertRedirect(route('instructor.dashboard'))
            ->assertSessionHas('success');

        Http::assertSentCount(1);
    }

    #[Test]
    public function an_expired_token_asks_for_consent_again(): void
    {
        Http::fake();

        $this->actingAs($this->instructor)
            ->withSession([
                'kakao.access_token' => 'tok',
                'kakao.expires_at' => now()->subMinute()->getTimestamp(),
            ])
            ->post(route('instructor.kakao.roster'), ['date' => $this->today])
            ->assertRedirectContains('kauth.kakao.com');

        Http::assertNothingSent();
    }

    #[Test]
    public function only_the_instructors_own_roster_is_sent(): void
    {
        $this->student('A540 Mine');

        $other = User::factory()->instructor()->create();
        $theirs = User::factory()->student()->create(['name' => 'A999 Theirs']);

        StudentProfile::factory()->create([
            'user_id' => $theirs->id,
            'instructor_id' => $other->id,
            'enrollment_status' => EnrollmentStatus::Approved,
            'sessions_remaining' => 5,
            'start_date' => '2026-01-05',
        ]);

        StudentSchedule::create(['student_id' => $theirs->id, 'day_of_week' => 3, 'start_time' => '19:00:00']);

        Http::fake(['kapi.kakao.com/*' => Http::response(['result_code' => 0])]);

        $this->actingAs($this->instructor)
            ->withSession([
                'kakao.access_token' => 'tok',
                'kakao.expires_at' => now()->addHour()->getTimestamp(),
            ])
            ->post(route('instructor.kakao.roster'), ['date' => $this->today]);

        Http::assertSent(function ($request) {
            $text = json_decode($request['template_object'], true)['text'];

            return str_contains($text, 'A540 Mine') && ! str_contains($text, 'A999 Theirs');
        });
    }

    #[Test]
    public function a_long_roster_is_trimmed_to_kakaos_limit_and_counts_the_rest(): void
    {
        $roster = collect(range(1, 20))->map(fn (int $i) => [
            'student' => new User(['name' => 'A'.(500 + $i).' Student Name '.$i]),
            'time' => '18:30:00',
            'session' => null,
            'makeup_for' => null,
            'is_extra' => false,
        ]);

        $text = RosterMessage::forDay($roster, CarbonImmutable::parse($this->today), 'https://example.test')['text'];

        $this->assertLessThanOrEqual(200, mb_strlen($text));
        $this->assertStringContainsString('20 classes', $text);
        // Nothing is silently dropped: the tail says how many did not fit.
        $this->assertMatchesRegularExpression('/\+\d+ more$/', $text);
    }

    #[Test]
    public function an_empty_day_says_so_rather_than_sending_a_bare_date(): void
    {
        $text = RosterMessage::forDay(collect(), CarbonImmutable::parse($this->today), 'https://example.test')['text'];

        $this->assertStringContainsString('Wed, Sep 16', $text);
        $this->assertStringContainsString('no classes', $text);
    }
}
