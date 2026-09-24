import { Card } from '@/shared/components/ui/Card';

export interface Benefit {
    icon: string;
    tone: string;
    title: string;
    hint: string;
}

/**
 * The case for the channel.
 *
 * Static copy, and it stays static: these are the reasons a clinic would turn
 * a channel on, not facts about their account. Anything that varies per clinic
 * belongs in the figures above it.
 */
export const WHATSAPP_BENEFITS: Benefit[] = [
    {
        icon: 'ti ti-messages',
        tone: 'is-emerald',
        title: 'Instant communication',
        hint: 'Reach patients on the app they already have open',
    },
    {
        icon: 'ti ti-calendar-event',
        tone: 'is-amber',
        title: 'Reduce no-shows',
        hint: 'A reminder the day before recovers most of them',
    },
    {
        icon: 'ti ti-users',
        tone: 'is-violet',
        title: 'Improve patient experience',
        hint: 'Timely updates without anyone making a call',
    },
    {
        icon: 'ti ti-clock',
        tone: 'is-sky',
        title: 'Save time at the desk',
        hint: 'Confirmations stop being a phone call each',
    },
    {
        icon: 'ti ti-eye',
        tone: 'is-teal',
        title: 'Higher open rate',
        hint: 'Messages are read 70–90% of the time',
    },
];

export const EMAIL_BENEFITS: Benefit[] = [
    {
        icon: 'ti ti-file-description',
        tone: 'is-sky',
        title: 'Room to say more',
        hint: 'Receipts, reports and instructions that do not fit a text',
    },
    {
        icon: 'ti ti-paperclip',
        tone: 'is-violet',
        title: 'Attachments',
        hint: 'Invoices and lab reports travel with the message',
    },
    {
        icon: 'ti ti-coin',
        tone: 'is-emerald',
        title: 'Cheap at volume',
        hint: 'No per-message fee on a clinic-wide send',
    },
    {
        icon: 'ti ti-archive',
        tone: 'is-amber',
        title: 'Patients keep it',
        hint: 'An inbox is where people look for a receipt months later',
    },
];

export function ChannelBenefits({ title, benefits }: { title: string; benefits: Benefit[] }) {
    return (
        <Card className="comm-card" title={title} icon="ti ti-bulb">
            <ul className="comm-benefits">
                {benefits.map((benefit) => (
                    <li key={benefit.title}>
                        <i className={`${benefit.icon} ${benefit.tone}`} aria-hidden="true" />

                        <span>
                            <b>{benefit.title}</b>
                            <small>{benefit.hint}</small>
                        </span>
                    </li>
                ))}
            </ul>
        </Card>
    );
}
