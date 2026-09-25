<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\DocumentTemplate;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use App\Services\Documents\TemplateAuthority;
use App\Support\Documents\DocumentTypes;
use App\Support\Documents\Placeholders;
use App\Support\Documents\TemplateConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Writing a document template.
 *
 * Three checks that the route cannot make, because all three depend on what is
 * in the payload:
 *
 *   1. AUTHORITY over this particular template — the organization's default is
 *      not a branch's to rewrite, and another branch's is not theirs either.
 *   2. PLACEHOLDERS — every `{{token}}` anywhere in the config has to be one
 *      this document type can actually fill. Nothing else in this codebase
 *      checks this, and the consequence on a prescription is a blank where a
 *      dose should be, on a document somebody acts on.
 *   3. LOCKED FIELDS — a branch may not change what the organization locked.
 *      Checked against what CHANGED rather than what was sent, because the
 *      editor posts the whole config back on every save.
 */
class SaveDocumentTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function template(): ?DocumentTemplate
    {
        $template = $this->route('template');

        return $template instanceof DocumentTemplate ? $template : null;
    }

    /** The type being written — from the record on edit, from the body on create. */
    private function documentType(): string
    {
        return (string) ($this->template()?->document_type ?? $this->input('document_type', ''));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $creating = $this->template() === null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],

            /*
             * Fixed at creation. Changing a live template's type would leave
             * every document ever printed from it pointing at a template that
             * claims to be something else.
             */
            'document_type' => [
                Rule::requiredIf($creating),
                'prohibited_if_declined:'.($creating ? 'false' : 'true'),
                'string',
                Rule::in(DocumentTypes::keys()),
            ],

            /*
             * Null means the organization's default. Only somebody holding
             * `documents.template_org` may send it — checked below, where the
             * message can say why.
             */
            'location_id' => [
                Rule::requiredIf(false),
                'nullable',
                'integer',
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],

            'config' => ['required', 'array'],
        ];
    }

    public function after(TemplateAuthority $authority): array
    {
        return [
            function (Validator $validator) use ($authority) {
                $organization = $this->attributes->get('tenant.organization');
                $actor = Auth::guard('web')->user();

                if (! $organization || ! $actor instanceof User) {
                    return;
                }

                $template = $this->template();
                $type = $this->documentType();

                if (! DocumentTypes::supports($type)) {
                    return;
                }

                $target = $template
                    ? $template->location_id
                    : ($this->input('location_id') !== null ? (int) $this->input('location_id') : null);

                /* 1. Authority over this template. */
                if (! $authority->mayWrite($organization, $actor, $target)) {
                    $validator->errors()->add(
                        $target === null ? 'location_id' : 'name',
                        $target === null
                            ? 'Only an organisation administrator can change the organisation’s default template.'
                            : 'You cannot change a template for a branch you do not run.',
                    );

                    return;
                }

                $config = TemplateConfig::sanitise((array) $this->input('config', []), $type);

                /* 2. Placeholders this document type cannot fill. */
                $unknown = Placeholders::unknownIn($config, $type);

                if ($unknown !== []) {
                    $validator->errors()->add(
                        'config',
                        count($unknown) === 1
                            ? "A {$this->typeName($type)} has no {{{$unknown[0]}}} — it would print as a blank."
                            : 'These placeholders do not exist on a '.$this->typeName($type).': '
                                .implode(', ', array_map(fn ($token) => '{{'.$token.'}}', $unknown)),
                    );
                }

                /* 3. Locked fields — only meaningful when editing. */
                if (! $template) {
                    return;
                }

                $before = $template->activeVersion?->config
                    ?? $template->versions->first()?->config
                    ?? TemplateConfig::defaultsFor($type);

                $violations = $authority->lockedViolations(
                    $organization,
                    $actor,
                    $template,
                    $before,
                    $config,
                );

                foreach ($violations as $path) {
                    $validator->errors()->add(
                        'config',
                        '“'.$authority->labelFor($path).'” is locked by your organisation.',
                    );
                }
            },
        ];
    }

    private function typeName(string $key): string
    {
        return mb_strtolower(DocumentTypes::find($key)['name'] ?? 'document');
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'document_type.in' => 'That is not a document this software can print.',
            'config.required' => 'Send the template’s settings, even if nothing changed.',
        ];
    }
}
