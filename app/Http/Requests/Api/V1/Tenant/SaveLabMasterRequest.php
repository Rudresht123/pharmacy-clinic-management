<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Support\Laboratory\LabMasters;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A row of one of the laboratory's lists, added or changed.
 *
 * The rules are the list's own description in LabMasters, so the form the
 * screen draws and what is checked here cannot drift apart. The route holds
 * `laboratory.settings_manage`.
 */
class SaveLabMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return LabMasters::rules($this->master(), $this->record(), $this->all());
    }

    /** The list being written, from the URL. */
    public function master(): string
    {
        return (string) $this->route('master');
    }

    /** The row being changed; null when adding. */
    public function record(): ?Model
    {
        $id = $this->route('id');

        return $id === null ? null : LabMasters::model($this->master())::query()->find((int) $id);
    }

    /**
     * Strings trimmed, blanks made null, codes kept in one case — they are
     * compared without regard to it.
     */
    protected function prepareForValidation(): void
    {
        $merged = [];

        foreach (LabMasters::get($this->master())['fields'] as $field) {
            $name = $field['name'];

            if (! $this->has($name)) {
                continue;
            }

            $value = $this->input($name);

            if (is_string($value)) {
                $value = trim($value);
                $value = $value === '' ? null : $value;

                if ($value !== null && $field['type'] === 'code') {
                    $value = mb_strtoupper($value);
                }
            }

            $merged[$name] = $value;
        }

        $this->merge($merged);
    }
}
