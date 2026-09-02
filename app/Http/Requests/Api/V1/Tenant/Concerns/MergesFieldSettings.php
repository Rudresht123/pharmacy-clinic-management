<?php

namespace App\Http\Requests\Api\V1\Tenant\Concerns;

use App\Services\Fields\FieldSchema;

/**
 * Folds an organization's field settings into a request's base rules.
 *
 * A field the organization marked mandatory has to actually be enforced
 * here, not merely starred in the browser — otherwise the setting is
 * decoration and an API client walks straight past it.
 */
trait MergesFieldSettings
{
    /**
     * @param  array<string, array<int, mixed>>  $rules  the request's own rules
     * @param  list<array<string, mixed>>  $registry
     * @return array<string, array<int, mixed>>
     */
    protected function withFieldSettings(array $rules, string $entity, array $registry): array
    {
        $schema = app(FieldSchema::class);
        $fields = $schema->for($entity, $registry);

        foreach ($schema->requiredBuiltIns($fields) as $key) {
            if (! isset($rules[$key])) {
                continue;
            }

            // Swap the optional marker for a mandatory one, leaving the type
            // rules (max, email, date…) exactly as the request declared them.
            $rules[$key] = array_values(array_filter(
                $rules[$key],
                fn ($rule) => $rule !== 'nullable'
            ));

            array_unshift($rules[$key], 'required');
        }

        return array_merge($rules, $schema->customRules($fields));
    }
}
