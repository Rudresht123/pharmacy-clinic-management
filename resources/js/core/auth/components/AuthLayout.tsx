import type { ReactNode } from 'react';

/**
 * Split-screen auth chrome shared by login, register and the password
 * flows. Styling comes from public/vendor/css/auth.css.
 */
export function AuthLayout({ children }: { children: ReactNode }) {
    return (
        <div className="hx-auth">
            <aside className="hx-stage">
                <div className="hx-brand">
                    <img src="/assets/img/logo-white.svg" alt="" />
                </div>

                <div className="hx-stage-body">
                    <span className="hx-eyebrow">
                        <i />
                        Pharmacy Management
                    </span>

                    <h1>
                        Every shelf, batch and sale — <em>in sync</em>.
                    </h1>

                    <p>
                        Inventory, prescriptions, billing and suppliers, unified in one secure
                        workspace built for modern pharmacies.
                    </p>

                    <div className="hx-viz">
                        <div className="hx-viz-top">
                            <b>Dispense trend</b>
                            <span>Last 7 days</span>
                        </div>

                        <svg
                            className="hx-chart"
                            viewBox="0 0 320 110"
                            preserveAspectRatio="none"
                            aria-hidden="true"
                        >
                            <defs>
                                <linearGradient id="hxFill" x1="0" y1="0" x2="0" y2="1">
                                    <stop offset="0%" stopColor="#00D3C7" stopOpacity=".34" />
                                    <stop offset="100%" stopColor="#00D3C7" stopOpacity="0" />
                                </linearGradient>
                            </defs>

                            <line className="grid" x1="0" y1="26" x2="320" y2="26" />
                            <line className="grid" x1="0" y1="58" x2="320" y2="58" />
                            <line className="grid" x1="0" y1="90" x2="320" y2="90" />

                            <path
                                className="area"
                                d="M8 84 C 30 80, 42 70, 60 68 S 92 74, 111 60 S 143 44, 162 48 S 194 36, 213 31 S 246 27, 264 22 S 296 15, 312 12 L 312 104 L 8 104 Z"
                            />
                            <path
                                className="line"
                                pathLength={400}
                                d="M8 84 C 30 80, 42 70, 60 68 S 92 74, 111 60 S 143 44, 162 48 S 194 36, 213 31 S 246 27, 264 22 S 296 15, 312 12"
                            />

                            <circle className="peak-ring" cx="312" cy="12" r="5" />
                            <circle className="peak" cx="312" cy="12" r="3.4" />
                        </svg>

                        <div className="hx-days">
                            <span>Mon</span>
                            <span>Tue</span>
                            <span>Wed</span>
                            <span>Thu</span>
                            <span>Fri</span>
                            <span>Sat</span>
                            <span>Sun</span>
                        </div>
                    </div>

                    <div className="hx-tags">
                        {[
                            'Batch & expiry tracking',
                            'Live stock across counters',
                            'Audit-ready billing',
                        ].map((tag) => (
                            <span key={tag}>
                                <svg
                                    width="15"
                                    height="15"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2.6"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <path d="M20 6L9 17l-5-5" />
                                </svg>
                                {tag}
                            </span>
                        ))}
                    </div>
                </div>

                <div className="hx-stage-foot">
                    <span>© {new Date().getFullYear()} Pharmacy Suite</span>
                </div>
            </aside>

            <main className="hx-panel">
                <div className="hx-form">{children}</div>
            </main>
        </div>
    );
}
