<?php
defined( 'ABSPATH' ) || exit;

/*
 * Table/meta adapters for SEO providers beyond Yoast and Rank Math.
 * Required from tools-seo.php; each returns the adapter shape documented on
 * cowboy_mcp_seo_meta_adapter(). Filled in by the SEOPress and AIOSEO tasks.
 */

/**
 * SEOPress (all postmeta). 'yes' in _seopress_robots_index/_follow means
 * noindex/nofollow; absent = index/follow. No cornerstone concept.
 */
function cowboy_mcp_seo_seopress_adapter(): array {
	$img = static fn( string $base ): array => [
		'key'         => $base,
		'type'        => 'url',
		'companion'   => $base . '_attachment_id',
		'extra_clear' => [ $base . '_width', $base . '_height' ],
	];
	return cowboy_mcp_seo_meta_adapter( 'seopress', [
		'title'               => [ 'key' => '_seopress_titles_title' ],
		'description'         => [ 'key' => '_seopress_titles_desc' ],
		'focus_keyword'       => [ 'key' => '_seopress_analysis_target_kw' ],
		'noindex'             => [ 'key' => '_seopress_robots_index', 'type' => 'flag', 'on' => 'yes' ],
		'nofollow'            => [ 'key' => '_seopress_robots_follow', 'type' => 'flag', 'on' => 'yes' ],
		'canonical_url'       => [ 'key' => '_seopress_robots_canonical', 'type' => 'url' ],
		'og_title'            => [ 'key' => '_seopress_social_fb_title' ],
		'og_description'      => [ 'key' => '_seopress_social_fb_desc' ],
		'og_image'            => $img( '_seopress_social_fb_img' ),
		'twitter_title'       => [ 'key' => '_seopress_social_twitter_title' ],
		'twitter_description' => [ 'key' => '_seopress_social_twitter_desc' ],
		'twitter_image'       => $img( '_seopress_social_twitter_img' ),
	], static fn( int $post_id ): array => [ 'seo_score' => null, 'readability_score' => null ], '__return_true' );
}

/** AIOSEO post model class when its API is usable, else null. */
function cowboy_mcp_seo_aioseo_model(): ?string {
	$class = '\AIOSEO\Plugin\Common\Models\Post';
	return ( class_exists( $class ) && method_exists( $class, 'savePost' ) && method_exists( $class, 'getPost' ) ) ? $class : null;
}

/** Raw aioseo_posts row as an array ([] when absent). */
function cowboy_mcp_seo_aioseo_row( int $post_id ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'aioseo_posts';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d", $post_id ), ARRAY_A );
	return is_array( $row ) ? $row : [];
}

/**
 * All in One SEO (table-backed). Writes go through AIOSEO's own
 * Models\Post::savePost() so its postmeta mirror and OG recompute stay in sync.
 * Per-post robots only apply when robots_default is false.
 */
function cowboy_mcp_seo_aioseo_adapter(): array {
	$read = static function ( int $post_id ): array {
		$r    = cowboy_mcp_seo_aioseo_row( $post_id );
		$text = static fn( $v ) => ( $v === null || $v === '' ) ? null : (string) $v;
		$kp   = json_decode( (string) ( $r['keyphrases'] ?? '' ), true );
		$own  = empty( $r['robots_default'] ) && $r !== [];
		return [
			'title'               => $text( $r['title'] ?? null ),
			'description'         => $text( $r['description'] ?? null ),
			'focus_keyword'       => $text( $kp['focus']['keyphrase'] ?? null ),
			'noindex'             => $own && ! empty( $r['robots_noindex'] ),
			'nofollow'            => $own && ! empty( $r['robots_nofollow'] ),
			'canonical_url'       => $text( $r['canonical_url'] ?? null ),
			'og_title'            => $text( $r['og_title'] ?? null ),
			'og_description'      => $text( $r['og_description'] ?? null ),
			'og_image'            => ( $r['og_image_type'] ?? '' ) === 'custom' ? $text( $r['og_image_custom_url'] ?? null ) : null,
			'twitter_title'       => $text( $r['twitter_title'] ?? null ),
			'twitter_description' => $text( $r['twitter_description'] ?? null ),
			'twitter_image'       => ( $r['twitter_image_type'] ?? '' ) === 'custom' ? $text( $r['twitter_image_custom_url'] ?? null ) : null,
			'cornerstone'         => ! empty( $r['pillar_content'] ),
		];
	};

	$write_many = static function ( int $post_id, array $writes ) use ( $read ): array {
		$model = cowboy_mcp_seo_aioseo_model();
		if ( ! $model ) {
			return array_fill_keys( array_keys( $writes ), 'AIOSEO API unavailable (Models\Post::savePost missing).' );
		}
		$row  = cowboy_mcp_seo_aioseo_row( $post_id );
		$data = [];
		foreach ( $writes as $field => $value ) {
			switch ( $field ) {
				case 'title':
				case 'description':
				case 'og_title':
				case 'og_description':
					$data[ $field ] = $value;
					break;
				case 'twitter_title':
				case 'twitter_description':
					$data[ $field ]           = $value;
					$data['twitter_use_og']   = false;
					break;
				case 'focus_keyword':
					$kp = json_decode( (string) ( $row['keyphrases'] ?? '' ), true );
					$kp = is_array( $kp ) ? $kp : [];
					$kp['focus'] = array_merge( (array) ( $kp['focus'] ?? [] ), [ 'keyphrase' => (string) $value ] );
					$data['keyphrases'] = $kp;
					break;
				case 'canonical_url':
					$data['canonicalUrl'] = $value;
					break;
				case 'og_image':
					$data['og_image_type']       = $value === '' ? 'default' : 'custom';
					$data['og_image_custom_url'] = $value;
					break;
				case 'twitter_image':
					$data['twitter_image_type']       = $value === '' ? 'default' : 'custom';
					$data['twitter_image_custom_url'] = $value;
					$data['twitter_use_og']           = false;
					break;
				case 'cornerstone':
					$data['pillar_content'] = (bool) $value;
					break;
			}
		}
		if ( array_key_exists( 'noindex', $writes ) || array_key_exists( 'nofollow', $writes ) ) {
			$current  = $read( $post_id );
			$noindex  = array_key_exists( 'noindex', $writes ) ? (bool) $writes['noindex'] : $current['noindex'];
			$nofollow = array_key_exists( 'nofollow', $writes ) ? (bool) $writes['nofollow'] : $current['nofollow'];
			$other    = false;
			foreach ( [ 'robots_noarchive', 'robots_nosnippet', 'robots_noimageindex', 'robots_noodp', 'robots_notranslate' ] as $col ) {
				if ( ! empty( $row[ $col ] ) ) {
					$other = true; // per-post directives beyond index/follow — keep custom robots
				}
			}
			foreach ( [ 'robots_max_snippet', 'robots_max_videopreview' ] as $col ) {
				if ( (int) ( $row[ $col ] ?? 0 ) > 0 ) { // -1 is AIOSEO's "no limit" default
					$other = true;
				}
			}
			$img = (string) ( $row['robots_max_imagepreview'] ?? '' );
			if ( $img !== '' && $img !== 'large' ) {
				$other = true;
			}
			$data['default']  = ! ( $noindex || $nofollow || $other );
			$data['noindex']  = $noindex;
			$data['nofollow'] = $nofollow;
		}
		try {
			$result = $model::savePost( $post_id, $data );
		} catch ( \Throwable $e ) {
			return array_fill_keys( array_keys( $writes ), 'AIOSEO rejected the update: ' . $e->getMessage() );
		}
		if ( is_string( $result ) && $result !== '' ) {
			return array_fill_keys( array_keys( $writes ), 'AIOSEO could not save: ' . $result );
		}
		if ( $result === false ) { // savePost() returns false only for empty data (nothing was written)
			return array_fill_keys( array_keys( $writes ), 'AIOSEO could not save: nothing to write.' );
		}
		return [];
	};

	return [
		'provider'   => 'aioseo',
		'supports'   => array_keys( COWBOY_MCP_SEO_FIELDS ),
		'read'       => $read,
		'write_many' => $write_many,
		'scores'     => static function ( int $post_id ): array {
			$r = cowboy_mcp_seo_aioseo_row( $post_id );
			return [ 'seo_score' => isset( $r['seo_score'] ) && $r['seo_score'] !== '' ? (int) $r['seo_score'] : null, 'readability_score' => null ];
		},
		'refresh'    => '__return_true',
	];
}
