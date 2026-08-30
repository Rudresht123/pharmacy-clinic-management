import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';

/**
 * The edit / delete pair used in every table's Action column, tucked behind
 * a single "…" button instead of two icons sitting in the row.
 *
 * The menu is positioned with `position: fixed` from the button's own
 * bounding rect (not CSS-anchored to the row) because the Action column
 * lives inside `.dt-wrap`, which is horizontally scrollable — an absolutely
 * positioned menu would get clipped at that container's edge.
 */
interface RowActionsProps {
    editTo?: string;
    onEdit?: () => void;
    onDelete?: () => void;
    editTitle?: string;
    deleteTitle?: string;
}

export function RowActions({
    editTo,
    onEdit,
    onDelete,
    editTitle = 'Edit',
    deleteTitle = 'Delete',
}: RowActionsProps) {
    const [open, setOpen] = useState(false);
    const [coords, setCoords] = useState({ top: 0, right: 0 });
    const buttonRef = useRef<HTMLButtonElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        function onOutside(event: MouseEvent) {
            const target = event.target as Node;

            if (
                !buttonRef.current?.contains(target) &&
                !menuRef.current?.contains(target)
            ) {
                setOpen(false);
            }
        }

        function onEscape(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        }

        // The table can scroll under the menu (horizontally in .dt-wrap,
        // vertically on the page) — closing on either keeps it from
        // drifting away from the button it belongs to.
        function onScroll() {
            setOpen(false);
        }

        document.addEventListener('mousedown', onOutside);
        document.addEventListener('keydown', onEscape);
        window.addEventListener('scroll', onScroll, true);

        return () => {
            document.removeEventListener('mousedown', onOutside);
            document.removeEventListener('keydown', onEscape);
            window.removeEventListener('scroll', onScroll, true);
        };
    }, [open]);

    function toggle() {
        if (!open && buttonRef.current) {
            const rect = buttonRef.current.getBoundingClientRect();

            setCoords({
                top: rect.bottom + 4,
                right: window.innerWidth - rect.right,
            });
        }

        setOpen((value) => !value);
    }

    return (
        <div className="d-inline-block">
            <button
                ref={buttonRef}
                type="button"
                className="action-badge menu-badge"
                title="Actions"
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={toggle}
            >
                <i className="ti ti-dots-vertical" />
            </button>

            {open && (
                <div
                    ref={menuRef}
                    className="dropdown-menu show mt-0"
                    role="menu"
                    style={{ position: 'fixed', top: coords.top, right: coords.right, left: 'auto' }}
                >
                    {(editTo || onEdit) &&
                        (editTo ? (
                            <Link
                                to={editTo}
                                className="dropdown-item dropdown-item-edit"
                                role="menuitem"
                                title={editTitle}
                                onClick={() => setOpen(false)}
                            >
                                <i className="ti ti-edit me-1 align-middle" />
                                <span className="align-middle">Edit</span>
                            </Link>
                        ) : (
                            <button
                                type="button"
                                className="dropdown-item dropdown-item-edit"
                                role="menuitem"
                                title={editTitle}
                                onClick={() => {
                                    setOpen(false);
                                    onEdit?.();
                                }}
                            >
                                <i className="ti ti-edit me-1 align-middle" />
                                <span className="align-middle">Edit</span>
                            </button>
                        ))}

                    {onDelete && (
                        <button
                            type="button"
                            className="dropdown-item dropdown-item-delete"
                            role="menuitem"
                            title={deleteTitle}
                            onClick={() => {
                                setOpen(false);
                                onDelete();
                            }}
                        >
                            <i className="ti ti-trash me-1 align-middle" />
                            <span className="align-middle">Delete</span>
                        </button>
                    )}
                </div>
            )}
        </div>
    );
}
