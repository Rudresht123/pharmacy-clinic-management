<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Jobs\SendEmailMessage;
use App\Jobs\SendPushMessage;
use App\Jobs\SendWhatsAppMessage;
use App\Models\Platform\Organization;
use App\Models\Tenant\Campaign;
use App\Models\Tenant\CampaignRecipient;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Services\Tenant\PatientAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * Campaigns that actually go out.
 *
 * They used to stop at a "queued" row in the delivery log with nothing behind
 * it. Now each message is handed to its channel — email, WhatsApp, App
 * notifications — through the same switches the automation rules obey, and a
 * campaign that cannot send (channel off, template not approved) fails saying
 * why instead of quietly reaching nobody.
 */
class CampaignDeliveryTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $organization;

    /** @var array<string, int> */
    private array $patients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->organization = $this->provisionOrganization('CD');
        $this->grantModule($this->organization, 'communication');

        $this->patients = $this->onTenant($this->organization, fn () => [
            'asha' => Customer::on('organization')->create([
                'name' => 'Asha Rane', 'phone' => '9810000011', 'email' => 'asha@example.com', 'is_active' => true,
            ])->id,
            'ravi' => Customer::on('organization')->create([
                'name' => 'Ravi Kumar', 'phone' => '9810000012', 'is_active' => true,
            ])->id,
            'meera' => Customer::on('organization')->create([
                'name' => 'Meera Joshi', 'email' => 'meera@example.com', 'is_active' => true,
            ])->id,
        ]);

        $this->signInAsOwner($this->organization);
    }

    private function connect(string $channel, bool $on = true): void
    {
        $this->onTenant($this->organization, fn () => CommunicationChannel::forChannel($channel)
            ->forceFill(['is_connected' => $on])
            ->save());
    }

    private function template(string $channel, string $status = MessageTemplate::APPROVED): int
    {
        return (int) $this->onTenant($this->organization, fn () => MessageTemplate::query()->create([
            'channel' => $channel,
            'name' => "Health camp {$channel}",
            'category' => 'Marketing',
            'subject' => $channel === CommunicationChannel::WHATSAPP ? null : 'Free health camp',
            'content' => 'Hi {{patient_name}}, join our free health camp at {{clinic_name}}.',
            'status' => $status,
            'is_active' => true,
        ])->id);
    }

    /** @param  array<string, mixed>  $extra */
    private function campaign(string $channel, ?int $templateId, array $extra = []): int
    {
        return (int) $this->postJson('/api/v1/tenant/communication/campaigns', $extra + [
            'channel' => $channel,
            'name' => "Health camp {$channel}",
            'message_template_id' => $templateId,
        ])->assertCreated()->json('data.id');
    }

    private function campaignRow(int $id): Campaign
    {
        return $this->onTenant($this->organization, fn () => Campaign::query()->findOrFail($id));
    }

    public function test_an_email_campaign_really_sends_to_everybody_with_an_address(): void
    {
        $this->connect(CommunicationChannel::EMAIL);
        $id = $this->campaign(CommunicationChannel::EMAIL, $this->template(CommunicationChannel::EMAIL));

        Queue::fake();

        $this->postJson("/api/v1/tenant/communication/campaigns/{$id}/send")->assertOk();

        // Asha and Meera have email; Ravi does not.
        Queue::assertPushedTimes(SendEmailMessage::class, 2);
        Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job) => $job->content['to'] === 'asha@example.com'
            && $job->content['variables']['patient_name'] === 'Asha Rane'
            && $job->content['variables']['clinic_name'] === $this->organization->organization_name);

        $this->assertSame(2, $this->onTenant($this->organization, fn () => MessageLog::query()->where('campaign_id', $id)->count()));
        $this->assertSame(2, $this->onTenant($this->organization, fn () => CampaignRecipient::query()
            ->where('campaign_id', $id)->where('status', CampaignRecipient::SENT)->count()));
        $this->assertSame(Campaign::COMPLETED, $this->campaignRow($id)->status);
    }

    public function test_a_campaign_on_a_channel_that_is_not_connected_fails_and_says_so(): void
    {
        $this->connect(CommunicationChannel::EMAIL, on: false);
        $id = $this->campaign(CommunicationChannel::EMAIL, $this->template(CommunicationChannel::EMAIL));

        Queue::fake();

        $this->postJson("/api/v1/tenant/communication/campaigns/{$id}/send")->assertOk();

        Queue::assertNotPushed(SendEmailMessage::class);

        $campaign = $this->campaignRow($id);
        $this->assertSame(Campaign::FAILED, $campaign->status);
        $this->assertSame('This channel is not connected.', $campaign->failure_reason);
    }

    public function test_a_campaign_on_a_draft_template_fails_and_sends_nothing(): void
    {
        $this->connect(CommunicationChannel::EMAIL);
        $id = $this->campaign(
            CommunicationChannel::EMAIL,
            $this->template(CommunicationChannel::EMAIL, MessageTemplate::DRAFT),
        );

        Queue::fake();

        $this->postJson("/api/v1/tenant/communication/campaigns/{$id}/send")->assertOk();

        Queue::assertNotPushed(SendEmailMessage::class);
        $this->assertSame(Campaign::FAILED, $this->campaignRow($id)->status);
    }

    public function test_a_scheduled_campaign_goes_when_its_time_comes(): void
    {
        $this->connect(CommunicationChannel::EMAIL);
        $id = $this->campaign(CommunicationChannel::EMAIL, $this->template(CommunicationChannel::EMAIL));

        $at = Carbon::now()->addHour()->startOfMinute();

        $this->postJson("/api/v1/tenant/communication/campaigns/{$id}/schedule", [
            'scheduled_at' => $at->toIso8601String(),
        ])->assertOk();

        Queue::fake();

        // Not yet.
        $this->artisan('campaigns:dispatch')->assertSuccessful();
        Queue::assertNotPushed(SendEmailMessage::class);

        $this->travelTo($at->copy()->addMinute());
        $this->artisan('campaigns:dispatch')->assertSuccessful();

        Queue::assertPushedTimes(SendEmailMessage::class, 2);
    }

    public function test_a_whatsapp_campaign_goes_through_the_provider(): void
    {
        $this->connect(CommunicationChannel::WHATSAPP);
        $id = $this->campaign(CommunicationChannel::WHATSAPP, $this->template(CommunicationChannel::WHATSAPP));

        Queue::fake();

        $this->postJson("/api/v1/tenant/communication/campaigns/{$id}/send")->assertOk();

        // Asha and Ravi have phones; Meera does not.
        Queue::assertPushedTimes(SendWhatsAppMessage::class, 2);
    }

    public function test_an_app_notification_campaign_reaches_only_patients_with_the_app(): void
    {
        $this->grantModule($this->organization, 'appointments');

        // Only Asha has signed in to the patient app.
        $this->onTenant($this->organization, fn () => app(PatientAccountProvisioner::class)->open(
            Customer::query()->findOrFail($this->patients['asha']),
            ['appointments'],
        ));

        $this->connect(CommunicationChannel::PUSH);
        $id = $this->campaign(CommunicationChannel::PUSH, $this->template(CommunicationChannel::PUSH));

        Queue::fake();

        $this->postJson("/api/v1/tenant/communication/campaigns/{$id}/send")->assertOk();

        Queue::assertPushedTimes(SendPushMessage::class, 1);
        Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->content['title'] === 'Free health camp'
            && $job->content['body'] === 'Hi Asha Rane, join our free health camp at '.$this->organization->organization_name.'.'
            && $job->content['data']['type'] === 'campaign');
    }

    public function test_a_campaign_may_carry_its_own_wording_on_email(): void
    {
        $this->connect(CommunicationChannel::EMAIL);
        $id = $this->campaign(CommunicationChannel::EMAIL, null, [
            'subject' => 'Monsoon advisory',
            'content' => 'Dear {{patient_name}}, boil your water this season.',
        ]);

        Queue::fake();

        $this->postJson("/api/v1/tenant/communication/campaigns/{$id}/send")->assertOk();

        Queue::assertPushed(SendEmailMessage::class, fn (SendEmailMessage $job) => $job->content['subject'] === 'Monsoon advisory'
            && $job->content['body'] === 'Dear {{patient_name}}, boil your water this season.');
    }
}
