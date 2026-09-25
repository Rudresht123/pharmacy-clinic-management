<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Support\Documents\DocumentCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Attaching one file to a patient.
 *
 * The capability is the route's. What this asks is whether the thing being
 * attached is a document at all, and whether the visit named belongs to the
 * patient named — an appointment id from another patient's record would
 * otherwise file this person's scan under that person's visit.
 */
class StorePatientDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                /*
                 * An allow-list of what a scanner, a phone or a lab actually
                 * produces. SVG is excluded for the same reason it is on a
                 * doctor's photograph: it is a document that can carry script.
                 */
                'mimes:pdf,jpg,jpeg,png,webp,heic,heif,tif,tiff,doc,docx',
                'max:20480',
            ],

            'category' => ['required', 'string', Rule::in(DocumentCategories::keys())],

            /*
             * The model class, not the table name: `Rule::exists('table')`
             * resolves against the DEFAULT connection, which is the master
             * database, and the appointment is the tenant's.
             */
            'appointment_id' => ['nullable', Rule::exists(Appointment::class, 'id')],

            'title' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $appointmentId = $this->input('appointment_id');

                if (! $appointmentId) {
                    return;
                }

                $customer = $this->route('customer');

                if (! $customer instanceof Customer) {
                    return;
                }

                $belongs = Appointment::query()
                    ->whereKey($appointmentId)
                    ->where('customer_id', $customer->getKey())
                    ->exists();

                if (! $belongs) {
                    $validator->errors()->add(
                        'appointment_id',
                        'That visit belongs to a different patient.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Attach a PDF, an image or a Word document.',
            'file.max' => 'That file is larger than 20 MB.',
            'category.in' => 'Choose what kind of document this is.',
        ];
    }
}
