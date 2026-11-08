/** What the Export Center's API answers with. */

export type ExportFormat = 'xlsx' | 'csv' | 'pdf';

export type ExportRunStatus = 'queued' | 'running' | 'completed' | 'failed' | 'cancelled' | 'expired';

/** One file being made on the queue — what the tray watches. */
export interface ExportRun {
    id: number;
    dataset: string;
    format: ExportFormat;
    /** "Billing — Invoices" */
    title: string;
    /** "invoices" */
    noun: string;
    status: ExportRunStatus;
    total_rows: number;
    processed_rows: number;
    /** 0–100; 99 at most until the file is closed. */
    percent: number;
    /** From the pace so far; null before there is one, and while the file is closed. */
    seconds_left: number | null;
    /** Every row read; the file is being zipped or finished. */
    finishing: boolean;
    file_name: string | null;
    /** Bytes, while the file is kept. */
    file_size: number | null;
    error: string | null;
    created_at: string | null;
    started_at: string | null;
    finished_at: string | null;
    expires_at: string | null;
    downloaded_at: string | null;
}

export type TabKey = 'patients' | 'pharmacy' | 'billing' | 'communication';

export interface ExportColumn {
    key: string;
    label: string;
    type: 'text' | 'number' | 'money' | 'date';
}

export interface ExportStatus {
    value: string;
    label: string;
}

export interface ExportDataset {
    key: string;
    label: string;
    description: string;
    icon: string;
    /** What one row is, plural — "invoices". */
    noun: string;
    /** Already narrowed to the columns this person's roles let them take. */
    columns: ExportColumn[];
    /** What the dates narrow, or null for an export with no date range. */
    date_label: string | null;
    default_period: string;
    statuses: ExportStatus[];
    /** What the status filter is called here — "Method" for payments. */
    status_label: string;
    search_hint: string | null;
    /** Column keys the table can sort by. */
    sortable: string[];
    /** Whether the doctor filter applies. */
    doctor_filter: boolean;
}

export interface ExportBranch {
    id: number;
    name: string;
}

export interface ExportTab {
    key: TabKey;
    label: string;
    icon: string;
    /** Every branch, rather than the listed ones only. */
    all_branches: boolean;
    branches: ExportBranch[];
    datasets: ExportDataset[];
}

export interface ExportCatalogue {
    tabs: ExportTab[];
    /** The doctor filter's choices — empty when no export offers it. */
    doctors: { id: number; name: string }[];
    can_manage_columns: boolean;
    pdf_limit: number;
}

export interface ExportRow {
    id: number;
    /** In the order of the columns, every value as it reads. */
    cells: string[];
    /** A second line under some cells, by column key — "Male, 32 yrs". */
    subs: Record<string, string>;
    /** Where the record opens in the software, when it has a screen. */
    link: string | null;
    /** The patient's photograph, for the avatar beside their name. */
    photo: string | null;
    muted: boolean;
}

export interface ExportPreview {
    total: number;
    columns: ExportColumn[];
    rows: ExportRow[];
    meta: { current_page: number; last_page: number; per_page: number; from: number; to: number };
}

/** What the filters on screen send — every value as a query-string string. */
export type ExportParams = Record<string, string | undefined>;

/* --- column access --------------------------------------------------- */

export interface RuleDataset {
    key: string;
    label: string;
    icon: string;
    columns: ExportColumn[];
}

export interface RuleTab {
    key: TabKey;
    label: string;
    icon: string;
    datasets: RuleDataset[];
}

export interface RuleRole {
    id: number;
    name: string;
    icon: string | null;
    scope: 'organization' | 'branch';
    branch: string | null;
    /** Which tabs the role's own capabilities give it. */
    tabs: Record<TabKey, boolean>;
    /** Columns hidden, by export — an export not named hides nothing. */
    hidden: Record<string, string[]>;
}

export interface ColumnRules {
    tabs: RuleTab[];
    roles: RuleRole[];
}
