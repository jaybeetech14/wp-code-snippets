# Recurring + Multi-day Events (ACF)

A drop-in WordPress system that adds recurring and multi-day event support on top of ACF
date fields — expanding virtual occurrences, rendering them via shortcode, and keeping
Elementor loops clean.

## How it works

- **Occurrence expansion** — `evt_get_event_occurrences()` takes one event post and a
  date range and returns concrete occurrences: single-day → one, multi-day → one spanning
  start..end, recurring → one per repeat (daily/weekly/biweekly/monthly/yearly), each
  carrying the multi-day span. Perpetual ("never ends") series are capped by a filterable
  horizon (default 12 months) with a loop guard.
- **Upcoming events query** — `evt_get_upcoming_events()` flattens every published event
  into one chronologically sorted occurrence list with an optional limit.
- **Smart date formatting** — collapses ranges sharing a month/year
  ("September 12 – 14, 2026").
- **`[evt_upcoming_events]` shortcode** — renders a responsive card grid (limit/columns
  attributes) with self-contained CSS, so it looks right with zero theme work.
- **`[evt_event_date]` shortcode** — prints one event's formatted date inside an
  Elementor Loop item (works around ACF dynamic tags printing raw `20260911`).
- **Elementor loop filter** — `elementor/query/upcoming_events` hides past events and
  orders soonest-first by the Ymd meta value (plain numeric compare, no date parsing).

## ACF fields expected

`event_date` (Ymd), `event_is_multiday`, `event_end_date`, `event_is_recurring`,
`event_recur_frequency`, `event_recur_end_type`, `event_recur_end_date`. Legacy
"F j, Y" text dates are parsed too, so no migration is strictly required.

## Usage

Drop into `functions.php` or require it from there. For Elementor loops, set the Loop
widget's Query ID to `upcoming_events`.

**File:** `recurring-events-acf.php`
