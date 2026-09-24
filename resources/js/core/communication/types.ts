/**
 * What the communication API answers with.
 *
 * Shared across channels on purpose: a template, a delivery record and an
 * automation rule mean the same thing whether they went out over WhatsApp,
 * email or SMS. Only the preview and the shape of the library differ, and both
 * are the screen's business rather than the data's.
 */

export type Channel = 'whatsapp' | 'email' | 'sms';

export type TemplateStatus = 'draft' | 'pending' | 'approved' | 'rejected';

export type DeliveryStatus = 'queued' | 'sent' | 'delivered' | 'read' | 'failed';

export type CampaignStatus =
    | 'draft'
    | 'scheduled'
    | 'sending'
    | 'completed'
    | 'paused'
    | 'failed'
    | 'cancelled';

export interface MessageTemplate {
    id: number;
    channel: Channel;
    name: string;
    category: string;
    template_type: string | null;
    subject: string | null;
    content: string;
    status: TemplateStatus;
    is_active: boolean;
    /** Stored on the row, so a clinic's own template can look like itself. */
    icon: string | null;
    tone: string | null;
    updated_at: string | null;
}

export interface AutomationRule {
    id: number;
    channel: Channel;
    event_key: string;
    title: string;
    description: string | null;
    icon: string | null;
    is_enabled: boolean;
    lead_minutes: number | null;
    message_template_id: number | null;
    template_name?: string | null;
}

export interface DeliveryRecord {
    id: number;
    channel: Channel;
    recipient_name: string | null;
    recipient: string;
    template_name: string | null;
    subject: string | null;
    status: DeliveryStatus;
    failure_reason: string | null;
    is_test: boolean;
    customer_id: number | null;
    campaign_id: number | null;
    scheduled_for: string | null;
    sent_at: string | null;
    delivered_at: string | null;
    read_at: string | null;
    created_at: string | null;
}

/** One step of connecting a channel, derived rather than stored. */
export interface SetupStep {
    key: string;
    label: string;
    state: string;
    done: boolean;
}

export interface ChannelAccount {
    display_name: string | null;
    handle: string | null;
    provider: string | null;
    is_connected: boolean;
    is_verified: boolean;
    webhook_configured: boolean;
    connected_at: string | null;
    settings: Record<string, unknown>;
}

/**
 * Counted from the log over a window.
 *
 * Rates are null rather than zero when there is nothing to divide by — "no
 * messages yet" and "0% delivered" are different answers and must not look
 * the same on a dashboard.
 */
export interface ChannelFigures {
    days: number;
    sent: number;
    delivered: number;
    read: number;
    failed: number;
    delivery_rate: number | null;
    read_rate: number | null;
    failure_rate: number | null;
    change: number | null;
}

/** Everything one channel's screen needs, in one request. */
export interface ChannelOverview {
    channel: Channel;
    account: ChannelAccount;
    setup: SetupStep[];
    figures: ChannelFigures;
    analytics: ChannelAnalytics;
    templates: MessageTemplate[];
    rules: AutomationRule[];
    recent: DeliveryRecord[];
}

/** One day on the trend line. Days with nothing sent are present and zero. */
export interface DailyPoint {
    date: string;
    label: string;
    /** A tick that must survive axis thinning — the first of a month. */
    major: boolean;
    sent: number;
    delivered: number;
    failed: number;
}

/** A labelled count, for the donut and the bar lists. */
export interface Tally {
    label: string;
    value: number;
    muted?: boolean;
}

/**
 * Everything the reporting screen draws, counted from the delivery log.
 *
 * Nothing here is estimated or padded — a clinic acts on these, and a chart
 * that invents a shape is worse than one showing a flat line.
 */
export interface ChannelAnalytics {
    daily: DailyPoint[];
    statuses: Tally[];
    templates: Tally[];
    failures: Tally[];
    hours: Tally[];
}

export interface AudienceSegment {
    id: number;
    name: string;
    description: string | null;
    icon: string | null;
    tone: string | null;
    is_system: boolean;
    filters: AudienceFilter[];
    /** Resolved now, not when the segment was saved. */
    total: number;
}

export interface AudienceFilter {
    field: string;
    operator: string;
    value?: string | number;
    value_to?: string | number;
}

export interface AudiencePreview {
    total: number;
    breakdown: { male: number; female: number; other: number };
}

export interface Campaign {
    id: number;
    channel: Channel;
    name: string;
    campaign_type: string | null;
    goal: string | null;
    subject: string | null;
    preview_text: string | null;
    content: string | null;
    from_name: string | null;
    from_email: string | null;
    reply_to: string | null;
    message_template_id: number | null;
    template_name?: string | null;
    audience_segment_id: number | null;
    segment_name?: string | null;
    audience_filters: AudienceFilter[];
    status: CampaignStatus;
    is_editable: boolean;
    scheduled_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    recipients_count: number;
    sent_count: number;
    delivered_count: number;
    opened_count: number;
    clicked_count: number;
    failed_count: number;
    open_rate: number | null;
    click_rate: number | null;
    failure_reason: string | null;
    created_at: string | null;
}
