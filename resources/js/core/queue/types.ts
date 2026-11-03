import type { RealtimeConfig } from '@/core/tenant-auth/api';

export type QueuePriority = 'normal' | 'urgent' | 'emergency';

export type DoctorAvailability = 'in_session' | 'later' | 'finished' | 'off';

/**
 * One doctor's queue, as a television sees it: this is the shape that goes to a
 * screen in a public room. About the doctor, tokens and counts, and the one patient
 * being called right now, so they know it is them.
 */
export interface QueueCard {
    doctor_id: number;
    doctor_name: string | null;
    photo_url: string | null;
    department: string | null;
    room: string | null;
    prefix: string | null;
    now_calling: string | null;
    now_calling_state: 'called' | 'serving' | null;
    serving: string | null;
    /** Only while being called: never whoever is next, or already with the doctor. */
    patient: { name: string | null; age: number | null; photo_url: string | null } | null;
    next: string[];
    waiting: number;
    paused: boolean;
    availability: DoctorAvailability;
    session_starts_at: string | null;
    estimated_wait_minutes: number;
    average_minutes: number;
}

/** The same card, with what only somebody signed in sees. */
export interface StaffQueueCard extends QueueCard {
    specialisation: string | null;
    skipped: number;
    called: number;
    emergency: number;
    urgent: number;
    longest_wait_minutes: number;
    seen: number;
    expected: number;
}

export interface QueueBoardPayload {
    date: string;
    location_id: number;
    cards: StaffQueueCard[];
    channels: string[];
    departments: { id: number; name: string; parent_id: number | null }[];
    doctors: { id: number; name: string | null; department: string | null }[];
    urgent_head_start_minutes: number;
    updated_at: string;
}

export interface QueueRow {
    id: number;
    token: string | null;
    customer_id: number;
    customer_name: string | null;
    customer_code: string | null;
    customer_photo_url: string | null;
    age: number | null;
    gender: string | null;
    type: 'booked' | 'walk_in';
    slot_at: string | null;
    status: string;
    queue_status: 'waiting' | 'called' | 'with_doctor' | 'skipped' | null;
    consultation_status: string;
    next_action: string | null;
    priority: QueuePriority;
    priority_reason: string | null;
    waiting_minutes: number | null;
    patients_ahead: number | null;
    estimated_wait_minutes: number | null;
    checked_in_at: string | null;
    called_at: string | null;
    skipped_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    available: Record<
        | 'check_in'
        | 'call'
        | 'recall'
        | 'requeue'
        | 'skip'
        | 'absent'
        | 'consult_start'
        | 'consult_complete'
        | 'reassign'
        | 'priority'
        | 'no_show'
        | 'cancel',
        boolean
    >;
}

export interface DoctorQueuePayload {
    date: string;
    location_id: number;
    channel: string;
    card: StaffQueueCard;
    settings: {
        room_no: string | null;
        token_prefix: string | null;
        average_consultation_minutes: number;
        auto_call_enabled: boolean;
        allow_priority: boolean;
        queue_enabled: boolean;
        paused: boolean;
        pause_reason: string | null;
        pause_until: string | null;
    };
    with_doctor: QueueRow[];
    called: QueueRow[];
    waiting: QueueRow[];
    skipped: QueueRow[];
    expected: QueueRow[];
    done: QueueRow[];
    updated_at: string;
}

export interface QueueHistoryRow {
    id: number;
    event_type: string;
    old_status: string | null;
    new_status: string | null;
    token: string | null;
    customer_name: string | null;
    actor_name: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
}

export interface DoctorQueueSetting {
    doctor_id: number;
    doctor_name: string | null;
    department: string | null;
    room_no: string | null;
    token_prefix: string | null;
    average_consultation_minutes: number;
    auto_call_enabled: boolean;
    allow_priority: boolean;
    queue_enabled: boolean;
}

export interface QueueSettingsPayload {
    doctors: DoctorQueueSetting[];
    departments: { id: number; name: string; parent_id: number | null }[];
    shared_runs: { department_id: number; token_prefix: string }[];
}

export type DisplayType = 'reception' | 'department' | 'doctor' | 'laboratory';

export interface QueueDisplay {
    id: number;
    display_name: string;
    display_type: DisplayType;
    location_id: number;
    location_name: string | null;
    department_id: number | null;
    department_name: string | null;
    doctor_ids: number[];
    doctors: { id: number; name: string | null }[];
    layout: 'grid' | 'list';
    theme: 'dark' | 'light';
    audio_enabled: boolean;
    audio_languages: ('en' | 'hi')[];
    is_active: boolean;
    expires_at: string | null;
    revoked_at: string | null;
    last_connected_at: string | null;
    is_online: boolean;
    refusal: 'revoked' | 'expired' | 'inactive' | null;
    /** Only in the answer that made it: the server keeps nothing it could show again. */
    url?: string;
}

export interface QueueDisplayInput {
    display_name: string;
    display_type: DisplayType;
    location_id: number;
    department_id: number | null;
    doctor_ids: number[];
    layout: 'grid' | 'list';
    theme: 'dark' | 'light';
    audio_enabled: boolean;
    audio_languages: ('en' | 'hi')[];
    is_active: boolean;
    expires_at: string | null;
}

export interface QueueDisplayOptions {
    locations: {
        id: number;
        name: string;
        doctors: { id: number; name: string | null; department_id: number | null; department: string | null }[];
    }[];
    departments: { id: number; name: string }[];
}

/** What a television reads, on opening and on its slow timer. */
export interface DisplayState {
    display: {
        id: number;
        name: string;
        type: DisplayType;
        layout: 'grid' | 'list';
        theme: 'dark' | 'light';
        audio_enabled: boolean;
        audio_languages: ('en' | 'hi')[];
    };
    organization: { name: string; logo_url: string | null };
    branch: { id: number | null; name: string | null };
    cards: QueueCard[];
    lab: { waiting: string[]; in_progress: string[]; ready: string[] } | null;
    channels: string[];
    realtime: RealtimeConfig;
    server_time: string;
}

/** The message a doctor's channel carries. The card replaces the one on screen. */
export interface QueueMessage {
    event: string;
    doctor_id: number;
    location_id: number;
    token: string | null;
    change: string;
    card: QueueCard;
    announce: {
        token: string;
        room: string | null;
        doctor_name: string | null;
        patient_name: string | null;
        recalled: boolean;
    } | null;
    at: string;
}

/** Every event a doctor's channel can carry. Any one of them replaces the card. */
export const QUEUE_EVENTS = [
    'queue.patient_checked_in',
    'queue.patient_called',
    'queue.consultation_started',
    'queue.consultation_completed',
    'queue.patient_skipped',
    'queue.priority_changed',
    'queue.updated',
    'queue.paused',
    'queue.resumed',
] as const;
