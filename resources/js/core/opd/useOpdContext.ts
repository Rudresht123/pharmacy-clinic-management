import { useCallback, useEffect, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useOpdBranches } from './api';
import type { OpdBranch } from './types';

/** Today, in the browser's own timezone — a clinic's day is a local one. */
export function today(): string {
    const now = new Date();
    const month = `${now.getMonth() + 1}`.padStart(2, '0');
    const day = `${now.getDate()}`.padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

const REMEMBERED = 'hms.opd.branch';

function remembered(): number | null {
    try {
        const value = Number(window.localStorage.getItem(REMEMBERED));

        return Number.isFinite(value) && value > 0 ? value : null;
    } catch {
        // A private window, or site data blocked. Not remembering is a
        // perfectly good outcome; throwing on the way to the queue is not.
        return null;
    }
}

function remember(id: number): void {
    try {
        window.localStorage.setItem(REMEMBERED, String(id));
    } catch {
        /* see above */
    }
}

export interface OpdContext {
    /** Which branch's day is on screen; '' only while the list is loading. */
    branchId: number | '';
    setBranch(id: number): void;
    branches: OpdBranch[];
    branchesLoading: boolean;
    branch: OpdBranch | undefined;

    date: string;
    setDate(value: string): void;
    isToday: boolean;

    /**
     * True when this person may work somewhere, but nowhere is set up for OPD
     * — head office, typically, who hold no branch membership.
     */
    hasNoBranch: boolean;
}

/**
 * Which branch's day, and which day.
 *
 * Both OPD screens need the same two answers and must agree on them, so they
 * come from one place. The branch is resolved rather than chosen: the switcher
 * already says where somebody is working, and asking again in a dropdown is
 * the sort of question software should answer for itself.
 *
 * Order of preference — the URL (so a link is shareable), then the branch they
 * switched to, then the one they used last, then the only sensible default.
 */
export function useOpdContext(): OpdContext {
    const [params, setParams] = useSearchParams();
    const { activeBranch, setActiveBranch } = useTenantAuth();

    const { data: branches, isLoading } = useOpdBranches();

    const date = params.get('date') || today();
    const fromUrl = Number(params.get('branch')) || null;

    const list = useMemo(() => branches ?? [], [branches]);

    const branchId = useMemo<number | ''>(() => {
        if (list.length === 0) {
            return '';
        }

        const usable = (id: number | null) =>
            id !== null && list.some((branch) => branch.id === id) ? id : null;

        return usable(fromUrl) ?? usable(activeBranch) ?? usable(remembered()) ?? list[0].id;
    }, [list, fromUrl, activeBranch]);

    // Written back on every resolution, not only on an explicit choice: the
    // branch somebody actually worked at is the one worth returning them to.
    useEffect(() => {
        if (branchId !== '') {
            remember(branchId);
        }
    }, [branchId]);

    const patch = useCallback(
        (key: string, value: string) => {
            const next = new URLSearchParams(params);
            next.set(key, value);
            setParams(next, { replace: true });
        },
        [params, setParams],
    );

    /*
     * Changing the branch here also changes where the whole workspace thinks
     * it is working.
     *
     * Without this the URL would say one branch while X-Branch-Id still said
     * another — so the server would resolve this person's CAPABILITIES at the
     * branch they switched away from while answering about the branch on
     * screen. Somebody who is a receptionist at one site and a manager at
     * another would see the wrong set of actions, and the reply would look
     * perfectly correct.
     */
    const setBranch = useCallback(
        (id: number) => {
            patch('branch', String(id));
            setActiveBranch(id);
        },
        [patch, setActiveBranch],
    );

    return {
        branchId,
        setBranch,
        branches: list,
        branchesLoading: isLoading,
        branch: list.find((entry) => entry.id === branchId),

        date,
        setDate: useCallback((value: string) => patch('date', value || today()), [patch]),
        isToday: date === today(),

        hasNoBranch: !isLoading && list.length === 0,
    };
}
