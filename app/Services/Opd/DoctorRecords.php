<?php

namespace App\Services\Opd;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Consultation;
use App\Models\Tenant\Doctor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * What one doctor has done, looked at from four directions.
 *
 * The day answers "who is next". These answer the questions asked afterwards —
 * who did I see last month, what have I prescribed, who is due back — and each
 * is the same handful of rows read a different way rather than a new table.
 *
 * Every method is scoped to one doctor. That is the whole point of these
 * screens: the department's versions already exist, behind capabilities a
 * doctor does not hold.
 */
class DoctorRecords
{
    /** How far back a list reaches when nobody says. */
    private const DEFAULT_DAYS = 30;

    /**
     * Their appointments over a window, whatever became of them.
     *
     * @return list<array<string, mixed>>
     */
    public function appointments(
        Doctor $doctor,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?string $status = null,
    ): array {
        [$start, $end] = $this->window($from, $to);

        return Appointment::on('organization')
            ->with(['customer', 'location', 'consultation'])
            ->where('doctor_id', $doctor->id)
            ->whereBetween('appointment_date', [$start->toDateString(), $end->toDateString()])
            ->when($status, fn ($query, $value) => $query->where('status', $value))
            ->orderByDesc('appointment_date')
            ->orderByDesc('slot_at')
            ->limit(300)
            ->get()
            ->map(fn (Appointment $row) => [
                'id' => $row->id,
                'date' => $row->appointment_date?->toDateString(),
                'slot_at' => $row->slot_at ? substr((string) $row->slot_at, 0, 5) : null,
                'token_no' => $row->token_no,
                'status' => $row->status,
                'type' => $row->type,

                'customer_id' => $row->customer_id,
                'customer_name' => $row->customer?->name,
                'customer_code' => $row->customer?->code,
                'location_name' => $row->location?->name,

                /*
                 * How long they were in the room.
                 *
                 * Worked out from the two timestamps the queue already writes
                 * rather than stored: it is the difference between them, and a
                 * third column holding it would be a number that could disagree
                 * with the two it came from.
                 */
                'minutes' => $this->minutesIn($row),

                /*
                 * The write-up itself, not merely whether there is one.
                 *
                 * "Written up: yes" answers nothing anybody asks of this list —
                 * what was prescribed, what was ordered, what was it for — and
                 * a second request per row to find out would be a list nobody
                 * could scan.
                 */
                'consultation' => $row->consultation ? $this->wroteUp($row->consultation) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * The visits they wrote up.
     *
     * @return list<array<string, mixed>>
     */
    public function consultations(Doctor $doctor, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return $this->written($doctor, $from, $to)
            ->map(fn (Consultation $row) => [
                'id' => $row->id,
                'appointment_id' => $row->appointment_id,
                'on' => $row->appointment?->appointment_date?->toDateString()
                    ?? $row->created_at?->toDateString(),

                'customer_id' => $row->customer_id,
                'customer_name' => $row->customer?->name,
                'customer_code' => $row->customer?->code,

                'minutes' => $row->appointment ? $this->minutesIn($row->appointment) : null,

                'chief_complaint' => $row->chief_complaint,
                'diagnoses' => $row->diagnoses ?? [],
                'advice' => $row->advice,
                'follow_up_days' => $row->follow_up_days,

                'prescription' => $row->prescription ?? [],
                'investigations' => $row->investigations ?? [],
                'prescription_count' => count($row->prescription ?? []),
                'investigation_count' => count($row->investigations ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * Every line they have prescribed or ordered, newest first.
     *
     * One row per line rather than per consultation: "what have I put this
     * patient on" and "how often do I order a CBC" are both questions about
     * lines, and a list of visits would make somebody open each one to answer
     * either.
     *
     * @param  'prescription'|'investigations'  $of
     * @return list<array<string, mixed>>
     */
    public function lines(
        Doctor $doctor,
        string $of,
        ?Carbon $from = null,
        ?Carbon $to = null,
    ): array {
        $rows = [];

        foreach ($this->written($doctor, $from, $to) as $consultation) {
            $on = $consultation->appointment?->appointment_date?->toDateString()
                ?? $consultation->created_at?->toDateString();

            foreach ($consultation->{$of} ?? [] as $index => $line) {
                $rows[] = [
                    'id' => "{$consultation->id}-{$index}",
                    'on' => $on,

                    'customer_id' => $consultation->customer_id,
                    'customer_name' => $consultation->customer?->name,
                    'customer_code' => $consultation->customer?->code,

                    // The line itself, whichever kind it is.
                    'what' => $line['drug'] ?? $line['test'] ?? '—',
                    'dose' => $line['dose'] ?? null,
                    'frequency' => $line['frequency'] ?? null,
                    'duration' => $line['duration'] ?? null,
                    'notes' => $line['notes'] ?? null,

                    // What it was for, so a line is not floating on its own.
                    'diagnoses' => $consultation->diagnoses ?? [],
                ];
            }
        }

        return $rows;
    }

    /**
     * Who is due back, and when.
     *
     * Worked out rather than stored: a follow-up is advice written at a visit
     * — "come back in seven days" — so the date it lands on is the visit's date
     * plus those days. Storing a due date would be storing something already
     * known, and it would go stale the moment the visit was corrected.
     *
     * Nothing here says whether they actually came back; that needs the return
     * visit to point at the one it follows, which no column does yet. So this
     * is "who was asked back", and it says so.
     *
     * @return list<array<string, mixed>>
     */
    public function followUps(Doctor $doctor): array
    {
        $rows = Consultation::on('organization')
            ->with(['customer', 'appointment'])
            ->where('doctor_id', $doctor->id)
            ->whereNotNull('follow_up_days')
            ->orderByDesc('created_at')
            ->limit(300)
            ->get()
            ->map(function (Consultation $row) {
                $seenOn = $row->appointment?->appointment_date ?? $row->created_at;
                $due = $seenOn?->copy()->addDays((int) $row->follow_up_days);

                return [
                    'id' => $row->id,
                    'seen_on' => $seenOn?->toDateString(),
                    'due_on' => $due?->toDateString(),
                    'days' => (int) $row->follow_up_days,

                    // Negative once the date has passed, which is the number
                    // that makes a list of these worth reading.
                    'due_in' => $due ? (int) now()->startOfDay()->diffInDays($due->startOfDay(), false) : null,

                    'customer_id' => $row->customer_id,
                    'customer_name' => $row->customer?->name,
                    'customer_code' => $row->customer?->code,

                    'chief_complaint' => $row->chief_complaint,
                    'diagnoses' => $row->diagnoses ?? [],
                ];
            })
            ->sortBy('due_on')
            ->values()
            ->all();

        return $rows;
    }

    /**
     * How long a visit took, once it is over.
     *
     * Null while somebody is still in the room — a running total belongs on the
     * dashboard, where it counts up; in a list of finished visits a number that
     * grows would be the only row that changed on every refresh.
     *
     * Carbon 3 returns a float from `diffInMinutes` where Carbon 2 returned an
     * int, and an unrounded one has reached a screen in this project before.
     */
    private function minutesIn(Appointment $row): ?int
    {
        if (! $row->started_at || ! $row->completed_at) {
            return null;
        }

        return (int) $row->started_at->diffInMinutes($row->completed_at);
    }

    /**
     * One consultation, as a list row shows it when opened.
     *
     * @return array<string, mixed>
     */
    private function wroteUp(Consultation $row): array
    {
        return [
            'chief_complaint' => $row->chief_complaint,
            'diagnoses' => $row->diagnoses ?? [],
            'vitals' => $row->vitals ?? [],
            'prescription' => $row->prescription ?? [],
            'investigations' => $row->investigations ?? [],
            'advice' => $row->advice,
            'notes' => $row->notes,
            'follow_up_days' => $row->follow_up_days,
        ];
    }

    /**
     * The consultations behind all three of the above.
     *
     * @return Collection<int, Consultation>
     */
    private function written(Doctor $doctor, ?Carbon $from, ?Carbon $to): Collection
    {
        [$start, $end] = $this->window($from, $to);

        return Consultation::on('organization')
            ->with(['customer', 'appointment'])
            ->where('doctor_id', $doctor->id)
            ->whereHas('appointment', fn ($query) => $query
                ->whereBetween('appointment_date', [$start->toDateString(), $end->toDateString()]))
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();
    }

    /**
     * The window a list covers.
     *
     * A month back by default. Unbounded, these screens would grow slower every
     * year a clinic runs, and "everything I have ever done" is not a question
     * anybody opens a list to answer.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function window(?Carbon $from, ?Carbon $to): array
    {
        return [
            $from ?? now()->copy()->subDays(self::DEFAULT_DAYS)->startOfDay(),
            $to ?? now()->copy()->endOfDay(),
        ];
    }
}
