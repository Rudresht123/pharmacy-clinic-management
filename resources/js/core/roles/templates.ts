import type { GrantableModule } from './types';

/**
 * Somewhere to start.
 *
 * A blank grid of twelve checkboxes is a bad first screen — it asks somebody
 * to hold the whole permission model in their head before they can name one
 * job. These are the jobs a clinic or pharmacy actually staffs, and picking
 * one fills the boxes in so the work becomes editing rather than composing.
 *
 * Each is a starting point and nothing more: the moment one is applied it is
 * an ordinary set of ticks with no memory of where it came from, so a template
 * that does not quite fit is adjusted rather than fought.
 *
 * Capabilities naming modules the organization does not hold are dropped when
 * a template is applied, so a template never offers something ungrantable.
 */
export interface RoleTemplate {
    key: string;
    name: string;
    /** What this person does all day, in the words somebody would use. */
    summary: string;
    icon: string;
    capabilities: string[];
}

export const ROLE_TEMPLATES: RoleTemplate[] = [
    {
        key: 'receptionist',
        name: 'Receptionist',
        summary: 'Books patients in, runs the queue, keeps records up to date.',
        icon: 'ti ti-headset',
        capabilities: [
            'branches.view',
            'customers.view',
            'customers.create',
            'customers.edit',
            'appointments.view',
            'appointments.book',
            'appointments.queue',
        ],
    },
    {
        key: 'doctor',
        name: 'Doctor',
        summary: 'Sees their patients and their day; does not administer anything.',
        icon: 'ti ti-stethoscope',
        capabilities: [
            'customers.view',
            'appointments.view',
            'prescriptions.view',
            'prescriptions.write',
        ],
    },
    {
        key: 'manager',
        name: 'Practice manager',
        summary: 'Runs the place day to day — staff, branches, doctors and the diary.',
        icon: 'ti ti-briefcase',
        capabilities: [
            'branches.view',
            'branches.create',
            'branches.edit',
            'people.view',
            'people.create',
            'people.edit',
            'customers.view',
            'customers.create',
            'customers.edit',
            'customers.delete',
            'appointments.view',
            'appointments.book',
            'appointments.queue',
            'appointments.cancel',
            'appointments.schedule',
            'settings.audit',
        ],
    },
    {
        key: 'read_only',
        name: 'Read-only',
        summary: 'Can look at everything and change nothing. Useful for an accountant.',
        icon: 'ti ti-eye',
        // Filled in from whatever `.view` capabilities exist, so this one
        // cannot fall behind when a module ships a new pair.
        capabilities: [],
    },
];

/**
 * A template's capabilities, narrowed to what this organization can grant.
 *
 * `read_only` is derived rather than listed: every capability whose key ends
 * in `.view`. Listing them would be a second place to remember when a module
 * ships, and the one nobody would remember.
 */
export function resolveTemplate(template: RoleTemplate, modules: GrantableModule[]): string[] {
    const available = modules.flatMap((module) =>
        module.capabilities.map((capability) => capability.key),
    );

    if (template.key === 'read_only') {
        return available.filter((key) => key.endsWith('.view'));
    }

    return template.capabilities.filter((key) => available.includes(key));
}
