# Trash Past Workshops (WP-Cron Cleanup)

Hourly WP-Cron job that moves expired `workshops` custom post type entries to Trash
once their event date has passed — 5:00pm site time on the event day.

## How it works

- **Self-healing schedule** — on `init`, it checks for the `vep_trash_past_workshops`
  hook: if an old daily schedule exists it is cleared and replaced with hourly; if no
  schedule exists, one is created for 1 minute past the next hour in the site's timezone
  (`wp_timezone()`).
- **Timezone-correct cutoff** — the event timestamp is converted to the event's calendar
  date, then compared against 5:00pm that day in the site timezone. This avoids the
  classic bug of trashing an event at midnight UTC while it's still afternoon locally.
- **Safe by design** — uses `wp_trash_post()`, so expired workshops go to Trash instead
  of being permanently deleted; posts with a missing or invalid `event-date` meta value
  are skipped untouched.
- **Lightweight query** — fetches only published IDs (`fields => 'ids'`, no meta
  preloading) and reads one meta key per post.

## Usage

Drop into a functionality plugin or theme `functions.php`. Adjust the post type
(`workshops`), meta key (`event-date`), and cutoff time (`17:00:00`) to fit.

**File:** `trash-past-workshops-cron.php`
