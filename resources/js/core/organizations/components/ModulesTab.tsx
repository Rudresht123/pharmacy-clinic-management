import { Card } from '@/shared/components/ui/Card';
import { ModuleAssignPanel } from './ModuleAssignPanel';

/**
 * An organization's modules, on its own detail screen.
 *
 * The panel is shared with the platform's Modules screen, which shows the
 * same thing beside a list of organizations — one arrangement, edited the
 * same way whichever direction you came from.
 */
export function ModulesTab({ uuid }: { uuid: string }) {
    return (
        <Card
            title="Modules"
            icon="ti ti-puzzle"
            description="What this organization is entitled to use. Its own roles are granted from the capabilities these confer, so a module that is not assigned cannot be given to anybody inside."
        >
            <ModuleAssignPanel uuid={uuid} />
        </Card>
    );
}
