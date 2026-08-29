import { Link } from 'react-router-dom';

/**
 * The edit / delete pair used in every table's Action column.
 *
 * Uses the theme's .action-badge / .edit-badge / .delete-badge classes, so
 * the soft-tinted blue and red buttons match the Blade tables exactly.
 * Edit renders as a link when given a `to`, otherwise as a button.
 */
interface RowActionsProps {
    editTo?: string;
    onEdit?: () => void;
    onDelete?: () => void;
    editTitle?: string;
    deleteTitle?: string;
}

const EDIT_ICON = (
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="14"
        height="14"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
    >
        <path d="M12 20h9" />
        <path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5z" />
    </svg>
);

const DELETE_ICON = (
    <svg
        xmlns="http://www.w3.org/2000/svg"
        width="14"
        height="14"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
    >
        <polyline points="3 6 5 6 21 6" />
        <path d="M19 6l-1 14H6L5 6" />
        <path d="M10 11v6" />
        <path d="M14 11v6" />
        <path d="M9 6V4h6v2" />
    </svg>
);

export function RowActions({
    editTo,
    onEdit,
    onDelete,
    editTitle = 'Edit',
    deleteTitle = 'Delete',
}: RowActionsProps) {
    return (
        <div className="d-flex align-items-center gap-2">
            {editTo ? (
                <Link to={editTo} className="action-badge edit-badge" title={editTitle}>
                    {EDIT_ICON}
                </Link>
            ) : (
                onEdit && (
                    <button
                        type="button"
                        className="action-badge edit-badge"
                        title={editTitle}
                        onClick={onEdit}
                    >
                        {EDIT_ICON}
                    </button>
                )
            )}

            {onDelete && (
                <button
                    type="button"
                    className="action-badge delete-badge"
                    title={deleteTitle}
                    onClick={onDelete}
                >
                    {DELETE_ICON}
                </button>
            )}
        </div>
    );
}
