import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { http, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useConfirm } from '@/shared/hooks/useConfirm';
import type { Page } from '@/shared/api/resource';
import type { ApiResponse } from '@/shared/types/api';

/** Just enough of a staff record to choose one. */
interface Candidate {
    id: number;
    name: string;
    email: string;
    role: string;
    is_active: boolean;
}

interface Branch {
    id: number;
    name: string;
    manager_id: number | null;
    manager_name: string | null;
}

/**
 * Who runs this branch.
 *
 * The panel is deliberately about ONE fact, because that is what the endpoint
 * behind it is: naming a manager moves their branch membership and the role on
 * it as well as the column, in one transaction, so a branch can never end up
 * with a manager who holds nothing.
 *
 * What the manager may then do is not configured here and is not configured
 * per branch — it is the Branch manager role, which the owner edits once on
 * the Roles screen and every branch's manager wears.
 */
export function BranchManagerPanel({ locationId }: { locationId: number }) {
    const client = useQueryClient();
    const confirm = useConfirm();

    const [chosen, setChosen] = useState('');
    const [error, setError] = useState<string | null>(null);

    const { data: branch, isLoading } = useQuery({
        queryKey: ['tenant', 'locations', locationId, 'manager'],
        queryFn: async (): Promise<Branch> => {
            const { data } = await http.get<ApiResponse<Branch>>(`/tenant/locations/${locationId}`);

            return data.data;
        },
    });

    /*
     * Who could run it. Staff only — an owner already runs every branch, and
     * the server refuses one here rather than leaving it to the list.
     */
    const { data: candidates } = useQuery({
        queryKey: ['tenant', 'locations', locationId, 'manager', 'candidates'],
        queryFn: async (): Promise<Candidate[]> => {
            const { data } = await http.get<Page<Candidate>>('/tenant/users', {
                params: { per_page: 100, status: 'active' },
            });

            return (data.data ?? []).filter((person) => person.role === 'staff' && person.is_active);
        },
    });

    function refresh() {
        void client.invalidateQueries({ queryKey: ['tenant', 'locations'] });
        void client.invalidateQueries({ queryKey: ['tenant/users'] });
    }

    const assign = useMutation({
        mutationFn: async (userId: number) => {
            const { data } = await http.put<ApiResponse<Branch>>(
                `/tenant/locations/${locationId}/manager`,
                { user_id: userId },
                // Silent: the panel shows the server's answer in place, beside
                // the person who was refused.
                { silent: true },
            );

            return data;
        },
        onSuccess: (data) => {
            notify.success(data.message ?? 'Branch manager updated');
            setChosen('');
            refresh();
        },
        onError: (failure) => setError(resolveErrorMessage(failure)),
    });

    const revoke = useMutation({
        mutationFn: () =>
            http.delete(`/tenant/locations/${locationId}/manager`, { silent: true }),
        onSuccess: () => {
            notify.success('This branch has no manager now');
            refresh();
        },
        onError: (failure) => setError(resolveErrorMessage(failure)),
    });

    if (isLoading) {
        return <LoadingBlock label="Reading the branch…" />;
    }

    async function stepDown() {
        const confirmed = await confirm({
            title: 'Remove the branch manager?',
            message:
                `${branch?.manager_name} stops running this branch. They stay a member of it, ` +
                'holding nothing, so you can give them another role here.',
            confirmLabel: 'Remove',
            danger: true,
        });

        if (confirmed) {
            setError(null);
            revoke.mutate();
        }
    }

    return (
        <Card
            title="Branch manager"
            icon="ti ti-user-shield"
            description="Who administers this branch and the people who work at it"
        >
            {branch?.manager_id ? (
                <div className="bm-current">
                    <span className="bm-avatar" aria-hidden="true">
                        <i className="ti ti-user-shield" />
                    </span>

                    <span className="bm-who">
                        <b>{branch.manager_name}</b>
                        <small>Runs this branch</small>
                    </span>

                    <Button
                        variant="light"
                        size="sm"
                        icon="ti ti-user-minus"
                        loading={revoke.isPending}
                        onClick={() => void stepDown()}
                    >
                        Remove
                    </Button>
                </div>
            ) : (
                <p className="bm-none">
                    <i className="ti ti-user-question" aria-hidden="true" />
                    Nobody runs this branch yet. Its staff are administered by the owner and by
                    head office until somebody does.
                </p>
            )}

            <div className="bm-assign">
                <SearchableSelect
                    id="branch-manager"
                    value={chosen}
                    onChange={(value) => {
                        setChosen(value);
                        setError(null);
                    }}
                    placeholder={branch?.manager_id ? 'Hand it to somebody else…' : 'Choose somebody…'}
                    options={(candidates ?? [])
                        .filter((person) => person.id !== branch?.manager_id)
                        .map((person) => ({
                            value: String(person.id),
                            label: `${person.name} · ${person.email}`,
                        }))}
                />

                <Button
                    icon="ti ti-user-check"
                    disabled={!chosen}
                    loading={assign.isPending}
                    onClick={() => {
                        setError(null);
                        assign.mutate(Number(chosen));
                    }}
                >
                    {branch?.manager_id ? 'Change manager' : 'Make manager'}
                </Button>
            </div>

            {error && <p className="alert alert-danger py-2 px-3 mb-0">{error}</p>}

            {/*
                Said out loud, because it is the thing somebody would
                otherwise go looking for a second screen to do.
            */}
            <p className="bm-note">
                <i className="ti ti-info-circle" aria-hidden="true" />
                Appointing somebody gives them the <b>Branch manager</b> role here and nowhere
                else. What that role includes is edited once, on the Roles screen.
            </p>
        </Card>
    );
}
