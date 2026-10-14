import axios from 'axios';
import { http } from '@/shared/api/http';
import type { ApiResponse } from '@/shared/types/api';
import type { LookupBase } from './pincode';

/**
 * How far a map point can be trusted:
 *   manual   somebody placed the pin on the building
 *   address  found from the street address
 *   pincode  only the PIN code's area was found
 *   city     only the city was found
 */
export type LocationSource = 'manual' | 'address' | 'pincode' | 'city';

export interface MapPoint {
    latitude: number;
    longitude: number;
    source: LocationSource;
}

export interface AddressParts {
    address?: string | null;
    city?: string | null;
    state?: string | null;
    pincode?: string | null;
}

/**
 * Where an address is on the map, or null when it cannot be found.
 *
 * A service outage rejects instead, so the caller can tell "not on the map"
 * (place it by hand) from "could not ask" (try again, or place it by hand).
 */
export async function geocodeAddress(parts: AddressParts, base: LookupBase): Promise<MapPoint | null> {
    try {
        const { data } = await http.get<ApiResponse<MapPoint>>(`${base}/geocode`, {
            params: {
                address: parts.address?.trim() || undefined,
                city: parts.city?.trim() || undefined,
                state: parts.state?.trim() || undefined,
                pincode: parts.pincode?.trim() || undefined,
            },
            silent: true,
        });

        return data.data;
    } catch (error) {
        if (axios.isAxiosError(error) && error.response?.status === 404) {
            return null;
        }

        throw error;
    }
}
