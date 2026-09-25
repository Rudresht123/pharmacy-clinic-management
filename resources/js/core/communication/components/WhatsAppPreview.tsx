import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { TemplatePill } from './StatusPill';
import { TemplatePreview } from './TemplatePreview';
import type { MessageTemplate } from '../types';

/**
 * The selected template as the patient will receive it.
 *
 * Deliberately WhatsApp's own colours rather than the theme's, in both light
 * and dark: the point of the panel is that somebody can tell at a glance this
 * is a phone and not another card in the page.
 */
export function WhatsAppPreview({
    template,
    onEdit,
}: {
    template: MessageTemplate | null;
    /** Absent for a reader: the preview stays, the way in does not. */
    onEdit?: (template: MessageTemplate) => void;
}) {
    return (
        <Card
            className="comm-card"
            title="Template preview"
            icon="ti ti-device-mobile"
            actions={
                template &&
                onEdit && (
                    <Button
                        variant="light"
                        size="sm"
                        icon="ti ti-edit"
                        onClick={() => onEdit(template)}
                    >
                        Edit template
                    </Button>
                )
            }
        >
            {!template ? (
                <p className="pd-quiet">
                    <i className="ti ti-hand-click" aria-hidden="true" />
                    Choose a template to preview it.
                </p>
            ) : (
                <>
                    <TemplatePreview channel="whatsapp" content={template.content} />

                    {/* What is being previewed, so the panel says something
                        about the template as well as showing it. */}
                    <div className="comm-preview-meta">
                        <span className="comm-tag">{template.category}</span>

                        {template.template_type && (
                            <span className="comm-tag">{template.template_type}</span>
                        )}

                        <TemplatePill status={template.status} />
                    </div>

                    <p className="pf-soon">
                        <i className="ti ti-info-circle" aria-hidden="true" />
                        Placeholders are shown with sample values. Real sends fill them from the
                        patient record.
                    </p>
                </>
            )}
        </Card>
    );
}
