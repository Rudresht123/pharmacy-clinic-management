<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Models\Platform\Organization;
use App\Models\Tenant\Location;
use App\Support\Guides\GuideLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TenantTestCase;

/**
 * The Guide section.
 *
 * What is asserted: the folder is the list — every guide in it is offered,
 * titled and summarised from its own page, and nothing else is; a guide opens
 * and its PDF downloads; a name that is not a guide, or that tries to be a
 * path, finds nothing; anybody signed in may read them and nobody else may;
 * and every guide shipped in docs/guides has its PDF built beside it.
 */
class GuideTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private int $branch;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = storage_path('framework/testing/guides-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($this->folder);

        File::put($this->folder.'/billing-basics.html', '<html><head><title>Billing basics</title>'
            .'<meta name="description" content="How a bill is drawn &amp; paid."></head><body><h1>Billing</h1></body></html>');
        File::put($this->folder.'/billing-basics.pdf', '%PDF-1.4 billing');
        File::put($this->folder.'/appointments.html', '<html><head><title>Appointments</title></head><body>Queue</body></html>');
        // Not a guide: its name is not a slug.
        File::put($this->folder.'/Draft Notes.html', '<title>Draft</title>');

        $this->app->instance(GuideLibrary::class, new GuideLibrary($this->folder));

        $this->organization = $this->provisionOrganization('GD');

        $this->branch = $this->onTenant($this->organization, fn () => Location::query()->value('id')
            ?? Location::create(['name' => 'Main', 'code' => 'MAIN', 'type' => Location::CLINIC, 'is_active' => true])->id);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    private function read(string $uri)
    {
        return $this->withHeader('X-Branch-Id', (string) $this->branch)->getJson('/api/v1/tenant/'.$uri);
    }

    public function test_every_guide_in_the_folder_is_listed_from_its_own_page(): void
    {
        $this->signInAsOwner($this->organization);

        $guides = $this->read('guides')->assertOk()->json('data');

        $this->assertSame(['Appointments', 'Billing basics'], array_column($guides, 'title'));
        $this->assertSame(['appointments', 'billing-basics'], array_column($guides, 'slug'));
        $this->assertSame('How a bill is drawn & paid.', $guides[1]['description']);
        $this->assertSame([false, true], array_column($guides, 'has_pdf'));
    }

    public function test_a_guide_opens_and_its_pdf_downloads(): void
    {
        $this->signInAsOwner($this->organization);

        $this->assertStringContainsString('<h1>Billing</h1>', $this->read('guides/billing-basics')->assertOk()->json('data.html'));

        $pdf = $this->withHeader('X-Branch-Id', (string) $this->branch)->get('/api/v1/tenant/guides/billing-basics/pdf');
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));

        // No PDF built for this one.
        $this->read('guides/appointments/pdf')->assertNotFound();
    }

    public function test_a_name_that_is_not_a_guide_finds_nothing(): void
    {
        $this->signInAsOwner($this->organization);

        $this->read('guides/no-such-guide')->assertNotFound();
        $this->read('guides/Draft%20Notes')->assertNotFound();
        $this->read('guides/..%2F..%2F.env')->assertNotFound();
        $this->read('guides/..%2Fbilling-basics')->assertNotFound();
    }

    public function test_anybody_signed_in_may_read_them_and_nobody_else(): void
    {
        $this->read('guides')->assertUnauthorized();

        // A member of the branch holding no capability at all.
        $this->placeStaffAt($this->organization, $this->branch);
        $this->setStaffCapabilities($this->organization, []);
        $this->signInAsStaff($this->organization);

        $this->read('guides')->assertOk();
        $this->read('guides/billing-basics')->assertOk();
    }

    public function test_every_guide_shipped_has_its_pdf_built_and_a_summary(): void
    {
        $shipped = (new GuideLibrary)->all();

        $this->assertNotEmpty($shipped, 'docs/guides has no guides.');

        foreach ($shipped as $guide) {
            $this->assertTrue($guide['has_pdf'], "Run `php docs/guides/build.php` — {$guide['slug']} has no PDF.");
            $this->assertNotSame('', $guide['description'], "{$guide['slug']} has no description meta.");
        }
    }
}
