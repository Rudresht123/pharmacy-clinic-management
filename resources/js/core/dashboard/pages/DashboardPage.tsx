import { useAuth } from '@/core/auth/AuthProvider';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';

export default function DashboardPage() {
    const { user } = useAuth();

    return (
        <>
            <PageHeader
                title={`Welcome back, ${user?.name ?? ''}`}
                icon="ti ti-layout-dashboard"
                tone="sky"
                crumbs={[{ label: 'Dashboard' }]}
                home={false}
            />

            <div className="row g-3">
                <div className="col-12">
                    <Card>
                        <p className="text-muted mb-0">
                            Pharmacy modules (medicines, inventory, sales) will appear here as they
                            are built.
                        </p>
                    </Card>
                </div>
            </div>
        </>
    );
}
