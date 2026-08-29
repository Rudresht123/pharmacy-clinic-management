import {
    createContext,
    useCallback,
    useContext,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';

interface ConfirmOptions {
    title?: string;
    message: ReactNode;
    confirmLabel?: string;
    cancelLabel?: string;
    danger?: boolean;
}

type ConfirmFn = (options: ConfirmOptions) => Promise<boolean>;

const ConfirmContext = createContext<ConfirmFn | null>(null);

/**
 * Promise-based confirmation dialog.
 *
 *   if (await confirm({ message: 'Delete this?' , danger: true })) { ... }
 *
 * Replaces window.confirm, SweetAlert and AlertifyJS with one accessible,
 * themed dialog.
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    const [options, setOptions] = useState<ConfirmOptions | null>(null);
    const resolver = useRef<((value: boolean) => void) | null>(null);

    const confirm = useCallback<ConfirmFn>((next) => {
        setOptions(next);

        return new Promise<boolean>((resolve) => {
            resolver.current = resolve;
        });
    }, []);

    const settle = useCallback((result: boolean) => {
        resolver.current?.(result);
        resolver.current = null;
        setOptions(null);
    }, []);

    const value = useMemo(() => confirm, [confirm]);

    return (
        <ConfirmContext.Provider value={value}>
            {children}

            <Modal
                open={options !== null}
                title={options?.title ?? 'Are you sure?'}
                size="sm"
                tone={options?.danger ? 'danger' : 'primary'}
                icon={
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                    >
                        <path d="M12 9v4M12 17h.01" />
                        <path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
                    </svg>
                }
                onClose={() => settle(false)}
                footer={
                    <>
                        <Button variant="light" onClick={() => settle(false)}>
                            {options?.cancelLabel ?? 'Cancel'}
                        </Button>
                        <Button
                            variant={options?.danger ? 'danger' : 'primary'}
                            onClick={() => settle(true)}
                        >
                            {options?.confirmLabel ?? 'Confirm'}
                        </Button>
                    </>
                }
            >
                <p className="mb-0">{options?.message}</p>
            </Modal>
        </ConfirmContext.Provider>
    );
}

export function useConfirm(): ConfirmFn {
    const context = useContext(ConfirmContext);

    if (!context) {
        throw new Error('useConfirm must be used within a ConfirmProvider.');
    }

    return context;
}
