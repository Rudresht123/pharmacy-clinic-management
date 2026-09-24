/**
 * The blanks a template may leave for the sender to fill.
 *
 * One list, used three ways: the chips that insert them, the sample values
 * every preview renders with, and the tooltip that says what each one will
 * become. Keeping those together is what stops a chip offering a placeholder
 * the preview cannot fill — which reads, to whoever wrote the template, as the
 * preview being broken.
 *
 * WhatsApp's own numbered form ({{1}}, {{2}}) is supported alongside the named
 * one because approved WhatsApp templates arrive using it, and a clinic
 * editing one should not have to rewrite it to see a preview.
 */
export interface Placeholder {
    token: string;
    label: string;
    sample: string;
}

export const PLACEHOLDERS: Placeholder[] = [
    { token: '{{patient_name}}', label: 'Patient', sample: 'Amit Kumar' },
    { token: '{{doctor_name}}', label: 'Doctor', sample: 'Dr. Rahul Mehta' },
    { token: '{{clinic_name}}', label: 'Clinic', sample: 'CarePlus Clinic' },
    { token: '{{date}}', label: 'Date', sample: '24 Sep 2026' },
    { token: '{{time}}', label: 'Time', sample: '11:00 AM' },
    { token: '{{amount}}', label: 'Amount', sample: '500' },
    { token: '{{service}}', label: 'Service', sample: 'Consultation' },
    { token: '{{phone}}', label: 'Phone', sample: '+91 98765 43210' },
];

/** Named placeholders, plus WhatsApp's numbered ones. */
const SAMPLES: Record<string, string> = {
    ...Object.fromEntries(
        PLACEHOLDERS.map((placeholder) => [
            placeholder.token.replace(/[{}]/g, ''),
            placeholder.sample,
        ]),
    ),

    // WhatsApp numbers its variables in the order they appear, and an
    // approved template carries them rather than names.
    '1': 'Amit Kumar',
    '2': 'Dr. Rahul Mehta',
    '3': '24 Sep 2026',
    '4': '11:00 AM',
};

/**
 * What the patient will actually read.
 *
 * An unknown placeholder is left exactly as written rather than blanked: it
 * is almost always a typo, and showing the braces is how somebody spots it.
 */
export function fillPlaceholders(content: string): string {
    return content.replace(
        /\{\{\s*(\w+)\s*\}\}/g,
        (whole, key: string) => SAMPLES[key] ?? whole,
    );
}
