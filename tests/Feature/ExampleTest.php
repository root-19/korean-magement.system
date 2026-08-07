<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Routing and access control for the entry points.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_root_url_shows_a_guest_the_landing_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('저스트텐미닛 전화, 화상영어')
            ->assertSee('Get Started');
    }

    #[Test]
    public function the_login_form_renders_for_a_guest(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Email or username');
    }

    #[Test]
    public function the_root_url_sends_an_instructor_to_their_dashboard(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->get('/')
            ->assertRedirect(route('instructor.dashboard'));
    }

    #[Test]
    public function instructor_pages_are_closed_to_guests(): void
    {
        $this->get(route('instructor.dashboard'))->assertRedirect(route('login'));
    }

    #[Test]
    public function instructor_pages_are_closed_to_students(): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)
            ->get(route('instructor.dashboard'))
            ->assertForbidden();
    }

    #[Test]
    public function a_deactivated_account_is_signed_out_on_its_next_request(): void
    {
        $instructor = User::factory()->instructor()->inactive()->create();

        $this->actingAs($instructor)
            ->get(route('instructor.dashboard'))
            ->assertForbidden();

        $this->assertGuest();
    }
}
