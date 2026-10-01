import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { EmptyState, ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { downloadGuidePdf, useGuides, type GuideSummary } from '../api';

/**
 * Guide — every guide on how the software works, in one place.
 *
 * Open to everybody signed in. Each card reads the guide here, or saves its
 * PDF to hand round or print.
 */
export default function GuidesPage() {
    const navigate = useNavigate();
    const { data: guides, isLoading, isError, refetch } = useGuides();

    async function download(guide: GuideSummary) {
        try {
            await downloadGuidePdf(guide);
        } catch (failure) {
            notify.error(resolveErrorMessage(failure));
        }
    }

    if (isLoading) return <LoadingBlock label="Loading guides…" />;
    if (isError || !guides) return <ErrorState onRetry={() => refetch()} />;

    return (
        <>
            <PageHeader
                title="Guide"
                icon="ti ti-book"
                subtitle="How HMS works — what each screen does and where each button is."
                crumbs={[{ label: 'Guide' }]}
            />

            {guides.length === 0 ? (
                <Card>
                    <EmptyState icon="ti ti-book" title="No guides yet" description="Guides added to the system appear here." />
                </Card>
            ) : (
                <div className="row g-3">
                    {guides.map((guide) => (
                        <div className="col-md-6 col-xl-4" key={guide.slug}>
                            <Card className="h-100">
                                <div className="d-flex flex-column h-100">
                                    <div className="d-flex align-items-start gap-3 mb-2">
                                        <span
                                            className="d-inline-flex align-items-center justify-content-center rounded bg-primary-subtle text-primary flex-shrink-0"
                                            style={{ width: 40, height: 40 }}
                                        >
                                            <i className="ti ti-book fs-20" />
                                        </span>
                                        <div>
                                            <h6 className="mb-1">{guide.title}</h6>
                                            <span className="dr-sub">
                                                Updated {new Date(guide.updated_at).toLocaleDateString('en-IN', {
                                                    day: 'numeric',
                                                    month: 'short',
                                                    year: 'numeric',
                                                })}
                                            </span>
                                        </div>
                                    </div>

                                    {guide.description && (
                                        <p className="text-muted fs-13 mb-3">{guide.description}</p>
                                    )}

                                    <div className="d-flex gap-2 mt-auto">
                                        <Button icon="ti ti-eye" type="button" onClick={() => navigate(`/guide/${guide.slug}`)}>
                                            Read
                                        </Button>
                                        {guide.has_pdf && (
                                            <Button
                                                variant="light"
                                                icon="ti ti-download"
                                                type="button"
                                                onClick={() => void download(guide)}
                                            >
                                                PDF
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            </Card>
                        </div>
                    ))}
                </div>
            )}
        </>
    );
}
