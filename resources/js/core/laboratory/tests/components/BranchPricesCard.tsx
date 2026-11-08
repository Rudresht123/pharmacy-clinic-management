import { useEffect, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { locationsHooks } from '@/core/locations/api';
import { cn } from '@/shared/utils/cn';
import { useSaveBranchPrices } from '../api';
import { money } from '../money';
import type { LabTest } from '../types';
import { FormSection } from './FormSection';

type Row = { price: string; tax: string };

/**
 * A branch's own price for this test. Empty means the branch charges the
 * test's price; a price with no tax takes the test's tax.
 */
export function BranchPricesCard({ test, canEdit }: { test: LabTest; canEdit: boolean }) {
    const { data: branches, isLoading } = locationsHooks.useList({ all: 1 });
    const save = useSaveBranchPrices();

    const [rows, setRows] = useState<Record<number, Row>>({});
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failure, setFailure] = useState<string | null>(null);

    useEffect(() => {
        const next: Record<number, Row> = {};

        for (const price of test.branch_prices ?? []) {
            next[price.location_id] = {
                price: String(price.price),
                tax: price.tax_percent === null ? '' : String(price.tax_percent),
            };
        }

        setRows(next);
    }, [test.branch_prices]);

    const onSave = async () => {
        setErrors({});
        setFailure(null);

        const prices = Object.entries(rows)
            .filter(([, row]) => row.price.trim() !== '')
            .map(([locationId, row]) => ({
                location_id: Number(locationId),
                price: row.price.trim(),
                tax_percent: row.tax.trim() === '' ? null : row.tax.trim(),
            }));

        try {
            await save.mutateAsync({ testId: test.id, prices });
        } catch (thrown) {
            const validation = getValidationErrors(thrown);
            setErrors(
                validation
                    ? Object.fromEntries(Object.entries(validation).map(([field, messages]) => [field, messages[0]]))
                    : {},
            );

            if (!validation) {
                setFailure(resolveErrorMessage(thrown));
            }
        }
    };

    return (
        <FormSection
            icon="ti ti-building-store"
            title="Branch Prices"
            caption={`Where a branch charges something other than ${money(test.price)}. Leave empty to use the test's price.`}
            actions={
                canEdit ? (
                    <Button size="sm" icon="ti ti-device-floppy" loading={save.isPending} onClick={onSave}>
                        Save prices
                    </Button>
                ) : undefined
            }
        >
            {failure && <div className="alert alert-danger py-2 px-3 fs-13">{failure}</div>}
            {Object.keys(errors).length > 0 && (
                <div className="alert alert-danger py-2 px-3 fs-13">{Object.values(errors).join(' ')}</div>
            )}

            {isLoading ? (
                <LoadingBlock label="Loading branches…" />
            ) : (
                <div className="lab-ranges-wrap">
                    <table className="lab-ranges lab-prices">
                        <thead>
                            <tr>
                                <th>Branch</th>
                                <th>Price (₹)</th>
                                <th>Tax %</th>
                                <th>Charges</th>
                            </tr>
                        </thead>
                        <tbody>
                            {(branches ?? []).map((branch) => {
                                const row = rows[branch.id] ?? { price: '', tax: '' };
                                const own = row.price.trim() !== '';

                                return (
                                    <tr key={branch.id}>
                                        <td>
                                            <b>{branch.name}</b>
                                        </td>
                                        <td>
                                            <input
                                                className="form-control"
                                                type="number"
                                                min={0}
                                                step="0.01"
                                                placeholder={String(test.price)}
                                                value={row.price}
                                                disabled={!canEdit}
                                                onChange={(event) =>
                                                    setRows({ ...rows, [branch.id]: { ...row, price: event.target.value } })
                                                }
                                            />
                                        </td>
                                        <td>
                                            <input
                                                className="form-control"
                                                type="number"
                                                min={0}
                                                max={100}
                                                step="0.01"
                                                placeholder={String(test.tax_percent)}
                                                value={row.tax}
                                                disabled={!canEdit || !own}
                                                onChange={(event) =>
                                                    setRows({ ...rows, [branch.id]: { ...row, tax: event.target.value } })
                                                }
                                            />
                                        </td>
                                        <td className={cn('lab-price-note', own && 'is-own')}>
                                            {own ? `${money(row.price)} — its own price` : `${money(test.price)} — the test's price`}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            )}
        </FormSection>
    );
}
