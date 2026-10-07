<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\LabOrder;
use App\Models\Tenant\LabOrderItem;
use App\Models\Tenant\Location;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use App\Services\Laboratory\LabOrders;
use App\Services\Opd\AvailabilityService;
use App\Services\Opd\BookingService;
use App\Services\Opd\VisitWorkflow;
use App\Services\Prescriptions\PrescriptionService;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Opd\Weekday;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One doctor's practice, with its clinical history, for looking at the
 * doctor's screens with.
 *
 * `opd:demo` fills the department's boards: who is booked, who is waiting,
 * who is in a room. It writes no consultations, so the doctor's own screens —
 * diagnoses, a patient's history, their prescriptions, their reports, the
 * follow-ups falling due — stay empty however busy the board looks. This
 * builds those: a hundred patients, each with months of visits written up the
 * way a general physician writes them, and today's list in every state a
 * visit can be in.
 *
 * EVERY VISIT GOES THROUGH THE REAL WORKFLOW. Check-in, call, start, write-up,
 * prescription, lab order, completion — the same services the desk and the
 * doctor go through, with the clock wound back to when the visit happened. So
 * the tokens, the RX- and LAB- numbers, the statuses and every timestamp are
 * the ones the product itself would have made, and nothing here has to be
 * kept in step with the rules by hand.
 *
 * NOTHING IS SENT TO ANYBODY. Issuing a prescription raises an event that can
 * message the patient, so this marks them issued itself — the same columns
 * `PrescriptionService::issue()` writes, without the event. No invoice is
 * drawn either: the workflow is built without the billing trigger.
 *
 * Run by hand, never from `db:seed`. `--fresh` removes everything an earlier
 * run made for the same doctor, found by the note every one of its patients
 * carries.
 */
class SeedDoctorDemoCommand extends Command
{
    protected $signature = 'doctor:demo
        {--org= : Organization id or subdomain; the only one, if there is only one}
        {--doctor= : Doctor id or exact name}
        {--patients=100 : How many patients to give them}
        {--fresh : Remove the patients an earlier run made for this doctor, and all their visits, first}';

    protected $description = "Build one doctor's patients and months of written-up visits, for looking at the doctor's screens with";

    /** Months of history behind today. */
    private const HISTORY_DAYS = 180;

    private const MEN = [
        'Aarav', 'Vivaan', 'Aditya', 'Rahul', 'Arjun', 'Kabir', 'Ishaan', 'Rohan', 'Karan', 'Manish',
        'Sanjay', 'Vikram', 'Nikhil', 'Amit', 'Rajesh', 'Suresh', 'Ramesh', 'Deepak', 'Anil', 'Prakash',
        'Mohit', 'Ravi', 'Ajay', 'Harsh', 'Gaurav', 'Pankaj', 'Mukesh', 'Shivam', 'Abhishek', 'Yash',
    ];

    private const WOMEN = [
        'Ananya', 'Diya', 'Aadhya', 'Priya', 'Neha', 'Sneha', 'Kavya', 'Meera', 'Pooja', 'Anjali',
        'Ritu', 'Sunita', 'Asha', 'Shruti', 'Nisha', 'Divya', 'Kiran', 'Rekha', 'Geeta', 'Savita',
        'Preeti', 'Komal', 'Shalini', 'Radha', 'Swati', 'Aarti', 'Poonam', 'Mamta', 'Sarita', 'Usha',
    ];

    private const LAST = [
        'Sharma', 'Verma', 'Gupta', 'Singh', 'Kumar', 'Yadav', 'Mishra', 'Tiwari', 'Pandey', 'Srivastava',
        'Shukla', 'Dubey', 'Tripathi', 'Chaurasia', 'Maurya', 'Jaiswal', 'Agrawal', 'Rai', 'Chauhan', 'Patel',
    ];

    private const CITIES = ['Gorakhpur', 'Deoria', 'Kushinagar', 'Basti', 'Maharajganj', 'Sant Kabir Nagar'];

    /** Weighted the way they turn up in north India: B+ and O+ most, the negatives rarely. */
    private const BLOOD_GROUPS = ['B+', 'B+', 'B+', 'O+', 'O+', 'O+', 'A+', 'A+', 'AB+', 'B-', 'O-', 'A-'];

    private const ALLERGIES = ['Penicillin', 'Sulfa drugs', 'Dust and pollen', 'Ibuprofen', 'Peanuts'];

    private const REASONS = ['Patient rescheduled', 'Patient went elsewhere', 'Booked twice by mistake'];

    /**
     * What brings people to a general physician, and what is done about it.
     *
     * `rx` lines name a catalogue medicine by id where the demo catalogue has
     * one, so the pharmacy can dispense them; the rest are typed, as a doctor
     * types a medicine the clinic does not stock. `tests` are ordered as a lab
     * order and listed on the write-up; `follow` is the review in days, null
     * for nothing.
     */
    private const CONDITIONS = [
        'hypertension' => [
            'diagnosis' => 'Essential hypertension',
            'complaints' => ['Headache and dizziness for a week', 'Routine BP review', 'Heaviness in the head in the mornings'],
            'findings' => 'BP raised. Heart sounds normal, no pedal oedema.',
            'rx' => [[7, 1, 'tablet', 'od', 'after_food', 30]],
            'tests' => ['Lipid profile', 'Serum creatinine', 'ECG'],
            'advice' => 'Cut down salt, walk 30 minutes a day, check BP at home twice a week.',
            'follow' => [14, 30],
            'chronic' => true,
        ],
        'diabetes' => [
            'diagnosis' => 'Type 2 diabetes mellitus',
            'complaints' => ['Increased thirst and frequent urination', 'Diabetes review', 'Tingling in both feet'],
            'findings' => 'Feet: sensation intact, pulses felt. No ulcers.',
            'rx' => [[6, 1, 'tablet', 'bd', 'after_food', 30]],
            'tests' => ['HbA1c', 'Fasting blood sugar'],
            'advice' => 'No sweets or sugary drinks, small meals, 45 minutes of walking daily.',
            'follow' => [30],
            'chronic' => true,
        ],
        'hypothyroidism' => [
            'diagnosis' => 'Hypothyroidism',
            'complaints' => ['Weight gain and tiredness', 'Thyroid review', 'Hair fall and feeling cold'],
            'findings' => 'Dry skin. Thyroid not enlarged.',
            'rx' => [['Thyronorm 50 mcg tablet', 1, 'tablet', 'od', 'empty_stomach', 30]],
            'tests' => ['TSH'],
            'advice' => 'Take the tablet on an empty stomach, 30 minutes before breakfast.',
            'follow' => [45],
            'chronic' => true,
        ],
        'asthma' => [
            'diagnosis' => 'Bronchial asthma',
            'complaints' => ['Wheezing and breathlessness at night', 'Cough worse in the early morning'],
            'findings' => 'Bilateral wheeze. No crepitations.',
            'rx' => [[18, 2, 'puff', 'sos', 'any', 30], [5, 1, 'tablet', 'hs', 'any', 10]],
            'tests' => [],
            'advice' => 'Carry the inhaler always. Avoid dust and smoke.',
            'follow' => [14],
            'chronic' => true,
        ],
        'dyslipidaemia' => [
            'diagnosis' => 'Dyslipidaemia',
            'complaints' => ['Cholesterol report review', 'Routine check-up'],
            'findings' => 'No xanthomas. BMI above normal.',
            'rx' => [[8, 1, 'tablet', 'hs', 'any', 30]],
            'tests' => ['Lipid profile'],
            'advice' => 'Less fried food and ghee, more vegetables and fruit.',
            'follow' => [60],
            'chronic' => true,
        ],
        'viral_fever' => [
            'diagnosis' => 'Viral fever',
            'complaints' => ['Fever and body ache for 3 days', 'High fever with chills since yesterday'],
            'findings' => 'Febrile. Throat congested. No rash.',
            'rx' => [[1, 1, 'tablet', 'tds', 'after_food', 5], [12, 1, 'sachet', 'sos', 'any', 3]],
            'tests' => ['CBC', 'Dengue NS1'],
            'advice' => 'Plenty of fluids and rest. Come back if fever lasts beyond 3 days.',
            'follow' => [5, null],
        ],
        'urti' => [
            'diagnosis' => 'Upper respiratory tract infection',
            'complaints' => ['Sore throat, cough and cold', 'Cough with yellow phlegm for 4 days'],
            'findings' => 'Throat congested. Chest clear.',
            'rx' => [[3, 1, 'tablet', 'od', 'after_food', 3], [5, 1, 'tablet', 'hs', 'any', 5], [11, 10, 'ml', 'tds', 'after_food', 5]],
            'tests' => [],
            'advice' => 'Warm salt-water gargles, steam inhalation twice a day.',
            'follow' => [7, null],
        ],
        'gastritis' => [
            'diagnosis' => 'Acute gastritis',
            'complaints' => ['Burning in the upper abdomen after meals', 'Acidity and bloating'],
            'findings' => 'Epigastric tenderness. No guarding.',
            'rx' => [[4, 1, 'tablet', 'od', 'before_food', 14], [10, 1, 'tablet', 'sos', 'any', 3]],
            'tests' => [],
            'advice' => 'Avoid spicy and oily food, tea on an empty stomach, and late dinners.',
            'follow' => [14, null],
        ],
        'gastroenteritis' => [
            'diagnosis' => 'Acute gastroenteritis',
            'complaints' => ['Loose motions and vomiting since yesterday'],
            'findings' => 'Mild dehydration. Abdomen soft.',
            'rx' => [[12, 1, 'sachet', 'tds', 'any', 3], [10, 1, 'tablet', 'sos', 'before_food', 3]],
            'tests' => ['Stool routine'],
            'advice' => 'ORS after every loose motion. Light food: khichdi, curd, banana.',
            'follow' => [null],
        ],
        'migraine' => [
            'diagnosis' => 'Migraine without aura',
            'complaints' => ['Recurring one-sided headache with nausea'],
            'findings' => 'Neurological examination normal.',
            'rx' => [[9, 1, 'tablet', 'sos', 'after_food', 10]],
            'tests' => [],
            'advice' => 'Regular sleep, do not skip meals, note what sets it off.',
            'follow' => [30, null],
        ],
        'rhinitis' => [
            'diagnosis' => 'Allergic rhinitis',
            'complaints' => ['Sneezing and runny nose every morning'],
            'findings' => 'Pale, boggy nasal mucosa.',
            'rx' => [[5, 1, 'tablet', 'od', 'any', 10]],
            'tests' => [],
            'advice' => 'Avoid dust; cover the mouth and nose outdoors.',
            'follow' => [null],
        ],
        'back_pain' => [
            'diagnosis' => 'Mechanical low back pain',
            'complaints' => ['Lower back pain after lifting', 'Back pain for two weeks'],
            'findings' => 'Paraspinal tenderness. SLR negative.',
            'rx' => [[9, 1, 'tablet', 'bd', 'after_food', 5], [15, 1, 'application', 'tds', 'any', 7]],
            'tests' => [],
            'advice' => 'Hot fomentation, back-strengthening exercises, avoid lifting heavy weights.',
            'follow' => [14, null],
        ],
        'vitamin_d' => [
            'diagnosis' => 'Vitamin D deficiency',
            'complaints' => ['Tiredness and pain in the legs', 'Body ache for a month'],
            'findings' => 'Mild bony tenderness. No deformity.',
            'rx' => [[13, 1, 'capsule', 'weekly', 'after_food', 8, 'weeks'], [14, 1, 'tablet', 'od', 'after_food', 30]],
            'tests' => ['Vitamin D (25-OH)'],
            'advice' => '20 minutes of morning sun every day.',
            'follow' => [60],
        ],
        'uti' => [
            'diagnosis' => 'Urinary tract infection',
            'complaints' => ['Burning while passing urine', 'Frequent urination with lower abdominal pain'],
            'findings' => 'Suprapubic tenderness. Afebrile.',
            'rx' => [['Nitrofurantoin 100 mg capsule', 1, 'capsule', 'bd', 'after_food', 5]],
            'tests' => ['Urine routine', 'Urine culture'],
            'advice' => 'Drink 3 litres of water a day.',
            'follow' => [7],
        ],
        'anaemia' => [
            'diagnosis' => 'Iron deficiency anaemia',
            'complaints' => ['Weakness and breathlessness on climbing stairs'],
            'findings' => 'Pallor present.',
            'rx' => [['Ferrous ascorbate 100 mg tablet', 1, 'tablet', 'od', 'after_food', 30]],
            'tests' => ['CBC', 'Serum ferritin'],
            'advice' => 'Green leafy vegetables, jaggery, dates. No tea with meals.',
            'follow' => [30],
        ],
    ];

    /** Chronic conditions, and how many in a hundred have each. */
    private const CHRONIC_SHARE = ['hypertension' => 30, 'diabetes' => 22, 'dyslipidaemia' => 10, 'hypothyroidism' => 8, 'asthma' => 6];

    /** Where the specimen comes from, by test. */
    private const SPECIMENS = [
        'ECG' => 'other', 'Urine routine' => 'urine', 'Urine culture' => 'urine', 'Stool routine' => 'stool',
    ];

    /** The bookable times: a morning and an evening OPD in quarter hours. */
    private const SLOTS = [
        '09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30', '11:45',
        '12:00', '12:15', '12:30', '12:45', '13:00', '13:15', '17:00', '17:15', '17:30', '17:45', '18:00', '18:15',
        '18:30', '18:45', '19:00', '19:15', '19:30', '19:45', '20:00', '20:15', '20:30',
    ];

    private VisitWorkflow $visits;

    private BookingService $booking;

    private LabOrders $labs;

    private Doctor $doctor;

    private Location $branch;

    /** @var array<int, int> weekday => the doctor's sitting there */
    private array $sittings = [];

    /** @var array<string, array<string, true>> date => slots already taken */
    private array $taken = [];

    /** The real moment the run started, before any clock is wound back. */
    private Carbon $now;

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly PrescriptionService $prescriptions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organization = $this->organization();

        if (! $organization) {
            return self::FAILURE;
        }

        (new TenantConnectionService)->connect($organization->database_name);

        try {
            $doctor = $this->findDoctor();

            if (! $doctor) {
                return self::FAILURE;
            }

            $this->doctor = $doctor;
            $this->now = Carbon::now();

            // Built here rather than injected: without the billing trigger,
            // completing a visit or a lab order draws no invoice.
            $this->visits = new VisitWorkflow;
            $this->booking = new BookingService(app(AvailabilityService::class));
            $this->labs = new LabOrders($this->visits);

            $this->info("Building {$doctor->name}'s practice in {$organization->organization_name}.");

            if ($this->option('fresh')) {
                $this->forget();
            }

            $this->branch = $this->branch();
            $this->sit();

            // Today's list alone takes two dozen people.
            $patients = $this->patients(max(30, (int) $this->option('patients')));

            $this->history($patients);
            $this->today($patients);
            $this->upcoming($patients);
        } finally {
            Carbon::setTestNow();
            (new TenantConnectionService)->disconnect();
        }

        $this->newLine();
        $this->info('Done. Sign in as the doctor on the app, or open their OPD on the web.');

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------- setup */

    private function organization(): ?Organization
    {
        $asked = $this->option('org');

        if ($asked) {
            $organization = Organization::query()
                ->when(is_numeric($asked), fn ($query) => $query->whereKey((int) $asked))
                ->when(! is_numeric($asked), fn ($query) => $query->where('subdomain', $asked))
                ->first();

            if (! $organization) {
                $this->error("No organization matches \"{$asked}\".");
            }

            return $organization;
        }

        $all = Organization::all();

        if ($all->count() === 1) {
            return $all->first();
        }

        $this->error('Name one with --org=<id|subdomain>:');

        foreach ($all as $organization) {
            $this->line("  {$organization->id}  {$organization->subdomain}  {$organization->organization_name}");
        }

        return null;
    }

    private function findDoctor(): ?Doctor
    {
        $asked = (string) $this->option('doctor');

        $doctor = $asked === '' ? null : Doctor::query()
            ->when(is_numeric($asked), fn ($query) => $query->whereKey((int) $asked))
            ->when(! is_numeric($asked), fn ($query) => $query->where('name', $asked))
            ->first();

        if ($doctor) {
            return $doctor;
        }

        $this->error($asked === '' ? 'Name the doctor with --doctor=<id|name>:' : "No doctor matches \"{$asked}\". The doctors here:");

        foreach (Doctor::orderBy('id')->get() as $row) {
            $this->line("  {$row->id}  {$row->name}");
        }

        return null;
    }

    /** Where the doctor already sits, or the first branch. */
    private function branch(): Location
    {
        $id = DoctorSchedule::where('doctor_id', $this->doctor->id)->where('is_active', true)->value('location_id');

        return Location::find($id) ?? Location::active()->orderBy('id')->firstOrFail();
    }

    /**
     * A sitting on every day of the week the doctor has none.
     *
     * Long ones, 08:00 to 23:45, because this is a doctor for looking at the
     * screens with and the screens are looked at whenever somebody is
     * working — a sitting that has ended by the evening shows an empty day.
     * Sittings they already have are left alone.
     */
    private function sit(): void
    {
        $sits = DoctorSchedule::where('doctor_id', $this->doctor->id)->where('is_active', true)->pluck('weekday')->all();
        $added = 0;

        foreach (range(Weekday::MONDAY, Weekday::SUNDAY) as $weekday) {
            if (in_array($weekday, $sits, true)) {
                continue;
            }

            DoctorSchedule::create([
                'doctor_id' => $this->doctor->id,
                'location_id' => $this->branch->id,
                'name' => 'OPD',
                'weekday' => $weekday,
                'starts_at' => '08:00',
                'ends_at' => '23:45',
                'slot_minutes' => 15,
                'is_active' => true,
            ]);

            $added++;
        }

        $this->sittings = DoctorSchedule::where('doctor_id', $this->doctor->id)
            ->where('location_id', $this->branch->id)
            ->where('is_active', true)
            ->get()
            ->mapWithKeys(fn (DoctorSchedule $schedule) => [$schedule->weekday => $schedule->id])
            ->all();

        $this->line("  Branch: {$this->branch->name}. Sittings added: {$added}");
    }

    /** What every patient this makes carries, so `--fresh` can find them again. */
    private function mark(): string
    {
        return "Demo patient (doctor:demo #{$this->doctor->id})";
    }

    /**
     * Everything an earlier run made for this doctor: its patients and all
     * that hangs off them, children before parents.
     */
    private function forget(): void
    {
        $ids = Customer::withTrashed()->where('notes', $this->mark())->pluck('id');

        if ($ids->isEmpty()) {
            $this->line('  Nothing from an earlier run to remove.');

            return;
        }

        $orders = LabOrder::withTrashed()->whereIn('customer_id', $ids)->pluck('id');
        $scripts = Prescription::withTrashed()->whereIn('customer_id', $ids)->pluck('id');

        LabOrderItem::withTrashed()->whereIn('lab_order_id', $orders)->forceDelete();
        LabOrder::withTrashed()->whereIn('id', $orders)->forceDelete();
        PrescriptionItem::withTrashed()->whereIn('prescription_id', $scripts)->forceDelete();
        Prescription::withTrashed()->whereIn('id', $scripts)->forceDelete();
        Consultation::whereIn('customer_id', $ids)->delete();
        Appointment::withTrashed()->whereIn('customer_id', $ids)->forceDelete();
        Customer::withTrashed()->whereIn('id', $ids)->forceDelete();

        $this->line("  Removed {$ids->count()} patients from an earlier run, with their visits.");
    }

    /**
     * The patients, each with what they live with.
     *
     * Through the repository, so every one gets its UHID the way a real
     * registration does. A name is drawn from the list for their gender, so
     * nobody called Priya is down as male.
     *
     * @return Collection<int, array{customer: Customer, chronic: list<string>, newcomer: bool}>
     */
    private function patients(int $wanted): Collection
    {
        $made = collect();

        for ($n = 0; $n < $wanted; $n++) {
            // About one in eight has not been seen before: their first visit
            // is today or still to come.
            $newcomer = random_int(1, 100) <= 12;

            // Registered before their first visit — months ago for a regular,
            // this week for somebody new.
            Carbon::setTestNow($newcomer
                ? $this->now->copy()->subDays(random_int(0, 3))->setTime(random_int(9, 19), random_int(0, 59))
                : $this->now->copy()->subDays(random_int(self::HISTORY_DAYS + 1, 400))->setTime(random_int(9, 19), random_int(0, 59)));

            $female = random_int(0, 1) === 1;
            $first = $female ? self::WOMEN[array_rand(self::WOMEN)] : self::MEN[array_rand(self::MEN)];
            $age = $this->age();

            $fields = ['blood_group' => self::BLOOD_GROUPS[array_rand(self::BLOOD_GROUPS)]];

            if (random_int(1, 100) <= 12) {
                $fields['allergies'] = self::ALLERGIES[array_rand(self::ALLERGIES)];
            }

            $customer = $this->customers->create([
                'registered_location_id' => $this->branch->id,
                'name' => $first.' '.self::LAST[array_rand(self::LAST)],
                'phone' => '9'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
                'gender' => $female ? Customer::FEMALE : Customer::MALE,
                'date_of_birth' => $this->now->copy()->subYears($age)->subDays(random_int(0, 364))->toDateString(),
                'city' => self::CITIES[array_rand(self::CITIES)],
                'state' => 'Uttar Pradesh',
                'country' => 'India',
                'custom_fields' => $fields,
                'notes' => $this->mark(),
                'is_active' => true,
            ]);

            // Chronic conditions climb with age, as they do.
            $chronic = collect(self::CHRONIC_SHARE)
                ->filter(fn (int $share) => random_int(1, 100) <= ($age >= 40 ? $share * 1.6 : $share * 0.4))
                ->keys()
                ->all();

            $made->push(['customer' => $customer, 'chronic' => $chronic, 'newcomer' => $newcomer]);
        }

        Carbon::setTestNow();

        $this->line("  Patients: {$made->count()} registered");

        return $made;
    }

    /** Mostly working-age adults, some elderly, a few teenagers. */
    private function age(): int
    {
        $roll = random_int(1, 100);

        return match (true) {
            $roll <= 8 => random_int(14, 19),
            $roll <= 70 => random_int(20, 54),
            default => random_int(55, 84),
        };
    }

    /* -------------------------------------------------------------- history */

    /**
     * Months of visits, run in the order they happened.
     *
     * Planned first and played back in time order, so each day's tokens run
     * in arrival order the way check-in issues them.
     */
    private function history(Collection $patients): void
    {
        $plan = [];

        foreach ($patients as $index => $patient) {
            if ($patient['newcomer']) {
                continue;
            }

            $count = $patient['chronic'] !== [] ? random_int(2, 6) : random_int(1, 3);

            foreach (range(1, $count) as $_) {
                $date = $this->now->copy()->subDays(random_int(1, self::HISTORY_DAYS))->startOfDay();
                $slot = $this->slot($date);

                // A walk-in turns up at some time in the sitting too; it is
                // what puts them in their place in the day's token order.
                $time = $slot ?? self::SLOTS[array_rand(self::SLOTS)];

                $plan[] = ['patient' => $index, 'date' => $date, 'slot' => $slot, 'when' => $date->toDateString().' '.$time];
            }
        }

        usort($plan, fn (array $a, array $b) => $a['when'] <=> $b['when']);

        $bar = $this->output->createProgressBar(count($plan));
        $bar->setFormat('  History   %current%/%max% [%bar%] %percent:3s%%');

        foreach ($plan as $visit) {
            $patient = $patients[$visit['patient']];
            $roll = random_int(1, 100);

            if ($roll <= 5 && $visit['slot']) {
                $this->missed($patient['customer'], $visit['date'], $visit['slot'], noShow: true);
            } elseif ($roll <= 8 && $visit['slot']) {
                $this->missed($patient['customer'], $visit['date'], $visit['slot'], noShow: false);
            } else {
                $at = Carbon::parse($visit['when'])->subMinutes(random_int(0, 10));

                $this->seen($patient, $at, $visit['slot'], settle: true);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
    }

    /** A free bookable time on that date, or null for a walk-in. */
    private function slot(Carbon $date): ?string
    {
        if (random_int(1, 100) <= 35) {
            return null;
        }

        $key = $this->takenOn($date);

        $free = array_values(array_diff(self::SLOTS, array_keys($this->taken[$key])));

        if ($free === []) {
            return null;
        }

        $slot = $free[array_rand($free)];
        $this->taken[$key][$slot] = true;

        return $slot;
    }

    /**
     * Reads the times already booked on a date, once, so a second run — or a
     * real booking — is never given the same slot again. The unique index on
     * slots would refuse it, and take the whole run down with it.
     *
     * @return string the date, as the key into `taken`
     */
    private function takenOn(Carbon $date): string
    {
        $key = $date->toDateString();

        $this->taken[$key] ??= Appointment::where('doctor_id', $this->doctor->id)
            ->whereDate('appointment_date', $key)
            ->whereNotNull('slot_at')
            ->where('status', '<>', Appointment::STATUS_CANCELLED)
            ->pluck('slot_at')
            ->mapWithKeys(fn ($slot) => [substr((string) $slot, 0, 5) => true])
            ->all();

        return $key;
    }

    /** A booking that did not happen: a no-show, or one called off. */
    private function missed(Customer $customer, Carbon $date, string $slot, bool $noShow): void
    {
        Carbon::setTestNow($date->copy()->subDays(random_int(1, 6))->setTime(11, 0));

        $appointment = $this->book($customer, $date, $slot);

        Carbon::setTestNow($date->copy()->setTimeFromTimeString($slot));

        $noShow
            ? $this->booking->markNoShow($appointment)
            : $this->booking->cancel($appointment, self::REASONS[array_rand(self::REASONS)]);
    }

    private function book(Customer $customer, Carbon $date, ?string $slot): Appointment
    {
        return Appointment::create([
            'customer_id' => $customer->id,
            'doctor_id' => $this->doctor->id,
            'location_id' => $this->branch->id,
            'doctor_schedule_id' => $this->sittings[Weekday::of($date)] ?? null,
            'appointment_date' => $date->toDateString(),
            'type' => $slot ? Appointment::BOOKED : Appointment::WALK_IN,
            'status' => Appointment::STATUS_BOOKED,
            'slot_at' => $slot,
        ]);
    }

    /**
     * One visit, start to finish, at the time it happened.
     *
     * `settle` is for a past day: the lab reports back the same afternoon,
     * and a prescription past its validity expires, as the nightly job would
     * have done. Today's are left as the doctor left them — waiting on the
     * pharmacy or the lab. `inRoom` stops once the consultation has started,
     * with only what a doctor types first.
     *
     * @param  array{customer: Customer, chronic: list<string>}  $patient
     */
    private function seen(array $patient, Carbon $arrived, ?string $slot, bool $settle, bool $inRoom = false): Appointment
    {
        Carbon::setTestNow($arrived);

        $appointment = $this->book($patient['customer'], $arrived, $slot);
        $this->booking->checkIn($appointment);

        Carbon::setTestNow($arrived->copy()->addMinutes(random_int(4, 14)));
        $this->visits->call($appointment);

        Carbon::setTestNow(Carbon::now()->addMinutes(random_int(1, 3)));
        $this->visits->startConsultation($appointment);

        $key = $this->conditionFor($patient);
        $condition = self::CONDITIONS[$key];

        if ($inRoom) {
            Consultation::where('appointment_id', $appointment->id)->first()->update([
                'chief_complaint' => $condition['complaints'][array_rand($condition['complaints'])],
                'vitals' => $this->vitals($key, $patient['customer']),
            ]);

            return $appointment;
        }

        $this->writeUp($appointment, $patient, $key);

        Carbon::setTestNow(Carbon::now()->addMinutes(random_int(8, 16)));
        $this->visits->completeConsultation($appointment);

        if (! $settle) {
            return $appointment;
        }

        foreach (LabOrder::where('appointment_id', $appointment->id)->get() as $order) {
            Carbon::setTestNow(Carbon::now()->addHours(random_int(2, 5)));
            $this->report($order);
        }

        $expired = Prescription::where('appointment_id', $appointment->id)
            ->where('status', Prescription::ISSUED)
            ->whereDate('valid_until', '<', $this->now->toDateString())
            ->get();

        foreach ($expired as $prescription) {
            $prescription->forceFill(['status' => Prescription::EXPIRED])->save();
        }

        if ($expired->isNotEmpty()) {
            $this->visits->refresh($appointment);
        }

        return $appointment;
    }

    /** A review of something they live with, more often than not; otherwise whatever is going round. */
    private function conditionFor(array $patient): string
    {
        if ($patient['chronic'] !== [] && random_int(1, 100) <= 65) {
            return $patient['chronic'][array_rand($patient['chronic'])];
        }

        $acute = array_keys(array_filter(self::CONDITIONS, fn (array $condition) => empty($condition['chronic'])));

        return $acute[array_rand($acute)];
    }

    /**
     * The write-up, the prescription and the lab order, as the doctor makes
     * them during the visit.
     */
    private function writeUp(Appointment $appointment, array $patient, string $key): void
    {
        $condition = self::CONDITIONS[$key];

        // What else they live with goes down as a second diagnosis.
        $diagnoses = collect([$condition['diagnosis']])
            ->merge(collect($patient['chronic'])->reject(fn (string $other) => $other === $key)->map(fn (string $other) => self::CONDITIONS[$other]['diagnosis']))
            ->take(3)
            ->values()
            ->all();

        // Not every visit sends somebody for tests; a first look at a
        // chronic condition nearly always does.
        $tests = random_int(1, 100) <= 45 ? array_slice($condition['tests'], 0, random_int(1, max(1, count($condition['tests'])))) : [];

        $follow = $condition['follow'][array_rand($condition['follow'])];

        Consultation::where('appointment_id', $appointment->id)->first()->update([
            'chief_complaint' => $condition['complaints'][array_rand($condition['complaints'])],
            'diagnoses' => $diagnoses,
            'vitals' => $this->vitals($key, $patient['customer']),
            'investigations' => array_map(fn (string $test) => ['test' => $test, 'notes' => null], $tests),
            'advice' => $condition['advice'],
            'notes' => $condition['findings'],
            'follow_up_days' => $follow,
        ]);

        if (random_int(1, 100) <= 88) {
            $this->prescribe($appointment, $condition['rx']);
        }

        if ($tests !== []) {
            $this->labs->create($appointment, [
                'items' => array_map(fn (string $test) => ['test_name' => $test, 'specimen' => self::SPECIMENS[$test] ?? 'blood'], $tests),
            ]);
        }
    }

    /**
     * The prescription, drafted through the service and then marked issued.
     *
     * Marked here rather than through `issue()`, which raises the event that
     * can message the patient — these patients are not real, but their phone
     * numbers could be. The columns are the ones `issue()` writes.
     *
     * @param  list<array<int, mixed>>  $lines
     */
    private function prescribe(Appointment $appointment, array $lines): void
    {
        $items = array_map(function (array $line) {
            [$medicine, $dose, $unit, $frequency, $food, $duration] = $line;

            /*
             * How many to hand over, where the dose cannot say: "when needed"
             * has no count, and a syrup, an inhaler or a gel is one pack
             * however it is taken. Tablets on a schedule are left to the
             * service to work out, as it does for a doctor.
             */
            $quantity = match (true) {
                in_array($unit, ['tablet', 'capsule'], true) && $frequency !== 'sos' => null,
                in_array($unit, ['ml', 'puff', 'application'], true) => 1,
                default => 6,
            };

            return [
                ...(is_int($medicine) ? ['medicine_id' => $medicine] : ['medicine_name' => $medicine]),
                ...($quantity === null ? [] : ['prescribed_quantity' => $quantity]),
                'dose_amount' => $dose,
                'dose_unit' => $unit,
                'frequency' => $frequency,
                'food_timing' => $food,
                'duration' => $duration,
                'duration_unit' => $line[6] ?? 'days',
            ];
        }, $lines);

        $prescription = $this->prescriptions->create($appointment, ['items' => $items]);

        $prescription->forceFill([
            'status' => Prescription::ISSUED,
            'issued_at' => Carbon::now(),
            'valid_until' => Carbon::now()->addDays(Prescription::DEFAULT_VALID_DAYS)->toDateString(),
        ])->save();
    }

    /** The lab picks the order up and reports every test on it. */
    private function report(LabOrder $order): void
    {
        $this->labs->start($order);

        foreach ($order->items()->get() as $item) {
            $this->labs->record($item, $this->result($item->test_name));
        }

        $this->labs->complete($order->fresh());
    }

    /**
     * A believable reading for a test, abnormal about as often as a clinic's
     * would be for the people sent for it.
     *
     * @return array<string, mixed>
     */
    private function result(string $test): array
    {
        $number = fn (float $low, float $high, int $places = 1) => round($low + (mt_rand() / mt_getrandmax()) * ($high - $low), $places);

        [$value, $unit, $range, $abnormal] = match ($test) {
            'HbA1c' => [$a = $number(5.4, 9.6), '%', '4.0 – 5.6', $a > 5.6],
            'Fasting blood sugar' => [$a = (int) $number(88, 196, 0), 'mg/dL', '70 – 100', $a > 100],
            'Lipid profile' => [$a = (int) $number(168, 262, 0), 'mg/dL total cholesterol', '< 200', $a >= 200],
            'Serum creatinine' => [$a = $number(0.6, 1.5, 2), 'mg/dL', '0.6 – 1.2', $a > 1.2],
            'TSH' => [$a = $number(1.2, 9.8, 2), 'µIU/mL', '0.4 – 4.0', $a > 4.0],
            'Vitamin D (25-OH)' => [$a = $number(8, 36), 'ng/mL', '30 – 100', $a < 30],
            'Serum ferritin' => [$a = $number(6, 60), 'ng/mL', '15 – 150', $a < 15],
            'CBC' => [$a = $number(9.2, 14.8), 'g/dL haemoglobin', '12.0 – 16.0', $a < 12],
            'Dengue NS1' => [($a = random_int(1, 100) <= 12) ? 'Positive' : 'Negative', null, 'Negative', $a],
            'ECG' => [($a = random_int(1, 100) <= 20) ? 'LVH voltage criteria' : 'Normal sinus rhythm', null, null, $a],
            'Urine routine' => [($a = random_int(1, 100) <= 60) ? 'Pus cells 12–15/hpf' : 'Pus cells 1–2/hpf', null, '0 – 5/hpf', $a],
            'Urine culture' => [($a = random_int(1, 100) <= 55) ? 'E. coli > 10⁵ CFU/mL' : 'No growth', null, 'No growth', $a],
            'Stool routine' => [($a = random_int(1, 100) <= 30) ? 'Pus cells present' : 'No abnormality', null, null, $a],
            default => ['Within normal limits', null, null, false],
        };

        return [
            'result_value' => (string) $value,
            'result_unit' => $unit,
            'reference_range' => $range,
            'is_abnormal' => $abnormal,
        ];
    }

    /**
     * Vitals that fit the visit: raised BP for a hypertensive, a fever for a
     * fever, the rest in the ordinary range.
     *
     * @return array<string, int|float>
     */
    private function vitals(string $key, Customer $customer): array
    {
        $female = $customer->gender === Customer::FEMALE;
        $height = $female ? random_int(148, 166) : random_int(160, 182);

        return [
            'bp_systolic' => $key === 'hypertension' ? random_int(142, 168) : random_int(108, 136),
            'bp_diastolic' => $key === 'hypertension' ? random_int(88, 102) : random_int(68, 86),
            'pulse' => in_array($key, ['viral_fever', 'gastroenteritis'], true) ? random_int(96, 112) : random_int(68, 92),
            'temperature' => in_array($key, ['viral_fever', 'urti'], true) ? round(37.8 + random_int(0, 15) / 10, 1) : round(36.5 + random_int(0, 6) / 10, 1),
            'spo2' => $key === 'asthma' ? random_int(92, 96) : random_int(97, 100),
            'weight' => round(($height - 100) * ($female ? 0.95 : 1.0) + random_int(-8, 14), 1),
            'height' => $height,
        ];
    }

    /* ---------------------------------------------------------------- today */

    /**
     * Today's list, in every state a visit can be in, timed back from now:
     * nine seen, one in the room, one called, six waiting, five still to
     * arrive and two called off.
     *
     * Seen earlier today means waiting on the pharmacy or the lab, or done —
     * whichever the visit produced — because nothing settles them.
     */
    private function today(Collection $patients): void
    {
        $today = $this->now->copy()->startOfDay();

        // Mostly regulars, with the newcomers whose first visit is today.
        $newcomers = $patients->filter(fn (array $patient) => $patient['newcomer'])->values();
        $regulars = $patients->reject(fn (array $patient) => $patient['newcomer'])->shuffle()->values();
        $list = $newcomers->take(6)->concat($regulars->take(24 - min(6, $newcomers->count())))->shuffle()->values();

        /*
         * The seen ones arrive spread over the sitting so far, the last of
         * them fifty minutes ago — long enough that even a slow visit has
         * finished by now. Nothing here may happen after the real clock: a
         * visit completed "in ten minutes" would be a state no screen should
         * ever have to show.
         */
        $opened = $today->copy()->setTime(8, 0);
        $lastSeen = $this->now->copy()->subMinutes(50);
        $gap = min(35, max(12, (int) floor(max(0, $opened->diffInMinutes($lastSeen, false)) / 9)));
        $at = $lastSeen->copy()->subMinutes($gap * 8);

        $n = 0;

        foreach (range(1, 9) as $_) {
            $this->seen($list[$n++], $at->copy(), $this->slotAt($today, $at), settle: false);
            $at->addMinutes($gap);
        }

        // In the room: arrived after the last of those, called and started
        // a few minutes ago.
        $this->seen($list[$n++], $this->now->copy()->subMinutes(24), null, settle: false, inRoom: true);

        // Called through, on their way in.
        $called = $this->arrive($list[$n++]['customer'], $this->now->copy()->subMinutes(21));
        Carbon::setTestNow($this->now->copy()->subMinutes(2));
        $this->visits->call($called);

        // Waiting, longest first.
        foreach ([19, 15, 11, 8, 5, 2] as $minutes) {
            $this->arrive($list[$n++]['customer'], $this->now->copy()->subMinutes($minutes));
        }

        // Still to come, and two who called it off.
        foreach ($this->laterSlots($today, 7) as $index => $slot) {
            Carbon::setTestNow($this->now->copy()->subDays(random_int(1, 5)));
            $appointment = $this->book($list[$n++]['customer'], $today, $slot);

            if ($index >= 5) {
                Carbon::setTestNow($this->now->copy()->subHours(random_int(1, 4)));
                $this->booking->cancel($appointment, self::REASONS[array_rand(self::REASONS)]);
            }
        }

        $this->line('  Today: 9 seen, 1 in the room, 1 called, 6 waiting, 5 still to come, 2 cancelled');
    }

    /** A walk-in who has just checked in. */
    private function arrive(Customer $customer, Carbon $at): Appointment
    {
        Carbon::setTestNow($at);

        $appointment = $this->book($customer, $at, null);
        $this->booking->checkIn($appointment);

        return $appointment;
    }

    /** The booked time nearest a moment, if it is free; otherwise they walked in. */
    private function slotAt(Carbon $date, Carbon $at): ?string
    {
        if (random_int(1, 100) <= 40) {
            return null;
        }

        $slot = $at->copy()->minute((int) (floor($at->minute / 15) * 15))->format('H:i');
        $key = $this->takenOn($date);

        if (isset($this->taken[$key][$slot])) {
            return null;
        }

        $this->taken[$key][$slot] = true;

        return $slot;
    }

    /**
     * Free quarter hours after now, up to the sitting's end — and before now
     * if the evening has run out, for somebody running late.
     *
     * @return list<string>
     */
    private function laterSlots(Carbon $date, int $wanted): array
    {
        $key = $this->takenOn($date);
        $slots = [];
        $at = $this->now->copy()->addMinutes(15 - $this->now->minute % 15)->second(0);
        $end = $date->copy()->setTime(23, 45);

        while (count($slots) < $wanted && $at->lessThanOrEqualTo($end)) {
            if (! isset($this->taken[$key][$at->format('H:i')])) {
                $slots[] = $at->format('H:i');
            }

            $at->addMinutes(15);
        }

        $at = $this->now->copy()->subMinutes($this->now->minute % 15)->second(0);

        while (count($slots) < $wanted && $at->greaterThan($date->copy()->setTime(8, 0))) {
            if (! isset($this->taken[$key][$at->format('H:i')])) {
                $slots[] = $at->format('H:i');
            }

            $at->subMinutes(15);
        }

        foreach ($slots as $slot) {
            $this->taken[$key][$slot] = true;
        }

        return $slots;
    }

    /* ------------------------------------------------------------- upcoming */

    /**
     * The next three weeks' bookings: every review the doctor asked for that
     * falls in them, and some new bookings besides.
     */
    private function upcoming(Collection $patients): void
    {
        $from = $this->now->copy()->addDay()->startOfDay();
        $until = $from->copy()->addDays(20);
        $made = 0;

        foreach ($patients as $patient) {
            $last = Consultation::where('customer_id', $patient['customer']->id)
                ->whereNotNull('follow_up_days')
                ->latest('id')
                ->first();

            $due = $last ? $last->created_at->copy()->startOfDay()->addDays($last->follow_up_days) : null;

            // A review falling in the window is booked; newcomers book a first
            // visit; and a few others simply make an appointment.
            $date = match (true) {
                $due !== null && $due->betweenIncluded($from, $until) && random_int(1, 100) <= 75 => $due,
                $patient['newcomer'] && random_int(1, 100) <= 60 => $from->copy()->addDays(random_int(0, 13)),
                random_int(1, 100) <= 8 => $from->copy()->addDays(random_int(0, 20)),
                default => null,
            };

            if (! $date) {
                continue;
            }

            $slot = $this->bookableSlot($date);

            if (! $slot) {
                continue;
            }

            Carbon::setTestNow($this->now->copy()->subDays(random_int(0, 6)));
            $this->book($patient['customer'], $date, $slot);
            $made++;
        }

        $this->line("  Upcoming: {$made} bookings over the next three weeks");
    }

    private function bookableSlot(Carbon $date): ?string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $slot = $this->slot($date);

            if ($slot) {
                return $slot;
            }
        }

        return null;
    }
}
