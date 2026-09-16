import { Card } from '@/shared/components/ui/Card';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { DepartmentManager } from '../components/DepartmentManager';

/** Departments and their sub-departments — what doctors are grouped by. */
export default function DepartmentsPage() {
    return (
        <>
            <PageHeader
                title="Departments"
                icon="ti ti-layout-grid"
                tone="violet"
                crumbs={[{ label: 'Departments' }]}
                subtitle="Departments and their sub-departments — what doctors are grouped by on bookings, the OPD board and reports."
            />

            <Card>
                <DepartmentManager />
            </Card>
        </>
    );
}
