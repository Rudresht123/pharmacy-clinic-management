import { Link, Outlet } from 'react-router-dom';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';

/**
 * Route guards for the tenant app.
 *
 * THESE ARE NOT THE ENFORCEMENT. Every route they cover is already refused by
 * the server — `permission:` and `module:` middleware sit on the API routes,
 * and the data for a screen somebody may not have simply never arrives. What
 * these fix is what that refusal looked like: typing /people used to render
 * the whole screen, fire a request, take a 403 and leave somebody staring at
 * an error state, which reads like the software is broken rather than like an
 * answer.
 *
 * So: a clear refusal instead of a blank screen, and nothing security-critical
 * resting on it. A guard that could be edited out of a bundle must never be
 * the only thing standing between a person and a record.
 */

/** The screen somebody who typed a URL they may not use actually wants. */
function NoAccess({ reason }: { reason: string }) {
    return (
        <div className="org-pending">
            <i className="ti ti-lock" />
            <h6>You do not have access to this</h6>
            <p>{reason}</p>

            <Link to="/dashboard" className="btn btn-primary mt-3">
                Back to dashboard
            </Link>
        </div>
    );
}

/**
 * Refuses a route whose capability this person's role does not hold.
 *
 * The same key the API route asks for, so the two cannot drift — if a screen
 * is reachable here, the request behind it will be allowed, and if it is not,
 * both refuse for the same stated reason.
 */
export function RequireCapability({ capability }: { capability: string }) {
    const { can } = useTenantAuth();

    if (!can(capability)) {
        return (
            <NoAccess reason="Your role does not include this. Ask an owner if you need it." />
        );
    }

    return <Outlet />;
}

/**
 * Refuses a route belonging to a module that is not running here.
 *
 * Two different failures, worth two different sentences: the organization was
 * never sold this, or it was but the branch this person works at does not run
 * it. Both arrive as the module simply being absent from `modules`, so the
 * message says the thing that is true of both rather than guessing.
 */
export function RequireModule({ module }: { module: string }) {
    const { modules } = useTenantAuth();

    if (!modules.includes(module)) {
        return (
            <NoAccess reason="This part of the software is not switched on for where you work." />
        );
    }

    return <Outlet />;
}

/**
 * Owner-only, for the two screens that are deliberately not delegatable.
 *
 * Roles and a branch's modules cannot sit behind a capability: a role able to
 * edit roles could grant itself every other one, so there would be nothing
 * left for the other levels to decide.
 */
export function RequireOwner() {
    const { user } = useTenantAuth();

    if (user?.role !== 'owner') {
        return <NoAccess reason="Only an owner can open this." />;
    }

    return <Outlet />;
}
