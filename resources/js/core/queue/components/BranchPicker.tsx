import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import type { OpdContext } from '@/core/opd/useOpdContext';

/**
 * Which branch's queue. No date beside it, unlike the other OPD screens: a live
 * queue is only ever today's. Drawn only when there is a choice to make.
 */
export function BranchPicker({ context }: { context: OpdContext }) {
    const { branches, branchId, setBranch } = context;

    if (branches.length <= 1) {
        return null;
    }

    return (
        <SearchableSelect
            compact
            className="q-doctor-pick"
            value={String(branchId)}
            onChange={(next) => setBranch(Number(next))}
            ariaLabel="Branch"
            options={branches.map((branch) => ({ value: String(branch.id), label: branch.name }))}
        />
    );
}
