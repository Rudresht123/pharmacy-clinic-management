import { useMemo, type ReactNode } from 'react';
import { createColumnHelper, type ColumnDef } from '@tanstack/react-table';
import { StatusBadge } from '@/shared/components/ui/Feedback';
import { orDash } from '@/shared/utils/format';
import type { ConfigurableField } from './types';

/**
 * How one screen wants a particular field drawn. Anything not named here
 * falls back to the generic rendering below.
 */
export type CellRenderers<TRow> = Record<string, (row: TRow) => ReactNode>;

interface Options<TRow> {
    fields: ConfigurableField[] | undefined;
    /** Page-local index plus the page offset, for the leading serial column. */
    pageIndex: number;
    pageSize: number;
    renderers?: CellRenderers<TRow>;
    /** Rendered in the trailing action column; omit for a read-only table. */
    actions?: (row: TRow) => ReactNode;
}

/**
 * Table columns built from an entity's configured fields.
 *
 * Every list screen in the tenant app shows whatever its organization put
 * in `show_in_table`, in that order, so the serial column, the fallback
 * cell rendering and the action column live here once rather than in each
 * page. A screen only supplies the cells that are special to it.
 */
export function useFieldColumns<TRow extends { custom_fields?: Record<string, unknown> }>({
    fields,
    pageIndex,
    pageSize,
    renderers,
    actions,
}: Options<TRow>): ColumnDef<TRow, unknown>[] {
    return useMemo(() => {
        const column = createColumnHelper<TRow>();

        /** The generic reading of a value when a screen has not overridden it. */
        function fallback(field: ConfigurableField, row: TRow): ReactNode {
            const value = field.is_custom
                ? row.custom_fields?.[field.key]
                : (row as unknown as Record<string, unknown>)[field.key];

            if (typeof value === 'boolean') {
                return <StatusBadge active={value} />;
            }

            return orDash(value == null ? null : String(value));
        }

        const configured = (fields ?? [])
            .filter((field) => field.in_table)
            .map((field) =>
                column.display({
                    id: field.key,
                    header: field.label,
                    // Supplies the label on the mobile card layout.
                    meta: { label: field.label },
                    cell: (info) => {
                        const render = renderers?.[field.key];

                        return render
                            ? render(info.row.original)
                            : fallback(field, info.row.original);
                    },
                }),
            );

        const columns: ColumnDef<TRow, unknown>[] = [
            column.display({
                id: 'serial',
                header: '#',
                size: 60,
                cell: (info) => pageIndex * pageSize + info.row.index + 1,
            }) as ColumnDef<TRow, unknown>,
            ...(configured as ColumnDef<TRow, unknown>[]),
        ];

        if (!actions) {
            return columns;
        }

        return [
            ...columns,
            column.display({
                id: 'actions',
                header: 'Action',
                size: 90,
                cell: (info) => actions(info.row.original),
            }) as ColumnDef<TRow, unknown>,
        ];
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [fields, pageIndex, pageSize, renderers, actions]);
}
