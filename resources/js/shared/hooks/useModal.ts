import { useCallback, useState } from 'react';

/**
 * Open/close state for a modal, plus the record it is acting on.
 *
 * Saves every list page from repeating the same two useState calls:
 *
 *   const modal = useModal<OrganizationType>();
 *   modal.openCreate();          // data === null  → "create" mode
 *   modal.openEdit(row);         // data === row   → "edit" mode
 */
export function useModal<T = never>() {
    const [open, setOpen] = useState(false);
    const [data, setData] = useState<T | null>(null);

    const openCreate = useCallback(() => {
        setData(null);
        setOpen(true);
    }, []);

    const openEdit = useCallback((record: T) => {
        setData(record);
        setOpen(true);
    }, []);

    // The record is kept while the dialog fades out so the title does not
    // flip from "Edit" to "Add" mid-animation.
    const close = useCallback(() => setOpen(false), []);

    return {
        open,
        data,
        isEditing: data !== null,
        openCreate,
        openEdit,
        close,
    };
}
