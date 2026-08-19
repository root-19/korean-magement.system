<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\InstructorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Creating an instructor account from the admin area.
 */
class AdminInstructorCreateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    #[Test]
    public function an_admin_can_open_the_create_form(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.instructors.create'))
            ->assertOk()
            ->assertSee('Add Instructor');
    }

    #[Test]
    public function an_admin_can_create_an_instructor(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.instructors.store'), [
            'name' => 'Jane Teacher',
            'email' => 'jane@example.com',
            'phone' => '09171234567',
            'bank_name' => 'BDO',
            'bank_account' => '1234567890',
        ]);

        $instructor = User::where('email', 'jane@example.com')->firstOrFail();

        $response->assertRedirect(route('admin.instructors.show', $instructor));
        $response->assertSessionHas('success');

        $this->assertSame(Role::Instructor, $instructor->role);
        $this->assertTrue($instructor->is_active);

        $profile = InstructorProfile::where('user_id', $instructor->id)->firstOrFail();
        $this->assertSame('BDO', $profile->bank_name);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'instructor.created',
            'auditable_id' => $instructor->id,
        ]);
    }

    #[Test]
    public function an_instructor_can_be_created_without_an_email(): void
    {
        $this->actingAs($this->admin)->post(route('admin.instructors.store'), [
            'name' => 'Nameless Teacher',
        ])->assertRedirect();

        $instructor = User::where('name', 'Nameless Teacher')->firstOrFail();

        $this->assertNull($instructor->email);
        $this->assertSame(Role::Instructor, $instructor->role);
    }

    #[Test]
    public function an_admin_can_set_the_instructors_password(): void
    {
        $this->actingAs($this->admin)->post(route('admin.instructors.store'), [
            'name' => 'Chosen Password Teacher',
            'password' => 'correct-horse',
        ])->assertRedirect();

        $instructor = User::where('name', 'Chosen Password Teacher')->firstOrFail();

        $this->assertTrue(Hash::check('correct-horse', $instructor->password));
    }

    #[Test]
    public function a_chosen_password_must_be_at_least_eight_characters(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.instructors.store'), [
                'name' => 'Short Password Teacher',
                'password' => 'short',
            ])
            ->assertSessionHasErrors('password');
    }

    #[Test]
    public function creating_an_instructor_requires_a_name(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.instructors.store'), ['email' => 'no-name@example.com'])
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function an_instructor_cannot_create_another_instructor(): void
    {
        $instructor = User::factory()->instructor()->create();

        $this->actingAs($instructor)
            ->post(route('admin.instructors.store'), ['name' => 'Should Fail'])
            ->assertForbidden();
    }
}
