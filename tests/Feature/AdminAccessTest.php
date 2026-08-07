<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin area is admin-only, and reachable by an admin.
 *
 * The legacy router checked roles inline and answered a mismatch with a 403 JSON
 * body regardless of what the client asked for; these pin the behaviour down.
 */
class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function adminRoutes(): array
    {
        return [
            'dashboard' => ['admin.dashboard'],
            'instructors' => ['admin.instructors.index'],
            'students' => ['admin.students.index'],
            'enrolments' => ['admin.enrollments.index'],
            'classes' => ['admin.classes.index'],
            'payouts' => ['admin.payouts.index'],
        ];
    }

    #[Test]
    #[DataProvider('adminRoutes')]
    public function a_guest_is_sent_to_the_login_form(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    #[Test]
    #[DataProvider('adminRoutes')]
    public function an_instructor_is_forbidden(string $route): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)->get(route($route))->assertForbidden();
    }

    #[Test]
    #[DataProvider('adminRoutes')]
    public function a_student_is_forbidden(string $route): void
    {
        $student = User::factory()->student()->create();

        $this->actingAs($student)->get(route($route))->assertForbidden();
    }

    #[Test]
    #[DataProvider('adminRoutes')]
    public function an_admin_can_open_it(string $route): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route($route))
            ->assertOk()
            // A directive inside a component attribute is never compiled, so a
            // page can be 200 and still show its own source to the user.
            ->assertDontSee('@money');
    }

    #[Test]
    public function an_admin_lands_on_the_admin_dashboard(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/')->assertRedirect(route('admin.dashboard'));
    }

    #[Test]
    public function an_admin_may_also_use_the_instructor_area(): void
    {
        // Admins support instructors, so they are allowed in — the instructor
        // routes are declared `role:instructor,admin`.
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('instructor.dashboard'))->assertOk();
    }

    #[Test]
    public function a_json_client_gets_json_on_a_role_mismatch(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->getJson(route('admin.dashboard'))
            ->assertForbidden()
            ->assertJsonPath('success', false);
    }
}
