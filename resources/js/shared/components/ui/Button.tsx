import type { ButtonHTMLAttributes, ReactNode } from 'react';
import { cn } from '@/shared/utils/cn';

type Variant = 'primary' | 'secondary' | 'light' | 'danger' | 'outline';
type Size = 'sm' | 'md';

const VARIANTS: Record<Variant, string> = {
    primary: 'btn-primary',
    secondary: 'btn-secondary',
    light: 'btn-light',
    danger: 'btn-danger',
    outline: 'btn-outline-secondary',
};

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
    variant?: Variant;
    size?: Size;
    loading?: boolean;
    icon?: string;
    children?: ReactNode;
}

export function Button({
    variant = 'primary',
    size = 'md',
    loading = false,
    icon,
    children,
    className,
    disabled,
    type = 'button',
    ...rest
}: ButtonProps) {
    return (
        <button
            type={type}
            className={cn('btn', VARIANTS[variant], size === 'sm' && 'btn-sm', className)}
            // A submitting form must not accept a second click.
            disabled={disabled || loading}
            {...rest}
        >
            {loading ? (
                <span
                    className="spinner-border spinner-border-sm me-2"
                    role="status"
                    aria-hidden="true"
                />
            ) : (
                icon && <i className={cn(icon, children ? 'me-1' : undefined)} />
            )}
            {children}
        </button>
    );
}
