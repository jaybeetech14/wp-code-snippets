<?php
/**
 * Recurring + Multi-day Event support 
 * ---------------------------------------------------------
 * Drop this in your theme (e.g. functions.php, or require it from there).
 *
 * IMPORTANT: This assumes the ACF date fields return the "Ymd" format
 * (e.g. 20260912), which the updated ACF export now uses. If your existing
 * events still have dates stored as "F j, Y" text, run the one-time
 * migration note at the bottom of this file, or re-save each event.
 *
 * ACF field names this code expects:
 *   event_date              (date_picker, Ymd)  - start date
 *   event_is_multiday       (true_false)
 *   event_end_date          (date_picker, Ymd)  - end date (multi-day)
 *   event_is_recurring      (true_false)
 *   event_recur_frequency   (select: daily|weekly|biweekly|monthly|yearly)
 *   event_recur_end_type    (select: on_date|never)
 *   event_recur_end_date    (date_picker, Ymd)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * How far into the future to project "perpetual" (never-ending) recurring
 * events. Without a cap, a perpetual weekly event would generate infinite
 * occurrences. One year ahead is usually plenty for an events listing.
 */
function evt_recurrence_horizon() {
	return apply_filters( 'evt_recurrence_horizon_months', 12 );
}

/**
 * Convert a Ymd string (or ACF date) into a DateTime, or null.
 */
function evt_parse_date( $value ) {
	if ( empty( $value ) ) return null;
	// Handles both "20260912" and legacy "September 12, 2026"
	$ts = strtotime( $value );
	if ( $ts === false ) return null;
	$dt = new DateTime();
	$dt->setTimestamp( $ts );
	$dt->setTime( 0, 0, 0 );
	return $dt;
}

/**
 * Given one event post ID, return an array of concrete occurrences that fall
 * between $range_start and $range_end. Each occurrence is:
 *   [ 'post_id' => int, 'start' => DateTime, 'end' => DateTime|null ]
 *
 * - Single-day event  -> at most one occurrence.
 * - Multi-day event   -> one occurrence spanning start..end.
 * - Recurring event   -> one occurrence per repeat, each carrying the same
 *                        multi-day span length if the base event is multi-day.
 */
function evt_get_event_occurrences( $post_id, DateTime $range_start, DateTime $range_end ) {
	$occurrences = array();

	$start = evt_parse_date( get_field( 'event_date', $post_id ) );
	if ( ! $start ) return $occurrences;

	$is_multiday = (bool) get_field( 'event_is_multiday', $post_id );
	$base_end    = null;
	$span_days   = 0;
	if ( $is_multiday ) {
		$base_end = evt_parse_date( get_field( 'event_end_date', $post_id ) );
		if ( $base_end && $base_end >= $start ) {
			$span_days = (int) $start->diff( $base_end )->days;
		}
	}

	$is_recurring = (bool) get_field( 'event_is_recurring', $post_id );

	// Helper to push one occurrence (respecting the display range).
	$push = function( DateTime $occ_start ) use ( &$occurrences, $post_id, $span_days, $range_start, $range_end ) {
		$occ_end = clone $occ_start;
		if ( $span_days > 0 ) {
			$occ_end->modify( "+{$span_days} days" );
		}
		// Include if any part of the occurrence intersects the range.
		if ( $occ_end >= $range_start && $occ_start <= $range_end ) {
			$occurrences[] = array(
				'post_id' => $post_id,
				'start'   => clone $occ_start,
				'end'     => $span_days > 0 ? $occ_end : null,
			);
		}
	};

	if ( ! $is_recurring ) {
		$push( $start );
		return $occurrences;
	}

	// --- Recurring expansion ---
	$frequency = get_field( 'event_recur_frequency', $post_id ) ?: 'weekly';
	$end_type  = get_field( 'event_recur_end_type', $post_id ) ?: 'on_date';

	// Determine the series end.
	if ( $end_type === 'never' ) {
		$series_end = clone $range_end; // capped by the display horizon
	} else {
		$series_end = evt_parse_date( get_field( 'event_recur_end_date', $post_id ) );
		if ( ! $series_end ) {
			$series_end = $start; // no end date given: treat as single occurrence
		}
	}

	$step = array(
		'daily'    => '+1 day',
		'weekly'   => '+1 week',
		'biweekly' => '+2 weeks',
		'monthly'  => '+1 month',
		'yearly'   => '+1 year',
	);
	$interval = isset( $step[ $frequency ] ) ? $step[ $frequency ] : '+1 week';

	$cursor = clone $start;
	$guard  = 0; // safety against runaway loops
	while ( $cursor <= $series_end && $cursor <= $range_end && $guard < 5000 ) {
		if ( $cursor >= $range_start ) {
			$push( $cursor );
		}
		$cursor = clone $cursor;
		$cursor->modify( $interval );
		$guard++;
	}

	return $occurrences;
}

/**
 * Build a flat, chronologically sorted list of upcoming event occurrences
 * across all published events. Use this to render the Upcoming Events page.
 *
 * @param int $limit  Max occurrences to return (0 = no limit).
 * @return array of occurrences (see evt_get_event_occurrences()).
 */
function evt_get_upcoming_events( $limit = 0 ) {
	$range_start = new DateTime();
	$range_start->setTime( 0, 0, 0 );

	$range_end = clone $range_start;
	$range_end->modify( '+' . evt_recurrence_horizon() . ' months' );

	$q = new WP_Query( array(
		'post_type'      => 'events',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'no_found_rows'  => true,
	) );

	$all = array();
	if ( $q->have_posts() ) {
		foreach ( $q->posts as $p ) {
			$all = array_merge(
				$all,
				evt_get_event_occurrences( $p->ID, $range_start, $range_end )
			);
		}
	}
	wp_reset_postdata();

	// Sort chronologically by start date.
	usort( $all, function( $a, $b ) {
		return $a['start'] <=> $b['start'];
	} );

	if ( $limit > 0 ) {
		$all = array_slice( $all, 0, $limit );
	}

	return $all;
}

/**
 * Format one occurrence's date for display.
 * Single day:  "September 12, 2026"
 * Multi-day:   "September 12 – 14, 2026"  (collapses year/month when shared)
 */
function evt_format_occurrence_dates( $occ ) {
	$start = $occ['start'];
	$end   = $occ['end'];

	if ( ! $end || $end == $start ) {
		return $start->format( 'F j, Y' );
	}

	$same_year  = $start->format( 'Y' ) === $end->format( 'Y' );
	$same_month = $same_year && $start->format( 'm' ) === $end->format( 'm' );

	if ( $same_month ) {
		return $start->format( 'F j' ) . ' – ' . $end->format( 'j, Y' );
	} elseif ( $same_year ) {
		return $start->format( 'F j' ) . ' – ' . $end->format( 'F j, Y' );
	}
	return $start->format( 'F j, Y' ) . ' – ' . $end->format( 'F j, Y' );
}

/**
 * ---------------------------------------------------------------------------
 * EXAMPLE: rendering the Upcoming Events list in a template.
 * ---------------------------------------------------------------------------
 *
 * $events = evt_get_upcoming_events();
 * foreach ( $events as $occ ) {
 *     $id    = $occ['post_id'];
 *     $title = get_the_title( $id );
 *     $link  = get_permalink( $id );
 *     $when  = evt_format_occurrence_dates( $occ );
 *     $desc  = get_field( 'event_description', $id );
 *     $thumb = get_the_post_thumbnail_url( $id, 'large' );
 *     ?>
 *     <article class="event-card">
 *         <a href="<?php echo esc_url( $link ); ?>">
 *             <?php if ( $thumb ) : ?>
 *                 <img src="<?php echo esc_url( $thumb ); ?>" alt="">
 *             <?php endif; ?>
 *             <h2><?php echo esc_html( $when ); ?></h2>
 *             <h3><?php echo esc_html( $title ); ?></h3>
 *         </a>
 *         <p><?php echo esc_html( $desc ); ?></p>
 *     </article>
 *     <?php
 * }
 *
 * ---------------------------------------------------------------------------
 * ELEMENTOR NOTE
 * ---------------------------------------------------------------------------
 * Your page is built with Elementor, which likely uses a Loop/Posts widget
 * that queries the "events" post type directly and sorts by post date — that
 * is why the current list is not in date order and cannot expand recurrences.
 *
 * Two ways to use this code with Elementor:
 *
 * 1) Shortcode approach (simplest): wrap the example render loop above in a
 *    shortcode and drop an Elementor Shortcode widget on the page. Replace the
 *    current Loop widget with it. Add this to the file:
 *
 *      add_shortcode( 'evt_upcoming_events', function() {
 *          ob_start();
 *          $events = evt_get_upcoming_events();
 *          // ... render loop here ...
 *          return ob_get_clean();
 *      } );
 *
 * 2) Query-filter approach: keep the Elementor Loop widget but give it a custom
 *    query ID, then hook 'elementor/query/{your_query_id}' to reorder by the
 *    event_date meta. This sorts correctly but will NOT expand recurring
 *    occurrences into multiple cards — only the shortcode approach does that,
 *    because recurrences are virtual (they don't exist as separate posts).
 *
 * ---------------------------------------------------------------------------
 * ONE-TIME DATA NOTE
 * ---------------------------------------------------------------------------
 * The updated ACF fields store dates as Ymd. Existing events created before
 * the update stored "F j, Y" text. evt_parse_date() handles both via strtotime,
 * so you don't strictly need to migrate — but re-saving old events (or bulk
 * re-saving) will normalize them to Ymd and is recommended.
 */

/**
 * ---------------------------------------------------------------------------
 * SHORTCODE: [evt_upcoming_events]
 * ---------------------------------------------------------------------------
 * Drop an Elementor "Shortcode" widget where the current event loop is and
 * enter:  [evt_upcoming_events]
 *
 * Optional attributes:
 *   limit    Max number of occurrences to show.   Default 0 (all).
 *            e.g. [evt_upcoming_events limit="12"]
 *   columns  Grid columns on desktop.             Default 3.
 *            e.g. [evt_upcoming_events columns="2"]
 *
 * The markup uses its own CSS classes (evt-*) and ships with a small inline
 * stylesheet below so it looks consistent out of the box. If you'd rather
 * style it entirely in Elementor / your theme, delete the <style> block in
 * evt_upcoming_events_styles() and target the evt-* classes yourself.
 */
function evt_render_upcoming_events_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'limit'   => 0,
			'columns' => 3,
		),
		$atts,
		'evt_upcoming_events'
	);

	$limit   = (int) $atts['limit'];
	$columns = max( 1, (int) $atts['columns'] );
	$events  = evt_get_upcoming_events( $limit );

	ob_start();

	evt_upcoming_events_styles();

	if ( empty( $events ) ) {
		echo '<p class="evt-events-empty">No upcoming events at this time. Please check back soon.</p>';
		return ob_get_clean();
	}

	echo '<div class="evt-events-grid" style="--evt-columns:' . esc_attr( $columns ) . ';">';

	foreach ( $events as $occ ) {
		$id    = $occ['post_id'];
		$title = get_the_title( $id );
		$link  = get_permalink( $id );
		$when  = evt_format_occurrence_dates( $occ );
		$desc  = get_field( 'event_description', $id );
		$thumb = get_the_post_thumbnail_url( $id, 'large' );
		?>
		<article class="evt-event-card">
			<div class="evt-event-datebar"><?php echo esc_html( $when ); ?></div>
			<?php if ( $thumb ) : ?>
				<a class="evt-event-media" href="<?php echo esc_url( $link ); ?>">
					<img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy">
				</a>
			<?php endif; ?>
			<div class="evt-event-body">
				<h3 class="evt-event-title">
					<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $title ); ?></a>
				</h3>
				<?php if ( $desc ) : ?>
					<p class="evt-event-desc"><?php echo esc_html( $desc ); ?></p>
				<?php endif; ?>
			</div>
		</article>
		<?php
	}

	echo '</div>';

	return ob_get_clean();
}
add_shortcode( 'evt_upcoming_events', 'evt_render_upcoming_events_shortcode' );

/**
 * Inline styles for the shortcode output. Printed once per page.
 * Delete this function (and the call to it above) if you prefer to style
 * the evt-* classes yourself in Elementor or your theme's CSS.
 */
function evt_upcoming_events_styles() {
	static $done = false;
	if ( $done ) return;
	$done = true;
	?>
	<style>
		/* Palette sampled from the original event cards */
		.evt-events-grid {
			--evt-teal:  #4d869f;
			--evt-cream: #faf2e7;
			--evt-title: #43484b;
			--evt-desc:  #6d6f6f;
			display: grid;
			grid-template-columns: repeat(var(--evt-columns, 3), 1fr);
			gap: 2rem;
		}
		@media (max-width: 1024px) {
			.evt-events-grid { grid-template-columns: repeat(2, 1fr); }
		}
		@media (max-width: 640px) {
			.evt-events-grid { grid-template-columns: 1fr; }
		}
		.evt-event-card {
			display: flex;
			flex-direction: column;
			overflow: hidden;
			background: var(--evt-cream);
			transition: transform .25s ease;
			will-change: transform;
		}
		.evt-event-card:hover {
			transform: translateY(-8px);
		}
		/* Teal date bar across the top */
		.evt-event-datebar {
			background: var(--evt-teal);
			color: #fff;
			text-align: center;
			font-family: 'Calistoga', sans-serif;
			font-weight: 400;
			font-size: 1.25rem;
			letter-spacing: .01em;
			padding: .65em 1em;
		}
		.evt-event-media { display: block; overflow: hidden; padding: 1rem 1rem 0; }
		.evt-event-media img { width: 100%; height: 212px; display: block; object-fit: cover; }
		.evt-event-body {
			padding: 1.25rem 1.5rem 2rem;
			display: flex;
			flex-direction: column;
			gap: .6rem;
			text-align: center;
		}
		.evt-event-title {
			margin: .25rem 0 0;
			font-family: 'Calistoga', sans-serif;
			font-weight: 700;
			font-size: 1.9rem;
			line-height: 1.15;
			color: var(--evt-title);
		}
		.evt-event-title a { text-decoration: none; color: inherit; }
		.evt-event-desc {
			margin: .35rem 0 0;
			font-size: 1.05rem;
			line-height: 1.55;
			color: var(--evt-desc);
		}
		.evt-events-empty { text-align: center; padding: 3rem 1rem; color: var(--evt-desc); }
	</style>
	<?php
}


/**
 * ---------------------------------------------------------------------------
 * SHORTCODE: [evt_event_date]
 * ---------------------------------------------------------------------------
 * Outputs a single event's start date formatted as "Month Day, Year"
 * (e.g. September 11, 2026), reading the raw Ymd value stored by ACF.
 *
 * PURPOSE: use this inside an Elementor Loop Grid / Loop Carousel item where
 * the ACF Date dynamic tag has no Format option and prints the raw 20260911.
 * Because it runs inside the loop, it automatically uses whichever event post
 * is currently being rendered - no post ID needed.
 *
 * USAGE (inside a loop item):
 *   Add a Text or Shortcode widget to the loop card and enter:
 *     [evt_event_date]
 *   Then delete the old date element that used the ACF dynamic tag.
 *
 * Optional attributes:
 *   format   Any PHP date format.        Default "F j, Y".
 *            e.g. [evt_event_date format="M j, Y"]  -> Sep 11, 2026
 *   field    ACF field name to read.     Default "event_date".
 *   id       Specific post ID to read.   Default: current loop post.
 *   color    Hex color for the date text. Default #faf2e7 (cream, for
 *            the teal date bar). On light backgrounds pass a dark color,
 *            e.g. [evt_event_date color="#54595f"].
 *
 * If the event is multi-day, this shows only the START date (single value),
 * which is what a Loop card typically wants. For a collapsed range like
 * "September 11 - 13, 2026", use the [evt_upcoming_events] shortcode instead.
 */
function evt_event_date_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'format' => 'F j, Y',
			'field'  => 'event_date',
			'id'     => 0,
			'color'  => '#faf2e7',
		),
		$atts,
		'evt_event_date'
	);

	$post_id = (int) $atts['id'];
	if ( ! $post_id ) {
		$post_id = get_the_ID(); // current loop item
	}
	if ( ! $post_id ) {
		return '';
	}

	$raw = get_field( $atts['field'], $post_id );
	if ( empty( $raw ) ) {
		return '';
	}

	$ts = strtotime( $raw ); // handles both Ymd and legacy "F j, Y"
	if ( $ts === false ) {
		return esc_html( $raw ); // fall back to whatever is stored
	}

	$formatted = esc_html( date_i18n( $atts['format'], $ts ) );
	$color     = sanitize_hex_color( $atts['color'] );
	if ( ! $color ) { $color = '#faf2e7'; }

	// Inline color so it renders regardless of theme/Elementor CSS.
	return '<span class="evt-event-date" style="color:' . esc_attr( $color ) . ';">' . $formatted . '</span>';
}
add_shortcode( 'evt_event_date', 'evt_event_date_shortcode' );

/**
 * ---------------------------------------------------------------------------
 * HIDE PAST EVENTS IN AN ELEMENTOR LOOP
 * ---------------------------------------------------------------------------
 * Excludes any event whose event_date is before today from an Elementor
 * Loop Grid / Loop Carousel, and orders the remaining events soonest-first.
 *
 * SETUP (one step in Elementor):
 *   Open the Loop Grid / Loop Carousel widget -> Query section -> set the
 *   "Query ID" field to:  upcoming_events
 *   That's the hook that connects this code to that specific loop. Any loop
 *   given this Query ID will hide past events; loops without it are untouched.
 *
 * Because event_date is stored as Ymd (e.g. 20260911), a plain string/numeric
 * compare against today's Ymd works correctly for "date has passed".
 *
 * NOTE ON RECURRING EVENTS: an Elementor Loop shows real posts once, using the
 * stored start date. A recurring event whose START date has passed will drop
 * off this loop even if it still recurs in the future, because the loop has no
 * per-occurrence data. To keep recurring/multi-day events visible by their next
 * occurrence, display them via the [evt_upcoming_events] shortcode instead,
 * which expands occurrences. This filter is for the plain Elementor loop.
 */
function evt_hide_past_events_query( $query ) {
	$today = date( 'Ymd' ); // e.g. 20260820

	$meta_query = (array) $query->get( 'meta_query' );

	$meta_query[] = array(
		'key'     => 'event_date',
		'value'   => $today,
		'compare' => '>=',   // keep today and future; drop past
		'type'    => 'NUMERIC',
	);

	$query->set( 'meta_query', $meta_query );

	// Order soonest-first by the event date.
	$query->set( 'meta_key', 'event_date' );
	$query->set( 'orderby', 'meta_value_num' );
	$query->set( 'order', 'ASC' );
}
add_action( 'elementor/query/upcoming_events', 'evt_hide_past_events_query' );
