import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';

/** Whether this role may open a patient's record — its own tick, apart from the list. */
export function useCanOpenRecord(): boolean {
    return useTenantAuth().can('customers.record');
}

/** Whether this role may call or WhatsApp a patient from the screen. */
export function useCanContact(): boolean {
    return useTenantAuth().can('customers.contact');
}

/**
 * A patient's number: tap to dial for a role given that tick, the same
 * number as plain text otherwise — still readable, just not dialled from here.
 */
export function PatientPhone({ phone, children }: { phone: string; children?: ReactNode }) {
    const content = children ?? phone;

    return useCanContact() ? <a href={`tel:${phone}`}>{content}</a> : <>{content}</>;
}

/**
 * Something about a patient — usually their name — that opens their record,
 * for a role given that tick. Without it, the same content as plain text: the
 * name is still worth reading, the door is just not offered.
 */
export function PatientRecordLink({
    customerId,
    className,
    children,
}: {
    customerId: number;
    className?: string;
    children: ReactNode;
}) {
    if (!useCanOpenRecord()) {
        return <span className={className}>{children}</span>;
    }

    return (
        <Link className={className} to={`/customers/${customerId}`}>
            {children}
        </Link>
    );
}
