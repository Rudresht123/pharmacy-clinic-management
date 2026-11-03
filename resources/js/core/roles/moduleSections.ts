/**
 * Modules filed the way the sidebar is, in the same order, so somebody looking
 * for a menu row knows where its module is. Every screen in the organization
 * that lists modules reads this, so they all read alike.
 *
 * Dashboard stands alone under no heading there too. The sidebar's "Clinical"
 * runs on through the pharmacy and billing; those split off here because each is
 * its own parent row there, and thirteen modules under one heading would say
 * nothing.
 */
const MODULE_SECTIONS: Array<{ title: string; modules: string[] }> = [
    { title: '', modules: ['dashboard'] },
    {
        title: 'Clinical',
        modules: ['appointments', 'queue', 'customers', 'prescriptions', 'laboratory', 'documents'],
    },
    { title: 'Pharmacy', modules: ['medicines', 'pharmacy', 'reports'] },
    { title: 'Billing & communication', modules: ['billing', 'communication'] },
    { title: 'This branch', modules: ['people', 'exports'] },
    { title: 'Organisation', modules: ['branches', 'settings'] },
];

/**
 * These modules, under their headings. Anything not filed above still shows
 * under "Other": a module added to the registry must never go missing from a
 * screen that grants it.
 */
export function toModuleSections<T extends { key: string }>(modules: T[]) {
    const filed = MODULE_SECTIONS.flatMap((section) => section.modules);

    return [
        ...MODULE_SECTIONS.map((section) => ({
            title: section.title,
            modules: section.modules.flatMap((key) => modules.filter((module) => module.key === key)),
        })),
        { title: 'Other', modules: modules.filter((module) => !filed.includes(module.key)) },
    ].filter((section) => section.modules.length > 0);
}
