/**
 * The profile page's drawings.
 *
 * Inline rather than image files because every colour has to follow the accent
 * picked in Appearance settings: the fills live in profile-page.css (.ppf-art-*)
 * and read --brand, so a clinic that chose green gets a green hospital.
 */

/** The hospital in the corner of the page header. */
export function HospitalArt() {
    return (
        <svg className="ppf-hero-art" viewBox="0 0 320 120" aria-hidden="true">
            {/* Clouds */}
            <g className="ppf-art-soft">
                <ellipse cx="46" cy="30" rx="16" ry="6" />
                <ellipse cx="58" cy="25" rx="11" ry="7" />
                <ellipse cx="96" cy="16" rx="11" ry="4.5" />
                <ellipse cx="104" cy="12" rx="7" ry="5" />
            </g>

            {/* The ground it stands on */}
            <path className="ppf-art-soft" d="M0 120 C60 96 120 104 170 102 S290 96 320 120 Z" />

            {/* Wings */}
            <rect className="ppf-art-wall" x="96" y="50" width="48" height="60" rx="2" />
            <rect className="ppf-art-wall" x="196" y="50" width="48" height="60" rx="2" />

            {/* Main block */}
            <rect className="ppf-art-wall" x="140" y="28" width="60" height="82" rx="3" />
            <rect className="ppf-art-mid" x="136" y="25" width="68" height="5" rx="2" />
            <rect className="ppf-art-mid" x="92" y="47" width="48" height="4" rx="2" />
            <rect className="ppf-art-mid" x="200" y="47" width="48" height="4" rx="2" />

            {/* Windows */}
            <g className="ppf-art-mid">
                {[58, 74, 90].map((y) =>
                    [103, 117, 130, 203, 217, 230].map((x) => (
                        <rect key={`${x}-${y}`} x={x} y={y} width="9" height="10" rx="1" />
                    )),
                )}

                {[37, 53, 69].map((y) =>
                    [149, 165, 181].map((x) => (
                        <rect key={`${x}-${y}`} x={x} y={y} width="10" height="11" rx="1" />
                    )),
                )}
            </g>

            {/* Door */}
            <rect className="ppf-art-strong" x="160" y="88" width="20" height="22" rx="1.5" />
            <rect className="ppf-art-wall" x="169.3" y="88" width="1.4" height="22" />

            {/* The cross */}
            <circle className="ppf-art-strong" cx="170" cy="14" r="13" />
            <path className="ppf-art-on-brand" d="M167.5 7h5v4.5h4.5v5H172.5V21h-5v-4.5H163v-5h4.500z" />

            {/* Trees */}
            <rect className="ppf-art-mid" x="68" y="88" width="3" height="20" rx="1" />
            <circle className="ppf-art-tree" cx="69.500" cy="80" r="14" />
            <rect className="ppf-art-mid" x="270" y="82" width="3.400" height="26" rx="1" />
            <ellipse className="ppf-art-tree" cx="271.500" cy="70" rx="15" ry="20" />
            <circle className="ppf-art-tree" cx="252" cy="104" r="8" />
        </svg>
    );
}

/** The soft swells behind the photo on the identity card. */
export function BannerWaves() {
    return (
        <svg className="ppf-id-waves" viewBox="0 0 360 190" preserveAspectRatio="none" aria-hidden="true">
            <path className="ppf-art-soft" d="M0 0 H360 V60 C300 120 230 60 150 92 S40 150 0 120 Z" />
            <path className="ppf-art-soft" d="M0 96 C50 70 96 118 150 150 C96 170 40 168 0 150 Z" />
            <path className="ppf-art-soft" d="M360 40 C330 90 290 120 250 136 C300 140 340 120 360 96 Z" />
        </svg>
    );
}

/** The shield beside the password fields. */
export function ShieldArt() {
    return (
        <svg className="ppf-shield" viewBox="0 0 180 170" aria-hidden="true">
            <defs>
                <linearGradient id="ppf-shield-fill" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" style={{ stopColor: 'rgb(var(--brand-2))' }} />
                    <stop offset="1" style={{ stopColor: 'rgb(var(--brand))' }} />
                </linearGradient>
            </defs>

            <ellipse className="ppf-art-soft" cx="90" cy="150" rx="74" ry="13" />

            {/* Leaves */}
            <g className="ppf-art-tree">
                <path d="M34 142 C14 134 8 116 12 98 C28 106 38 122 34 142 Z" />
                <path d="M30 148 C14 150 2 142 0 128 C14 126 26 134 30 148 Z" />
                <path d="M146 142 C166 134 172 116 168 98 C152 106 142 122 146 142 Z" />
                <path d="M150 148 C164 150 178 142 180 128 C166 126 154 134 150 148 Z" />
            </g>

            {/* Shield, with a paler rim */}
            <path
                className="ppf-art-rim"
                d="M90 10 L148 30 V84 C148 120 120 144 90 156 C60 144 32 120 32 84 V30 Z"
            />
            <path
                d="M90 22 L137 38 V84 C137 113 114 133 90 144 C66 133 43 113 43 84 V38 Z"
                style={{ fill: 'url(#ppf-shield-fill)' }}
            />

            {/* Lock */}
            <path
                className="ppf-art-shackle"
                d="M78 78 V67 a12 12 0 0 1 24 0 V78"
            />
            <rect className="ppf-art-on-brand" x="70" y="76" width="40" height="33" rx="6" />
            <circle className="ppf-art-strong" cx="90" cy="89" r="4.500" />
            <rect className="ppf-art-strong" x="88" y="90" width="4" height="10" rx="2" />

            {/* Check — green because it says "safe", not because it is decoration. */}
            <circle className="ppf-art-ok-ring" cx="132" cy="128" r="21" />
            <circle className="ppf-art-ok" cx="132" cy="128" r="17" />
            <path className="ppf-art-tick" d="M124 128 l6 6 l11 -12" />
        </svg>
    );
}
