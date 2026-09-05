<?php

namespace App\Console\Commands;

use App\Models\Platform\Organization;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use App\Services\Tenancy\TenantConnectionService;
use App\Support\Opd\Weekday;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * A department's worth of OPD data, for looking at the screens with.
 *
 * The board and the queue are both about *shape* — twelve waiting, one of
 * them for twenty-one minutes, four doctors of whom one is free. None of that
 * can be judged against three patients and no appointments, so this builds a
 * busy morning to judge it against.
 *
 * Deliberately not a factory or a seeder class: it is run by hand against a
 * chosen organization's database, never as part of `db:seed`, because it
 * writes real rows into a real tenant and nothing should do that by accident.
 *
 * Idempotent by intent rather than by upsert — running it twice gives two
 * days' worth rather than a crash, and `--fresh` clears the days it manages
 * first.
 */
class SeedOpdDemoCommand extends Command
{
    protected $signature = 'opd:demo
        {--org= : Organization id or subdomain; the only one, if there is only one}
        {--patients=120 : How many patients to make sure exist}
        {--days=3 : Days of history to build, ending today}
        {--fresh : Delete the appointments in that window first}';

    protected $description = 'Build a realistic OPD day in one tenant, for looking at the screens with';

    /** Enough names that a list of a hundred does not read as a list of five. */
    private const FIRST = [
        'Aarav', 'Vivaan', 'Aditya', 'Rahul', 'Arjun', 'Kabir', 'Ishaan', 'Vihaan',
        'Rohan', 'Karan', 'Manish', 'Sanjay', 'Vikram', 'Nikhil', 'Amit', 'Rajesh',
        'Ananya', 'Diya', 'Aadhya', 'Priya', 'Neha', 'Sneha', 'Kavya', 'Meera',
        'Pooja', 'Anjali', 'Ritu', 'Sunita', 'Asha', 'Shruti', 'Nisha', 'Divya',
    ];

    private const LAST = [
        'Sharma', 'Verma', 'Gupta', 'Singh', 'Kumar', 'Patel', 'Reddy', 'Nair',
        'Iyer', 'Rao', 'Joshi', 'Mehta', 'Desai', 'Kulkarni', 'Chauhan', 'Bhatt',
        'Menon', 'Pillai', 'Shetty', 'Malhotra', 'Kapoor', 'Bansal', 'Saxena', 'Dubey',
    ];

    private const CITIES = ['Pune', 'Mumbai', 'Nashik', 'Nagpur', 'Thane', 'Aurangabad'];

    /** Name, speciality, and how long they take per patient. */
    private const DOCTORS = [
        ['Dr. Anjali Sharma', 'General Medicine', 'MBBS, MD', 15],
        ['Dr. Vikram Rao', 'Cardiology', 'MBBS, DM', 20],
        ['Dr. Meera Iyer', 'Paediatrics', 'MBBS, DCH', 10],
        ['Dr. Rajesh Menon', 'Orthopaedics', 'MBBS, MS', 20],
        ['Dr. Sneha Kulkarni', 'Dermatology', 'MBBS, MD', 15],
        ['Dr. Arjun Bhatt', 'ENT', 'MBBS, MS', 15],
    ];

    /** Why an appointment was called off. */
    private const REASONS = [
        'Patient rescheduled',
        'Doctor called away',
        'Booked twice by mistake',
        'Patient went elsewhere',
    ];

    /**
     * How long each doctor takes, by name.
     *
     * The simulation needs this per doctor rather than per position, because
     * the doctors sitting at a branch on a given day are a filtered subset —
     * their index in that list is not their index in self::DOCTORS.
     *
     * @return array<string, int>
     */
    private const MINUTES = [
        'Dr. Anjali Sharma' => 15,
        'Dr. Vikram Rao' => 20,
        'Dr. Meera Iyer' => 10,
        'Dr. Rajesh Menon' => 20,
        'Dr. Sneha Kulkarni' => 15,
        'Dr. Arjun Bhatt' => 15,
    ];

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $organization = $this->organization();

        if (! $organization) {
            return self::FAILURE;
        }

        $this->info("Seeding OPD demo data into {$organization->organization_name}.");

        (new TenantConnectionService)->connect($organization->database_name);

        try {
            $branches = $this->branches();
            $doctors = $this->doctors($branches);
            $patients = $this->patients((int) $this->option('patients'));

            $this->line('');

            $made = 0;

            /*
             * A quarter of the book is held back for today.
             *
             * Without this every patient has been seen on one of the earlier
             * days, so "new versus follow-up" reads as a hundred per cent
             * follow-up and the split is a chart of one thing. Reserving a
             * slice for the last day is what makes some of today genuinely
             * somebody's first visit.
             */
            $regulars = $patients->take((int) ceil($patients->count() * 0.75))->values();

            for ($back = (int) $this->option('days') - 1; $back >= 0; $back--) {
                $date = now()->subDays($back)->startOfDay();
                $pool = $back === 0 ? $patients : $regulars;

                if ($this->option('fresh')) {
                    Appointment::whereDate('appointment_date', $date->toDateString())->forceDelete();
                }

                // Every branch, not just the first: the branch picker and the
                // switcher are only worth anything when the two answers differ.
                foreach ($branches as $branch) {
                    $made += $this->day($date, $branch, $doctors, $pool);
                }
            }

            $this->line('');
            $this->info("Done. {$made} appointments across ".$this->option('days').' day(s).');
            $this->line('Open /opd to see it.');
        } finally {
            (new TenantConnectionService)->disconnect();
        }

        return self::SUCCESS;
    }

    /* ---------------------------------------------------------------- setup */

    private function organization(): ?Organization
    {
        $asked = $this->option('org');

        if ($asked) {
            $organization = Organization::where('id', $asked)
                ->orWhere('subdomain', $asked)
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

    /** Whatever branches exist, or one if there are none. */
    private function branches()
    {
        $branches = Location::active()->orderBy('id')->get();

        if ($branches->isEmpty()) {
            $branches = collect([Location::create([
                'name' => 'Main Clinic',
                'code' => 'MAIN',
                'type' => Location::CLINIC,
                'city' => 'Pune',
                'is_active' => true,
            ])]);

            $this->line('  Created a branch: Main Clinic');
        }

        $this->line('  Branches: '.$branches->pluck('name')->join(', '));

        return $branches;
    }

    /**
     * Six doctors, each sitting somewhere every weekday.
     *
     * Sittings for the whole week rather than one day, so moving the date on
     * the board shows a department rather than an empty room.
     */
    private function doctors($branches)
    {
        $doctors = collect();

        foreach (self::DOCTORS as $index => [$name, $speciality, $qualification, $minutes]) {
            $doctor = Doctor::firstOrCreate(
                ['name' => $name],
                [
                    'specialisation' => $speciality,
                    'qualification' => $qualification,
                    'default_consultation_fee' => 400 + ($index * 150),
                    'is_active' => true,
                ],
            );

            // Alternating branches, so a two-branch clinic has a real split
            // rather than every doctor sitting in the same room.
            $branch = $branches[$index % $branches->count()];

            foreach (range(Weekday::MONDAY, Weekday::SUNDAY) as $weekday) {
                // Sunday off, which is what makes an empty day worth looking at.
                if ($weekday === Weekday::SUNDAY) {
                    continue;
                }

                DoctorSchedule::firstOrCreate(
                    [
                        'doctor_id' => $doctor->id,
                        'location_id' => $branch->id,
                        'weekday' => $weekday,
                        'starts_at' => '09:00',
                    ],
                    [
                        'name' => 'Morning OPD',
                        'ends_at' => '13:00',
                        'slot_minutes' => $minutes,
                        'is_active' => true,
                    ],
                );
            }

            $doctors->push($doctor);
        }

        $this->line("  Doctors: {$doctors->count()}, each sitting Mon–Sat 09:00–13:00");

        return $doctors;
    }

    /**
     * Enough patients that the search box has something to find.
     *
     * Through the repository, not the model, so every one gets its number the
     * same way a real registration does.
     */
    private function patients(int $wanted)
    {
        $existing = Customer::count();
        $missing = max(0, $wanted - $existing);

        for ($n = 0; $n < $missing; $n++) {
            $this->customers->create([
                'name' => self::FIRST[array_rand(self::FIRST)].' '.self::LAST[array_rand(self::LAST)],

                // Unique by construction: the phone column has a partial
                // unique index and a collision would abort the whole run.
                'phone' => '9'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),

                'gender' => [Customer::MALE, Customer::FEMALE, Customer::OTHER][random_int(0, 2)],
                'date_of_birth' => now()->subYears(random_int(1, 84))->subDays(random_int(0, 364))->toDateString(),
                'city' => self::CITIES[array_rand(self::CITIES)],
                'is_active' => true,
            ]);
        }

        $this->line("  Patients: {$existing} already here, {$missing} added");

        return Customer::inRandomOrder()->get();
    }

    /* ------------------------------------------------------------ the day */

    /**
     * One day at one branch, simulated rather than sprinkled.
     *
     * The first version of this drew each appointment's timestamps
     * independently — arrive, then be seen four to twenty minutes later. Every
     * status came out right and the board's counts looked fine, but the queue
     * never got deeper than one, because nothing in it modelled the reason
     * queues exist: arrivals outpacing service.
     *
     * So this runs the morning instead. Patients turn up a little faster than
     * the doctor can see them, each consultation starts when the room frees
     * rather than when the patient arrives, and where the clock falls through
     * that sequence is what decides each status. Waits, queue depth and the
     * flow chart all then agree with each other because they are all reading
     * the same simulated morning.
     */
    private function day(Carbon $date, Location $branch, $doctors, $patients): int
    {
        $today = $date->isToday();
        $made = 0;

        // Doctors actually sitting here on this date.
        $sitting = $doctors->filter(
            fn (Doctor $doctor) => DoctorSchedule::where('doctor_id', $doctor->id)
                ->where('location_id', $branch->id)
                ->where('weekday', Weekday::of($date))
                ->where('is_active', true)
                ->exists()
        )->values();

        if ($sitting->isEmpty()) {
            $this->line("  {$date->toDateString()}: nobody sitting — skipped");

            return 0;
        }

        /*
         * The clock we simulate up to.
         *
         * Today that is now, which is what leaves the morning half-finished:
         * some seen, some in rooms, some waiting. A past day runs to the end
         * of the session, because a Tuesday where four patients are still
         * "waiting" is a state the screen would then have to render as if it
         * were true.
         */
        $clock = $today ? now() : $date->copy()->setTime(14, 0);

        $sessionStart = $today
            ? $clock->copy()->subHours(3)->startOfHour()
            : $date->copy()->setTime(9, 0);

        foreach ($sitting as $index => $doctor) {
            $token = 0;

            /*
             * A deliberately uneven split. Every doctor with the same list
             * makes the board look computed; one doctor with six waiting and
             * another with none is what a real morning looks like, and it is
             * the case the layout has to survive.
             */
            $count = $today
                ? [14, 11, 16, 8, 12, 6][$index % 6]
                : random_int(9, 18);

            $consult = self::MINUTES[$doctor->name] ?? 15;

            /*
             * Arrivals run a little faster than the doctor can see people.
             *
             * This is the whole reason a queue forms, and the first version of
             * this command missed it — every patient was drawn independently,
             * so the queue never got deeper than one and the flow chart was a
             * flat line whatever the counts said.
             *
             * 0.85 rather than something more dramatic. At 0.72 a ten-minute
             * doctor accumulated a backlog of two and a half hours over one
             * morning, which is not a busy clinic — it is a clinic where
             * everybody would have gone home. The gentler ratio lands the
             * longest waits in the twenty-to-fifty minute band, which is
             * exactly the range the board's thresholds are about.
             */
            $arriveGap = max(4, (int) round($consult * 0.85));

            $arriveAt = $sessionStart->copy()->addMinutes(random_int(0, 9));
            $freeAt = $sessionStart->copy();

            // The promised times, which follow the sitting rather than the
            // arrivals: being early or late is the difference between them.
            $slot = $sessionStart->copy();

            foreach (range(1, $count) as $n) {
                $patient = $patients[($index * 37 + $n * 7) % $patients->count()];
                $walkIn = random_int(1, 100) <= 35;

                $arrival = $arriveAt->copy();
                $arriveAt->addMinutes($arriveGap + random_int(-3, 7));

                /*
                 * Decided before the timings, not after.
                 *
                 * Somebody who never came does not consume the doctor's time,
                 * and deciding this afterwards let a no-show hold the
                 * consultation slot that straddled the clock — so a branch
                 * with sixteen people waiting reported nobody in a room, which
                 * is the one state a department cannot actually be in.
                 */
                $noShow = random_int(1, 100) <= 6;
                $cancelled = ! $noShow && random_int(1, 100) <= 4;

                if ($noShow || $cancelled) {
                    Appointment::create([
                        'customer_id' => $patient->id,
                        'doctor_id' => $doctor->id,
                        'location_id' => $branch->id,
                        'appointment_date' => $date->toDateString(),
                        'type' => $walkIn ? Appointment::WALK_IN : Appointment::BOOKED,
                        'slot_at' => $walkIn ? null : $slot->format('H:i'),
                        'status' => $noShow
                            ? Appointment::STATUS_NO_SHOW
                            : Appointment::STATUS_CANCELLED,
                        'cancellation_reason' => $cancelled
                            ? self::REASONS[array_rand(self::REASONS)]
                            : null,
                    ]);

                    $slot->addMinutes($consult);
                    $made++;

                    continue;
                }

                /*
                 * Running late: turns up after the clock while their slot has
                 * already passed. Without a few of these nothing is ever
                 * overdue, and the Expected tile could only say one thing —
                 * which is a poor way to learn whether it says the other
                 * thing correctly.
                 */
                $late = $today && ! $walkIn && random_int(1, 100) <= 7;

                if ($late) {
                    $arrival = $clock->copy()->addMinutes(random_int(5, 40));
                }

                // The room frees when the last consultation ends, not when
                // this patient arrives. That gap is the wait.
                $startedAt = $arrival->greaterThan($freeAt) ? $arrival->copy() : $freeAt->copy();
                $startedAt->addMinutes(random_int(0, 2));

                /*
                 * Nobody sits for two hours without the desk noticing.
                 *
                 * Left alone, a queue that grows all morning puts the tail of
                 * a long list at a hundred-plus minutes — arithmetically right
                 * and clinically absurd, and it makes the board look broken
                 * rather than busy.
                 *
                 * Measured against the CLOCK, not against the start time. The
                 * first version of this capped `startedAt - arrival`, which for
                 * somebody whose turn is still hours away pushed their arrival
                 * into the future — so a doctor with sixteen patients showed
                 * one seen and fifteen "expected", and the board reported a
                 * department that had barely opened.
                 */
                $waitingNow = $arrival->lessThanOrEqualTo($clock)
                    && $startedAt->greaterThan($clock);

                if ($waitingNow && $arrival->diffInMinutes($clock, false) > 70) {
                    $arrival = $clock->copy()->subMinutes(random_int(8, 52));
                }

                $completedAt = $startedAt->copy()->addMinutes(max(5, $consult + random_int(-4, 9)));
                $freeAt = $completedAt->copy();

                $appointment = new Appointment([
                    'customer_id' => $patient->id,
                    'doctor_id' => $doctor->id,
                    'location_id' => $branch->id,
                    'appointment_date' => $date->toDateString(),
                    'type' => $walkIn ? Appointment::WALK_IN : Appointment::BOOKED,
                    'slot_at' => $walkIn ? null : $slot->format('H:i'),
                    'notes' => null,
                ]);

                $slot->addMinutes($consult);

                /*
                 * Where the clock falls through the sequence above is the
                 * status. Derived rather than chosen, so the timestamps and
                 * the status can never disagree — which is what lets the
                 * board, the wait badges and the flow chart all be read off
                 * the same morning.
                 */
                if ($arrival->greaterThan($clock)) {
                    $appointment->status = Appointment::STATUS_BOOKED;
                } elseif ($completedAt->lessThanOrEqualTo($clock)) {
                    $appointment->status = Appointment::STATUS_COMPLETED;
                    $appointment->checked_in_at = $arrival;
                    $appointment->started_at = $startedAt;
                    $appointment->completed_at = $completedAt;
                    $appointment->token_no = ++$token;
                } elseif ($startedAt->lessThanOrEqualTo($clock)) {
                    $appointment->status = Appointment::STATUS_IN_CONSULTATION;
                    $appointment->checked_in_at = $arrival;
                    $appointment->started_at = $startedAt;
                    $appointment->token_no = ++$token;
                } else {
                    $appointment->status = Appointment::STATUS_CHECKED_IN;
                    $appointment->checked_in_at = $arrival;
                    $appointment->token_no = ++$token;
                }

                $appointment->save();
                $made++;
            }
        }

        $this->line(
            "  {$date->toDateString()} · {$branch->name}: {$made} appointments"
            ." across {$sitting->count()} doctors"
        );

        return $made;
    }

}
