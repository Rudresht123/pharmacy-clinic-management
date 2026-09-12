import axios from 'axios';
import { useQuery } from '@tanstack/react-query';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';

/** One post office a PIN code covers. */
export interface PincodeArea {
    name: string;
    block: string | null;
    branch_type: string | null;
    /** Whether this office delivers post, which is how India Post marks a locality. */
    delivery: boolean;
}

/** What India Post says a PIN code is. */
export interface PincodePlace {
    pincode: string;
    country: string;
    state: string;
    district: string;
    /** Every post office under the code, sorted by name. A city code covers many. */
    areas: PincodeArea[];
}

/** Six digits, never starting with zero. The server checks exactly the same. */
export const PINCODE_PATTERN = /^[1-9][0-9]{5}$/;

export function isPincode(value: unknown): value is string {
    return typeof value === 'string' && PINCODE_PATTERN.test(value.trim());
}

/**
 * The place a PIN code belongs to, or null when there is no such code.
 *
 * Silent, because both failures are shown beside the field rather than as a
 * toast. A service outage still rejects, so the caller can tell "no such code"
 * from "could not ask", which it must: the first means the code is wrong, the
 * second means type the address by hand.
 */
export async function lookupPincode(pincode: string): Promise<PincodePlace | null> {
    try {
        const { data } = await http.get<ApiResponse<PincodePlace>>(
            `/tenant/lookups/pincode/${pincode}`,
            { silent: true },
        );

        return data.data;
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
            return null;
        }

        throw error;
    }
}

/**
 * Looks a PIN code up once it is complete, and remembers the answer.
 *
 * Idle until the value is six valid digits, so typing "2013" asks nothing.
 * Kept for the whole session: a PIN code does not change while somebody is
 * filling in a form, and the server caches it for a month anyway.
 */
export function usePincodeLookup(pincode: string | null | undefined) {
    const pin = (pincode ?? '').trim();

    return useQuery({
        queryKey: ['geo', 'pincode', pin],
        queryFn: () => lookupPincode(pin),
        enabled: isPincode(pin),
        staleTime: Infinity,
        gcTime: 30 * 60 * 1000,
        // A retry would only repeat an outage the user is already being told about.
        retry: false,
    });
}
