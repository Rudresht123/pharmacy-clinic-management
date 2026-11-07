<?php

namespace Tests\Feature\Api\V1\Tenant;

use App\Jobs\SendEmailMessage;
use App\Jobs\SendPushMessage;
use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Models\Tenant\MessageTemplate;
use App\Models\Tenant\Role;
use App\Models\Tenant\User as TenantUser;
use App\Services\Clinic\ClinicEvent;
use App\Services\Communication\DoctorNotifier;
use App\Services\Tenant\DefaultRoleSeeder;
use App\Services\Tenant\DoctorPostings;
use App\Services\Tenant\PatientAccountProvisioner;
use App\Support\Clinic\ClinicEvents;
use App\Support\Opd\Weekday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TenantTestCase;

/**
 * Who an automation rule tells — To, CC and BCC on an email, Send to on an
 * App notification — by place on the record (the doctor, whoever booked it,
 * the branch manager, the desk), by role, by name, or by address.
 */
class AutomationRecipientsTest extends TenantTestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-09-07';

    private const STAFF = '/api/v1/tenant';

    private const DOCTOR_EMAIL = 'dr.anjali@clinic.test';

    private const DOCTOR_LOGIN = 'anjali@clinic.test';

    private const MANAGER_EMAIL = 'manager@clinic.test';

    private Organization $organization;

    private int $gurgaon;

    private int $delhi;

    private int $doctorId;

    private int $patientId;

    private string $defaultGuard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultGuard = (string) config('auth.defaults.guard');
        $this->artisan('modules:sync');
        $this->travelTo(Carbon::parse(self::MONDAY.' 08:00'));

        $this->organization = $this->provisionOrganization('AR');
        $this->grantModule($this->organization, 'appointments');
        $this->grantModule($this->organization, 'communication');

        $this->signInAsOwner($this->organization);

        [$this->gurgaon, $this->delhi] = $this->onTenant($this->organization, fn () => [
            Location::on('organization')->create(['name' => 'Gurgaon', 'code' => 'AR-GGN', 'type' => Location::CLINIC, 'is_active' => true])->id,
            Location::on('organization')->create(['name' => 'Delhi', 'code' => 'AR-DEL', 'type' => Location::CLINIC, 'is_active' => true])->id,
        ]);

        $this->doctorId = $this->postJson(self::STAFF.'/doctors', [
            'name' => 'Dr. Anjali Sharma',
            'specialisation' => 'General Physician',
            'email' => self::DOCTOR_EMAIL,
            'is_active' => true,
            'account' => ['email' => self::DOCTOR_LOGIN, 'password' => 'doctor-secret-1'],
        ])->assertCreated()->json('data.id');

        $this->patientId = $this->onTenant($this->organization, function () {
            DoctorSchedule::on('organization')->create([
                'doctor_id' => $this->doctorId, 'location_id' => $this->gurgaon, 'name' => 'Morning OPD',
                'weekday' => Weekday::MONDAY, 'starts_at' => '10:00', 'ends_at' => '12:00',
                'slot_minutes' => 30, 'is_active' => true,
            ]);

            app(DoctorPostings::class)->ensure(Doctor::query()->findOrFail($this->doctorId), $this->gurgaon);

            $manager = TenantUser::query()->create([
                'name' => 'Meena Manager', 'email' => self::MANAGER_EMAIL, 'password' => bcrypt('secret-123'),
                'is_active' => true, 'role' => TenantUser::STAFF,
            ]);

            Location::query()->whereKey($this->gurgaon)->update(['manager_id' => $manager->id]);

            return Customer::on('organization')->create([
                'name' => 'Rohit Sharma', 'phone' => '9810000044', 'email' => 'rohit@example.com', 'is_active' => true,
            ])->id;
        });

        Queue::fake();
    }

    /** As a new request finds the app — see VideoCallTest::fresh(). */
    private function fresh(): void
    {
        $this->flushSession();
        $this->flushHeaders();
        $this->withHeader('Origin', config('app.url'));
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver($this->defaultGuard);
    }

    private function rule(string $channel, string $audience = AutomationRule::STAFF, string $event = AutomationRule::APPOINTMENT_CONFIRMED): AutomationRule
    {
        return $this->onTenant($this->organization, fn () => AutomationRule::query()
            ->where('channel', $channel)->where('event_key', $event)->where('audience', $audience)->sole());
    }

    /** Saved as the owner would, through the rule screen. */
    private function tell(string $channel, array $recipients, string $audience = AutomationRule::STAFF, string $event = AutomationRule::APPOINTMENT_CONFIRMED)
    {
        $this->fresh();
        $this->signInAsOwner($this->organization);

        return $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule($channel, $audience, $event)->id, [
            'recipients' => $recipients,
        ]);
    }

    /** A patient of their own each time, unless one is named: one visit a day each. */
    private function book(string $slot = '10:30', ?int $customer = null): int
    {
        $customer ??= $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
            'name' => 'Walk-up '.$slot, 'phone' => '98'.random_int(10000000, 99999999), 'is_active' => true,
        ])->id);

        return (int) $this->postJson(self::STAFF.'/appointments', [
            'customer_id' => $customer,
            'doctor_id' => $this->doctorId,
            'location_id' => $this->gurgaon,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => $slot,
        ])->assertCreated()->json('data.id');
    }

    private function bookAtTheDesk(string $slot = '10:30'): int
    {
        $this->fresh();
        $this->signInAsReceptionist($this->organization, $this->gurgaon);

        return $this->book($slot);
    }

    private function login(string $email): int
    {
        return (int) $this->onTenant($this->organization, fn () => TenantUser::query()->where('email', $email)->value('id'));
    }

    /** @return list<array{to: string, also_to: list<string>, cc: list<string>, bcc: list<string>}> */
    private function emails(string $subject): array
    {
        return collect(Queue::pushed(SendEmailMessage::class))
            ->filter(fn (SendEmailMessage $job) => $job->content['subject'] === $subject)
            ->map(fn (SendEmailMessage $job) => [
                'to' => $job->content['to'],
                'also_to' => $job->content['also_to'] ?? [],
                'cc' => $job->content['cc'] ?? [],
                'bcc' => $job->content['bcc'] ?? [],
            ])
            ->values()->all();
    }

    /** @return list<list<int>> who each push of [$type] went to */
    private function pushes(string $type): array
    {
        return collect(Queue::pushed(SendPushMessage::class))
            ->filter(fn (SendPushMessage $job) => ($job->content['data']['type'] ?? null) === $type)
            ->map(fn (SendPushMessage $job) => collect($job->content['user_ids'])->sort()->values()->all())
            ->values()->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Who hears
    |--------------------------------------------------------------------------
    */

    public function test_a_booking_tells_whoever_the_staff_rule_names_on_to_cc_and_bcc(): void
    {
        $this->tell(CommunicationChannel::EMAIL, [
            'to' => [['type' => 'doctor']],
            'cc' => [['type' => 'branch_manager']],
            'bcc' => [['type' => 'email', 'address' => 'Owner-Copy@clinic.test']],
        ])->assertOk()
            ->assertJsonPath('data.recipients.to.0.label', 'Doctor')
            ->assertJsonPath('data.recipients.cc.0.label', 'Branch manager')
            ->assertJsonPath('data.recipients.bcc.0.label', 'owner-copy@clinic.test');

        $this->tell(CommunicationChannel::PUSH, [
            'to' => [['type' => 'doctor'], ['type' => 'branch_manager']],
        ])->assertOk();

        $this->bookAtTheDesk();

        // ONE email: the doctor's own address on To, the manager copied in,
        // the owner's copy hidden.
        $this->assertSame([[
            'to' => self::DOCTOR_EMAIL,
            'also_to' => [],
            'cc' => [self::MANAGER_EMAIL],
            'bcc' => ['owner-copy@clinic.test'],
        ]], $this->emails('Appointment booked'));

        $this->assertSame(
            [collect([$this->login(self::DOCTOR_LOGIN), $this->login(self::MANAGER_EMAIL)])->sort()->values()->all()],
            $this->pushes('appointment_booked'),
        );
    }

    public function test_switched_off_nobody_hears_and_the_doctor_is_not_told_a_second_way(): void
    {
        foreach ([CommunicationChannel::EMAIL, CommunicationChannel::PUSH] as $channel) {
            $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule($channel)->id, ['is_enabled' => false])
                ->assertOk();
        }

        $this->book();

        $this->assertSame([], $this->emails('Appointment booked'));
        $this->assertSame([], $this->pushes('appointment_booked'));
    }

    public function test_booked_by_is_whoever_made_the_booking_never_the_patient(): void
    {
        $this->tell(CommunicationChannel::PUSH, ['to' => [['type' => 'creator']]])->assertOk();

        $this->bookAtTheDesk();
        $this->assertSame([[$this->login(self::RECEPTIONIST_EMAIL)]], $this->pushes('appointment_booked'));

        // Booked by the patient, from the app: nobody on staff "booked it".
        $token = $this->onTenant($this->organization, function () {
            $patient = Customer::on('organization')->create(['name' => 'Asha Rane', 'phone' => '9810000045', 'is_active' => true]);

            return app(PatientAccountProvisioner::class)->open($patient, ['appointments'])
                ->createToken('Patient phone')->plainTextToken;
        });

        $this->fresh();
        $this->withHeaders(['X-Organization' => $this->organization->subdomain, 'Authorization' => "Bearer {$token}"])
            ->postJson(self::STAFF.'/portal/appointments', [
                'doctor_id' => $this->doctorId,
                'location_id' => $this->gurgaon,
                'appointment_date' => self::MONDAY,
                'slot_at' => '11:00',
            ])->assertCreated();

        $this->assertCount(1, $this->pushes('appointment_booked'));
    }

    public function test_the_front_desk_and_a_role_are_the_people_at_that_branch(): void
    {
        // A receptionist at Gurgaon, and one at Delhi who must not hear about Gurgaon.
        $this->signInAsReceptionist($this->organization, $this->gurgaon);
        $roleId = $this->onTenant($this->organization, function () {
            app(DefaultRoleSeeder::class)->seedBranch($this->organization, Location::query()->findOrFail($this->delhi));

            // Each branch's own Receptionist — the rule names Gurgaon's copy.
            $gurgaon = Role::query()->where('slug', Role::slugAt(self::RECEPTIONIST_ROLE, $this->gurgaon))->firstOrFail();
            $delhi = Role::query()->where('slug', Role::slugAt(self::RECEPTIONIST_ROLE, $this->delhi))->firstOrFail();

            $desk = TenantUser::query()->create([
                'name' => 'Delhi Desk', 'email' => 'delhi.desk@clinic.test', 'password' => bcrypt('secret-123'),
                'is_active' => true, 'role' => TenantUser::STAFF,
            ]);

            BranchMembership::query()->create(['user_id' => $desk->id, 'location_id' => $this->delhi, 'role_id' => $delhi->id]);

            return $gurgaon->id;
        });

        $this->tell(CommunicationChannel::PUSH, ['to' => [['type' => 'front_desk']]])->assertOk();
        $this->book('10:00');

        $this->tell(CommunicationChannel::PUSH, ['to' => [['type' => 'role', 'id' => $roleId]]])->assertOk()
            ->assertJsonPath('data.recipients.to.0.label', 'Receptionist');
        $this->book('10:30');

        // Gurgaon's desk both times — never Delhi's, and never the doctor,
        // who is posted to Gurgaon but hears as the doctor or not at all.
        $desk = [$this->login(self::RECEPTIONIST_EMAIL)];
        $this->assertSame([$desk, $desk], $this->pushes('appointment_booked'));
    }

    public function test_somebody_named_twice_is_told_once_on_the_higher_list(): void
    {
        $managerId = $this->login(self::MANAGER_EMAIL);

        $this->tell(CommunicationChannel::EMAIL, [
            'to' => [['type' => 'doctor']],
            'cc' => [['type' => 'user', 'id' => $managerId], ['type' => 'branch_manager']],
            'bcc' => [['type' => 'email', 'address' => self::DOCTOR_EMAIL]],
        ])->assertOk();

        $this->book();

        $this->assertSame([[
            'to' => self::DOCTOR_EMAIL,
            'also_to' => [],
            'cc' => [self::MANAGER_EMAIL],
            'bcc' => [],
        ]], $this->emails('Appointment booked'));
    }

    public function test_nobody_reachable_on_to_moves_the_copies_up_and_a_lone_bcc_goes_on_its_own(): void
    {
        // Gurgaon without a manager: To is nobody, so CC becomes the addressee.
        $this->onTenant($this->organization, fn () => Location::query()->whereKey($this->gurgaon)->update(['manager_id' => null]));

        $this->tell(CommunicationChannel::EMAIL, [
            'to' => [['type' => 'branch_manager']],
            'cc' => [['type' => 'email', 'address' => 'front@clinic.test']],
        ])->assertOk();
        $this->book('10:00');

        $this->tell(CommunicationChannel::EMAIL, [
            'to' => [['type' => 'branch_manager']],
            'bcc' => [['type' => 'email', 'address' => 'a@clinic.test'], ['type' => 'email', 'address' => 'b@clinic.test']],
        ])->assertOk();
        $this->book('10:30');

        $this->assertSame([
            ['to' => 'front@clinic.test', 'also_to' => [], 'cc' => [], 'bcc' => []],
            // Each hidden recipient alone — neither sees the other.
            ['to' => 'a@clinic.test', 'also_to' => [], 'cc' => [], 'bcc' => []],
            ['to' => 'b@clinic.test', 'also_to' => [], 'cc' => [], 'bcc' => []],
        ], $this->emails('Appointment booked'));
    }

    public function test_the_patients_own_message_can_copy_the_doctor_in(): void
    {
        $this->onTenant($this->organization, fn () => CommunicationChannel::forChannel(CommunicationChannel::EMAIL)
            ->forceFill(['is_connected' => true])->save());

        $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule(CommunicationChannel::EMAIL, AutomationRule::PATIENT)->id, [
            'is_enabled' => true,
            'recipients' => ['to' => [['type' => 'patient']], 'cc' => [['type' => 'doctor']]],
        ])->assertOk();

        $this->book(customer: $this->patientId);

        $this->assertSame([[
            'to' => 'rohit@example.com',
            'also_to' => [],
            'cc' => [self::DOCTOR_EMAIL],
            'bcc' => [],
        ]], $this->emails('Appointment confirmed — {{clinic_name}}'));
    }

    /*
    |--------------------------------------------------------------------------
    | What may be saved
    |--------------------------------------------------------------------------
    */

    public function test_a_list_has_to_make_sense_for_its_rule(): void
    {
        $push = $this->rule(CommunicationChannel::PUSH)->id;
        $email = $this->rule(CommunicationChannel::EMAIL)->id;
        $patientEmail = $this->rule(CommunicationChannel::EMAIL, AutomationRule::PATIENT)->id;
        $rules = self::STAFF.'/communication/automation-rules/';

        // Nobody is copied on a push.
        $this->putJson($rules.$push, ['recipients' => ['to' => [['type' => 'doctor']], 'cc' => [['type' => 'branch_manager']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.cc');

        // Staff wording never reaches the patient…
        $this->putJson($rules.$email, ['recipients' => ['to' => [['type' => 'patient']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.to.0');

        // …while the patient's own message is the clinic's to send to whoever it chooses.
        $this->putJson($rules.$patientEmail, ['recipients' => ['to' => [['type' => 'doctor']]]])->assertOk();

        // Somebody who exists, and an address that is one.
        $this->putJson($rules.$email, ['recipients' => ['to' => [['type' => 'role', 'id' => 99999]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.to.0');
        $this->putJson($rules.$email, ['recipients' => ['to' => [['type' => 'user', 'id' => 99999]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.to.0');
        $this->putJson($rules.$email, ['recipients' => ['to' => [['type' => 'email', 'address' => 'not-an-address']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.to.0.address');

        // Switched on, it has to tell somebody.
        $this->putJson($rules.$email, ['recipients' => ['to' => []]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.to');
        $this->putJson($rules.$email, ['is_enabled' => false, 'recipients' => ['to' => []]])->assertOk();

        // A patient template on a staff alert, the wrong reader.
        $patientTemplate = $this->onTenant($this->organization, fn () => MessageTemplate::query()
            ->where('channel', CommunicationChannel::EMAIL)->where('audience', AutomationRule::PATIENT)->value('id'));
        $this->putJson($rules.$email, ['message_template_id' => $patientTemplate])
            ->assertUnprocessable()->assertJsonValidationErrors('message_template_id');
    }

    public function test_only_who_sets_up_messages_changes_who_hears(): void
    {
        $this->fresh();
        $this->signInAsReceptionist($this->organization, $this->gurgaon);

        $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule(CommunicationChannel::EMAIL)->id, [
            'recipients' => ['to' => [['type' => 'email', 'address' => 'me@example.com']]],
        ])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | The one central screen
    |--------------------------------------------------------------------------
    */

    private function connect(string $channel): void
    {
        $this->onTenant($this->organization, fn () => CommunicationChannel::forChannel($channel)
            ->forceFill(['is_connected' => true])->save());
    }

    /** Saved as the central Recipients screen saves it. */
    private function central(string $channel, array $recipients, string $audience = AutomationRule::STAFF, string $event = AutomationRule::APPOINTMENT_CONFIRMED)
    {
        $this->fresh();
        $this->signInAsOwner($this->organization);

        return $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule($channel, $audience, $event)->id.'/recipients', [
            'recipients' => $recipients,
        ]);
    }

    public function test_only_who_the_central_screen_names_hears_a_message(): void
    {
        $this->connect(CommunicationChannel::PUSH);

        // The doctor, and not the patient it was written for.
        $this->central(CommunicationChannel::PUSH, ['to' => [['type' => 'doctor']]], AutomationRule::PATIENT)
            ->assertOk()
            ->assertJsonPath('data.recipients.to.0.label', 'Doctor');

        // A new clinic's patient messages start switched off.
        $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule(CommunicationChannel::PUSH, AutomationRule::PATIENT)->id, ['is_enabled' => true])
            ->assertOk();

        // The staff alert stays out of the way, so only that message is in play.
        $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule(CommunicationChannel::PUSH)->id, ['is_enabled' => false])
            ->assertOk();

        $this->book(customer: $this->patientId);

        // The doctor's phone gets it in the type the staff app routes by.
        $this->assertSame([[$this->login(self::DOCTOR_LOGIN)]], $this->pushes('appointment_booked'));

        // The patient's phone is never told.
        $this->assertSame([], $this->pushes('appointment.confirmed'));
    }

    public function test_the_central_screen_reaches_a_whole_role_at_the_visits_branch(): void
    {
        $this->signInAsReceptionist($this->organization, $this->gurgaon);

        $roleId = $this->onTenant($this->organization, fn () => Role::query()
            ->where('slug', Role::slugAt(self::RECEPTIONIST_ROLE, $this->gurgaon))->value('id'));

        $this->central(CommunicationChannel::PUSH, ['to' => [['type' => 'role', 'id' => $roleId]]])
            ->assertOk()
            ->assertJsonPath('data.recipients.to.0.label', 'Receptionist');

        $this->book();

        $this->assertSame([[$this->login(self::RECEPTIONIST_EMAIL)]], $this->pushes('appointment_booked'));
    }

    public function test_reception_and_the_branch_manager_are_two_separate_columns(): void
    {
        $this->signInAsReceptionist($this->organization, $this->gurgaon);

        // A manager by role, with nobody named on the branch: how most clinics run.
        $holder = $this->onTenant($this->organization, function () {
            Location::query()->whereKey($this->gurgaon)->update(['manager_id' => null]);

            $role = Role::query()->where('slug', Role::slugAt('branch-manager', $this->gurgaon))->firstOrFail();

            // They can answer reschedule requests, so the desk would have swept them in.
            $this->assertContains('appointments.reschedule', $role->capabilityKeys());

            $person = TenantUser::query()->create([
                'name' => 'Priya Manager', 'email' => 'priya.manager@clinic.test', 'password' => bcrypt('secret-123'),
                'is_active' => true, 'role' => TenantUser::STAFF,
            ]);

            BranchMembership::query()->create(['user_id' => $person->id, 'location_id' => $this->gurgaon, 'role_id' => $role->id]);

            return $person->id;
        });

        $this->central(CommunicationChannel::PUSH, ['to' => [['type' => 'front_desk']]])->assertOk();
        $this->book('10:00');

        $this->central(CommunicationChannel::PUSH, ['to' => [['type' => 'branch_manager']]])->assertOk();
        $this->book('10:30');

        $this->assertSame(
            [[$this->login(self::RECEPTIONIST_EMAIL)], [$holder]],
            $this->pushes('appointment_booked'),
        );
    }

    public function test_the_central_screen_sets_to_cc_and_bcc_on_an_email(): void
    {
        $this->connect(CommunicationChannel::EMAIL);

        $this->central(CommunicationChannel::EMAIL, [
            'to' => [['type' => 'patient']],
            'cc' => [['type' => 'branch_manager']],
            'bcc' => [['type' => 'doctor']],
        ], AutomationRule::PATIENT)->assertOk();

        // A new clinic's patient messages start switched off.
        $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule(CommunicationChannel::EMAIL, AutomationRule::PATIENT)->id, ['is_enabled' => true])
            ->assertOk();

        $this->book(customer: $this->patientId);

        $this->assertSame([[
            'to' => 'rohit@example.com',
            'also_to' => [],
            'cc' => [self::MANAGER_EMAIL],
            'bcc' => [self::DOCTOR_EMAIL],
        ]], $this->emails('Appointment confirmed — {{clinic_name}}'));
    }

    public function test_the_central_screen_changes_who_hears_and_nothing_else_about_the_message(): void
    {
        $before = $this->rule(CommunicationChannel::PUSH);

        $this->central(CommunicationChannel::PUSH, ['to' => [['type' => 'front_desk']]])->assertOk();

        // Sent anyway, and ignored: those belong to the channel's own settings.
        $this->putJson(self::STAFF."/communication/automation-rules/{$before->id}/recipients", [
            'recipients' => ['to' => [['type' => 'doctor']]],
            'title' => 'Renamed',
            'message_template_id' => null,
        ])->assertOk();

        $after = $this->rule(CommunicationChannel::PUSH);

        $this->assertSame($before->title, $after->title);
        $this->assertSame($before->message_template_id, $after->message_template_id);
        $this->assertSame([['type' => 'doctor']], $after->recipients['to']);
    }

    public function test_what_a_message_cannot_be_sent_to_is_refused_by_name(): void
    {
        // Staff wording never reaches the patient.
        $this->central(CommunicationChannel::PUSH, ['to' => [['type' => 'patient']]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.to.0');

        // A message that is on has to tell somebody.
        $this->central(CommunicationChannel::PUSH, ['to' => []])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.to');

        // A role that exists.
        $this->central(CommunicationChannel::PUSH, ['to' => [['type' => 'role', 'id' => 99999]]])
            ->assertUnprocessable()->assertJsonValidationErrors('recipients.to.0');

        // Saying nothing at all is not an answer.
        $this->putJson(self::STAFF.'/communication/automation-rules/'.$this->rule(CommunicationChannel::PUSH)->id.'/recipients', [])
            ->assertUnprocessable();
    }

    public function test_the_central_screen_has_its_own_tick(): void
    {
        $rule = $this->rule(CommunicationChannel::PUSH)->id;
        $url = self::STAFF."/communication/automation-rules/{$rule}/recipients";
        $body = ['recipients' => ['to' => [['type' => 'front_desk']]]];

        // Everything else about communication is no way in.
        $this->fresh();
        $this->signInAsReceptionist($this->organization, $this->gurgaon);
        $this->setRoleTicks(['communication.view', 'communication.email', 'communication.push']);
        $this->putJson($url, $body)->assertForbidden();

        // An organization-wide role holding it writes, and reads every channel's messages.
        $this->fresh();
        $this->onTenant($this->organization, function () {
            $role = Role::query()->create([
                'name' => 'Comms', 'slug' => 'comms', 'scope' => Role::SCOPE_ORGANIZATION,
            ]);
            $role->syncCapabilities(['communication.recipients']);

            TenantUser::query()->where('email', self::RECEPTIONIST_EMAIL)->update(['role_id' => $role->id]);
        });
        $this->signIn($this->organization, self::RECEPTIONIST_EMAIL);
        $this->setRoleTicks([]);

        $this->putJson($url, $body)->assertOk();

        $channels = collect($this->getJson(self::STAFF.'/communication/automation-rules')->assertOk()->json('data'))
            ->pluck('channel')->unique();

        $this->assertContains(CommunicationChannel::EMAIL, $channels);
        $this->assertContains(CommunicationChannel::PUSH, $channels);
    }

    /*
    |--------------------------------------------------------------------------
    | One branch's own choice
    |--------------------------------------------------------------------------
    */

    public function test_a_branch_chooses_for_itself_and_no_other_branch_changes(): void
    {
        $this->signInAsReceptionist($this->organization, $this->gurgaon);
        $this->setRoleTicks(['communication.push', 'communication.branch_templates', 'appointments.reschedule']);

        // A second doctor's week at Delhi, so a visit can be booked there too.
        $this->onTenant($this->organization, function () {
            DoctorSchedule::on('organization')->create([
                'doctor_id' => $this->doctorId, 'location_id' => $this->delhi, 'name' => 'Morning OPD',
                'weekday' => Weekday::MONDAY, 'starts_at' => '10:00', 'ends_at' => '12:00',
                'slot_minutes' => 30, 'is_active' => true,
            ]);

            app(DoctorPostings::class)->ensure(Doctor::query()->findOrFail($this->doctorId), $this->delhi);
        });

        $rule = $this->rule(CommunicationChannel::PUSH);
        $url = self::STAFF."/communication/automation-rules/{$rule->id}/recipients";

        $this->fresh();
        $this->signIn($this->organization, self::RECEPTIONIST_EMAIL);

        $this->putJson($url, ['recipients' => ['to' => [['type' => 'front_desk']]]])
            ->assertOk()
            ->assertJsonPath('data.recipients.to.0.label', 'Receptionist')
            ->assertJsonPath('data.branch_own', true);

        // The rule itself, which every other branch reads, is as it was.
        $this->assertSame([['type' => 'doctor']], $this->rule(CommunicationChannel::PUSH)->recipients['to']);

        // The branch reads back its own choice, everywhere it reads rules from.
        $this->assertSame('Receptionist', collect($this->getJson(self::STAFF.'/communication/push/overview')->assertOk()->json('data.rules'))
            ->firstWhere('id', $rule->id)['recipients']['to'][0]['label']);

        // A booking at Gurgaon follows Gurgaon's choice, one at Delhi the organisation's.
        $this->fresh();
        $this->signInAsOwner($this->organization);

        $book = fn (int $branch, string $slot) => $this->postJson(self::STAFF.'/appointments', [
            'customer_id' => $this->onTenant($this->organization, fn () => Customer::on('organization')->create([
                'name' => 'Walk-up '.$slot, 'phone' => '98'.random_int(10000000, 99999999), 'is_active' => true,
            ])->id),
            'doctor_id' => $this->doctorId,
            'location_id' => $branch,
            'appointment_date' => self::MONDAY,
            'type' => Appointment::BOOKED,
            'slot_at' => $slot,
        ])->assertCreated();

        $book($this->gurgaon, '10:00');
        $book($this->delhi, '10:30');

        $this->assertSame([
            [$this->login(self::RECEPTIONIST_EMAIL)],
            [$this->login(self::DOCTOR_LOGIN)],
        ], $this->pushes('appointment_booked'));

        // The owner reads the organisation's choice, not one branch's.
        $this->assertSame('Doctor', collect($this->getJson(self::STAFF.'/communication/push/overview')->assertOk()->json('data.rules'))
            ->firstWhere('id', $rule->id)['recipients']['to'][0]['label']);

        // Back to the organisation's.
        $this->fresh();
        $this->signIn($this->organization, self::RECEPTIONIST_EMAIL);

        $this->deleteJson($url)
            ->assertOk()
            ->assertJsonPath('data.recipients.to.0.label', 'Doctor')
            ->assertJsonPath('data.branch_own', false);
    }

    public function test_a_branch_chooses_from_its_own_staff_only(): void
    {
        $this->signInAsReceptionist($this->organization, $this->gurgaon);
        $this->setRoleTicks(['communication.push', 'communication.branch_templates']);

        [$here, $there] = $this->onTenant($this->organization, function () {
            $person = fn (string $name, int $branch) => tap(TenantUser::query()->create([
                'name' => $name, 'email' => strtolower($name).'@clinic.test', 'password' => bcrypt('secret-123'),
                'is_active' => true, 'role' => TenantUser::STAFF,
            ]), fn (TenantUser $user) => BranchMembership::query()->create([
                'user_id' => $user->id, 'location_id' => $branch,
            ]))->id;

            return [$person('Asha', $this->gurgaon), $person('Delhi', $this->delhi)];
        });

        $this->fresh();
        $this->signIn($this->organization, self::RECEPTIONIST_EMAIL);

        $offered = collect($this->getJson(self::STAFF.'/communication/automation-rules/recipients')->assertOk()->json('data.staff'))
            ->pluck('id');

        $this->assertContains($here, $offered);
        $this->assertNotContains($there, $offered);

        $url = self::STAFF.'/communication/automation-rules/'.$this->rule(CommunicationChannel::PUSH)->id.'/recipients';

        $this->putJson($url, ['recipients' => ['to' => [['type' => 'user', 'id' => $there]]]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipients.to.0');

        $this->putJson($url, ['recipients' => ['to' => [['type' => 'user', 'id' => $here]]]])->assertOk();

        // The owner chooses from everybody.
        $this->fresh();
        $this->signInAsOwner($this->organization);

        $this->assertContains($there, collect($this->getJson(self::STAFF.'/communication/automation-rules/recipients')->assertOk()->json('data.staff'))->pluck('id'));
    }

    /** What the receptionist's own branch role holds. */
    private function setRoleTicks(array $ticks): void
    {
        $this->onTenant($this->organization, fn () => Role::query()
            ->where('slug', Role::slugAt(self::RECEPTIONIST_ROLE, $this->gurgaon))
            ->firstOrFail()
            ->syncCapabilities($ticks));
    }

    /*
    |--------------------------------------------------------------------------
    | Nobody is told a second way
    |--------------------------------------------------------------------------
    */

    public function test_where_a_message_exists_the_doctor_only_hears_if_it_names_them(): void
    {
        $this->connect(CommunicationChannel::PUSH);
        $this->grantModule($this->organization, 'queue');

        $this->fresh();
        $this->signInAsOwner($this->organization);
        $this->book(customer: $this->patientId);

        $tellTheDoctor = fn () => $this->onTenant($this->organization, fn () => app(DoctorNotifier::class)->handle(
            ClinicEvent::for(ClinicEvents::TOKEN_CALLED, Appointment::query()->firstOrFail()),
        ));

        $alerts = fn () => collect(Queue::pushed(SendPushMessage::class))
            ->filter(fn (SendPushMessage $job) => ($job->content['data']['type'] ?? null) === ClinicEvents::TOKEN_CALLED)
            ->count();

        // A message sends this on push, and it does not name the doctor.
        $tellTheDoctor();
        $this->assertSame(0, $alerts());

        // An event with no message on this channel has nothing to choose, so it still reaches them.
        $this->onTenant($this->organization, fn () => AutomationRule::query()
            ->where('event_key', AutomationRule::TOKEN_CALLED)
            ->where('channel', CommunicationChannel::PUSH)
            ->update(['message_template_id' => null, 'is_enabled' => false]));

        $tellTheDoctor();
        $this->assertSame(1, $alerts());
    }

    public function test_the_choices_are_places_roles_and_staff_never_patients(): void
    {
        // A patient with an app login, who must not be offered.
        $this->onTenant($this->organization, fn () => app(PatientAccountProvisioner::class)
            ->open(Customer::query()->findOrFail($this->patientId), ['appointments']));

        $data = $this->getJson(self::STAFF.'/communication/automation-rules/recipients')->assertOk()->json('data');

        $this->assertSame(['patient', 'doctor', 'creator', 'branch_manager', 'front_desk'], array_column($data['places'], 'type'));
        $this->assertContains('Meena Manager', array_column($data['staff'], 'name'));
        $this->assertNotContains('Rohit Sharma', array_column($data['staff'], 'name'));
        $this->assertNotContains('Patient', array_column($data['roles'], 'name'));
    }
}
