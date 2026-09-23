import type { Timestamps } from '@/shared/types/api';

/**
 * A medicine in the organization's catalogue.
 *
 * What a medicine is, not how much there is of it: stock arrives with the
 * pharmacy module, counted in `base_unit`.
 */
export interface Medicine extends Timestamps {
    id: number;

    /** A store sells more than medicine: consumables, devices, general goods. */
    item_kind: 'medicine' | 'consumable' | 'device' | 'other';

    medicine_code: string | null;
    sku: string | null;
    barcode: string | null;

    generic_name: string;
    brand_name: string | null;
    strength: string | null;
    /** Null on anything that is not a medicine — gloves have no dosage form. */
    dosage_form: string | null;
    route: string | null;

    /** "Dolo 650 (Paracetamol) 650 mg tablet" — how every screen names it. */
    display_name: string;

    /** What stock is counted and dispensed in: tablet, bottle, vial… */
    base_unit: string;
    /** Base units per purchase pack: a strip of 10 is 10. */
    pack_size: number;

    manufacturer: string | null;
    category: string | null;

    /** What a bill needs: the code it is taxed under, and at what rate. */
    hsn_code: string | null;
    tax_rate: number;

    schedule: string | null;
    prescription_required: boolean;

    description: string | null;
    is_active: boolean;

    custom_fields: Record<string, unknown>;

    /** Set only on a removed medicine, which the restore screen lists. */
    deleted_at: string | null;
    deletion_reason?: string | null;
    deleted_by_name?: string | null;
}
