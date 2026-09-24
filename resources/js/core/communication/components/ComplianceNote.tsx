/** What gets a clinic in trouble, whichever channel it uses. */
const POINTS = [
    'Get patient consent before sending clinic messages.',
    'Keep clinical detail out of a notification unless it has to be there.',
    'Send only templates the provider has approved.',
    'Follow the data protection rules that apply where you practise (HIPAA, GDPR).',
];

/**
 * On the screen rather than in the documentation.
 *
 * Consent and what may be written in a notification are the two things that go
 * wrong, and nobody reads a setup guide a second time.
 */
export function ComplianceNote() {
    return (
        <div className="comm-note">
            <i className="ti ti-shield-check" aria-hidden="true" />

            <div>
                <b>Important: compliance and privacy</b>

                <ul>
                    {POINTS.map((point) => (
                        <li key={point}>{point}</li>
                    ))}
                </ul>
            </div>
        </div>
    );
}
