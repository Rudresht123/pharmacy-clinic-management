<?php

namespace App\Support\Modules;

/**
 * Which ticks open which read, where several screens share the data.
 *
 * Every row in the menu has a tick of its own, but the screens behind them read
 * from one another: the counter reads the shelf, the outstanding list reads the
 * invoices. So a route asks for ANY of the screens that read its data, and never
 * for a broad "pharmacy" key, or taking one tick off a role would change nothing
 * on the server.
 *
 * The routes and the Policies ask from these same lists: the route about the
 * branch somebody is acting at, the Policy about the branch the record belongs to.
 */
final class ScreenTicks
{
    public const PHARMACY_DASHBOARD = ['pharmacy.view'];

    /** The Inventory screen, and the shelf the counter sells from. */
    public const PHARMACY_STOCK = ['pharmacy.inventory_view', 'pharmacy.sell'];

    public const PHARMACY_PURCHASES = ['pharmacy.purchases_view'];

    public const PHARMACY_MOVEMENTS = ['pharmacy.movements_view'];

    /** Adjustments and transfers: what moved stock, read beside either screen. */
    public const PHARMACY_STOCK_DOCUMENTS = ['pharmacy.inventory_view', 'pharmacy.movements_view'];

    public const PHARMACY_BILLS = ['pharmacy.bills_view'];

    /** One bill: the Bills screen, and the counter that has just rung it up. */
    public const PHARMACY_ONE_BILL = ['pharmacy.bills_view', 'pharmacy.sell'];

    /** Purchases names the supplier, and receiving stock picks one. */
    public const PHARMACY_SUPPLIERS = ['pharmacy.suppliers_view', 'pharmacy.purchases_view', 'pharmacy.inward'];

    /** A store's own page and what it stocks, and the form that edits a store. */
    public const PHARMACY_STORE = ['pharmacy.stores_view', 'pharmacy.stores'];

    /** The counter prices and warns from these; taking money on a bill needs them too. */
    public const PHARMACY_SETTINGS = ['pharmacy.settings_view', 'pharmacy.stores', 'pharmacy.sell', 'pharmacy.bills_view'];

    /** Patients from OPD, on the counter and on the pharmacist's phone. */
    public const PHARMACY_HANDOFF = ['pharmacy.view', 'pharmacy.sell'];

    /** Every pharmacy screen: each has to pick a store before it can show anything. */
    public const PHARMACY_ANY = [
        'pharmacy.view',
        'pharmacy.inventory_view',
        'pharmacy.purchases_view',
        'pharmacy.bills_view',
        'pharmacy.movements_view',
        'pharmacy.suppliers_view',
        'pharmacy.stores_view',
        'pharmacy.settings_view',
        'pharmacy.sell',
        'pharmacy.inward',
        'pharmacy.stores',
    ];

    public const BILLING_OVERVIEW = ['billing.view'];

    /** The Overview's outstanding table, and the Outstanding screen. */
    public const BILLING_OUTSTANDING = ['billing.view', 'billing.outstanding_view'];

    public const BILLING_PAYMENTS = ['billing.payments_view'];

    /** Overview and Outstanding are both drawn from the invoice list. */
    public const BILLING_INVOICES = ['billing.view', 'billing.invoices_view', 'billing.outstanding_view'];

    /** One invoice opens from any list that names it. */
    public const BILLING_ONE_INVOICE = ['billing.view', 'billing.invoices_view', 'billing.outstanding_view', 'billing.payments_view'];

    /** Raising a bill picks from the catalogue; the catalogue's own screen reads it. */
    public const BILLING_SERVICES = ['billing.view', 'billing.invoices_view', 'billing.create', 'billing.manage_services'];

    /** Every screen that takes or shows money needs the clinic's methods and rules. */
    public const BILLING_SETTINGS = [
        'billing.view',
        'billing.invoices_view',
        'billing.outstanding_view',
        'billing.payments_view',
        'billing.create',
        'billing.collect_payment',
        'billing.manage_settings',
    ];

    public const BILLING_NUMBERING = ['billing.view', 'billing.manage_settings'];

    /**
     * One tick per channel's screen. `communication.view` is WhatsApp's, the
     * channel it opened first; SMS has no screen yet and stays with it.
     *
     * @var array<string, string>
     */
    public const COMMUNICATION_CHANNELS = [
        'whatsapp' => 'communication.view',
        'email' => 'communication.email',
        'push' => 'communication.push',
        'sms' => 'communication.view',
    ];

    public const COMMUNICATION_ANY = ['communication.view', 'communication.email', 'communication.push', 'communication.recipients'];

    /**
     * Each of a doctor's own lists. My day and My queue read the first three as
     * well, on the web and on the phone.
     *
     * @var array<string, list<string>>
     */
    public const MY_RECORDS = [
        'appointments' => ['appointments.my_patients', 'appointments.my_day', 'appointments.my_queue'],
        'patients' => ['appointments.my_patients', 'appointments.my_day', 'appointments.my_queue'],
        'consultations' => ['appointments.my_consultations', 'appointments.my_patients', 'appointments.my_day', 'appointments.my_queue'],
        'prescriptions' => ['appointments.my_prescriptions'],
        'investigations' => ['appointments.my_investigations'],
        'follow-ups' => ['appointments.my_follow_ups'],
    ];

    public const MY_RECORDS_ANY = [
        'appointments.my_patients',
        'appointments.my_day',
        'appointments.my_queue',
        'appointments.my_consultations',
        'appointments.my_prescriptions',
        'appointments.my_investigations',
        'appointments.my_follow_ups',
    ];

    /**
     * The route middleware for a list of ticks.
     *
     * @param  list<string>  $ticks
     */
    public static function gate(array $ticks): string
    {
        return 'permission:'.implode(',', $ticks);
    }
}
