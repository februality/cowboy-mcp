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

/**
 * A bare "+02:00" offset (what wp_timezone_string() returns on sites set to a
 * manual UTC offset — the WordPress default "UTC+0" included) in TEC's own
 * "UTC+2" notation. Pro cannot build a recurrence set for a "+02:00" zone
 * (probed on 7.8.3: no rset, one occurrence), while TEC maps "UTC+2" to a
 * named zone. Anything else is returned unchanged.
 */
function cowboy_mcp_events_tz_normalize( string $tz ): string {
	if ( ! preg_match( '/^([+-])(\d{2}):?(\d{2})$/', trim( $tz ), $m ) ) {
		return $tz;
	}
	$h = (int) $m[2];
	if ( $h === 0 && $m[3] === '00' ) {
		return 'UTC';
	}
	return 'UTC' . $m[1] . $h . ( $m[3] !== '00' ? ':' . $m[3] : '' );
}

/** The _EventTimezone value TEC's ORM would store for a validated timezone. */
function cowboy_mcp_events_tz_store_name( string $tz ): string {
	$tz = cowboy_mcp_events_tz_normalize( $tz );
	if ( class_exists( 'Tribe__Timezones' ) && method_exists( 'Tribe__Timezones', 'build_timezone_object' ) ) {
		try {
			$name = Tribe__Timezones::build_timezone_object( $tz )->getName();
			if ( is_string( $name ) && $name !== '' ) {
				return $name;
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // keep the validated string
		}
	}
	return $tz;
}

/** Validate a timezone string (IANA, "UTC", "UTC+2", "UTC-5:30", "+02:00"); default site tz. */
function cowboy_mcp_events_tz( ?string $tz ): string|WP_Error {
	$tz = cowboy_mcp_events_tz_normalize( trim( (string) $tz ) );
	if ( $tz === '' ) {
		return cowboy_mcp_events_tz_normalize( wp_timezone_string() );
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

/**
 * TEC boolean meta: the ORM stores "1" (e.g. _EventAllDay), the classic
 * editor "yes" — both mean on; absent/""/"0"/"no" mean off.
 */
function cowboy_mcp_events_meta_truthy( mixed $v ): bool {
	return in_array( strtolower( trim( (string) $v ) ), [ '1', 'yes', 'true', 'on' ], true );
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
		'all_day'       => cowboy_mcp_events_meta_truthy( $meta( '_EventAllDay' ) ),
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
		'show_map'           => cowboy_mcp_events_meta_truthy( $meta( '_EventShowMap' ) ),
		'hide_from_upcoming' => cowboy_mcp_events_meta_truthy( $meta( '_EventHideFromUpcoming' ) ),
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

/** Linked-post event count (venue or organizer) — real events, not occurrences. */
function cowboy_mcp_events_linked_count( int $id, string $meta_key ): int {
	$q = new WP_Query( [ 'post_type' => 'tribe_events', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids', 'tec_events_ignore' => true, 'tribe_suppress_query_filters' => true, 'meta_query' => [ [ 'key' => $meta_key, 'value' => $id ] ] ] ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	return (int) $q->found_posts;
}

const COWBOY_MCP_EVENTS_VENUE_META = [
	'address' => '_VenueAddress', 'city' => '_VenueCity', 'state' => '_VenueState', 'province' => '_VenueProvince',
	'zip' => '_VenueZip', 'country' => '_VenueCountry', 'phone' => '_VenuePhone', 'website' => '_VenueURL', 'show_map' => '_VenueShowMap',
];
const COWBOY_MCP_EVENTS_ORGANIZER_META = [ 'phone' => '_OrganizerPhone', 'website' => '_OrganizerWebsite', 'email' => '_OrganizerEmail' ];

function cowboy_mcp_events_format_venue( WP_Post $p ): array {
	$out = [ 'id' => (int) $p->ID, 'title' => $p->post_title, 'status' => $p->post_status ];
	foreach ( COWBOY_MCP_EVENTS_VENUE_META as $field => $key ) {
		$out[ $field ] = (string) get_post_meta( $p->ID, $key, true );
	}
	$out['show_map']    = in_array( $out['show_map'], [ '1', 'true', 'yes' ], true );
	$out['event_count'] = cowboy_mcp_events_linked_count( (int) $p->ID, '_EventVenueID' );
	return $out;
}

function cowboy_mcp_events_format_organizer( WP_Post $p ): array {
	$out = [ 'id' => (int) $p->ID, 'title' => $p->post_title, 'status' => $p->post_status ];
	foreach ( COWBOY_MCP_EVENTS_ORGANIZER_META as $field => $key ) {
		$out[ $field ] = (string) get_post_meta( $p->ID, $key, true );
	}
	$out['event_count'] = cowboy_mcp_events_linked_count( (int) $p->ID, '_EventOrganizerID' );
	return $out;
}

/**
 * Create/update a venue or organizer through TEC's ORM (keeps TEC's own
 * sanitising and hooks). $kind: 'venue' | 'organizer'.
 */
function cowboy_mcp_events_save_linked( string $kind, array $a, ?int $id ): WP_Post|WP_Error {
	$repo_fn = $kind === 'venue' ? 'tribe_venues' : 'tribe_organizers';
	$fields  = $kind === 'venue' ? COWBOY_MCP_EVENTS_VENUE_META : COWBOY_MCP_EVENTS_ORGANIZER_META;
	if ( ! function_exists( $repo_fn ) ) {
		return new WP_Error( 'tec_api_unavailable', "The Events Calendar {$kind} API ({$repo_fn}) is unavailable." );
	}
	$args = [];
	if ( isset( $a['title'] ) ) {
		$args['title'] = sanitize_text_field( $a['title'] );
	}
	if ( isset( $a['status'] ) ) {
		$args['status'] = sanitize_key( $a['status'] );
	}
	foreach ( array_keys( $fields ) as $field ) {
		if ( ! array_key_exists( $field, $a ) ) {
			continue;
		}
		$args[ $field ] = match ( $field ) {
			'email'    => sanitize_email( (string) $a[ $field ] ),
			'website'  => esc_url_raw( (string) $a[ $field ] ),
			'show_map' => (bool) $a[ $field ],
			default    => sanitize_text_field( (string) $a[ $field ] ),
		};
	}
	try {
		if ( $id === null ) {
			$args += [ 'status' => 'publish' ];
			$post = $repo_fn()->set_args( $args )->create();
		} else {
			$repo_fn()->where( 'id', $id )->set_args( $args )->save();
			$post = get_post( $id );
		}
	} catch ( \Throwable $e ) {
		return new WP_Error( 'tec_api_unavailable', "The Events Calendar rejected the {$kind}: " . $e->getMessage() );
	}
	if ( ! $post instanceof WP_Post ) {
		return new WP_Error( 'save_failed', "The Events Calendar did not save the {$kind} (a title is required)." );
	}
	// ORM drops falsy values; make explicit clears stick.
	foreach ( $fields as $field => $key ) {
		if ( array_key_exists( $field, $a ) && ( $a[ $field ] === '' || $a[ $field ] === false ) ) {
			delete_post_meta( $post->ID, $key );
		}
	}
	return get_post( $post->ID );
}

function cowboy_mcp_events_list_linked( string $type, callable $format, array $a ): array {
	$q = new WP_Query( [
		'post_type'      => $type,
		'post_status'    => [ 'publish', 'draft', 'private' ],
		's'              => sanitize_text_field( (string) ( $a['search'] ?? '' ) ),
		'posts_per_page' => min( max( (int) ( $a['per_page'] ?? 20 ), 1 ), 100 ),
		'paged'          => max( (int) ( $a['page'] ?? 1 ), 1 ),
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );
	return [ 'items' => array_map( $format, $q->posts ), 'total' => (int) $q->found_posts ];
}

/**
 * Validate agent args and build TEC ORM args. Only keys present in $a are
 * touched. Falsy flags are returned in clear_meta because the ORM drops them.
 */
function cowboy_mcp_events_build_args( array $a, ?WP_Post $existing ): array|WP_Error {
	$orm   = [];
	$clear = [];
	foreach ( [ 'title' => 'sanitize_text_field', 'excerpt' => 'sanitize_textarea_field' ] as $k => $fn ) {
		if ( isset( $a[ $k ] ) ) {
			$orm[ $k ] = $fn( (string) $a[ $k ] );
		}
	}
	if ( isset( $a['content'] ) ) {
		$orm['content'] = wp_kses_post( (string) $a['content'] );
	}
	if ( isset( $a['status'] ) ) {
		$status = sanitize_key( $a['status'] );
		if ( ! in_array( $status, [ 'publish', 'draft', 'pending', 'private', 'future' ], true ) ) {
			return new WP_Error( 'invalid_params', "status must be one of publish, draft, pending, private, future (got '{$status}'). Use wp_events_delete to trash an event." );
		}
		$orm['status'] = $status;
	}
	foreach ( [ 'cost' => '_EventCost', 'currency_symbol' => '_EventCurrencySymbol', 'url' => '_EventURL' ] as $k => $meta ) {
		if ( ! array_key_exists( $k, $a ) ) {
			continue;
		}
		$v = $k === 'url' ? esc_url_raw( (string) $a[ $k ] ) : sanitize_text_field( (string) $a[ $k ] );
		if ( $v === '' ) {
			$clear[] = $meta;
		} else {
			$orm[ $k ] = $v;
		}
	}
	if ( isset( $a['currency_position'] ) ) {
		if ( ! in_array( $a['currency_position'], [ 'prefix', 'postfix' ], true ) ) {
			return new WP_Error( 'invalid_params', 'currency_position must be "prefix" or "postfix".' );
		}
		$orm['currency_position'] = $a['currency_position'];
	}
	foreach ( [ 'featured' => '_tribe_featured', 'show_map' => '_EventShowMap', 'hide_from_upcoming' => '_EventHideFromUpcoming' ] as $k => $meta ) {
		if ( array_key_exists( $k, $a ) ) {
			if ( $a[ $k ] ) {
				$orm[ $k ] = true;
			} else {
				$clear[] = $meta;
			}
		}
	}
	if ( array_key_exists( 'venue_id', $a ) ) {
		if ( empty( $a['venue_id'] ) ) {
			$clear[] = '_EventVenueID';
		} else {
			$v = cowboy_mcp_events_get_linked( (int) $a['venue_id'], 'tribe_venue' );
			if ( is_wp_error( $v ) ) {
				return $v;
			}
			$orm['venue'] = (int) $v->ID;
		}
	}
	if ( array_key_exists( 'organizer_ids', $a ) ) {
		$ids = [];
		foreach ( (array) $a['organizer_ids'] as $oid ) {
			$o = cowboy_mcp_events_get_linked( (int) $oid, 'tribe_organizer' );
			if ( is_wp_error( $o ) ) {
				return $o;
			}
			$ids[] = (int) $o->ID;
		}
		if ( $ids ) {
			$orm['organizer'] = array_values( array_unique( $ids ) );
		} else {
			$clear[] = '_EventOrganizerID';
		}
	}
	if ( array_key_exists( 'image_id', $a ) ) {
		if ( empty( $a['image_id'] ) ) {
			$clear[] = '_thumbnail_id';
		} elseif ( get_post_type( (int) $a['image_id'] ) !== 'attachment' ) {
			return new WP_Error( 'invalid_params', 'image_id ' . (int) $a['image_id'] . ' is not a media attachment.' );
		} else {
			$orm['image'] = (int) $a['image_id'];
		}
	}

	// Terms (applied after save, never auto-created).
	$terms = [ 'tribe_events_cat' => null, 'post_tag' => null ];
	if ( array_key_exists( 'categories', $a ) ) {
		$terms['tribe_events_cat'] = [];
		foreach ( (array) $a['categories'] as $c ) {
			$term = is_numeric( $c ) ? get_term( (int) $c, 'tribe_events_cat' ) : get_term_by( 'slug', sanitize_title( (string) $c ), 'tribe_events_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				$label = sanitize_text_field( (string) $c );
				return new WP_Error( 'invalid_params', "Unknown event category '{$label}'. Create it first with wp_create_term (taxonomy tribe_events_cat)." );
			}
			$terms['tribe_events_cat'][] = (int) $term->term_id;
		}
	}
	if ( array_key_exists( 'tags', $a ) ) {
		$terms['post_tag'] = array_map( 'sanitize_text_field', (array) $a['tags'] );
	}

	// Dates: resolved against existing values so partial updates stay consistent.
	$dates     = null;
	$date_keys = [ 'start_date', 'end_date', 'all_day', 'timezone' ];
	if ( array_intersect( $date_keys, array_keys( $a ) ) ) {
		$old_tz = $existing ? ( (string) get_post_meta( $existing->ID, '_EventTimezone', true ) ?: wp_timezone_string() ) : null;
		$tz     = cowboy_mcp_events_tz( $a['timezone'] ?? $old_tz );
		if ( is_wp_error( $tz ) ) {
			return $tz;
		}
		$all_day = array_key_exists( 'all_day', $a ) ? (bool) $a['all_day'] : ( $existing && cowboy_mcp_events_meta_truthy( get_post_meta( $existing->ID, '_EventAllDay', true ) ) );
		$raw_s   = $a['start_date'] ?? ( $existing ? (string) get_post_meta( $existing->ID, '_EventStartDate', true ) : null );
		if ( $raw_s === null || $raw_s === '' ) {
			return new WP_Error( 'invalid_params', 'start_date is required.' );
		}
		$start = cowboy_mcp_events_parse_date( (string) $raw_s, $tz );
		if ( is_wp_error( $start ) ) {
			return $start;
		}
		$raw_e = $a['end_date'] ?? ( $existing ? (string) get_post_meta( $existing->ID, '_EventEndDate', true ) : null );
		if ( ( $raw_e === null || $raw_e === '' ) && ! $all_day ) {
			return new WP_Error( 'invalid_params', 'end_date is required unless all_day is true.' );
		}
		$end = ( $raw_e === null || $raw_e === '' ) ? $start : cowboy_mcp_events_parse_date( (string) $raw_e, $tz );
		if ( is_wp_error( $end ) ) {
			return $end;
		}
		if ( $all_day ) {
			$start = $start->setTime( 0, 0, 0 );
			$end   = $end->setTime( 23, 59, 59 );
		}
		if ( $end < $start ) {
			return new WP_Error( 'end_before_start', 'end_date is before start_date.' );
		}
		$dates             = [ 'start' => $start, 'end' => $end, 'tz' => $tz, 'all_day' => $all_day ];
		$orm['start_date'] = $start->format( 'Y-m-d H:i:s' );
		$orm['end_date']   = $end->format( 'Y-m-d H:i:s' );
		$orm['timezone']   = $tz;
		if ( $all_day ) {
			$orm['all_day'] = true;
		} else {
			$clear[] = '_EventAllDay';
		}
	}
	return [ 'orm' => $orm, 'clear_meta' => array_values( array_unique( $clear ) ), 'terms' => $terms, 'dates' => $dates ];
}

/**
 * Save ORM args onto ONE real event post. tribe_events()->where( 'id', … ) is
 * unusable here: under Pro it resolves through the occurrence query, so save()
 * targets the PROVISIONAL occurrence id — meta lands (Pro redirects it) but post
 * fields such as the title silently do not. The repository's own
 * get_query_for_posts() + set_query() pins the target to the real post.
 */
function cowboy_mcp_events_orm_save( int $id, array $orm ): ?WP_Error {
	try {
		$repo = tribe_events();
		if ( method_exists( $repo, 'get_query_for_posts' ) && method_exists( $repo, 'set_query' ) ) {
			$query          = $repo->get_query_for_posts( [ $id ] );
			$query->request = '/* cowboy-mcp: fixed target */'; // marks the query as already run: get_ids() reads ->posts
			$repo->set_query( $query );
		} else {
			$repo = $repo->where( 'id', $id );
		}
		$saved = $repo->set_args( $orm )->save();
	} catch ( \Throwable $e ) {
		return new WP_Error( 'tec_api_unavailable', 'The Events Calendar rejected the update: ' . $e->getMessage() );
	}
	if ( ! is_array( $saved ) || empty( $saved[ $id ] ) || is_wp_error( $saved[ $id ] ) ) {
		return new WP_Error( 'save_failed', "The Events Calendar did not save event {$id}." );
	}
	return null;
}

/** Replace an event's category/tag sets (null = leave that taxonomy alone). */
function cowboy_mcp_events_apply_terms( int $id, array $terms ): void {
	if ( $terms['tribe_events_cat'] !== null ) {
		wp_set_object_terms( $id, $terms['tribe_events_cat'], 'tribe_events_cat' );
	}
	if ( $terms['post_tag'] !== null ) {
		wp_set_post_tags( $id, $terms['post_tag'] );
	}
}

/**
 * Convert an agent RRULE string into TEC's _EventRecurrence array (Pro).
 * DTSTART/DTEND always come from the event's own dates, never from the string.
 */
function cowboy_mcp_events_rrule_to_recurrence( string $rrule, DateTimeImmutable $start, DateTimeImmutable $end ): array|WP_Error {
	$class = '\TEC\Events_Pro\Custom_Tables\V1\Events\Recurrence';
	if ( ! class_exists( $class ) || ! method_exists( $class, 'from_icalendar_string' ) || ! method_exists( $class, 'to_event_recurrence' ) ) {
		return new WP_Error( 'tec_api_unavailable', 'Events Calendar Pro recurrence API is unavailable.' );
	}
	$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n/', trim( $rrule ) ) ), static fn( $l ) => $l !== '' && ! preg_match( '/^(DTSTART|DTEND)\b/i', $l ) ) );
	if ( ! $lines ) {
		return new WP_Error( 'invalid_rrule', 'Invalid recurrence rule: it is empty. Use an RFC 5545 RRULE, e.g. RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10.' );
	}
	foreach ( $lines as $n => $line ) {
		if ( ! preg_match( '/^(RRULE|RDATE|EXDATE|EXRULE)[:;]/i', $line ) ) {
			$lines[ $n ] = $line = 'RRULE:' . $line;
		}
		// Validate RRULE parts ourselves: Pro's converter silently drops what it cannot read.
		if ( preg_match( '/^(RRULE|EXRULE):(.*)$/i', $line, $m ) ) {
			$parts = [];
			foreach ( explode( ';', $m[2] ) as $kv ) {
				[ $k, $v ] = array_pad( explode( '=', $kv, 2 ), 2, '' );
				$parts[ strtoupper( trim( $k ) ) ] = trim( $v );
			}
			if ( ! in_array( strtoupper( $parts['FREQ'] ?? '' ), [ 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY' ], true ) ) {
				return new WP_Error( 'invalid_rrule', "Invalid recurrence rule '{$line}': FREQ must be DAILY, WEEKLY, MONTHLY or YEARLY. Use an RFC 5545 RRULE, e.g. RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10." );
			}
		}
	}
	$rrule = implode( "\n", $lines );
	try {
		$rec = $class::from_icalendar_string( $rrule, $start, $end );
		$arr = $rec ? $rec->to_event_recurrence() : null;
	} catch ( \Throwable $e ) {
		$arr = null;
	}
	if ( ! is_array( $arr ) || empty( $arr['rules'] ) ) {
		return new WP_Error( 'invalid_rrule', "Invalid recurrence rule '{$rrule}'. Use an RFC 5545 RRULE, e.g. RRULE:FREQ=WEEKLY;BYDAY=MO,WE;COUNT=10." );
	}
	return $arr;
}

/**
 * Make sure a (now recurring) event belongs to a series; create an auto series
 * if not — the same calls Pro's own repository makes for a new recurring event.
 */
function cowboy_mcp_events_ensure_series( int $event_id ): ?WP_Error {
	if ( cowboy_mcp_events_series_ids( $event_id ) ) {
		return null;
	}
	$series_class = '\TEC\Events_Pro\Custom_Tables\V1\Models\Series';
	$rel_class    = '\TEC\Events_Pro\Custom_Tables\V1\Series\Relationship';
	$event_class  = '\TEC\Events\Custom_Tables\V1\Models\Event';
	if ( ! function_exists( 'tribe' ) || ! class_exists( $series_class ) || ! method_exists( $series_class, 'vinsert' ) || ! class_exists( $rel_class ) || ! method_exists( $rel_class, 'with_event' ) || ! class_exists( $event_class ) ) {
		return new WP_Error( 'tec_api_unavailable', 'Events Calendar Pro series API is unavailable.' );
	}
	try {
		// where()->first(), not find(): find() is memoized per request.
		$event = $event_class::where( 'post_id', $event_id )->first();
		if ( ! $event instanceof $event_class ) {
			return new WP_Error( 'tec_api_unavailable', "The Events Calendar has no custom-table row for event {$event_id}; could not attach it to a series." );
		}
		$series_id = (int) $series_class::vinsert( [ 'title' => get_the_title( $event_id ) ], [ 'post_status' => get_post_status( $event_id ) ] );
		if ( ! $series_id ) {
			return new WP_Error( 'tec_api_unavailable', 'Could not create a series for the recurring event.' );
		}
		tribe( $rel_class )->with_event( $event, [ $series_id ] );
	} catch ( \Throwable $e ) {
		return new WP_Error( 'tec_api_unavailable', 'Could not attach the event to a series: ' . $e->getMessage() );
	}
	return null;
}

/**
 * Recurrence args for create (Pro). The ORM create path is correct (verified on
 * Pro 7.8.3); only the UPDATE path is broken. The rule is validated here, before
 * anything is written, so a bad rule never leaves a half-created event behind.
 */
function cowboy_mcp_events_recurrence_create_args( array $a, ?array $dates = null ): array|WP_Error {
	$has_rule   = isset( $a['recurrence'] ) && trim( (string) $a['recurrence'] ) !== '';
	$has_series = ! empty( $a['series_id'] );
	if ( ! $has_rule && ! $has_series ) {
		return [];
	}
	if ( ! Cowboy_MCP_Tools::events_pro_ready() ) {
		return new WP_Error( 'pro_unavailable', 'Recurring events and series need Events Calendar Pro with custom tables active.' );
	}
	$out = [];
	if ( $has_rule ) {
		if ( $dates ) {
			$rec = cowboy_mcp_events_rrule_to_recurrence( (string) $a['recurrence'], $dates['start'], $dates['end'] );
			if ( is_wp_error( $rec ) ) {
				return $rec;
			}
		}
		$rrule = trim( (string) $a['recurrence'] );
		$out['recurrence'] = preg_match( '/^(RRULE|RDATE|EXDATE|EXRULE|DTSTART)[:;]/i', $rrule ) ? $rrule : 'RRULE:' . $rrule;
	}
	if ( $has_series ) {
		if ( get_post_type( (int) $a['series_id'] ) !== 'tribe_event_series' ) {
			return new WP_Error( 'invalid_params', 'series_id ' . (int) $a['series_id'] . ' is not a series.' );
		}
		$out['series'] = [ (int) $a['series_id'] ];
	}
	return $out;
}

/**
 * Validate a recurrence change for an existing event and compute the new
 * _EventRecurrence WITHOUT writing anything (errors after the first write would
 * leave an unjournaled change). null = nothing to do; otherwise
 * [ 'recurrence' => array (new rule set) | null (make it a single event) ].
 */
function cowboy_mcp_events_recurrence_plan( int $id, array $a, ?array $dates, bool $recurring ): array|WP_Error|null {
	$has_rule = array_key_exists( 'recurrence', $a );
	if ( ! $has_rule && ! ( $recurring && $dates ) ) {
		return null;
	}
	if ( ! Cowboy_MCP_Tools::events_pro_ready() ) {
		return $has_rule ? new WP_Error( 'pro_unavailable', 'Recurring events need Events Calendar Pro with custom tables active.' ) : null;
	}
	if ( $has_rule && trim( (string) $a['recurrence'] ) === '' ) {
		return [ 'recurrence' => null ];
	}
	try {
		$tz    = $dates['tz'] ?? ( (string) get_post_meta( $id, '_EventTimezone', true ) ?: wp_timezone_string() );
		$zone  = cowboy_mcp_events_tz_object( $tz );
		$start = $dates['start'] ?? new DateTimeImmutable( (string) get_post_meta( $id, '_EventStartDate', true ), $zone );
		$end   = $dates['end'] ?? new DateTimeImmutable( (string) get_post_meta( $id, '_EventEndDate', true ), $zone );
	} catch ( \Exception $e ) {
		return new WP_Error( 'invalid_date', "Event {$id} has unreadable stored dates; set start_date and end_date explicitly." );
	}
	$old = get_post_meta( $id, '_EventRecurrence', true );
	if ( $has_rule ) {
		$rec = cowboy_mcp_events_rrule_to_recurrence( (string) $a['recurrence'], $start, $end );
		if ( is_wp_error( $rec ) ) {
			return $rec;
		}
		// A single event stored with a bare "+02:00" zone cannot recur (see
		// cowboy_mcp_events_tz_normalize()): store TEC's equivalent name with the rule.
		$stored = (string) get_post_meta( $id, '_EventTimezone', true );
		$tz_fix = ( ! $dates && cowboy_mcp_events_tz_normalize( $stored ) !== $stored ) ? cowboy_mcp_events_tz_store_name( $stored ) : null;
		return [ 'recurrence' => $rec, 'tz' => $tz_fix ];
	}
	// Dates moved on a recurring event: re-stamp the stored rule set onto the new
	// dates (exclusions and the description are kept; Pro reads the time of every
	// rule/exclusion from its EventStartDate/EventEndDate).
	if ( ! is_array( $old ) || empty( $old['rules'] ) ) {
		return new WP_Error( 'tec_api_unavailable', "Could not read the stored recurrence rules of event {$id}." );
	}
	$s = $start->format( 'Y-m-d H:i:s' );
	$e = $end->format( 'Y-m-d H:i:s' );
	foreach ( [ 'rules', 'exclusions' ] as $k ) {
		foreach ( (array) ( $old[ $k ] ?? [] ) as $n => $r ) {
			if ( is_array( $r ) ) {
				$old[ $k ][ $n ]['EventStartDate'] = $s;
				$old[ $k ][ $n ]['EventEndDate']   = $e;
			}
		}
	}
	return [ 'recurrence' => $old ];
}

/**
 * Recurrence/date changes for an existing event (Pro), bypassing the ORM
 * recurrence update path: on Pro 7.8.3 it re-parents the event into a NEW
 * series (orphaning the old one) and drops the rule. Meta is written directly,
 * then TEC re-syncs its tables from it (occurrence rows are updated in place).
 */
function cowboy_mcp_events_recurrence_update( int $id, array $a, ?array $dates, bool $recurring, array|WP_Error|null $plan = null ): ?WP_Error {
	$plan ??= cowboy_mcp_events_recurrence_plan( $id, $a, $dates, $recurring );
	if ( $plan === null || is_wp_error( $plan ) ) {
		return $plan;
	}
	if ( $dates && $recurring ) {
		// The ORM path was skipped for these keys (wp_events_update strips them).
		$utc   = new DateTimeZone( 'UTC' );
		$start = $dates['start'];
		$end   = $dates['end'];
		update_post_meta( $id, '_EventStartDate', $start->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, '_EventEndDate', $end->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, '_EventStartDateUTC', $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, '_EventEndDateUTC', $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
		update_post_meta( $id, '_EventDuration', (string) ( $end->getTimestamp() - $start->getTimestamp() ) );
		update_post_meta( $id, '_EventTimezone', cowboy_mcp_events_tz_store_name( $dates['tz'] ) );
		if ( metadata_exists( 'post', $id, '_EventTimezoneAbbr' ) ) {
			update_post_meta( $id, '_EventTimezoneAbbr', $start->format( 'T' ) );
		}
		if ( $dates['all_day'] ) {
			update_post_meta( $id, '_EventAllDay', 'yes' );
		}
	}
	if ( $plan['recurrence'] === null ) {
		delete_post_meta( $id, '_EventRecurrence' );
		return cowboy_mcp_events_resync( $id );
	}
	if ( ! empty( $plan['tz'] ) ) {
		update_post_meta( $id, '_EventTimezone', $plan['tz'] );
	}
	update_post_meta( $id, '_EventRecurrence', $plan['recurrence'] );
	$err = cowboy_mcp_events_resync( $id );
	if ( is_wp_error( $err ) ) {
		return $err;
	}
	return cowboy_mcp_events_ensure_series( $id );
}
