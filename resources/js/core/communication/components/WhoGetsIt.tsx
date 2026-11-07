import { recipientSummary } from './AutomationRules';
import type { AutomationRule, Channel } from '../types';

/** Who a template goes to, in one line. Changed from its row, or on Recipients. */
export function WhoGetsIt({ channel, rules }: { channel: Channel; rules: AutomationRule[] }) {
    const rule = rules[0] ?? null;

    const said =
        channel !== 'email' && channel !== 'push'
            ? 'The patient’s own WhatsApp number, and nobody else’s.'
            : rule === null
              ? 'Nothing sends this by itself. Who gets it is chosen when a campaign goes out.'
              : recipientSummary(rule);

    return (
        <p className="rcp-goes-to">
            <i className="ti ti-users" aria-hidden="true" />
            <span>
                <b>Who gets it</b>
                <small>{said}</small>
            </span>
        </p>
    );
}
