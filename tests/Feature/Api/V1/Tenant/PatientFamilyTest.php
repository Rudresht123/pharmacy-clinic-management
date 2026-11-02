<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Jobs\SendPushMessage;
use App\Models\Platform\Module;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\FamilyMember;
use App\Models\Tenant\Location;
use App\Models\Tenant\PatientNotification;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Services\Portal\Otp\OtpSender;
use App\Services\Portal\PatientFamily;
use App\Services\Push\PushManager;
use App\Services\Tenant\PatientAccountProvisioner;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * A patient looking after their family from the app, and choosing where to be
 * seen.
 *
 * What matters is where the walls are: a family member is a real patient
 * record, only the patient who added them can book for or read about them, and
 * a branch that has closed is not a choice.
 */
class PatientFamilyTest extends TenantTestCase
{
    use RefreshDatabase;

    /** A Monday morning, so the Monday sitting is today and still ahead. */
    private const MONDAY = '2026-09-07';

    private const PHONE = '9876500011';

    private const OTHER_PHONE = '9876500022';

    private OtpSender $sms;

    private ?string $code = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('modules:sync');

        $test = $this;
        $this->sms = new class($test) implements OtpSender
        {
            public function __construct(private readonly PatientFamilyTest $test) {}

            public function send(string $phone, string $code, string $clinicName): void
            {
                $this->test->heard($code);
            }
        };
        $this->app->instance(OtpSender::class, $this->sms);

        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00'));
    }

    public function heard(string $code): void
    {
        $this->code = $code;
    }

    /** @return array{0: Organization, 1: int, 2: int, 3: int} org, doctor, Gurgaon, Noida */
    private function clinic(): array
    {
        $organization = $this->provisionOrganization('FM');

        $organization->moduleEntitlements()->create([
            'module_id' => Module::where('key', 'appointments')->value('id'),
            'is_enabled' => true,
        ]);

        [$doctorId, $gurgaon, $noida] = $this->onTenant($organization, function () {
            $gurgaon = Location::on('organization')->create([
                'name' => 'Gurgaon',
                'code' => 'GGN',
                'type' => Location::CLINIC,
                'address' => '12 MG Road',
                'city' => 'Gurugram',
                'is_active' => true,
            ]);

            $noida = Location::on('organization')->create([
                'name' => 'Noida',
                'code' => 'NDA',
                'type' => Location::CLINIC,
                'is_active' => true,
            ]);

            $doctor = Doctor::on('organization')->create([
                'name' => 'Dr. Priya Sharma',
                'specialisation' => 'General Physician',
                'default_consultation_fee' => 500,
                'is_active' => true,
            ]);

            // Mondays at Gurgaon, Tuesdays at Noida.
            foreach ([[$gurgaon, Weekday::MONDAY], [$noida, Weekday::MONDAY + 1]] as [$branch, $weekday]) {
                DoctorSchedule::on('organization')->create([
                    'doctor_id' => $doctor->id,
                    'location_id' => $branch->id,
                    'name' => 'Morning OPD',
                    'weekday' => $weekday,
                    'starts_at' => '10:00',
                    'ends_at' => '12:00',
                    'slot_minutes' => 30,
                    'is_active' => true,
                ]);
            }

            return [$doctor->id, $gurgaon->id, $noida->id];
        });

        return [$organization, $doctorId, $gurgaon, $noida];
    }

    private function signInPatient(Organization $organization, string $phone = self::PHONE, string $name = 'Rahul Tiwari'): string
    {
        $this->fresh();
        $headers = ['X-Organization' => $organization->subdomain];

        $this->withHeaders($headers)
            ->postJson('/api/v1/tenant/portal/auth/code', ['phone' => $phone])
            ->assertOk();

        $this->fresh();

        $token = $this->withHeaders($headers)
            ->postJson('/api/v1/tenant/portal/auth/verify', [
                'phone' => $phone,
                'code' => $this->code,
                'name' => $name,
                'device_name' => 'test',
            ])
            ->assertOk()
            ->json('data.token');

        // The next number's code is not held back by this one's cooldown.
        $this->travel(2)->minutes();

        return $token;
    }

    /**
     * As a new request would find things. The default guard goes back too: a
     * real request starts in a new process, but here the previous one's would
     * look its token up on a tenant connection onTenant() has since closed.
     */
    private function fresh(): void
    {
        $this->flushSession();
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->forgetGuards();
    }

    private function asPatient(Organization $organization, string $token): static
    {
        $this->fresh();

        return $this->withHeaders([
            'X-Organization' => $organization->subdomain,
            'Authorization' => "Bearer {$token}",
        ]);
    }

    private function addMember(Organization $organization, string $token, array $overrides = []): int
    {
        return $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/family', $overrides + [
                'name' => 'Aarav Tiwari',
                'relation' => FamilyMember::CHILD,
                'gender' => Customer::MALE,
                'date_of_birth' => '2018-03-01',
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function book(Organization $organization, string $token, int $doctorId, int $branchId, array $overrides = [])
    {
        return $this->asPatient($organization, $token)->postJson('/api/v1/tenant/portal/appointments', $overrides + [
            'doctor_id' => $doctorId,
            'location_id' => $branchId,
            'appointment_date' => self::MONDAY,
            'slot_at' => '10:30',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | The family
    |--------------------------------------------------------------------------
    */

    public function test_adding_a_family_member_opens_a_real_patient_record_with_no_phone(): void
    {
        [$organization] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/family', [
                'name' => '  Aarav Tiwari ',
                'relation' => FamilyMember::CHILD,
                'gender' => Customer::MALE,
                'date_of_birth' => '2018-03-01',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Aarav Tiwari')
            ->assertJsonPath('data.relation', FamilyMember::CHILD)
            ->assertJsonPath('data.age', 8);

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/family')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Aarav Tiwari');

        $this->onTenant($organization, function () {
            $member = Customer::query()->where('name', 'Aarav Tiwari')->firstOrFail();

            // Their own number at the clinic, and none a sign-in could match.
            $this->assertNotNull($member->code);
            $this->assertNull($member->phone);
            $this->assertSame('Rahul Tiwari', $member->accountHolder()?->name);
        });
    }

    public function test_a_family_member_needs_a_name_a_relation_and_a_gender(): void
    {
        [$organization] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/family', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'relation', 'gender'], 'errors');

        $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/family', [
                'name' => 'Aarav',
                'relation' => FamilyMember::CHILD,
                'gender' => Customer::MALE,
                'date_of_birth' => '2026-12-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_of_birth'], 'errors');
    }

    public function test_the_same_person_is_not_added_twice_and_a_family_has_a_limit(): void
    {
        [$organization] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->addMember($organization, $token);

        $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/family', [
                'name' => 'aarav tiwari',
                'relation' => FamilyMember::CHILD,
                'gender' => Customer::MALE,
                'date_of_birth' => '2018-03-01',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'aarav tiwari is already in your family.');

        for ($i = 2; $i <= PatientFamily::MAX_MEMBERS; $i++) {
            $this->addMember($organization, $token, ['name' => "Member {$i}"]);
            $this->travel(5)->seconds();
        }

        $this->asPatient($organization, $token)
            ->postJson('/api/v1/tenant/portal/family', [
                'name' => 'One Too Many',
                'relation' => FamilyMember::OTHER,
                'gender' => Customer::OTHER,
            ])
            ->assertUnprocessable();

        $this->onTenant($organization, fn () => $this->assertFalse(Customer::query()->where('name', 'One Too Many')->exists()));
    }

    public function test_a_family_member_can_be_edited_and_only_by_their_own_family(): void
    {
        [$organization] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $change = [
            'name' => 'Aarav K Tiwari',
            'relation' => FamilyMember::OTHER,
            'gender' => Customer::MALE,
            'date_of_birth' => '2018-03-02',
        ];

        $this->asPatient($organization, $token)
            ->putJson("/api/v1/tenant/portal/family/{$memberId}", $change)
            ->assertOk()
            ->assertJsonPath('data.name', 'Aarav K Tiwari')
            ->assertJsonPath('data.relation', FamilyMember::OTHER)
            ->assertJsonPath('data.date_of_birth', '2018-03-02');

        $stranger = $this->signInPatient($organization, self::OTHER_PHONE, 'Meera Singh');

        $this->asPatient($organization, $stranger)
            ->putJson("/api/v1/tenant/portal/family/{$memberId}", ['name' => 'Taken Over'] + $change)
            ->assertNotFound();

        $this->asPatient($organization, $stranger)
            ->deleteJson("/api/v1/tenant/portal/family/{$memberId}")
            ->assertNotFound();

        $this->asPatient($organization, $stranger)
            ->getJson('/api/v1/tenant/portal/family')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_removal_waits_for_their_visits_and_leaves_the_clinics_record(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $visitId = $this->book($organization, $token, $doctorId, $gurgaon, ['member_id' => $memberId])
            ->assertCreated()
            ->json('data.id');

        $this->asPatient($organization, $token)
            ->deleteJson("/api/v1/tenant/portal/family/{$memberId}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Aarav Tiwari has a visit booked. Please cancel it first.');

        $this->asPatient($organization, $token)->postJson("/api/v1/tenant/portal/appointments/{$visitId}/cancel")->assertOk();

        $this->asPatient($organization, $token)->deleteJson("/api/v1/tenant/portal/family/{$memberId}")->assertOk();

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/family')
            ->assertJsonCount(0, 'data');

        $this->onTenant($organization, fn () => $this->assertTrue(Customer::query()->whereKey($memberId)->exists()));
    }

    /*
    |--------------------------------------------------------------------------
    | Booking for them
    |--------------------------------------------------------------------------
    */

    public function test_a_patient_books_for_a_family_member_and_sees_it_with_their_own(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $visitId = $this->book($organization, $token, $doctorId, $gurgaon, ['member_id' => $memberId])
            ->assertCreated()
            ->assertJsonPath('data.patient.name', 'Aarav Tiwari')
            ->assertJsonPath('data.patient.is_self', false)
            ->assertJsonPath('data.patient.relation', FamilyMember::CHILD)
            ->json('data.id');

        // On the member's record, where the desk and the doctor will look.
        $this->onTenant($organization, fn () => $this->assertSame(
            $memberId,
            (int) Appointment::query()->findOrFail($visitId)->customer_id,
        ));

        $this->book($organization, $token, $doctorId, $gurgaon, ['slot_at' => '11:00'])
            ->assertCreated()
            ->assertJsonPath('data.patient.is_self', true)
            ->assertJsonPath('data.patient.relation', null);

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/appointments')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/home')
            ->assertOk()
            ->assertJsonPath('data.upcoming_count', 2)
            ->assertJsonPath('data.next_appointment.patient.name', 'Aarav Tiwari');
    }

    public function test_one_live_booking_per_doctor_per_day_is_per_person(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $this->book($organization, $token, $doctorId, $gurgaon)->assertCreated();

        // Somebody else in the family may see the same doctor that day...
        $this->book($organization, $token, $doctorId, $gurgaon, ['slot_at' => '11:00', 'member_id' => $memberId])
            ->assertCreated();

        // ...but neither of them twice.
        $this->book($organization, $token, $doctorId, $gurgaon, ['slot_at' => '11:30', 'member_id' => $memberId])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Aarav Tiwari already has an appointment with Dr. Priya Sharma that day.');

        $this->book($organization, $token, $doctorId, $gurgaon, ['slot_at' => '11:30'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You already have an appointment with Dr. Priya Sharma that day.');
    }

    public function test_a_patient_cannot_book_for_anybody_outside_their_family(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $stranger = $this->signInPatient($organization, self::OTHER_PHONE, 'Meera Singh');

        // Somebody else's relative...
        $this->book($organization, $stranger, $doctorId, $gurgaon, ['member_id' => $memberId])->assertNotFound();

        // ...somebody else outright...
        $rahul = $this->onTenant($organization, fn () => Customer::query()->where('name', 'Rahul Tiwari')->value('id'));
        $this->book($organization, $stranger, $doctorId, $gurgaon, ['member_id' => $rahul])->assertNotFound();

        // ...and a `customer_id` slipped into the body names nobody.
        $visit = $this->book($organization, $stranger, $doctorId, $gurgaon, ['customer_id' => $rahul])
            ->assertCreated()
            ->assertJsonPath('data.patient.name', 'Meera Singh');

        $this->assertNotNull($visit->json('data.id'));
    }

    public function test_a_family_member_cannot_be_booked_a_video_visit(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $this->book($organization, $token, $doctorId, $gurgaon, [
            'member_id' => $memberId,
            'mode' => Appointment::MODE_VIDEO,
        ])->assertUnprocessable();

        $this->onTenant($organization, fn () => $this->assertSame(0, Appointment::query()->count()));
    }

    public function test_a_patient_moves_and_cancels_a_family_members_visit_but_a_stranger_cannot(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $visitId = $this->book($organization, $token, $doctorId, $gurgaon, ['member_id' => $memberId])
            ->assertCreated()
            ->json('data.id');

        $move = ['appointment_date' => self::MONDAY, 'slot_at' => '11:00'];

        $stranger = $this->signInPatient($organization, self::OTHER_PHONE, 'Meera Singh');

        $this->asPatient($organization, $stranger)
            ->postJson("/api/v1/tenant/portal/appointments/{$visitId}/reschedule", $move)
            ->assertNotFound();

        $this->asPatient($organization, $stranger)
            ->postJson("/api/v1/tenant/portal/appointments/{$visitId}/cancel")
            ->assertNotFound();

        $this->asPatient($organization, $stranger)
            ->getJson('/api/v1/tenant/portal/appointments')
            ->assertJsonCount(0, 'data');

        $this->asPatient($organization, $token)
            ->postJson("/api/v1/tenant/portal/appointments/{$visitId}/reschedule", $move)
            ->assertOk()
            ->assertJsonPath('data.reschedule_request.status', 'pending');

        $this->asPatient($organization, $token)
            ->deleteJson("/api/v1/tenant/portal/appointments/{$visitId}/reschedule")
            ->assertOk();

        $this->asPatient($organization, $token)
            ->postJson("/api/v1/tenant/portal/appointments/{$visitId}/cancel")
            ->assertOk();
    }

    public function test_family_follows_the_editable_patient_role(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $this->onTenant($organization, fn () => Role::query()
            ->where('slug', PatientAccountProvisioner::ROLE)
            ->firstOrFail()
            ->syncCapabilities(['portal.appointments.view', 'portal.appointments.book']));

        $this->asPatient($organization, $token)->getJson('/api/v1/tenant/portal/family')->assertForbidden();

        $this->book($organization, $token, $doctorId, $gurgaon, ['member_id' => $memberId])->assertForbidden();

        // Their own booking is untouched by it.
        $this->book($organization, $token, $doctorId, $gurgaon)->assertCreated();
    }

    /*
    |--------------------------------------------------------------------------
    | Hearing about it
    |--------------------------------------------------------------------------
    */

    public function test_the_bell_names_the_family_member_and_reaches_the_patient(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $this->book($organization, $token, $doctorId, $gurgaon, ['member_id' => $memberId])->assertCreated();

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/home')
            ->assertJsonPath('data.unread_notifications', 1);

        $this->onTenant($organization, fn () => $this->assertStringStartsWith(
            "Aarav Tiwari's appointment with Dr. Priya Sharma",
            (string) Customer::query()->findOrFail($memberId)->hasMany(PatientNotification::class, 'customer_id')->value('body'),
        ));
    }

    public function test_a_push_about_a_family_member_goes_to_the_patient_but_they_cannot_answer_a_call_for_them(): void
    {
        [$organization] = $this->clinic();
        $token = $this->signInPatient($organization);
        $memberId = $this->addMember($organization, $token);

        $this->onTenant($organization, function () use ($organization, $memberId) {
            $member = Customer::query()->findOrFail($memberId);
            $login = (int) TenantUser::query()
                ->where('userable_type', Customer::class)
                ->where('userable_id', $member->accountHolder()->id)
                ->value('id');

            $push = app(PushManager::class);

            Queue::fake();

            $this->assertNotNull($push->queue($organization, $member, 'Booked', 'Aarav is booked'));

            Queue::assertPushed(SendPushMessage::class, fn (SendPushMessage $job) => $job->content['user_ids'] === [$login]);

            // VideoCalls asks this before ringing; a call would ring a phone
            // whose app could not open it.
            $this->assertSame([], $push->loginsFor($member));
        });

        $this->assertNotNull($token);
    }

    /*
    |--------------------------------------------------------------------------
    | Choosing a branch
    |--------------------------------------------------------------------------
    */

    public function test_doctors_say_which_branches_they_sit_at_and_where_those_are(): void
    {
        [$organization, $doctorId, $gurgaon, $noida] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/doctors')
            ->assertOk()
            ->assertJsonPath('data.0.locations.0.id', $gurgaon)
            ->assertJsonPath('data.0.locations.0.address', '12 MG Road, Gurugram')
            ->assertJsonPath('data.0.locations.1.id', $noida)
            ->assertJsonPath('data.0.locations.1.address', null)
            ->assertJsonPath('data.0.sittings.0.location_id', $gurgaon)
            ->assertJsonPath('data.0.next_available.location_id', $gurgaon)
            ->assertJsonPath('meta.branches.0.name', 'Gurgaon')
            ->assertJsonPath('meta.branches.1.name', 'Noida');
    }

    public function test_the_list_filters_to_one_branch_and_answers_for_it(): void
    {
        [$organization, $doctorId, $gurgaon, $noida] = $this->clinic();
        $token = $this->signInPatient($organization);

        // Free today at Gurgaon, but at Noida not until Tuesday.
        $this->asPatient($organization, $token)
            ->getJson("/api/v1/tenant/portal/doctors?location_id={$noida}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.next_available.date', '2026-09-08')
            ->assertJsonPath('data.0.next_available.location_id', $noida);

        $empty = $this->onTenant($organization, fn () => Location::on('organization')->create([
            'name' => 'Pune',
            'code' => 'PNQ',
            'type' => Location::CLINIC,
            'is_active' => true,
        ])->id);

        $this->asPatient($organization, $token)
            ->getJson("/api/v1/tenant/portal/doctors?location_id={$empty}")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            // Nobody sits there, so it is not offered as a choice either.
            ->assertJsonCount(2, 'meta.branches');
    }

    public function test_a_closed_branch_is_not_a_choice(): void
    {
        [$organization, $doctorId, $gurgaon, $noida] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->onTenant($organization, fn () => Location::query()->whereKey($noida)->update(['is_active' => false]));

        $this->asPatient($organization, $token)
            ->getJson('/api/v1/tenant/portal/doctors')
            ->assertOk()
            ->assertJsonCount(1, 'data.0.locations')
            ->assertJsonPath('data.0.locations.0.id', $gurgaon)
            ->assertJsonPath('data.0.days', ['Mon'])
            ->assertJsonCount(1, 'meta.branches');

        $this->asPatient($organization, $token)
            ->getJson("/api/v1/tenant/portal/doctors/{$doctorId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.locations');
    }

    public function test_a_visit_says_where_to_go(): void
    {
        [$organization, $doctorId, $gurgaon] = $this->clinic();
        $token = $this->signInPatient($organization);

        $this->book($organization, $token, $doctorId, $gurgaon)
            ->assertCreated()
            ->assertJsonPath('data.location.name', 'Gurgaon')
            ->assertJsonPath('data.location.address', '12 MG Road, Gurugram');
    }
}
