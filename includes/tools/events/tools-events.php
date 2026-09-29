<?php
defined( 'ABSPATH' ) || exit;

if ( ! Cowboy_MCP_Tools::domain_available( __FILE__ ) ) {
	return [ 'tools' => [], 'handlers' => [] ];
}

require_once __DIR__ . '/helpers.php';

// Shared schema/annotation arrays live here, above every tool definition.
$cowboy_mcp_events_ro = [ 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ];

$cowboy_mcp_events_tools = [
	Cowboy_MCP_Tools::tool( 'wp_events_list', '[Events] List The Events Calendar events overlapping a date window (default: from now on). A recurring event appears once with is_recurring, series_id and next_occurrence — use wp_events_list_occurrences for its dates. Dates are event-local unless they carry an offset.', [
		'from'         => [ 'type' => 'string', 'description' => 'Window start (default now). Events ending on/after this are included.' ],
		'to'           => [ 'type' => 'string', 'description' => 'Window end. Events starting on/before this are included.' ],
		'status'       => [ 'type' => 'string', 'description' => 'Post status: publish (default), draft, pending, private, future, any' ],
		'category'     => [ 'type' => 'string', 'description' => 'Event category slug or ID' ],
		'venue_id'     => [ 'type' => 'integer', 'description' => 'Only events at this venue' ],
		'organizer_id' => [ 'type' => 'integer', 'description' => 'Only events with this organizer' ],
		'search'       => [ 'type' => 'string', 'description' => 'Search title/content' ],
		'featured'     => [ 'type' => 'boolean', 'description' => 'Only featured events' ],
		'page'         => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
		'per_page'     => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
	], [ 'title' => 'List Events' ] + $cowboy_mcp_events_ro, [
		'type' => 'object', 'properties' => [ 'events' => [ 'type' => 'array', 'items' => [ 'type' => 'object' ] ], 'total' => [ 'type' => 'integer' ], 'page' => [ 'type' => 'integer' ] ],
	] ),
	Cowboy_MCP_Tools::tool( 'wp_events_get', '[Events] Get one event: dates, timezone, venue, organizers, cost, categories, tags, image; for recurring events (Events Calendar Pro) the recurrence rule (RFC 5545), exclusions and occurrence count. Accepts an occurrence ID and reports the real event_id.', [
		'event_id' => [ 'type' => 'integer', 'description' => 'Event ID (or an occurrence ID)', 'required' => true ],
	], [ 'title' => 'Get Event' ] + $cowboy_mcp_events_ro, [ 'type' => 'object' ] ),
];

$cowboy_mcp_events_handlers = [
	'wp_events_list' => function ( array $a ) {
		global $wpdb;
		$per_page = min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 100 );
		$page     = max( (int) ( $a['page'] ?? 1 ), 1 );
		$tz       = wp_timezone_string();
		$from     = cowboy_mcp_events_parse_date( (string) ( $a['from'] ?? wp_date( 'Y-m-d H:i:s' ) ), $tz );
		$to       = isset( $a['to'] ) ? cowboy_mcp_events_parse_date( (string) $a['to'], $tz ) : null;
		if ( is_wp_error( $from ) ) {
			return $from;
		}
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		$utc  = new DateTimeZone( 'UTC' );
		$args = [
			'post_type'      => 'tribe_events',
			'post_status'    => sanitize_key( $a['status'] ?? 'publish' ),
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'meta_query'     => [], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'tax_query'      => [], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			// TEC's own opt-outs. Without them the custom-tables query monitor rewrites a
			// tribe_events query to one row per OCCURRENCE (Pro: a 3-date series = 3 rows with
			// provisional ids, found_posts 3), and the legacy query layer adds its own date
			// clauses. The window and ordering are computed here: one row per real event.
			'tec_events_ignore'            => true,
			'tribe_suppress_query_filters' => true,
		];
		$occ_table = cowboy_mcp_events_table( 'tec_occurrences' );
		if ( $occ_table ) {
			// One row per real event, ordered by its first date inside the window.
			$sql = $wpdb->prepare( "SELECT post_id FROM {$occ_table} WHERE end_date_utc >= %s", $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $to ) {
				$sql .= $wpdb->prepare( ' AND start_date_utc <= %s', $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared
			$ids = array_map( 'intval', $wpdb->get_col( $sql . ' GROUP BY post_id ORDER BY MIN(start_date_utc) ASC' ) );
			$args['post__in'] = $ids ?: [ 0 ];
			$args['orderby']  = 'post__in';
		} else {
			$args['meta_query'][] = [ 'key' => '_EventEndDateUTC', 'value' => $from->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'compare' => '>=', 'type' => 'DATETIME' ];
			if ( $to ) {
				$args['meta_query'][] = [ 'key' => '_EventStartDateUTC', 'value' => $to->setTimezone( $utc )->format( 'Y-m-d H:i:s' ), 'compare' => '<=', 'type' => 'DATETIME' ];
			}
			$args['meta_key'] = '_EventStartDateUTC'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['orderby']  = 'meta_value';
			$args['order']    = 'ASC';
		}
		if ( ! empty( $a['venue_id'] ) ) {
			$args['meta_query'][] = [ 'key' => '_EventVenueID', 'value' => (int) $a['venue_id'] ];
		}
		if ( ! empty( $a['organizer_id'] ) ) {
			$args['meta_query'][] = [ 'key' => '_EventOrganizerID', 'value' => (int) $a['organizer_id'] ];
		}
		if ( ! empty( $a['featured'] ) ) {
			$args['meta_query'][] = [ 'key' => '_tribe_featured', 'value' => '1' ];
		}
		if ( ! empty( $a['category'] ) ) {
			$cat = (string) $a['category'];
			$args['tax_query'][] = [ 'taxonomy' => 'tribe_events_cat', 'field' => ctype_digit( $cat ) ? 'term_id' : 'slug', 'terms' => ctype_digit( $cat ) ? (int) $cat : sanitize_title( $cat ) ];
		}
		if ( ! empty( $a['search'] ) ) {
			$args['s'] = sanitize_text_field( $a['search'] );
		}
		$q = new WP_Query( $args );
		return [
			'events' => array_map( static fn( $p ) => cowboy_mcp_events_format( $p, false ), $q->posts ),
			'total'  => (int) $q->found_posts,
			'page'   => $page,
		];
	},

	'wp_events_get' => function ( array $a ) {
		$post = cowboy_mcp_events_get( (int) $a['event_id'], false );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$out = cowboy_mcp_events_format( $post, true );
		$norm = cowboy_mcp_events_real_id( (int) $a['event_id'] );
		if ( $norm['was_occurrence'] ) {
			$out['requested_occurrence_id'] = (int) $a['event_id'];
		}
		return $out;
	},
];

return [ 'tools' => $cowboy_mcp_events_tools, 'handlers' => $cowboy_mcp_events_handlers ];
