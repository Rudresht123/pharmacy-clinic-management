/*
 * India's regions, and which state belongs to which.
 *
 * A region is an identity, so it takes a fixed categorical hue — a state is
 * always its region's colour, whatever its rank this month. The name travels
 * with the colour everywhere it appears (legend, list, map tooltip).
 */

export interface Region {
    key: string;
    label: string;
    color: string;
}

export const REGIONS: Region[] = [
    { key: 'north', label: 'North', color: 'var(--cat-1)' },
    { key: 'west', label: 'West', color: 'var(--cat-4)' },
    { key: 'south', label: 'South', color: 'var(--cat-2)' },
    { key: 'east', label: 'East', color: 'var(--cat-3)' },
    { key: 'northeast', label: 'North-East', color: 'var(--cat-5)' },
    { key: 'central', label: 'Central', color: 'var(--cat-6)' },
];

const STATE_REGION: Record<string, string> = {
    jammuandkashmir: 'north', ladakh: 'north', himachalpradesh: 'north', punjab: 'north',
    chandigarh: 'north', haryana: 'north', delhi: 'north', uttarakhand: 'north',
    uttarpradesh: 'north', rajasthan: 'north',
    gujarat: 'west', maharashtra: 'west', goa: 'west', dadraandnagarhavelianddamananddiu: 'west',
    karnataka: 'south', kerala: 'south', tamilnadu: 'south', andhrapradesh: 'south',
    telangana: 'south', puducherry: 'south', lakshadweep: 'south', andamanandnicobarislands: 'south',
    bihar: 'east', jharkhand: 'east', odisha: 'east', westbengal: 'east',
    assam: 'northeast', arunachalpradesh: 'northeast', manipur: 'northeast', meghalaya: 'northeast',
    mizoram: 'northeast', nagaland: 'northeast', sikkim: 'northeast', tripura: 'northeast',
    madhyapradesh: 'central', chhattisgarh: 'central',
};

/** The other names a state turns up under. */
const ALIASES: Record<string, string> = {
    nctofdelhi: 'delhi',
    newdelhi: 'delhi',
    orissa: 'odisha',
    pondicherry: 'puducherry',
    jandk: 'jammuandkashmir',
    uttaranchal: 'uttarakhand',
    andamanandnicobar: 'andamanandnicobarislands',
};

function stateKey(state: string): string {
    const key = state.toLowerCase().replace(/&/g, 'and').replace(/[^a-z]/g, '');

    return ALIASES[key] ?? key;
}

/** Null for a state the table does not know — drawn grey, never given a hue. */
export function regionOf(state: string | null | undefined): Region | null {
    if (!state) return null;

    const key = STATE_REGION[stateKey(state)];

    return REGIONS.find((region) => region.key === key) ?? null;
}
