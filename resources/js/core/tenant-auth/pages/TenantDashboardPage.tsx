import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { useTenantAuth } from '../TenantAuthProvider';

/**
 * The landing page inside an organization's workspace.
 *
 * Deliberately thin: the shell around it (sidebar, header, appearance
 * settings) is the same one the platform panel uses, and the clinical
 * modules that will fill this page do not exist yet.
 */
export default function TenantDashboardPage() {
    const { user, organization } = useTenantAuth();

    return (
        <>
            <PageHeader
                title={`Welcome back, ${user?.name ?? ''}`}
                subtitle={organization?.name}
                icon="ti ti-layout-dashboard"
                tone="sky"
                crumbs={[{ label: 'Dashboard' }]}
                home={false}
            />

            <div className="row g-3">
                <div className="col-12">
                    <Card>
                        <p className="text-muted mb-0">
                            Your clinical modules — patients, appointments, billing, inventory —
                            will appear here as they are built.
                        </p>
                    </Card>
                </div>
            </div>
        </>
    );
}
