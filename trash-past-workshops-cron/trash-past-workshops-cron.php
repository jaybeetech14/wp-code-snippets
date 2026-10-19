<?php
/**
 * Trash Past Workshops — hourly cleanup
 *
 * Moves "workshops" custom post type entries to Trash once their event date
 * has passed (5:00pm site time on the event day). Uses wp_trash_post so
 * nothing is permanently deleted.
 */

// Schedule the cleanup to run hourly (replaces the old daily schedule)
add_action( 'init', function() {
    $hook = 'vep_trash_past_workshops';

    // If the old daily schedule exists, clear it so the hourly one takes over
    if ( wp_next_scheduled( $hook ) && 'hourly' !== wp_get_schedule( $hook ) ) {
        wp_clear_scheduled_hook( $hook );
    }

    if ( ! wp_next_scheduled( $hook ) ) {
        // First run: 1 minute past the next hour, in the site's timezone
        $next = new DateTime( 'now', wp_timezone() );
        $next->modify( '+1 hour' );
        $next->setTime( (int) $next->format( 'G' ), 1 );

        wp_schedule_event( $next->getTimestamp(), 'hourly', $hook );
    }
} );

// The cleanup routine
add_action( 'vep_trash_past_workshops', function() {

    $tz  = wp_timezone();
    $now = time();

    $workshops = get_posts( array(
        'post_type'      => 'workshops',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ) );

    foreach ( $workshops as $id ) {
        $event_date = get_post_meta( $id, 'event-date', true );

        if ( '' === $event_date || ! is_numeric( $event_date ) ) {
            continue; // no valid date set, leave it alone
        }

        // Get the event's calendar date, then 5:00pm on that date in the site's timezone
        $day    = gmdate( 'Y-m-d', (int) $event_date );
        $cutoff = new DateTime( $day . ' 17:00:00', $tz );

        if ( $now >= $cutoff->getTimestamp() ) {
            wp_trash_post( $id ); // moves to Trash, not permanent delete
        }
    }
} );
