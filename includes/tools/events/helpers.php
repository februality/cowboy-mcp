<?php
defined( 'ABSPATH' ) || exit;

/*
 * The Events Calendar helpers. Loaded only from tools-events.php, after its
 * domain guard. Every TEC/Pro internal is guarded — a vendor refactor must
 * surface as tec_api_unavailable, never a fatal.
 */

/**
 * Full table name for a TEC custom table, or null. The CT1 tables count only
 * while CT1 is LIVE (Cowboy_MCP_Tools::events_ct1_ready()): TEC creates them
 * during a migration preview/in-progress and leaves them behind when CT1 is
 * disabled, while TEC itself keeps reading and writing post meta — using the
 * tables then would silently miss or misdate events. Readiness is re-checked on
 * every call (cheap, no queries); only a positive existence check is cached, so
 * a table created later in the request is still found.
 */
function cowboy_mcp_events_table( string $short ): ?string {
	static $exists = [];
	if ( in_array( $short, [ 'tec_events', 'tec_occurrences', 'tec_series_relationships' ], true ) && ! Cowboy_MCP_Tools::events_ct1_ready() ) {
		return null;
	}
	if ( ! isset( $exists[ $short ] ) ) {
		global $wpdb;
		$name = $wpdb->prefix . $short;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $name ) ) ) !== $name ) {
			return null;
		}
		$exists[ $short ] = $name;
	}
	return $exists[ $short ];
}

/** Normalise a (possibly provisional occurrence) id to the real event post id. */
function cowboy_mcp_events_real_id( int $id ): array {
	// Pro's own normaliser (provisional occurrence id → event post id); a no-op without Pro.
	$real = (int) apply_filters( 'tec_events_custom_tables_v1_normalize_occurrence_id', $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- The Events Calendar's hook, applied on purpose.
	$real = $real > 0 ? $real : $id;
	return [ 'id' => $real, 'was_occurrence' => $real !== $id ];
}

/** The event post, or a WP_Error. Writes refuse occurrence ids. */
function cowboy_mcp_events_get( int $id, bool $for_write ): WP_Post|WP_Error {
	$norm = cowboy_mcp_events_real_id( $id );
	if ( $for_write && $norm['was_occurrence'] ) {
		return new WP_Error(
			'occurrence_id_not_writable',
			"ID {$id} is a single occurrence of recurring event #{$norm['id']}. Occurrence IDs are provisional and read-only; edit event #{$norm['id']} (changes apply to every date) or cancel one date with wp_events_exclude_date. Editing only this occurrence or \"this and following\" is only available in wp-admin.",
			[ 'event_id' => $norm['id'] ]
		);
	}
	$post = get_post( $norm['id'] );
	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'not_found', "Event {$id} not found." );
	}
	if ( $post->post_type !== 'tribe_events' ) {
		return new WP_Error( 'not_an_event', "Post {$id} is not an event (post type {$post->post_type})." );
	}
	return $post;
}

/** A venue/organizer post of the given type, or WP_Error. */
function cowboy_mcp_events_get_linked( int $id, string $type ): WP_Post|WP_Error {
	$post  = get_post( $id );
	$label = $type === 'tribe_venue' ? 'venue' : 'organizer';
	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'not_found', ucfirst( $label ) . " {$id} not found." );
	}
	if ( $post->post_type !== $type ) {
		return new WP_Error( "not_a_{$label}", "Post {$id} is not a {$label} (post type {$post->post_type})." );
	}
	return $post;
}

/** Validate a timezone string (IANA, "UTC", "UTC+2", "UTC-5:30"); default site tz. */
function cowboy_mcp_events_tz( ?string $tz ): string|WP_Error {
	$tz = trim( (string) $tz );
	if ( $tz === '' ) {
		return wp_timezone_string();
	}
	$probe = $tz;
	if ( preg_match( '/^UTC([+-])(\d{1,2})(?::?(\d{2}))?$/i', $tz, $m ) ) {
		$probe = sprintf( '%s%02d:%02d', $m[1], (int) $m[2], (int) ( $m[3] ?? 0 ) );
	}
	try {
		new DateTimeZone( $probe );
	} catch ( \Exception $e ) {
		return new WP_Error( 'invalid_timezone', "Unknown timezone '{$tz}'. Use an IANA name like Europe/Berlin, or UTC+2." );
	}
	return $tz;
}

/** DateTimeZone for a validated TEC timezone string. */
function cowboy_mcp_events_tz_object( string $tz ): DateTimeZone {
	if ( preg_match( '/^UTC([+-])(\d{1,2})(?::?(\d{2}))?$/i', $tz, $m ) ) {
		return new DateTimeZone( sprintf( '%s%02d:%02d', $m[1], (int) $m[2], (int) ( $m[3] ?? 0 ) ) );
	}
	return new DateTimeZone( $tz );
}

/**
 * Parse "Y-m-d H:i:s" / "Y-m-d H:i" / "Y-m-d" (event-local) or ISO 8601 with
 * an offset (converted into the event timezone).
 */
function cowboy_mcp_events_parse_date( string $raw, string $tz ): DateTimeImmutable|WP_Error {
	$raw  = trim( $raw );
	$zone = cowboy_mcp_events_tz_object( $tz );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $raw ) ) {
		return new WP_Error( 'invalid_date', "Date '{$raw}' must start with YYYY-MM-DD (event-local), e.g. 2026-10-01 18:30, or be ISO 8601 with an offset." );
	}
	try {
		$has_offset = (bool) preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $raw );
		$dt         = $has_offset ? ( new DateTimeImmutable( $raw ) )->setTimezone( $zone ) : new DateTimeImmutable( $raw, $zone );
	} catch ( \Exception $e ) {
		return new WP_Error( 'invalid_date', "Date '{$raw}' could not be parsed." );
	}
	return $dt;
}

/** Series post ids the event belongs to (Pro), sorted. */
function cowboy_mcp_events_series_ids( int $post_id ): array {
	$table = cowboy_mcp_events_table( 'tec_series_relationships' );
	if ( ! $table ) {
		return [];
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT series_post_id FROM {$table} WHERE event_post_id = %d", $post_id ) ) );
	sort( $ids );
	return array_values( array_unique( $ids ) );
}

/** RFC 5545 recurrence set stored by TEC for the event (DTSTART/RRULE/EXDATE lines), or null. */
function cowboy_mcp_events_rset( int $post_id ): ?string {
	static $has_column = false; // only a positive result is cached (Pro may add the column later)
	$table = cowboy_mcp_events_table( 'tec_events' );
	if ( ! $table ) {
		return null;
	}
	global $wpdb;
	if ( ! $has_column ) {
		// Pro adds `rset` to TEC's table lazily (its activation init, once a day) — absent until then.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_column = (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$table} LIKE 'rset'" );
	}
	if ( ! $has_column ) {
		return null;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rset = $wpdb->get_var( $wpdb->prepare( "SELECT rset FROM {$table} WHERE post_id = %d", $post_id ) );
	return ( is_string( $rset ) && $rset !== '' ) ? $rset : null;
}

/** Occurrence rows (start/end local + provisional id) for one event. */
function cowboy_mcp_events_occurrences( int $post_id, ?string $after_utc = null, ?string $before_utc = null, int $limit = 100, int $offset = 0 ): array {
	$table = cowboy_mcp_events_table( 'tec_occurrences' );
	if ( ! $table ) {
		return [ 'total' => 0, 'rows' => [] ];
	}
	global $wpdb;
	$where = $wpdb->prepare( 'post_id = %d', $post_id );
	if ( $after_utc ) {
		$where .= $wpdb->prepare( ' AND end_date_utc >= %s', $after_utc );
	}
	if ( $before_utc ) {
		$where .= $wpdb->prepare( ' AND start_date_utc <= %s', $before_utc );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT occurrence_id, start_date, end_date FROM {$table} WHERE {$where} ORDER BY start_date ASC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
	// phpcs:enable
	$out = [];
	foreach ( (array) $rows as $r ) {
		$out[] = [
			'occurrence_id' => cowboy_mcp_events_provisional_id( (int) $r['occurrence_id'] ),
			'start'         => $r['start_date'],
			'end'           => $r['end_date'],
		];
	}
	return [ 'total' => $total, 'rows' => $out ];
}

/**
 * Provisional post id for a tec_occurrences.occurrence_id — the id TEC's
 * front end, REST and the normalize filter use for a single occurrence.
 * Pro's own generator (base option + occurrence_id, base filterable); the
 * same formula with the same default as the fallback.
 */
function cowboy_mcp_events_provisional_id( int $occurrence_id ): int {
	$class = '\TEC\Events_Pro\Custom_Tables\V1\Events\Provisional\ID_Generator';
	if ( function_exists( 'tribe' ) && class_exists( $class ) && method_exists( $class, 'provide_id' ) ) {
		try {
			$id = (int) tribe( $class )->provide_id( $occurrence_id );
			if ( $id > 0 ) {
				return $id;
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // fall through to the documented formula
		}
	}
	return (int) get_option( 'tec_custom_tables_v1_provisional_post_base_provisional_id', 10000000 ) + $occurrence_id;
}

/** Force TEC to rebuild tec_events/tec_occurrences from the event's meta. */
function cowboy_mcp_events_resync( int $post_id ): ?WP_Error {
	if ( ! Cowboy_MCP_Tools::events_ct1_ready() ) {
		return null; // legacy mode: meta is the only store
	}
	$class = '\TEC\Events\Custom_Tables\V1\Updates\Events';
	if ( ! class_exists( $class ) || ! method_exists( $class, 'update' ) ) {
		return new WP_Error( 'tec_api_unavailable', 'The Events Calendar custom-tables updater is unavailable.' );
	}
	try {
		$updater = tribe( $class );
		$updater->update( $post_id );
		if ( method_exists( $updater, 'rebuild_known_range' ) ) {
			$updater->rebuild_known_range();
		}
	} catch ( \Throwable $e ) {
		return new WP_Error( 'tec_api_unavailable', 'The Events Calendar could not rebuild its tables: ' . $e->getMessage() );
	}
	return null;
}

/** Response shape for an event. */
function cowboy_mcp_events_format( WP_Post $post, bool $full ): array {
	$id   = (int) $post->ID;
	$meta = static fn( string $k ): string => (string) get_post_meta( $id, $k, true );
	$tz   = $meta( '_EventTimezone' ) ?: wp_timezone_string();
	$vid  = (int) $meta( '_EventVenueID' );
	$oids = array_values( array_filter( array_map( 'intval', (array) get_post_meta( $id, '_EventOrganizerID' ) ) ) );
	$sids = cowboy_mcp_events_series_ids( $id );
	$recurring = Cowboy_MCP_Tools::events_pro_ready() && get_post_meta( $id, '_EventRecurrence', true ) !== '' && cowboy_mcp_events_rset( $id ) !== null;

	$out = [
		'id'            => $id,
		'title'         => $post->post_title,
		'status'        => $post->post_status,
		'start'         => [ 'local' => $meta( '_EventStartDate' ), 'utc' => $meta( '_EventStartDateUTC' ), 'timezone' => $tz ],
		'end'           => [ 'local' => $meta( '_EventEndDate' ), 'utc' => $meta( '_EventEndDateUTC' ), 'timezone' => $tz ],
		'all_day'       => $meta( '_EventAllDay' ) === 'yes',
		'venue'         => $vid ? [ 'id' => $vid, 'title' => get_the_title( $vid ) ] : null,
		'organizer_ids' => $oids,
		'cost'          => $meta( '_EventCost' ),
		'featured'      => $meta( '_tribe_featured' ) !== '' && $meta( '_tribe_featured' ) !== '0',
		'permalink'     => get_permalink( $id ),
		'is_recurring'  => $recurring,
		'series_id'     => $sids[0] ?? null,
	];
	if ( $recurring ) {
		$next = cowboy_mcp_events_occurrences( $id, gmdate( 'Y-m-d H:i:s' ), null, 1 );
		$out['next_occurrence'] = $next['rows'][0] ?? null;
	}
	if ( ! $full ) {
		return $out;
	}
	$terms = static function ( string $tax ) use ( $id ): array {
		$got = wp_get_object_terms( $id, $tax );
		return is_array( $got ) ? array_map( static fn( $t ) => [ 'id' => (int) $t->term_id, 'name' => $t->name, 'slug' => $t->slug ], $got ) : [];
	};
	$out += [
		'content'            => $post->post_content,
		'excerpt'            => $post->post_excerpt,
		'organizers'         => array_map( static fn( int $o ) => [ 'id' => $o, 'title' => get_the_title( $o ) ], $oids ),
		'currency_symbol'    => $meta( '_EventCurrencySymbol' ),
		'currency_position'  => $meta( '_EventCurrencyPosition' ),
		'url'                => $meta( '_EventURL' ),
		'show_map'           => in_array( $meta( '_EventShowMap' ), [ '1', 'yes', 'true' ], true ),
		'hide_from_upcoming' => $meta( '_EventHideFromUpcoming' ) === 'yes',
		'categories'         => $terms( 'tribe_events_cat' ),
		'tags'               => $terms( 'post_tag' ),
		'image_id'           => (int) get_post_thumbnail_id( $id ) ?: null,
	];
	if ( $recurring ) {
		$rec = get_post_meta( $id, '_EventRecurrence', true );
		$occ = cowboy_mcp_events_occurrences( $id, null, null, 1 );
		$out['recurrence'] = [
			'rrule'            => cowboy_mcp_events_rset( $id ),
			'description'      => is_array( $rec ) ? (string) ( $rec['description'] ?? '' ) : '',
			'exclusions'       => cowboy_mcp_events_exclusion_dates( is_array( $rec ) ? $rec : [] ),
			'series_id'        => $sids[0] ?? null,
			'occurrence_count' => $occ['total'],
		];
	}
	return $out;
}

/** Y-m-d strings found anywhere inside _EventRecurrence['exclusions']. */
function cowboy_mcp_events_exclusion_dates( array $recurrence ): array {
	$dates = [];
	foreach ( (array) ( $recurrence['exclusions'] ?? [] ) as $ex ) {
		array_walk_recursive( $ex, static function ( $v ) use ( &$dates ) {
			if ( is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				$dates[] = $v;
			}
		} );
	}
	$dates = array_values( array_unique( $dates ) );
	sort( $dates );
	return $dates;
}
