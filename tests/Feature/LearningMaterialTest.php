<?php

namespace Tests\Feature;

use App\Models\LearningMaterial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Learning materials: an admin posts a PDF, instructors download it.
 *
 * The file is stored on the private `local` disk and handed out by a controller
 * action, so the access rules are code, not web-server configuration — which is
 * what these assert. A draft must not leak to an instructor, and the file must
 * not outlive the row.
 */
class LearningMaterialTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(LearningMaterial::DISK);

        $this->admin = User::factory()->admin()->create();
        $this->instructor = User::factory()->instructor()->create();
    }

    private function pdf(string $name = 'grammar.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'application/pdf');
    }

    /**
     * A material written straight to the database and the fake disk.
     *
     * Used by the tests about reading and access, deliberately not the admin
     * endpoint: posting leaves a success flash naming the material, which the
     * next page would render and defeat an assertDontSee.
     */
    private function makeMaterial(bool $published, string $title, string $name = 'file.pdf'): LearningMaterial
    {
        $path = LearningMaterial::DIRECTORY.'/'.md5($title).'.pdf';

        Storage::disk(LearningMaterial::DISK)->put($path, '%PDF-1.4 fake');

        return LearningMaterial::create([
            'title' => $title,
            'description' => 'Ten pages of drills for the B1 group.',
            'file_path' => $path,
            'original_name' => $name,
            'file_size' => 1024,
            'uploaded_by' => $this->admin->id,
            'is_published' => $published,
            'published_at' => $published ? now() : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Grammar drills — present perfect',
            'description' => 'Ten pages of drills for the B1 group.',
            'file' => $this->pdf(),
        ], $overrides);
    }

    // ----------------------------------------------------------------- posting

    #[Test]
    public function an_admin_can_post_a_material_with_a_pdf(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload(['is_published' => 1]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $material = LearningMaterial::firstOrFail();

        $this->assertSame('Grammar drills — present perfect', $material->title);
        $this->assertSame('grammar.pdf', $material->original_name);
        $this->assertTrue($material->is_published);
        $this->assertNotNull($material->published_at);
        $this->assertSame($this->admin->id, $material->uploaded_by);

        Storage::disk(LearningMaterial::DISK)->assertExists($material->file_path);

        // Stored under its own directory, and never in a publicly served one.
        $this->assertStringStartsWith(LearningMaterial::DIRECTORY.'/', $material->file_path);
    }

    #[Test]
    public function a_material_posted_without_publishing_is_a_draft(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $material = LearningMaterial::firstOrFail();

        $this->assertFalse($material->is_published);
        $this->assertNull($material->published_at);
    }

    #[Test]
    public function a_title_and_a_file_are_both_required(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), ['description' => 'No title, no file'])
            ->assertSessionHasErrors(['title', 'file']);

        $this->assertSame(0, LearningMaterial::count());
    }

    #[Test]
    public function a_file_that_is_not_a_pdf_is_refused(): void
    {
        // Including one renamed to .pdf: the rule checks the reported type too.
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload([
                'file' => UploadedFile::fake()->create('payload.pdf', 10, 'application/x-msdownload'),
            ]))
            ->assertSessionHasErrors('file');

        $this->assertSame(0, LearningMaterial::count());
        $this->assertEmpty(Storage::disk(LearningMaterial::DISK)->allFiles());
    }

    #[Test]
    public function an_instructor_cannot_post_a_material(): void
    {
        $this->actingAs($this->instructor)
            ->post(route('admin.materials.store'), $this->payload())
            ->assertForbidden();

        $this->assertSame(0, LearningMaterial::count());
    }

    // -------------------------------------------------------------- publishing

    #[Test]
    public function publishing_and_unpublishing_flips_visibility(): void
    {
        $this->actingAs($this->admin)->post(route('admin.materials.store'), $this->payload());

        $material = LearningMaterial::firstOrFail();

        $this->actingAs($this->admin)
            ->patch(route('admin.materials.published', $material))
            ->assertRedirect();

        $this->assertTrue($material->refresh()->is_published);
        $this->assertNotNull($material->published_at);

        $this->actingAs($this->admin)->patch(route('admin.materials.published', $material));

        $this->assertFalse($material->refresh()->is_published);
        $this->assertNull($material->published_at);
    }

    // --------------------------------------------------------- what each role sees

    #[Test]
    public function an_instructor_sees_published_materials_but_not_drafts(): void
    {
        $this->makeMaterial(published: true, title: 'Published resource');
        $this->makeMaterial(published: false, title: 'Draft resource');

        $this->actingAs($this->instructor)
            ->get(route('instructor.materials.index'))
            ->assertOk()
            ->assertSee('Published resource')
            ->assertDontSee('Draft resource');
    }

    #[Test]
    public function an_admin_sees_both_on_their_own_page(): void
    {
        $this->makeMaterial(published: false, title: 'Draft resource');

        $this->actingAs($this->admin)
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee('Draft resource')
            ->assertSee('Draft');
    }

    #[Test]
    public function the_instructor_page_is_closed_to_students(): void
    {
        $this->actingAs(User::factory()->student()->create())
            ->get(route('instructor.materials.index'))
            ->assertForbidden();
    }

    // -------------------------------------------------------------- downloading

    #[Test]
    public function an_instructor_can_download_a_published_material(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload(['is_published' => 1]));

        $material = LearningMaterial::firstOrFail();

        $response = $this->actingAs($this->instructor)
            ->get(route('instructor.materials.download', $material))
            ->assertOk();

        // Served under the name that was uploaded, not the hashed one on disk.
        $this->assertStringContainsString('grammar.pdf', $response->headers->get('content-disposition'));
    }

    #[Test]
    public function an_instructor_cannot_download_a_draft(): void
    {
        $this->actingAs($this->admin)->post(route('admin.materials.store'), $this->payload());

        $material = LearningMaterial::firstOrFail();

        $this->actingAs($this->instructor)
            ->get(route('instructor.materials.download', $material))
            ->assertNotFound();

        // The admin who owns it still can.
        $this->actingAs($this->admin)
            ->get(route('instructor.materials.download', $material))
            ->assertOk();
    }

    #[Test]
    public function a_guest_cannot_download_anything(): void
    {
        $material = $this->makeMaterial(published: true, title: 'Published resource');

        // The PDF is outside the web root, so this route is the only way in.
        $this->get(route('instructor.materials.download', $material))
            ->assertRedirect(route('login'));
    }

    // ----------------------------------------------------------------- deleting

    #[Test]
    public function deleting_a_material_removes_its_file_from_disk(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload(['is_published' => 1]));

        $material = LearningMaterial::firstOrFail();
        $path = $material->file_path;

        $this->actingAs($this->admin)
            ->delete(route('admin.materials.destroy', $material))
            ->assertRedirect();

        $this->assertSame(0, LearningMaterial::count());

        // An orphaned PDF on disk belongs to nobody and is never cleaned up.
        Storage::disk(LearningMaterial::DISK)->assertMissing($path);
    }

    #[Test]
    public function an_instructor_cannot_delete_a_material(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.materials.store'), $this->payload(['is_published' => 1]));

        $material = LearningMaterial::firstOrFail();

        $this->actingAs($this->instructor)
            ->delete(route('admin.materials.destroy', $material))
            ->assertForbidden();

        $this->assertSame(1, LearningMaterial::count());
        Storage::disk(LearningMaterial::DISK)->assertExists($material->file_path);
    }

    // -------------------------------------------------------------------- menus

    #[Test]
    public function both_sidebars_link_to_their_materials_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.materials.index'), false);

        $this->actingAs($this->instructor)
            ->get(route('instructor.dashboard'))
            ->assertOk()
            ->assertSee(route('instructor.materials.index'), false);
    }
}
