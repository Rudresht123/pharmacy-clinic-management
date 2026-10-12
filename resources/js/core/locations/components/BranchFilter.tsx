import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { locationsHooks } from '../api';

/**
 * "Which branch" as a report filter — every billing and pharmacy report asks
 * this the same way, so it is built once here rather than once per screen.
 *
 * Empty string reads as "every branch", matching the `location_id` filter
 * every report endpoint already accepts when it is left out.
 */
export function BranchFilter({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    const { data: branches } = locationsHooks.useList({ all: 1 });

    return (
        <div className="bd-branch-filter">
            <SearchableSelect
                value={value}
                onChange={onChange}
                ariaLabel="Branch"
                placeholder="All Branches"
                clearable
                options={(branches ?? []).map((location) => ({
                    value: String(location.id),
                    label: location.name,
                    hint: location.city ?? location.code,
                }))}
            />
        </div>
    );
}
