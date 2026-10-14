<?php

namespace Tests\Feature\Api\V1\Website;

use App\Models\Platform\WebsiteEnquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The marketing website's demo and trial forms.
 *
 * Public and sessionless: anybody may write one enquiry, nothing comes back
 * about what is stored, and a bot filling the hidden field is turned away.
 */
class EnquiryTest extends TestCase
{
    use RefreshDatabase;

    private function enquiry(array $overrides = []): array
    {
        return [
            'type' => 'demo',
            'name' => 'Dr. Asha Rao',
            'organization_name' => 'Rao Clinic',
            'email' => 'asha@raoclinic.in',
            'phone' => '+91 98765 43210',
            'interest' => 'OPD Queue & Doctor Appointments',
            ...$overrides,
        ];
    }

    public function test_a_demo_request_is_stored_as_new(): void
    {
        $this->postJson('/api/v1/website/enquiries', $this->enquiry())
            ->assertCreated()
            ->assertJsonMissingPath('data.id');

        $stored = WebsiteEnquiry::sole();
        $this->assertSame('demo', $stored->type);
        $this->assertSame('new', $stored->status);
        $this->assertSame('Rao Clinic', $stored->organization_name);
    }

    public function test_it_needs_a_name_an_email_and_a_known_type(): void
    {
        $this->postJson('/api/v1/website/enquiries', ['type' => 'spam'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'name', 'email']);

        $this->assertSame(0, WebsiteEnquiry::count());
    }

    public function test_a_filled_honeypot_is_refused(): void
    {
        $this->postJson('/api/v1/website/enquiries', $this->enquiry(['website' => 'http://spam.example']))
            ->assertUnprocessable();

        $this->assertSame(0, WebsiteEnquiry::count());
    }
}
