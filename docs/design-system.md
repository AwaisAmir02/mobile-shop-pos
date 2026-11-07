# HisabOKitab design system

The one visual and structural system for the app, built up across the design
overhaul stages D0 to D10. It's for anyone changing a screen. If a screen needs
something this doesn't cover, add it here and to the shared kit rather than styling
one page by hand.

**Status:** agreed at D0, built from D1 onward. Sections describe the target, and
nothing here exists in the code until its stage ships.

**The one hard rule:** this is a visual and navigation pass. Actions, Models, FIFO,
dues and ledger logic don't change. Volt components keep their public properties and
method names, because tests drive them directly. The only approved flow change is
the variants wizard (see [Product creation](#product-creation-with-variants)).

## Colour tokens

Screens use **named roles, never palette colours**. Each role has a light and a
dark value, so dark mode can later be switched on without revisiting screens.

Dark mode itself isn't being built yet: no toggle, no OS detection, and the dark
values below are proposals that get tuned when dark mode is built.

### How it's wired (D1)

1. `resources/css/app.css` declares each token as an RGB triplet on `:root`, with
   dark values under `[data-theme="dark"]`. Nothing sets that attribute yet, so the
   dark block is inactive.
2. `tailwind.config.js` exposes them as **new** colour names, for example
   `surface: 'rgb(var(--surface) / <alpha-value>)'`, so opacity modifiers keep working.
3. `darkMode: ['selector', '[data-theme="dark"]']`, so Tailwind's default doesn't
   follow the OS setting by accident.
4. `:root { color-scheme: light; }`, switched to `dark` in the dark block, so native
   date pickers, scrollbars and selects follow the theme.

**`brand`, `accent` and `slate` keep their current values.** The marketing site
uses them directly, so editing them would restyle the site.

### Tokens

Every text pair below was calculated against WCAG: 4.5:1 for text, 3:1 for large
text and control edges.

| Token | Use | Light | Dark | Contrast L / D |
|---|---|---|---|---|
| `page` | App background | `#f8fafc` | `#0b1220` | |
| `surface` | Cards, tables, sidebar | `#ffffff` | `#111a2b` | |
| `surface-raised` | Menus, modals, drawers | `#ffffff` | `#172236` | |
| `surface-sunken` | Table headers, wells, hover | `#f1f5f9` | `#0e1726` | |
| `line` | Dividers, card borders | `#e2e8f0` | `#26334a` | decorative |
| `line-strong` | Input and control edges | `#8a94a6` | `#64768f` | 3.06 / 3.75 |
| `ink` | Main text and numbers | `#0f172a` | `#e8edf5` | 17.85 / 14.81 |
| `ink-muted` | Secondary text, nav items | `#475569` | `#a7b4c7` | 7.58 / 8.29 |
| `ink-faint` | Hints, captions, placeholders | `#64748b` | `#8c9bb1` | 4.76 / 6.17 |
| `primary` | Everyday primary button | `#0033a9` | `#8ca3d8` | |
| `primary-hover` | Its hover | `#012d8e` | `#b3c2e5` | |
| `on-primary` | Text on primary | `#ffffff` | `#021b3c` | 10.26 / 6.81 |
| `link` | Links, focus rings | `#0033a9` | `#b3c2e5` | 10.26 / 9.75 |
| `nav-active` | Active sidebar item fill | `#f0f3fa` | `#1b2c52` | |
| `on-nav-active` | Active sidebar item text | `#012d8e` | `#d9e0f2` | 10.70 / 10.40 |
| `sale` | New Sale and Complete Sale only | `#d65002` | `#d65002` | 4.19 / 4.19, large text only |
| `sale-hover` | Their hover | `#ab3e02` | `#ab3e02` | 6.14 / 6.14 |
| `on-sale` | Text on sale | `#ffffff` | `#ffffff` | |
| `success`, `success-soft` | Paid, settled | `#047857` on `#ecfdf5` | `#6ee7b7` on `#10291f` | 5.21 / 10.14 |
| `warning`, `warning-soft` | Partial, expiring | `#92400e` on `#fffbeb` | `#fcd34d` on `#2b2110` | 6.84 / 10.97 |
| `danger`, `danger-soft` | Unpaid, destructive | `#b91c1c` on `#fef2f2` | `#fca5a5` on `#2f1616` | 5.91 / 8.87 |
| `info`, `info-soft` | Neutral notices | `#012d8e` on `#f0f3fa` | `#b3c2e5` on `#16264a` | 10.70 / 8.34 |
| `danger-solid` | Destructive button | `#dc2626`, white text | `#fca5a5`, `#2f1616` text | 4.83 / 8.87 |

**Why the sale button is `accent-600`, not the site's `accent-500`:** white on
`#fe6402` is 2.98:1, just short of the 3:1 large-text minimum. `#d65002` passes at
4.19:1 and is what `x-ui.button variant="accent"` and the sidebar's active item
already use. The marketing site keeps `accent-500`.

### Keeping it honest

Today the app's views hold about 2,260 raw colour classes across 151 files: 1,483
neutrals (`text-slate-500`, `bg-white`), 417 status colours and 363 brand or accent.
The marketing site holds about 408 and **has no tokens at all**, so there was nothing
to inherit from it.

- D1 converts the shared kit and shell to tokens and adds a ratchet test recording
  the current raw-colour count. The count may only go down.
- Each later stage converts every screen it touches.
- `resources/views/site/*` and `components/site/*` are outside the count. Whether the
  site gets a dark mode is a separate decision for when dark mode is built.
- Must stay light whatever the theme: PDFs, printed invoices, emails. The logo sits
  on a white tile so it works on both.

Things that would otherwise break dark mode later, handled in D1: the base focus
ring's `ring-offset-white`, `@tailwindcss/forms` default field colours, inline chart
colours, and `shadow-*` values that need a dark equivalent.

## Type, shape and elevation

Taken from the marketing site. Font is Figtree. The app layouts must load weight 800,
which they don't today (they stop at 700).

| Element | Style |
|---|---|
| Page title | 24px, `font-extrabold tracking-tight`, replacing the 18px rule in `.app-header h1` |
| Section label | `text-xs uppercase tracking-[0.18em]`, in `ink-faint`, not orange |
| Money and counts | `tabular-nums`, never wrapping. Records stay `Rs 1,234.00` |
| Cards | `rounded-2xl`, `line` border, flat |
| Floating (menus, modals) | `surface-raised`, soft large shadow, hairline ring |
| Icon tile | `h-11 w-11 rounded-xl` with a soft fill |
| Badges | Soft fill with matching text |

**Not carried over from the site:** float, drift, marquee, gradient headings, scroll
reveals, hover-lift on dense rows. They'd grate at 200 uses a day. App motion is
150-200ms fades and small transforms, honouring reduced motion.

## Buttons

- **Page-level actions:** pills (`rounded-full`), semibold.
- **Inside tables and dense toolbars:** compact, slightly rounded controls.
- **Primary:** navy. One per screen.
- **Orange:** New Sale and Complete Sale only, large and bold. Never small text,
  never anything else.
- **Destructive:** separated from the rest, and confirmed.
- Link-styled buttons use `x-ui.button href`, never a button wrapped in `<a>`.

## Screen types

| Type | What it is | Examples |
|---|---|---|
| List | Sidebar target. Holds the New button | Products, Stock In, Bills |
| Create/Edit | Modal up to about 5 fields, drawer for edit forms, full page for documents with line items | Stock In, PO, Invoice |
| Detail | One record's own page | Sale, PO, Product |
| Register | Sales only: opens ready to sell, with a History tab | Sales |
| Quick-add row | One-line records, form above the list | Expenses |

**Navigation:** tabs move between pages (`x-ui.module-tabs`, one config). Segmented
controls switch a view within a page (`x-ui.segmented`). Side rail is for Settings only.
Arrow text links at the top of a page are not a navigation pattern.

**List-first without breaking URLs:** the 8 sidebar links point at existing history
routes. Every URL stays as it is, so `stock-ins.index` remains the form. That name is a
known wart, and flipping it isn't worth breaking bookmarks and tests.

**Every tab, card and link repeats the existing visibility rule exactly**: permission
plus shop flag. `SidebarVisibilityGuardTest` pins the sidebar's set of links. Ledger
routes guard in `mount`, so hiding a tab isn't the protection; the page guard stays.

## Components

**Restyled in place:** button, card, table, stat, badge, input, select, image-select,
modal, alert, empty-state, tooltip, toast, pagination.

**New**, presentation only with no Action or Model code:

| Component | Job |
|---|---|
| `x-ui.page-header` | Title, subtitle, back or breadcrumb, one primary action, overflow menu |
| `x-ui.module-tabs` | Section tabs from one config |
| `x-ui.segmented` | In-page view switch |
| `x-ui.filter-bar` | Search, key filters, More filters, active chips, export |
| `x-ui.date-range` | Presets over the same existing from and to properties |
| `x-ui.stat-row` | Fixed row of `x-ui.stat` |
| `x-ui.row-actions` | One visible action plus a ⋯ menu, destructive items apart |
| `x-ui.confirm` | Styled replacement for `wire:confirm` |
| `x-ui.drawer` | Side panel for create and edit |
| `x-ui.form-section`, `x-ui.sticky-summary`, `x-ui.icon`, `x-ui.skeleton` | Form and page parts |

**Layering:** sticky header 30, sidebar and tooltips 40, sidebar on phone and modals
50, menus 60, toasts 70. Toasts move up from today's `z-[60]` so a menu can sit above
a modal without covering them.

**Rules:** mark required fields, and don't write "Optional" under fields. Tables become
stacked cards under `md`. PC and laptop are the primary target at 1366 wide, with phones
fully supported but not the design lead.

### Row menus (proven in D0)

⋯ menus sit inside scrolling tables, which would clip them, so they must teleport to
`body`. D0 tested this on a real Livewire page in Chrome, 28 of 28 checks passing:

```blade
<tr wire:key="row-{{ $row->id }}">
    <td>
        <div x-data="{ open: false }" x-on:keydown.escape.window="open = false">
            <button type="button" x-ref="trigger" x-on:click="open = ! open">⋯</button>
            <template x-teleport="body">
                <div
                    x-show="open"
                    x-cloak
                    x-anchor.bottom-end="$refs.trigger"
                    x-trap="open"
                    x-on:click.outside="open = false"
                >
                    <button type="button" wire:click="act({{ $row->id }})" x-on:click="open = false">Act</button>
                </div>
            </template>
        </div>
    </td>
</tr>
```

**Works:**
- `wire:click` reaches the server with the right record, and from a new page of rows.
- Menu content updates on re-render.
- Removing a row or changing page leaves no orphan menus.
- `wire:navigate` inside a menu works and leaves nothing behind.
- Anchoring follows the trigger, flips up near the screen bottom and stays on screen at 390 wide.
- Nothing extra to install: `x-teleport`, `x-anchor` and `x-trap` ship with Livewire 3.8.5.

**Requirements for `x-ui.row-actions`:**
- **`wire:key` on every row is mandatory.**
- **`x-trap` is required.** Without it, Tab skips the menu entirely, because the menu
  lives at the end of `body`. With it, focus enters on open and Escape returns it to
  the button.
- One root element inside the `<template>`.
- **Cancelling `wire:confirm` leaves the menu open**, as confirm stops the click
  handler that closes it. The row is safe, it just looks untidy. Use `x-ui.confirm`
  in menus, which closes the menu first.

## Sales register

- Stays register-first with a Register | History tab.
- Keeps existing properties and methods. `CreateSale` and FIFO don't change.
- **No live profit figure:** a line can span batches, so it could disagree with the
  final FIFO report.
- **No auto-print:** the cashier chooses to print. Site wording that implies otherwise
  gets corrected.
- Quick-pick tiles of frequent products on the empty screen, from a new read-only query.
- **No single-letter shortcuts.** USB scanners type into the page as a keyboard and
  would trigger them.

## Product creation with variants

One flow, **not** create-then-attach-later: Add Product opens a wizard with a Details
step, then a Variants step, saved together at the end. The product is never renamed as
part of this. It replaces the overloaded modal and the need to choose between two
buttons up front. Only the Products Volt component's calls change, with nothing in
Actions or Models. Built in D5.

## Checks for every stage

1. Full "before" baseline, finished before the first edit.
2. Dues, Sales/FIFO and full suite stay green. A test may change only if it pinned
   wording or placement, never a number or a rule, and each edit goes in the stage report.
3. `npm run build`, because `public/build` isn't in git.
4. Before and after screenshots at 1366 and 390, including the marketing site whenever
   tokens or shared components change. See `tools/ui-shots/README.md`.
5. Its own commit, once the full baseline passes.
