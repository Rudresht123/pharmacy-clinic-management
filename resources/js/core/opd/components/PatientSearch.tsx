import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useDebounce } from '@/shared/hooks/useDebounce';
import { customersHooks } from '@/core/customers/api';
import { useEntityLabel } from '@/core/field-settings/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';

/**
 * Finding a patient from anywhere in the workspace.
 *
 * The desk's most frequent action by a wide margin, and until now it needed
 * navigating to the patients list first. Sits in the header so it is reachable
 * from the queue, a doctor's screen, or the middle of a booking.
 *
 * Searches the same endpoint the list does — name, number, phone, email, town
 * — so the one thing somebody half-remembers is enough. Debounced, because the
 * key is the raw input and a ten-letter name would otherwise be eight
 * requests.
 */
export function PatientSearch() {
    const navigate = useNavigate();
    const label = useEntityLabel('customer');
    const { can } = useTenantAuth();

    const [term, setTerm] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);

    const box = useRef<HTMLDivElement>(null);
    const field = useRef<HTMLInputElement>(null);

    const query = useDebounce(term, 300);

    const { data, isFetching } = customersHooks.useList(
        query.trim().length >= 2 ? { search: query.trim(), per_page: 6 } : undefined,
        { enabled: query.trim().length >= 2 },
    );

    const results = data ?? [];

    /*
     * Ctrl/Cmd+K from anywhere. Worth a shortcut because it is the one action
     * repeated dozens of times an hour — most are not, and a keyboard map
     * nobody can remember is worse than none.
     */
    useEffect(() => {
        function onKey(event: KeyboardEvent) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                field.current?.focus();
                field.current?.select();
            }

            if (event.key === 'Escape') {
                setOpen(false);
                field.current?.blur();
            }
        }

        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        function onOutside(event: MouseEvent) {
            if (box.current && !box.current.contains(event.target as Node)) {
                setOpen(false);
            }
        }

        document.addEventListener('mousedown', onOutside);

        return () => document.removeEventListener('mousedown', onOutside);
    }, []);

    // A new search starts at the top of its own results, not wherever the
    // last one was left.
    useEffect(() => setActive(0), [query]);

    if (!can('customers.view')) {
        return null;
    }

    function choose(id: number) {
        setOpen(false);
        setTerm('');
        navigate(can('customers.edit') ? `/customers/${id}/edit` : '/customers');
    }

    function onKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
        if (results.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((at) => (at + 1) % results.length);
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((at) => (at - 1 + results.length) % results.length);
        }

        if (event.key === 'Enter') {
            event.preventDefault();
            choose(results[active].id);
        }
    }

    return (
        <div className="gs" ref={box}>
            <div className="gs-field">
                <i className="ti ti-search" aria-hidden="true" />

                <input
                    ref={field}
                    type="search"
                    placeholder={`Search ${label.plural.toLowerCase()} by name, mobile, or ID…`}
                    value={term}
                    onChange={(event) => {
                        setTerm(event.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => setOpen(true)}
                    onKeyDown={onKeyDown}
                    aria-label={`Search ${label.plural.toLowerCase()}`}
                />

                <kbd className="gs-key">Ctrl + K</kbd>
            </div>

            {open && term.trim().length >= 2 && (
                <div className="gs-drop" role="listbox">
                    {isFetching && results.length === 0 ? (
                        <p className="gs-note">Searching…</p>
                    ) : results.length === 0 ? (
                        <p className="gs-note">
                            Nobody matches “{term.trim()}”.
                        </p>
                    ) : (
                        results.map((customer, index) => (
                            <button
                                type="button"
                                key={customer.id}
                                role="option"
                                aria-selected={index === active}
                                className={`gs-hit${index === active ? ' is-on' : ''}`}
                                onMouseEnter={() => setActive(index)}
                                onClick={() => choose(customer.id)}
                            >
                                <span className="gs-hit-who">
                                    <b>{customer.name}</b>
                                    <small>
                                        {customer.code && <code>{customer.code}</code>}
                                        {customer.phone && <span>{customer.phone}</span>}
                                    </small>
                                </span>

                                <i className="ti ti-arrow-right" aria-hidden="true" />
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
