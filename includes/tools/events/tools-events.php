<?php
defined( 'ABSPATH' ) || exit;

if ( ! Cowboy_MCP_Tools::domain_available( __FILE__ ) ) {
	return [ 'tools' => [], 'handlers' => [] ];
}

require_once __DIR__ . '/helpers.php';

// Shared schema/annotation arrays live here, above every tool definition.
$cowboy_mcp_events_ro = [ 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ];
$cowboy_mcp_events_write = [ 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ];
$cowboy_mcp_events_venue_props = [
	'address' => [ 'type' => 'string' ], 'city' => [ 'type' => 'string' ], 'state' => [ 'type' => 'string', 'description' => 'US state' ],
	'province' => [ 'type' => 'string', 'description' => 'Province/region outside the US' ], 'zip' => [ 'type' => 'string' ],
	'country' => [ 'type' => 'string' ], 'phone' => [ 'type' => 'string' ], 'website' => [ 'type' => 'string' ],
	'show_map' => [ 'type' => 'boolean' ], 'status' => [ 'type' => 'string', 'description' => 'publish (default) or draft' ],
];
$cowboy_mcp_events_org_props = [
	'phone' => [ 'type' => 'string' ], 'website' => [ 'type' => 'string' ], 'email' => [ 'type' => 'string' ],
	'status' => [ 'type' => 'string', 'description' => 'publish (default) or draft' ],
];
$cowboy_mcp_events_list_props = [ 'search' => [ 'type' => 'string' ], 'page' => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ], 'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ] ];
// Event fields shared by create/update (Pro-only fields are merged in before the definitions).
$cowboy_mcp_events_fields = [
	'title'              => [ 'type' => 'string' ],
	'content'            => [ 'type' => 'string', 'description' => 'Event description (HTML allowed, filtered like post content)' ],
	'excerpt'            => [ 'type' => 'string' ],
	'status'             => [ 'type' => 'string', 'description' => 'publish, draft (default on create), pending, private, future' ],
	'start_date'         => [ 'type' => 'string', 'description' => 'Event-local "YYYY-MM-DD HH:MM[:SS]" (or "YYYY-MM-DD" with all_day), or ISO 8601 with offset' ],
	'end_date'           => [ 'type' => 'string', 'description' => 'Same format as start_date; optional with all_day' ],
	'all_day'            => [ 'type' => 'boolean' ],
	'timezone'           => [ 'type' => 'string', 'description' => 'IANA name (Europe/Berlin) or UTC+2; default: site timezone' ],
	'venue_id'           => [ 'type' => 'integer', 'description' => 'Venue ID (0 clears)' ],
	'organizer_ids'      => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'description' => 'Organizer IDs ([] clears)' ],
	'cost'               => [ 'type' => 'string', 'description' => 'e.g. "15" or "Free" ("" clears)' ],
	'currency_symbol'    => [ 'type' => 'string' ],
	'currency_position'  => [ 'type' => 'string', 'enum' => [ 'prefix', 'postfix' ] ],
	'url'                => [ 'type' => 'string', 'description' => 'Event website' ],
	'featured'           => [ 'type' => 'boolean' ],
	'show_map'           => [ 'type' => 'boolean' ],
	'hide_from_upcoming' => [ 'type' => 'boolean' ],
	'categories'         => [ 'type' => 'array', 'items' => [ 'type' => [ 'string', 'integer' ] ], 'description' => 'Existing event category IDs or slugs (replaces the set)' ],
	'tags'               => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'description' => 'Tag names (replaces the set)' ],
	'image_id'           => [ 'type' => 'integer', 'description' => 'Featured image attachment ID (0 clears)' ],
];

$cowboy_mcp_events_tools = [
	Cowboy_MCP_Tools::tool( 'wp_events_list', '[Events] List The Events Calendar events overlapping a date window (default: from now on). A recurring event appears once with is_recurring, series_id and next_occurrence — use wp_events_list_occurrences for its dates. Dates are site-local unless they carry an offset.', [
		'from'         => [ 'type' => 'string', 'description' => 'Window start (default now). Events ending on/after this are included.' ],
		'to'           => [ 'type' => 'string', 'description' => 'Window end. Events starting on/before this are included. A date-only value (YYYY-MM-DD) includes that whole day.' ],
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
	Cowboy_MCP_Tools::tool(
		'wp_events_create',
		'[Events] Create an event (default status draft). Requires title, start_date and end_date (or all_day). Undoable.',
		array_merge( $cowboy_mcp_events_fields, [ 'title' => [ 'type' => 'string', 'required' => true ], 'start_date' => $cowboy_mcp_events_fields['start_date'] + [ 'required' => true ] ] ),
		[ 'title' => 'Create Event' ] + $cowboy_mcp_events_write,
		[ 'type' => 'object' ]
	),
	Cowboy_MCP_Tools::tool(
		'wp_events_update',
		'[Events] Update an event; only provided fields change. On a recurring event the change applies to every date of the series (single-occurrence and "this and following" edits are wp-admin only). Undoable.',
		[ 'event_id' => [ 'type' => 'integer', 'required' => true ] ] + $cowboy_mcp_events_fields,
		[ 'title' => 'Update Event' ] + $cowboy_mcp_events_write,
		[ 'type' => 'object' ]
	),
	Cowboy_MCP_Tools::tool(
		'wp_events_delete',
		'[Events] Trash an event (undoable). force: true deletes permanently after taking an automatic database checkpoint (restore it with wp_restore_checkpoint); refused if the checkpoint cannot be created.',
		[
			'event_id' => [ 'type' => 'integer', 'required' => true ],
			'force'    => [ 'type' => 'boolean', 'description' => 'Delete permanently instead of trashing (default false)', 'default' => false ],
		],
		[ 'title' => 'Delete Event', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ],
		[ 'type' => 'object' ]
	),
	Cowboy_MCP_Tools::tool( 'wp_events_list_venues', '[Events] List venues with address and number of events. Delete a venue with wp_delete_post (trash, undoable).', $cowboy_mcp_events_list_props, [ 'title' => 'List Venues' ] + $cowboy_mcp_events_ro ),
	Cowboy_MCP_Tools::tool( 'wp_events_create_venue', '[Events] Create a venue. Undoable.', [ 'title' => [ 'type' => 'string', 'required' => true ] ] + $cowboy_mcp_events_venue_props, [ 'title' => 'Create Venue' ] + $cowboy_mcp_events_write ),
	Cowboy_MCP_Tools::tool( 'wp_events_update_venue', '[Events] Update a venue; only provided fields change ("" clears). Undoable.', [ 'venue_id' => [ 'type' => 'integer', 'required' => true ], 'title' => [ 'type' => 'string' ] ] + $cowboy_mcp_events_venue_props, [ 'title' => 'Update Venue' ] + $cowboy_mcp_events_write ),
	Cowboy_MCP_Tools::tool( 'wp_events_list_organizers', '[Events] List organizers with contact details and number of events. Delete with wp_delete_post.', $cowboy_mcp_events_list_props, [ 'title' => 'List Organizers' ] + $cowboy_mcp_events_ro ),
	Cowboy_MCP_Tools::tool( 'wp_events_create_organizer', '[Events] Create an organizer. Undoable.', [ 'title' => [ 'type' => 'string', 'required' => true ] ] + $cowboy_mcp_events_org_props, [ 'title' => 'Create Organizer' ] + $cowboy_mcp_events_write ),
	Cowboy_MCP_Tools::tool( 'wp_events_update_organizer', '[Events] Update an organizer; only provided fields change ("" clears). Undoable.', [ 'organizer_id' => [ 'type' => 'integer', 'required' => true ], 'title' => [ 'type' => 'string' ] ] + $cowboy_mcp_events_org_props, [ 'title' => 'Update Organizer' ] + $cowboy_mcp_events_write ),
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
		if ( $to && preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( (string) $a['to'] ) ) ) {
			$to = $to->setTime( 23, 59, 59 ); // date-only end = the whole day, not its first second
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

	'wp_events_create' => function ( array $a ) {
		if ( ! function_exists( 'tribe_events' ) ) {
			return new WP_Error( 'tec_api_unavailable', 'The Events Calendar ORM (tribe_events) is unavailable.' );
		}
		// Validate first so a malformed start_date reports the format, not a missing end.
		$built = cowboy_mcp_events_build_args( $a, null );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		if ( $built['dates'] === null ) {
			return new WP_Error( 'invalid_params', 'start_date is required.' );
		}
		$orm = $built['orm'] + [ 'status' => 'draft' ];
		$pro = cowboy_mcp_events_recurrence_create_args( $a );
		if ( is_wp_error( $pro ) ) {
			return $pro;
		}
		try {
			$post = tribe_events()->set_args( $orm + $pro )->create();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'tec_api_unavailable', 'The Events Calendar rejected the event: ' . $e->getMessage() );
		}
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'save_failed', 'The Events Calendar did not create the event (check title and dates).' );
		}
		cowboy_mcp_events_apply_terms( (int) $post->ID, $built['terms'] );
		clean_post_cache( $post->ID );
		return cowboy_mcp_events_format( get_post( $post->ID ), true );
	},

	'wp_events_update' => function ( array $a ) {
		if ( ! function_exists( 'tribe_events' ) ) {
			return new WP_Error( 'tec_api_unavailable', 'The Events Calendar ORM (tribe_events) is unavailable.' );
		}
		$post = cowboy_mcp_events_get( (int) $a['event_id'], true );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', 'The authenticated user cannot edit this event.' );
		}
		$built = cowboy_mcp_events_build_args( $a, $post );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$recurring = cowboy_mcp_events_format( $post, false )['is_recurring'];
		$orm       = $built['orm'];
		if ( $recurring && $built['dates'] ) {
			// Never through the ORM for a recurring event (it re-parents the series).
			foreach ( [ 'start_date', 'end_date', 'timezone', 'all_day' ] as $k ) {
				unset( $orm[ $k ] );
			}
		}
		if ( $orm ) {
			$err = cowboy_mcp_events_orm_save( (int) $post->ID, $orm );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
		}
		foreach ( $built['clear_meta'] as $key ) {
			delete_post_meta( $post->ID, $key );
		}
		cowboy_mcp_events_apply_terms( (int) $post->ID, $built['terms'] );
		$err = cowboy_mcp_events_recurrence_update( (int) $post->ID, $a, $built['dates'], $recurring );
		if ( is_wp_error( $err ) ) {
			return $err;
		}
		if ( $built['clear_meta'] || $built['dates'] ) {
			$err = cowboy_mcp_events_resync( (int) $post->ID );
			if ( is_wp_error( $err ) ) {
				return $err;
			}
		}
		clean_post_cache( $post->ID );
		return cowboy_mcp_events_format( get_post( $post->ID ), true );
	},

	'wp_events_delete' => function ( array $a ) {
		$post = cowboy_mcp_events_get( (int) $a['event_id'], true );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new WP_Error( 'forbidden', 'The authenticated user cannot delete this event.' );
		}
		if ( empty( $a['force'] ) ) {
			// Trash disabled: core's wp_trash_post() would PERMANENTLY delete (no checkpoint).
			if ( defined( 'EMPTY_TRASH_DAYS' ) && ! EMPTY_TRASH_DAYS ) {
				return new WP_Error( 'trash_disabled', "This site has the trash disabled (EMPTY_TRASH_DAYS is 0), so event {$post->ID} cannot be trashed. Use force: true to delete it permanently; an automatic database checkpoint is taken first." );
			}
			if ( $post->post_status === 'trash' ) {
				return new WP_Error( 'already_trashed', "Event {$post->ID} is already in the trash. Use force: true to delete it permanently." );
			}
			// Explicit trash: wp_delete_post() without force PERMANENTLY deletes CPTs.
			if ( ! wp_trash_post( $post->ID ) ) {
				return new WP_Error( 'delete_failed', "Could not trash event {$post->ID}." );
			}
			return [ 'deleted' => true, 'mode' => 'trash', 'id' => (int) $post->ID, 'checkpoint_id' => null ];
		}
		// Fail closed: the checkpoint is the only way back (the journal entry is type none).
		$cp = Cowboy_MCP_Checkpoint::create( 'Before permanent delete of event #' . $post->ID, 'auto_event_delete' );
		if ( is_wp_error( $cp ) || empty( $cp['checkpoint_id'] ) ) {
			$why = is_wp_error( $cp ) ? $cp->get_error_message() : 'no checkpoint id returned';
			return new WP_Error( 'checkpoint_failed', "Refusing to permanently delete event {$post->ID}: the safety checkpoint could not be created ({$why}). Trash it instead (force: false)." );
		}
		Cowboy_MCP_Rollback::$last_checkpoint_id = (int) $cp['checkpoint_id'];
		if ( ! wp_delete_post( $post->ID, true ) ) {
			Cowboy_MCP_Rollback::$last_checkpoint_id = null; // capture is discarded on error; do not leak into a later journal row
			return new WP_Error( 'delete_failed', "Could not delete event {$post->ID}. Checkpoint #{$cp['checkpoint_id']} was taken before the attempt." );
		}
		return [ 'deleted' => true, 'mode' => 'force', 'id' => (int) $post->ID, 'checkpoint_id' => (int) $cp['checkpoint_id'] ];
	},

	'wp_events_list_venues'    => fn( array $a ) => cowboy_mcp_events_list_linked( 'tribe_venue', 'cowboy_mcp_events_format_venue', $a ),
	'wp_events_list_organizers'  => fn( array $a ) => cowboy_mcp_events_list_linked( 'tribe_organizer', 'cowboy_mcp_events_format_organizer', $a ),
	'wp_events_create_venue'     => function ( array $a ) {
		$p = cowboy_mcp_events_save_linked( 'venue', $a, null );
		return is_wp_error( $p ) ? $p : cowboy_mcp_events_format_venue( $p );
	},
	'wp_events_update_venue'     => function ( array $a ) {
		$p = cowboy_mcp_events_get_linked( (int) $a['venue_id'], 'tribe_venue' );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$p = cowboy_mcp_events_save_linked( 'venue', $a, (int) $p->ID );
		return is_wp_error( $p ) ? $p : cowboy_mcp_events_format_venue( $p );
	},
	'wp_events_create_organizer' => function ( array $a ) {
		$p = cowboy_mcp_events_save_linked( 'organizer', $a, null );
		return is_wp_error( $p ) ? $p : cowboy_mcp_events_format_organizer( $p );
	},
	'wp_events_update_organizer' => function ( array $a ) {
		$p = cowboy_mcp_events_get_linked( (int) $a['organizer_id'], 'tribe_organizer' );
		if ( is_wp_error( $p ) ) {
			return $p;
		}
		$p = cowboy_mcp_events_save_linked( 'organizer', $a, (int) $p->ID );
		return is_wp_error( $p ) ? $p : cowboy_mcp_events_format_organizer( $p );
	},
];

return [ 'tools' => $cowboy_mcp_events_tools, 'handlers' => $cowboy_mcp_events_handlers ];
