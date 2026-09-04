import { useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { HistoryTimeline } from '@/shared/components/ui/HistoryTimeline';
import { Pagination } from '@/shared/components/ui/Pagination';
import { useOrganizationHistory } from '@/core/audit/api';

/**
 * Everything that has happened to one organization.
 *
 * Scoped server-side by organization rather than by record type, so
 * assigning a module — which is a change to the binding, not to the
 * organization — appears here beside a rename. Filtering by entity would
 * have shown the renames and silently missed every commercial decision.
 */
export function OrganizationHistoryTab({ uuid }: { uuid: string }) {
    const [page, setPage] = useState(1);

    const { data, isLoading, isError, refetch } = useOrganizationHistory(uuid, {
        page,
        per_page: 25,
    });

    const meta = data?.meta;

    return (
        <Card
            title="History"
            icon="ti ti-history"
            description="Every change to this organization, field by field. Append-only — these rows cannot be edited or removed."
        >
            {isLoading ? (
                <LoadingBlock label="Loading history…" />
            ) : isError ? (
                <ErrorState onRetry={() => refetch()} />
            ) : (
                <>
                    <div className="ht-scroll">
                        <HistoryTimeline
                            entries={data?.data ?? []}
                            empty="Nothing has been changed since this organization was created."
                        />
                    </div>

                    {meta && (
                        <Pagination
                            page={meta.current_page}
                            pageCount={meta.last_page}
                            total={meta.total}
                            perPage={meta.per_page}
                            onChange={setPage}
                        />
                    )}
                </>
            )}
        </Card>
    );
}
