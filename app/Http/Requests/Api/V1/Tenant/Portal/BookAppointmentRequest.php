<?php

namespace App\Http\Requests\Api\V1\Tenant\Portal;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Services\Portal\BookableDoctors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A patient booking for themselves.
 *
 * No `customer_id` — who the booking is for comes from the signed-in login,
 * never from the request — and no `type`, because a patient can only book a
 * time; walk-ins are something that happens at the desk.
 */
class BookAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'doctor_id' => [
                'required', 'integer',
                Rule::exists(Doctor::class, 'id')->whereNull('deleted_at')->where('is_active', true),
            ],
            'location_id' => [
                'required', 'integer',
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],
            'appointment_date' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:today',
                'before_or_equal:'.now()->addDays(BookableDoctors::DAYS_AHEAD)->toDateString(),
            ],
            'slot_at' => ['required', 'date_format:H:i'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'appointment_date.after_or_equal' => 'That date has already gone.',
            'appointment_date.before_or_equal' => 'Bookings open '.BookableDoctors::DAYS_AHEAD.' days ahead.',
        ];
    }
}
