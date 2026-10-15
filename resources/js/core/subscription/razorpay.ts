import type { Checkout } from './types';

/**
 * Razorpay's checkout, loaded on demand.
 *
 * The browser only ever OPENS a payment the server created (order id and
 * amount come from our API). What it reports back — order, payment and
 * signature — is sent to the server, which checks the signature and asks
 * Razorpay itself before anything changes. Nothing here is proof of payment.
 */
const SCRIPT = 'https://checkout.razorpay.com/v1/checkout.js';

interface RazorpayResult {
    razorpay_order_id: string;
    razorpay_payment_id: string;
    razorpay_signature: string;
}

declare global {
    interface Window {
        Razorpay?: new (options: Record<string, unknown>) => { open(): void };
    }
}

let loading: Promise<void> | null = null;

function load(): Promise<void> {
    if (window.Razorpay) {
        return Promise.resolve();
    }

    loading ??= new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => {
            loading = null;
            reject(new Error('The payment window could not be loaded. Check your connection.'));
        };
        document.body.appendChild(script);
    });

    return loading;
}

/** Opens checkout; resolves with what to send for verification, or null if closed. */
export async function openCheckout(checkout: Checkout, organizationName?: string): Promise<RazorpayResult | null> {
    await load();

    return new Promise((resolve) => {
        const Razorpay = window.Razorpay;

        if (!Razorpay) {
            resolve(null);

            return;
        }

        new Razorpay({
            key: checkout.key_id,
            order_id: checkout.order_id,
            amount: checkout.amount,
            currency: checkout.currency,
            name: 'ZEVRO',
            description: checkout.description,
            prefill: {
                name: checkout.prefill.name ?? organizationName ?? undefined,
                email: checkout.prefill.email ?? undefined,
                contact: checkout.prefill.contact ?? undefined,
            },
            theme: { color: '#1d4ed8' },
            handler: (result: RazorpayResult) => resolve(result),
            modal: { ondismiss: () => resolve(null) },
        }).open();
    });
}
