<?php

namespace App\Http\Requests\Api\V1\Tenant;

use App\Models\Tenant\PharmacyStore;

/**
 * Editing a pharmacy store: the same rules, with the store itself excluded
 * from the code check and its branch fixed.
 */
class UpdatePharmacyStoreRequest extends StorePharmacyStoreRequest
{
    protected function store(): ?PharmacyStore
    {
        $store = $this->route('store');

        return $store instanceof PharmacyStore ? $store : null;
    }
}
