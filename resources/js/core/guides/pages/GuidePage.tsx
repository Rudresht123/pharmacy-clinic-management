import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { downloadGuidePdf, useGuide } from '../api';

/**
 * One guide, read inside the app.
 *
 * IN A SANDBOXED FRAME, not as markup in this page. The guide is a whole
 * document with its own stylesheet — written for the PDF — and dropped into
 * the page its CSS would restyle the app around it. The frame keeps the two
 * apart, and its sandbox runs no script at all: `allow-same-origin` is there
 * only so this page can measure the frame's height, never so the guide can
 * act.
 *
 * The same page the PDF is printed from, with a few screen-only rules laid
 * over it: point sizes chosen for A4 read small on a monitor, and a five-
 * column table needs to scroll sideways on a phone rather than crush.
 */
const SCREEN_CSS = `
<style>
    html, body { background: #ffffff; }
    body { max-width: 960px; margin: 0 auto; padding: 4px 16px 32px; font-size: 14px; }
    td, th { font-size: 13px !important; }
    code, .mono, td.mono, .files td.mono { font-size: 12px !important; }
    .small, .sub { font-size: 12.5px !important; }
    h1 { font-size: 26px; }
    h2 { font-size: 19px; }
    h3 { font-size: 15px; }
    @media (max-width: 640px) {
        body { padding: 4px 8px 24px; }
        table { display: block; overflow-x: auto; }
    }
</style>`;

export default function GuidePage() {
    const { slug } = useParams<{ slug: string }>();
    const navigate = useNavigate();
    const { data: guide, isLoading, isError, refetch } = useGuide(slug);

    const frame = useRef<HTMLIFrameElement>(null);
    const [height, setHeight] = useState(800);

    const document_ = useMemo(() => {
        if (!guide) return '';

        return guide.html.includes('</head>')
            ? guide.html.replace('</head>', `${SCREEN_CSS}</head>`)
            : SCREEN_CSS + guide.html;
    }, [guide]);

    /* As tall as the guide, so the page scrolls once rather than twice. */
    const measure = useCallback(() => {
        const inner = frame.current?.contentDocument?.documentElement;

        if (inner) {
            setHeight(inner.scrollHeight + 8);
        }
    }, []);

    useEffect(() => {
        window.addEventListener('resize', measure);

        return () => window.removeEventListener('resize', measure);
    }, [measure]);

    async function download() {
        if (!guide) return;

        try {
            await downloadGuidePdf(guide);
        } catch (failure) {
            notify.error(resolveErrorMessage(failure));
        }
    }

    if (isLoading) return <LoadingBlock label="Loading guide…" />;
    if (isError || !guide) return <ErrorState onRetry={() => refetch()} />;

    return (
        <>
            <PageHeader
                title={guide.title}
                icon="ti ti-book"
                crumbs={[{ label: 'Guide', to: '/guide' }, { label: guide.title }]}
                actions={
                    <div className="d-flex gap-2">
                        <Button variant="light" icon="ti ti-arrow-left" type="button" onClick={() => navigate('/guide')}>
                            All guides
                        </Button>
                        {guide.has_pdf && (
                            <Button icon="ti ti-download" type="button" onClick={() => void download()}>
                                Download PDF
                            </Button>
                        )}
                    </div>
                }
            />

            <Card>
                <iframe
                    ref={frame}
                    title={guide.title}
                    srcDoc={document_}
                    sandbox="allow-same-origin"
                    onLoad={measure}
                    style={{ width: '100%', height, border: 0, display: 'block', background: '#ffffff' }}
                />
            </Card>
        </>
    );
}
