import { fillPlaceholders } from '../placeholders';
import type { Channel } from '../types';

/**
 * A template as the patient will receive it, in the shape of its channel.
 *
 * Presentational and value-driven rather than record-driven: the editor
 * previews what is being TYPED, which is not a saved template yet, while the
 * library previews one that is. Both want the same picture, so the picture
 * lives here and neither owns it.
 *
 * Deliberately no Card, no title and no edit button. The two page-level
 * previews wrap this in a Card; the modal drops it straight in, where an
 * "Edit template" button inside the edit dialog would be nonsense.
 */
export function TemplatePreview({
    channel,
    name,
    subject,
    content,
    icon,
}: {
    channel: Channel;
    /** Shown on the email banner; ignored on WhatsApp, which has no header. */
    name?: string | null;
    subject?: string | null;
    content: string;
    icon?: string | null;
}) {
    const written = content.trim() !== '';
    const body = written ? fillPlaceholders(content) : null;

    /*
     * Email is a small web page and WhatsApp is a chat bubble. Branching here
     * rather than at each call site is the whole point: the editor was showing
     * a phone for an email because the markup was inlined where only one
     * channel had been thought about.
     */
    if (channel === 'email') {
        return (
            <>
                <p className="comm-subject-line">
                    <span>Subject</span>
                    <b>{subject?.trim() || name?.trim() || 'No subject yet'}</b>
                </p>

                <div className="comm-mail">
                    <div className="comm-mail-head">
                        <span className="comm-mail-logo" aria-hidden="true">
                            <i className="ti ti-square-rounded-plus-filled" />
                        </span>

                        <span>
                            <b>CarePlus</b>
                            <small>Your health, our priority</small>
                        </span>
                    </div>

                    <div className="comm-mail-banner">
                        <span className="comm-mail-banner-icon" aria-hidden="true">
                            <i className={icon || 'ti ti-calendar-check'} />
                        </span>

                        <b>{name?.trim() || 'Untitled template'}</b>
                    </div>

                    <div className="comm-mail-body">
                        <p className={written ? undefined : 'comm-bubble-empty'}>
                            {body ?? 'Your message will appear here, as the patient reads it.'}
                        </p>

                        <div className="comm-mail-grid">
                            <div>
                                <i className="ti ti-calendar" aria-hidden="true" />
                                <span>Date</span>
                                <b>24 Sep 2026</b>
                            </div>

                            <div>
                                <i className="ti ti-clock" aria-hidden="true" />
                                <span>Time</span>
                                <b>11:00 AM</b>
                            </div>

                            <div>
                                <i className="ti ti-stethoscope" aria-hidden="true" />
                                <span>Doctor</span>
                                <b>Dr. Rahul Mehta</b>
                                <small>General physician</small>
                            </div>

                            <div>
                                <i className="ti ti-building-hospital" aria-hidden="true" />
                                <span>Clinic</span>
                                <b>CarePlus Clinic</b>
                                <small>Gurugram</small>
                            </div>
                        </div>

                        <span className="comm-mail-cta">View appointment details</span>

                        <p className="comm-mail-foot">
                            Thank you for choosing CarePlus Clinic.
                            <br />
                            We look forward to seeing you.
                        </p>
                    </div>
                </div>
            </>
        );
    }

    return (
        <div className="comm-phone">
            <div className="comm-phone-bar">
                <span className="comm-phone-avatar" aria-hidden="true">
                    <i className="ti ti-building-hospital" />
                </span>

                <span>
                    <b>CarePlus Clinic</b>
                    <small>online</small>
                </span>
            </div>

            <div className="comm-bubble">
                <p className={written ? undefined : 'comm-bubble-empty'}>
                    {body ?? 'Your message will appear here, as the patient sees it.'}
                </p>

                <time>10:30 AM</time>
            </div>
        </div>
    );
}
