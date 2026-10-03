---
kind: frontend_style
name: 'Dual-Style Frontend: Admin Panel Design System + Mobile-First Captive Portal CSS'
category: frontend_style
scope:
    - '**'
source_files:
    - admin/assets/admin.css
    - hotspot/css/style.css
    - hotspot/assets/css/core.css
    - hotspot/assets/css/bootstrap.min.css
    - hotspot/assets/css/toast.min.css
---

## Overview

The repository has two distinct frontend styling systems, each targeting a different user-facing surface:

1. **Admin panel** (`admin/assets/admin.css`) — a self-contained, framework-free design system with a dark "industrial network-console" aesthetic.
2. **Hotspot captive portal** (`hotspot/css/style.css`, `hotspot/assets/css/core.css`, plus Bootstrap/Toast/CSS in `hotspot/assets/css/`) — a mobile-first, teal-accented captive portal for MikroTik hotspot clients.

There is no build step, no SCSS/Sass, no Tailwind, and no component library beyond the vendored Bootstrap 4.x CSS/JS under `hotspot/assets/css/` and `hotspot/assets/js/`. All styles are plain `.css` files served directly by lighttpd.

## Admin Panel Design System (`admin/assets/admin.css`)

### Palette & tokens

A single `:root` block defines the entire visual vocabulary:

- Signal palette: `--teal`, `--teal-bright`, `--teal-deep`, `--teal-ink`
- Surfaces: `--rail-0` through `--rail-2`, `--page`, `--card`, `--card-alt`, `--line`, `--line-soft`
- Text: `--ink`, `--ink-soft`, `--ink-faint`, `--rail-text`
- Status: `--ok` / `--ok-soft`, `--bad` / `--bad-soft`, `--warn` / `--warn-soft`, `--info` / `--info-soft`
- Type stacks (local/system fonts only): `--font-display` (Bahnschrift/DIN/Oswald), `--font-body` (Segoe UI/Helvetica), `--font-mono` (Cascadia Code/JetBrains Mono/Consolas)
- Geometry: `--rail-w: 248px`, radii `--r-sm/md/lg`, three shadow levels

The file header explicitly states: *"Self-contained. No framework, no CDN, no external fonts."*

### Architecture & conventions

- **BEM-like naming**: classes use double-underscore sub-elements (e.g. `.rail__brand`, `.rail__link`, `.card__head`, `.modal__panel`, `.toast--success`).
- **Layout primitives**: `.shell` (CSS grid rail+main), `.rail` (sticky left nav), `.topbar`, `.content`, `.grid`, `.stack`, `.row`, `.form-grid`.
- **Component primitives**: `.btn` (with `--primary`, `--ghost`, `--danger`, `--sm`, `--block` variants), `.card`, `.flash`, `.badge`, `.tabs`, `.modal`, `.toasts`, `.note`, `.table-wrap table.data`, `.rcard` (router card with `.metric` children), `.meter`, `.pulse`, `.spin`, `.skeleton`, `.empty`.
- **Form primitives**: `.field`, `.input`, `.select`, `.check`, `.radio`, `.hint`, `.label`.
- **Responsive strategy**: single breakpoint at `900px` that collapses the rail into a slide-out drawer toggled via `body.nav-open`; secondary breakpoint at `560px` for small screens; `prefers-reduced-motion` disables all animations.
- **Accessibility**: `[hidden] { display: none !important }` override, `focus-visible` outlines on interactive elements, semantic `<button>` elements styled as links for logout.

### Where it's used

The admin PHP pages (`admin/index.php`, `admin/routers.php`, `admin/login.php`, etc.) include this stylesheet and compose the layout using the `.shell` / `.rail` / `.topbar` / `.content` structure.

## Hotspot Captive Portal Styles (`hotspot/css/style.css`)

### Palette & tokens

A separate `:root` token block declares:

- Primary: `#00BCD4` (teal) with `--primary-dark`, `--primary-glow`
- Danger: `#E91E63`
- Neutrals: `--btn-gray`, `--light-gray`, `--text-dark`, `--text-muted`, `--bg`, `--card`, `--border`
- Accent: `--amber-bg`, `--amber-text` (for blocked-voucher notices)
- Typography: `--font: Arial, Helvetica, sans-serif`

Header comment documents the palette and font stack.

### Architecture & conventions

- **Mobile-first container**: `.container` capped at `480px`, centered, with a `.banner` (gradient placeholder with scanlines) and an overlapping `.content` card.
- **Staggered entrance animation**: every direct child of `.content` gets a `rise` animation with incremental `animation-delay` from `0.05s` to `0.50s`.
- **Components**: `.status-text` (connected/disconnected color variants), `.timer`, `.btn` (`.btn-primary`, `.btn-gray`, `.btn-danger`), `.voucher-section` with `.voucher-input` + `.voucher-submit` row, `.input-wrap` (username/password with inline SVG icons), `.error-msg`, `.summary` table, `.modal-overlay` + `.modal-card` for WiFi rates, `.footer`, `.chat-bubble` (floating help button).
- **Animations**: `rise`, `slideUp`, `spin`, `chatPulse`, `shake`, `pulseBorder` — all defined locally.
- **Responsive breakpoints**: `360px` (small phones), `481px` (tablet), `720px` (desktop — centers the phone-like card with rounded corners and drop shadow).

### Supporting assets

- `hotspot/assets/css/bootstrap.min.css` — vendored Bootstrap 4.x CSS (present but largely superseded by the custom `style.css`).
- `hotspot/assets/css/toast.min.css` — vendored toast notification styles.
- `hotspot/assets/css/JuanFi.css` — additional theme overrides (not inspected here).
- `hotspot/assets/css/core.css` — minimal shared utilities (full-screen `.spinner`, `.insertCoinImg`).

## Shared Conventions Across Both Systems

- **No preprocessors**: everything is compiled-to-production CSS shipped as-is.
- **CSS custom properties** (`:root` variables) are the primary theming mechanism in both systems.
- **Teal `#00BCD4`** is the dominant accent color across both admin and portal surfaces.
- **System fonts only**: neither system loads webfonts; the admin uses local fallback stacks, the portal uses `Arial/Helvetica/sans-serif`.
- **No utility-first approach**: styles are written as explicit class rules rather than atomic utilities.
- **Animations are hand-written keyframes**, not driven by a library.
- **Responsive design is breakpoint-driven** with media queries, not fluid-only.

## Constraints & Enforced Rules

- The admin CSS header states the style system is self-contained with no framework, no CDN, and no external fonts — this is a stated design constraint in the source file itself.
- `[hidden] { display: none !important }` in the admin CSS ensures the HTML `hidden` attribute wins over any other display rule (explicitly documented in a comment).
- `prefers-reduced-motion: reduce` is honored in the admin CSS by forcing all animation/transition durations to `0.001ms`.
- The captive portal's `.container` is hard-capped at `480px`, enforcing a phone-like column even on desktop.
- The vendor-provided Bootstrap and Toast CSS under `hotspot/assets/css/` are included alongside the custom styles, so their global resets can interact with the custom CSS.