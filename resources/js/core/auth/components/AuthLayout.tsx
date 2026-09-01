import type { ReactNode } from 'react';

export interface StageKpi {
    /** The headline figure itself, pre-formatted. */
    value: string;
    /** Optional movement chip beside it, e.g. "8.6%". */
    delta?: string;
    /** One line under the number saying what it counts. */
    caption: string;
}

export interface StageFeature {
    /** Tabler icon class, e.g. "ti ti-database". */
    icon: string;
    title: string;
    /** One supporting line — says what the title means, nothing more. */
    text: string;
}

interface AuthLayoutProps {
    children: ReactNode;
    /** Replaces the top-left logo/wordmark. Defaults to the platform's own mark. */
    brand?: ReactNode;
    /** Small pill above the heading, e.g. "Pharmacy Management". */
    eyebrow?: string;
    /** Supports a trailing <em> for the accent-colored word, as the default does. */
    heading?: ReactNode;
    description?: string;
    /** Cut-out (transparent) portrait anchored to the bottom of the stage. */
    heroImage?: string;
    /** Shown in the pill floating over the portrait — which workspace this is. */
    appUrl?: string;
    /** The one figure on the card overlapping the portrait. */
    kpi?: StageKpi;
    features?: StageFeature[];
    /** Trailing part of the footer line: "© {year} {footerBrand}". */
    footerBrand?: string;
}

const DEFAULT_FEATURES: StageFeature[] = [
    {
        icon: 'ti ti-vaccine-bottle',
        title: 'Batch & expiry tracking',
        text: 'Know what expires before it does.',
    },
    {
        icon: 'ti ti-building-store',
        title: 'Live stock across counters',
        text: 'One number, every till and shelf.',
    },
    {
        icon: 'ti ti-file-invoice',
        title: 'Audit-ready billing',
        text: 'Every invoice traceable end to end.',
    },
];

const DEFAULT_KPI: StageKpi = {
    value: '1,248',
    delta: '12.4%',
    caption: 'Items dispensed this week',
};

/**
 * Split-screen auth chrome shared by login, register and the password
 * flows. Styling comes from public/vendor/css/auth.css.
 *
 * The stage pairs the copy with a cut-out portrait anchored to the bottom
 * edge. That portrait sits in an absolutely-positioned layer on purpose: it
 * can never add height, so the panel cannot outgrow the viewport and start
 * scrolling however tall the image is.
 *
 * Every piece of the copy is overridable — the tenant login page passes its
 * own, since the platform's "Pharmacy Management" vendor pitch reads oddly
 * on an organization's own staff sign-in screen.
 */
export function AuthLayout({
    children,
    brand = <img src="/assets/img/logo-white.svg" alt="" />,
    eyebrow = 'Pharmacy Management',
    heading = (
        <>
            Every shelf, batch and sale — <em>in sync</em>.
        </>
    ),
    description = 'Inventory, prescriptions, billing and suppliers, unified in one secure workspace built for modern pharmacies.',
    heroImage = '/assets/img/auth/cover-imgs-1.png',
    appUrl = 'app.hms.local',
    kpi = DEFAULT_KPI,
    features = DEFAULT_FEATURES,
    footerBrand = 'Pharmacy Suite',
}: AuthLayoutProps) {
    return (
        <div className="hx-auth">
            <aside className="hx-stage">
                <span className="hx-orb-b" aria-hidden="true" />

                {/* Decorative only — nothing here is announced, and it adds no
                    height to the column beside it. */}
                <div className="hx-hero" aria-hidden="true">
                    <span className="hx-hero-ring" />

                    <img className="hx-hero-img" src={heroImage} alt="" />

                    <span className="hx-hero-pill">
                        <i className="hx-live-dot" />
                        {appUrl}
                    </span>

                    <span className="hx-hero-card">
                        <span className="hx-hero-card-top">
                            <b>{kpi.value}</b>

                            {kpi.delta && (
                                <span className="hx-hero-delta">
                                    <svg
                                        width="11"
                                        height="11"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="3"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                    >
                                        <path d="M12 19V5M5 12l7-7 7 7" />
                                    </svg>
                                    {kpi.delta}
                                </span>
                            )}
                        </span>

                        <span className="hx-hero-card-caption">{kpi.caption}</span>
                    </span>
                </div>

                <div className="hx-brand">{brand}</div>

                <div className="hx-stage-body">
                    <span className="hx-eyebrow">
                        <i />
                        {eyebrow}
                    </span>

                    <h1>{heading}</h1>

                    <p>{description}</p>

                    <ul className="hx-feats">
                        {features.map((feature) => (
                            <li key={feature.title}>
                                <span className="hx-feat-icon" aria-hidden="true">
                                    <i className={feature.icon} />
                                </span>

                                <div className="hx-feat-text">
                                    <b>{feature.title}</b>
                                    <span>{feature.text}</span>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="hx-stage-foot">
                    <span>© {new Date().getFullYear()} {footerBrand}</span>
                    <span>
                        <i className="ti ti-shield-check" aria-hidden="true" />
                        Encrypted &amp; access-controlled
                    </span>
                </div>
            </aside>

            <main className="hx-panel">
                <div className="hx-form">{children}</div>
            </main>
        </div>
    );
}
