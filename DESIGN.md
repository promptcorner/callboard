# Design

## Product Character

Callboard is a dedicated music player for WordPress. The listener experience is purpose-built. Administration uses WordPress conventions without a separate branded shell.

## Visual System

### Listener surfaces

The existing cast-facing player is the authority. It uses warm neutral light and dark schemes, restrained texture, a single configurable orange accent, compact system typography, square cover art, ruled lists, and a persistent hardware-like playback deck.

### WordPress administration

Admin surfaces use core WordPress headings, page actions, buttons, notices, form tables, list tables, descriptions, spacing, and responsive behavior. Setup may use one compact dark listening header with a simple waveform and Callboard orange. It contains the real page title and primary action, never a kicker or marketing slogan. Do not add status cards, promotional sidebars, decorative copy, or a second component system.

## Layout

Admin pages use the standard `.wrap` container and a readable working width. Setup is a short vertical flow: compact listening header, then one core list table showing the library and listener-facing settings. Settings use the Settings API's headings and form tables without extra panel chrome.

The Set editor stays inside the classic WordPress post editor. Its Tracks panel is a compact working list: numbered rows, editable titles, duration, keyboard reorder controls, and quiet file actions. Audio enters through the core Media Library and removed tracks remain there.

## Interaction

Use standard WordPress buttons, links, form fields, details elements, and focus behavior. Motion is reserved for listener playback state; administration should feel immediate and stable. New-tab actions include an accessible warning. Empty states name the next useful action.

## Accessibility

Maintain WCAG AA text contrast, visible WordPress focus styles, semantic headings, native controls, useful link text, and responsive reading order. Decorative waveform artwork is hidden from assistive technology.
