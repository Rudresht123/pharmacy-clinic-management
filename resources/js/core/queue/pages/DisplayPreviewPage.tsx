import { useEffect, useMemo } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { TvScreen } from '../display/TvScreen';
import { useDisplayPreview } from '../api';

const NOTHING_CALLING = new Set<number>();

/**
 * What a television shows, from somebody's desk.
 *
 * Needs no link, because only the display's hash is kept: a signed-in person
 * reads the same state the screen would. Outside the workspace's chrome, so it
 * looks like the wall does.
 */
export default function DisplayPreviewPage() {
    const { id } = useParams();
    const displayId = Number(id) || null;

    const { data, isLoading, isError, refetch } = useDisplayPreview(displayId);

    useEffect(() => {
        document.body.classList.add('tv-open');

        return () => document.body.classList.remove('tv-open');
    }, []);

    const calling = useMemo(() => NOTHING_CALLING, []);

    if (isLoading) return <LoadingBlock label="Loading the preview…" />;
    if (isError || !data) return <ErrorState message="Unable to load this display." onRetry={() => refetch()} />;

    return (
        <>
            <TvScreen state={data} calling={calling} connection="preview" />

            <Link to="/settings/tv-displays" className="tv-back">
                <i className="ti ti-arrow-left" aria-hidden="true" /> Back to displays
            </Link>
        </>
    );
}
