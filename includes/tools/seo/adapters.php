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
