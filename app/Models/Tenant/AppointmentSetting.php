<?php

namespace App\Models\Tenant;

use App\Support\History\RecordsHistory;
use Illuminate\Database\Eloquent\Model;

/**
 * How appointments run across the organization. One row.
 *
 *   reschedule_notify   who hears when a patient asks to move a booking —
 *                       the desk that answers it, the doctor whose day it
 *                       changes, or both (an app notification and an email)
 *   check_in_by         who checks a patient in, calls them through, and
 *   call_by             starts their consultation — the desk, the doctor,
 *   consult_start_by    or either. Not every clinic has a receptionist.
 *
 * Every value is one of `desk`, `doctor`, `both`.
 */
class AppointmentSetting extends Model
{
    use RecordsHistory;

    /** The people at the visit's branch who run the queue — receptionists, the branch manager. */
    public const DESK = 'desk';

    public const DOCTOR = 'doctor';

    public const BOTH = 'both';

    public const OPTIONS = [self::DESK, self::DOCTOR, self::BOTH];

    /** The queue's steps, keyed as the API names them, and the column each is set in. */
    public const STEPS = [
        'check_in' => 'check_in_by',
        'call' => 'call_by',
        'consult_start' => 'consult_start_by',
    ];

    protected $connection = 'organization';

    protected $fillable = [
        'reschedule_notify',
        'check_in_by',
        'call_by',
        'consult_start_by',
    ];

    /** Before anybody has saved the screen: what the software did before there was a choice. */
    protected $attributes = [
        'reschedule_notify' => self::DESK,
        'check_in_by' => self::BOTH,
        'call_by' => self::BOTH,
        'consult_start_by' => self::DOCTOR,
    ];

    /**
     * The organization's settings — the saved row, or the defaults unsaved.
     * Reading never writes: a session asking who may check in should not
     * create a row as a side effect.
     */
    public static function current(): self
    {
        return static::query()->first() ?? new static;
    }

    /** Who may do [$step] — `check_in`, `call` or `consult_start`. */
    public function whoFor(string $step): string
    {
        return (string) $this->getAttribute(self::STEPS[$step]);
    }

    public function notifiesDesk(): bool
    {
        return in_array($this->reschedule_notify, [self::DESK, self::BOTH], true);
    }

    public function notifiesDoctor(): bool
    {
        return in_array($this->reschedule_notify, [self::DOCTOR, self::BOTH], true);
    }

    protected function historyLabel(): ?string
    {
        return 'Appointment settings';
    }
}
