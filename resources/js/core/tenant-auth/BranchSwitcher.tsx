import { useEffect, useRef, useState } from 'react';
import { useTenantAuth } from './TenantAuthProvider';

/**
 * Which branch the workspace is being used from.
 *
 * Only appears for somebody who actually works at more than one. A picker with
 * a single entry is a control that cannot do anything, and the owner and head
 * office work across the network rather than at a counter — neither has a
 * branch to switch between.
 *
 * Choosing one sends X-Branch-Id on every later request. The server does not
 * take that on trust: ResolveActingBranch checks it against this person's own
 * memberships and refuses a branch they do not work at, so this is a statement
 * of intent rather than a grant.
 */
export function BranchSwitcher() {
    const { branches, activeBranch, setActiveBranch } = useTenantAuth();
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        function onOutside(event: MouseEvent) {
            if (box.current && !box.current.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', onOutside);

        return () => document.removeEventListener('mousedown', onOutside);
    }, [open]);

    if (branches.length < 2) {
        return null;
    }

    const current = branches.find((branch) => branch.id === activeBranch) ?? branches[0];

    return (
        <div className="bs" ref={box}>
            <button
                type="button"
                className="bs-trigger"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
            >
                <i className="ti ti-arrows-left-right" />

                <span className="bs-current">
                    <small>Working at</small>
                    <b>{current.name ?? 'a branch'}</b>
                </span>

                <i className={open ? 'ti ti-chevron-up' : 'ti ti-chevron-down'} />
            </button>

            {open && (
                <div className="bs-menu">
                    <p className="bs-menu-label">Switch to</p>

                    {branches.map((branch) => (
                        <button
                            type="button"
                            key={branch.id}
                            className={`bs-option${branch.id === current.id ? ' is-on' : ''}`}
                            onClick={() => {
                                setActiveBranch(branch.id);
                                setOpen(false);
                            }}
                        >
                            <span>
                                <b>{branch.name ?? `Branch ${branch.id}`}</b>
                                {/*
                                    The role they hold THERE. The whole point of
                                    switching: a receptionist at one branch can
                                    be the manager at another.
                                */}
                                {branch.role && <small>{branch.role}</small>}
                            </span>

                            {branch.id === current.id && <i className="ti ti-check" />}
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}
