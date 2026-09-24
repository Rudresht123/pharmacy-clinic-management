import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { TemplatePreview } from './TemplatePreview';
import type { MessageTemplate } from '../types';

/**
 * The selected template as it lands in an inbox.
 *
 * An email is a small web page, so the preview is one too — its own white
 * ground and palette in both themes, because what matters is whether the
 * patient can read it, not whether it suits the admin screen around it.
 *
 * The subject sits outside the render, above it, exactly where a mail client
 * puts it: it is the only part most people will ever see.
 */
export function EmailPreview({
    template,
    onEdit,
}: {
    template: MessageTemplate | null;
    onEdit: (template: MessageTemplate) => void;
}) {
    return (
        <Card
            className="comm-card"
            title="Template preview"
            icon="ti ti-mail-opened"
            actions={
                template && (
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
                <TemplatePreview
                    channel="email"
                    name={template.name}
                    subject={template.subject}
                    content={template.content}
                    icon={template.icon}
                />
            )}
        </Card>
    );
}
