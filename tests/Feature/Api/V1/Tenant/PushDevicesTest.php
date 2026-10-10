<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Jobs\SendPushMessage;
use App\Models\Platform\FirebaseDevice;
use App\Models\Platform\Organization;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageLog;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\User as TenantUser;
use App\Services\Push\DeviceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * The App notifications screen's phones: the device list, the "Send to"
 * picker, switching a phone off, and a notification typed for one person.
 *
 * The registry is in the master database and user ids repeat across
 * clinics, so every check here that matters is "only this clinic's".
 */
class PushDevicesTest extends TenantTestCase
{
    use RefreshDatabase;

    private Organization $apollo;

    private Organization $fortis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $this->apollo = $this->provisionOrganization('DA');
        $this->fortis = $this->provisionOrganization('DF');

        foreach ([$this->apollo, $this->fortis] as $organization) {
            $this->grantModule($organization, 'communication');
        }
    }

    /** A patient with an app login, as the portal makes one. */
    private function patient(Organization $organization, string $name, string $phone): int
    {
        return (int) $this->onTenant($organization, function () use ($name, $phone) {
            $customer = Customer::on('organization')->create([
                'name' => $name, 'phone' => $phone, 'code' => "P{$phone}", 'is_active' => true,
            ]);

            return TenantUser::on('organization')->create([
                'name' => $name,
                'email' => "{$phone}@patients.test",
                'password' => bcrypt('not-used'),
                'role' => TenantUser::PATIENT,
                'is_active' => true,
                'userable_type' => Customer::class,
                'userable_id' => $customer->id,
            ])->id;
        });
    }

    private function owner(Organization $organization): int
    {
        return (int) $this->onTenant($organization, fn () => TenantUser::query()
            ->where('email', $organization->email)->value('id'));
    }

    private function phone(Organization $organization, int $userId, string $token, string $platform = 'android'): void
    {
        $this->onTenant($organization, function () use ($organization, $userId, $token, $platform) {
            app(DeviceRegistry::class)->register($organization, TenantUser::query()->findOrFail($userId), [
                // Not derived from the token in any readable way, so the
                // "token never leaves" check below means something.
                'device_id' => 'phone-'.substr(md5($token), 0, 10),
                'fcm_token' => $token,
                'platform' => $platform,
                'app_version' => '1.0.5',
            ]);
        });
    }

    private function switchOn(): void
    {
        $this->onTenant($this->apollo, fn () => CommunicationChannel::forChannel(CommunicationChannel::PUSH)
            ->forceFill(['is_connected' => true])->save());
    }

    public function test_the_list_shows_this_clinics_phones_with_who_they_belong_to(): void
    {
        $rahul = $this->patient($this->apollo, 'Rahul Tiwari', '9876500041');
        $this->phone($this->apollo, $rahul, 'fcm-rahul-android-123456');
        $this->phone($this->apollo, $this->owner($this->apollo), 'fcm-owner-ios', 'ios');

        // Same user ids at the other clinic, its own phones.
        $this->phone($this->fortis, $this->owner($this->fortis), 'fcm-fortis-owner');

        $this->signInAsOwner($this->apollo);

        $response = $this->getJson('/api/v1/tenant/communication/push/devices')->assertOk();

        $this->assertSame(2, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.summary.active'));
        $this->assertSame(1, $response->json('meta.summary.android'));
        $this->assertSame(1, $response->json('meta.summary.ios'));

        $rows = collect($response->json('data'))->keyBy('user.name');

        $this->assertSame('patient', $rows['Rahul Tiwari']['user']['kind']);
        $this->assertSame('P9876500041', $rows['Rahul Tiwari']['user']['code']);
        $this->assertSame('1.0.5', $rows['Rahul Tiwari']['app_version']);
        $this->assertSame('…123456', $rows['Rahul Tiwari']['token_hint']);

        // The token itself never leaves the server.
        $this->assertStringNotContainsString('fcm-rahul-android-123456', $response->getContent());
        $this->assertStringNotContainsString('fcm-fortis-owner', $response->getContent());
    }

    public function test_the_list_filters_by_audience_platform_status_and_search(): void
    {
        $rahul = $this->patient($this->apollo, 'Rahul Tiwari', '9876500041');
        $neha = $this->patient($this->apollo, 'Neha Gupta', '9876500042');
        $this->phone($this->apollo, $rahul, 'fcm-rahul');
        $this->phone($this->apollo, $neha, 'fcm-neha', 'ios');
        $this->phone($this->apollo, $this->owner($this->apollo), 'fcm-owner');

        FirebaseDevice::query()->where('fcm_token', 'fcm-neha')->update(['is_active' => false]);

        $this->signInAsOwner($this->apollo);

        $names = fn (string $query) => collect($this->getJson("/api/v1/tenant/communication/push/devices?{$query}")
            ->assertOk()->json('data'))->pluck('user.name')->sort()->values()->all();

        $this->assertSame(['Neha Gupta', 'Rahul Tiwari'], $names('audience=patients'));
        $this->assertCount(1, $names('audience=staff'));
        $this->assertSame(['Neha Gupta'], $names('platform=ios'));
        $this->assertSame(['Neha Gupta'], $names('status=inactive'));
        $this->assertSame(['Rahul Tiwari'], $names('search=rahul'));
        $this->assertSame(['Neha Gupta'], $names('search=9876500042'));
    }

    public function test_switching_a_phone_off_stops_it_and_another_clinics_phone_cannot_be_reached(): void
    {
        $rahul = $this->patient($this->apollo, 'Rahul Tiwari', '9876500041');
        $this->phone($this->apollo, $rahul, 'fcm-rahul');
        $this->phone($this->fortis, $this->owner($this->fortis), 'fcm-fortis');

        $ours = (int) FirebaseDevice::query()->where('fcm_token', 'fcm-rahul')->value('id');
        $theirs = (int) FirebaseDevice::query()->where('fcm_token', 'fcm-fortis')->value('id');

        $this->signInAsOwner($this->apollo);

        $this->deleteJson("/api/v1/tenant/communication/push/devices/{$theirs}")->assertNotFound();
        $this->assertTrue(FirebaseDevice::query()->findOrFail($theirs)->is_active);

        $this->deleteJson("/api/v1/tenant/communication/push/devices/{$ours}")->assertOk();
        $this->assertFalse(FirebaseDevice::query()->findOrFail($ours)->is_active);
    }

    public function test_the_picker_lists_only_people_with_a_live_phone_here(): void
    {
        $rahul = $this->patient($this->apollo, 'Rahul Tiwari', '9876500041');
        $this->patient($this->apollo, 'No Phone', '9876500043');
        $this->phone($this->apollo, $rahul, 'fcm-rahul');
        $this->phone($this->fortis, $this->owner($this->fortis), 'fcm-fortis');

        $this->signInAsOwner($this->apollo);

        $people = $this->getJson('/api/v1/tenant/communication/push/recipients')->assertOk()->json('data');

        $this->assertSame(['Rahul Tiwari'], array_column($people, 'name'));
        $this->assertSame(1, $people[0]['devices']);
    }

    public function test_a_notification_to_one_person_goes_to_their_phones_and_is_logged_as_a_test(): void
    {
        Queue::fake();

        $rahul = $this->patient($this->apollo, 'Rahul Tiwari', '9876500041');
        $this->phone($this->apollo, $rahul, 'fcm-rahul');
        $this->switchOn();

        $this->signInAsOwner($this->apollo);

        $this->postJson('/api/v1/tenant/communication/push/send', [
            'user_id' => $rahul, 'title' => 'Clinic closed', 'body' => 'We are closed on Friday.',
        ])->assertOk();

        Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->content['user_ids'] === [$rahul]
            && $job->content['title'] === 'Clinic closed'
            && $job->organizationId === $this->apollo->id);

        $log = $this->onTenant($this->apollo, fn () => MessageLog::query()->sole());

        $this->assertTrue($log->is_test);
        $this->assertSame(CommunicationChannel::PUSH, $log->channel);
        $this->assertSame('Rahul Tiwari', $log->recipient_name);
    }

    public function test_sending_to_one_person_is_refused_without_a_phone_or_with_the_channel_off(): void
    {
        Queue::fake();

        $rahul = $this->patient($this->apollo, 'Rahul Tiwari', '9876500041');
        $nophone = $this->patient($this->apollo, 'No Phone', '9876500043');
        $this->phone($this->apollo, $rahul, 'fcm-rahul');

        $this->signInAsOwner($this->apollo);

        $send = fn (int $userId) => $this->postJson('/api/v1/tenant/communication/push/send', [
            'user_id' => $userId, 'title' => 'Hello', 'body' => 'Hi there',
        ]);

        // Off: not to a patient...
        $send($rahul)->assertUnprocessable()->assertJsonValidationErrors('user_id');

        // ...but the sender's own phone is how they test it first.
        $this->phone($this->apollo, $this->owner($this->apollo), 'fcm-owner');
        $send($this->owner($this->apollo))->assertOk();

        $this->switchOn();

        $send($nophone)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $send(999999)->assertUnprocessable()->assertJsonValidationErrors('user_id');

        Queue::assertPushed(SendPushMessage::class, 1);
    }

    public function test_reading_the_list_needs_view_and_sending_needs_manage(): void
    {
        // `communication.view` is a branch capability, so the staff member
        // works somewhere.
        $branch = (int) $this->onTenant($this->apollo, fn () => Location::on('organization')->create([
            'name' => 'Gurgaon', 'code' => 'DA-GGN', 'type' => Location::CLINIC, 'is_active' => true,
        ])->id);
        $this->placeStaffAt($this->apollo, $branch);

        $this->setStaffCapabilities($this->apollo, []);
        $this->signInAsStaff($this->apollo);

        $this->getJson('/api/v1/tenant/communication/push/devices')->assertForbidden();
        $this->postJson('/api/v1/tenant/communication/push/send', [])->assertForbidden();

        $this->setStaffCapabilities($this->apollo, ['communication.view']);
        $this->signInAsStaff($this->apollo);

        $this->getJson('/api/v1/tenant/communication/push/devices')->assertOk();
        $this->getJson('/api/v1/tenant/communication/push/recipients')->assertForbidden();
        $this->postJson('/api/v1/tenant/communication/push/send', [])->assertForbidden();
    }

    public function test_templates_say_whether_they_are_the_platforms_and_how_many_branches_reword_them(): void
    {
        $this->signInAsOwner($this->apollo);

        $this->postJson('/api/v1/tenant/communication/templates', [
            'channel' => CommunicationChannel::PUSH,
            'name' => 'Clinic news',
            'category' => 'General',
            'subject' => 'News from the clinic',
            'content' => 'We have a new paediatrician.',
        ])->assertCreated();

        $templates = collect($this->getJson('/api/v1/tenant/communication/push/overview')->assertOk()->json('data.templates'))
            ->keyBy('name');

        $this->assertTrue($templates['Appointment confirmed']['is_default']);
        $this->assertFalse($templates['Clinic news']['is_default']);
        $this->assertSame(0, $templates['Appointment confirmed']['branch_wordings']);

        $this->assertSame(
            MessageTemplate::APPROVED,
            $this->onTenant($this->apollo, fn () => MessageTemplate::query()->where('name', 'Appointment confirmed')->value('status')),
        );
    }
}
