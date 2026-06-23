<?php
/**
 * Cowboy MCP – Core-API compatibility shims.
 *
 * Reimplements the handful of WordPress functions/classes that live ONLY in
 * wp-admin/includes/*.php, using exclusively wp-includes (always-loaded) core
 * functions plus $wpdb. This lets the MCP REST endpoint perform plugin, user,
 * media and site-health operations WITHOUT ever `require_once`-ing a core
 * wp-admin file in request context.
 *
 * Each method mirrors the behavior of its core counterpart as closely as is
 * practical; divergences are noted inline.
 *
 * @package Cowboy_MCP
 */

defined( 'ABSPATH' ) || exit;

// Cowboy_MCP_Compat reimplements WordPress *core* functions, so by design it refers
// to core globals by their real names: it fires core hooks (activate_plugin,
// deleted_user, intermediate_image_sizes_advanced, …), writes core options
// (active_plugins, active_sitewide_plugins) and defines the core WP_SANDBOX_SCRAPING
// constant. Prefixing any of those would break WordPress and other plugins, so the
// global-prefix rule does not apply here. The only global symbol this file actually
// DEFINES — the class below — is correctly prefixed Cowboy_MCP_.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// It also performs the same direct DB reads/writes core does (deleting a user touches the
// posts, links and usermeta tables) — inherent, uncacheable operations, and every value is
// bound via $wpdb->prepare()/the $wpdb->update()/delete() format arrays.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Cowboy_MCP_Compat {

	/** @var array|null Per-request cache of scanned plugins. */
	private static ?array $plugins_cache = null;

	/* ============================================================
	 * Paths — derived from plugin_dir_path() (COWBOY_MCP_PATH) and
	 * wp_upload_dir() instead of the WP_CONTENT_DIR / WP_PLUGIN_DIR / ABSPATH
	 * constants, per WP.org "determine file/directory locations correctly".
	 * Assumes the standard layout (this plugin lives in wp-content/plugins);
	 * correct on virtually all installs.
	 * ============================================================ */

	/** Absolute path to the plugins directory (no trailing slash). */
	public static function plugins_dir(): string {
		return dirname( untrailingslashit( COWBOY_MCP_PATH ) );
	}

	/** Absolute path to wp-content (no trailing slash). */
	public static function content_dir(): string {
		return dirname( self::plugins_dir() );
	}

	/** Absolute path to the WordPress install root (trailing slash, mirroring ABSPATH). */
	public static function wp_root(): string {
		return trailingslashit( dirname( self::content_dir() ) );
	}

	/* ============================================================
	 * Plugins  (replaces wp-admin/includes/plugin.php)
	 * ============================================================ */

	/**
	 * Require-free reimplementation of core get_plugins().
	 *
	 * Scans the plugins directory (top-level .php files + one level of sub-directories)
	 * and parses each plugin header via core get_file_data().
	 *
	 * @return array<string,array> Map of "dir/file.php" => header data.
	 */
	public static function get_plugins(): array {
		if ( self::$plugins_cache !== null ) {
			return self::$plugins_cache;
		}

		$wp_plugins  = [];
		$plugin_root = self::plugins_dir();

		$plugin_files = [];
		$dir          = @opendir( $plugin_root );
		if ( $dir ) {
			while ( ( $file = readdir( $dir ) ) !== false ) {
				if ( str_starts_with( $file, '.' ) ) {
					continue;
				}
				if ( is_dir( $plugin_root . '/' . $file ) ) {
					$subdir = @opendir( $plugin_root . '/' . $file );
					if ( $subdir ) {
						while ( ( $subfile = readdir( $subdir ) ) !== false ) {
							if ( str_starts_with( $subfile, '.' ) ) {
								continue;
							}
							if ( str_ends_with( $subfile, '.php' ) ) {
								$plugin_files[] = "$file/$subfile";
							}
						}
						closedir( $subdir );
					}
				} elseif ( str_ends_with( $file, '.php' ) ) {
					$plugin_files[] = $file;
				}
			}
			closedir( $dir );
		}

		foreach ( $plugin_files as $plugin_file ) {
			$path = "$plugin_root/$plugin_file";
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$data = self::get_plugin_data( $path );
			if ( empty( $data['Name'] ) ) {
				continue;
			}
			$wp_plugins[ $plugin_file ] = $data;
		}

		uasort(
			$wp_plugins,
			static fn( $a, $b ) => strnatcasecmp( $a['Name'], $b['Name'] )
		);

		self::$plugins_cache = $wp_plugins;
		return $wp_plugins;
	}

	/**
	 * Require-free reimplementation of core get_plugin_data() header parsing.
	 *
	 * @param string $plugin_file Absolute path to the plugin's main file.
	 * @return array Header fields (Name, Version, Description, Author, ...).
	 */
	public static function get_plugin_data( string $plugin_file ): array {
		$headers = [
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'TextDomain'  => 'Text Domain',
			'DomainPath'  => 'Domain Path',
			'Network'     => 'Network',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
			'UpdateURI'   => 'Update URI',
		];

		// get_file_data() is core (wp-includes/functions.php).
		$data = get_file_data( $plugin_file, $headers, 'plugin' );

		$data['Network'] = ( strtolower( $data['Network'] ) === 'true' );
		// Mirror core convenience aliases.
		$data['Title']      = $data['Name'];
		$data['AuthorName'] = $data['Author'];

		return $data;
	}

	/**
	 * Whether a plugin is active (single-site or network-wide).
	 */
	public static function is_plugin_active( string $plugin ): bool {
		return in_array( $plugin, (array) get_option( 'active_plugins', [] ), true )
			|| self::is_plugin_active_for_network( $plugin );
	}

	/**
	 * Whether a plugin is network-activated (multisite only).
	 */
	public static function is_plugin_active_for_network( string $plugin ): bool {
		if ( ! is_multisite() ) {
			return false;
		}
		$plugins = get_site_option( 'active_sitewide_plugins' );
		return isset( $plugins[ $plugin ] );
	}

	/**
	 * Require-free, fatal-safe reimplementation of core activate_plugin().
	 *
	 * Safety mirrors core: the plugin file is loaded (sandbox) BEFORE the
	 * active_plugins option is committed, so a plugin that fatals on load never
	 * gets marked active. PHP 7+ throwables are additionally caught and returned
	 * as a clean WP_Error instead of a 500.
	 *
	 * @return null|WP_Error Null on success (matching core), WP_Error on failure.
	 */
	public static function activate_plugin( string $plugin ) {
		$plugin = plugin_basename( trim( $plugin ) ); // core
		$file   = self::plugins_dir() . '/' . $plugin;

		if ( str_contains( $plugin, '..' ) || ! file_exists( $file ) ) {
			return new WP_Error( 'plugin_not_found', "Plugin file '{$plugin}' does not exist." );
		}
		$data = self::get_plugin_data( $file );
		if ( empty( $data['Name'] ) ) {
			return new WP_Error( 'plugin_invalid', "File '{$plugin}' has no valid plugin header." );
		}

		if ( self::is_plugin_active( $plugin ) ) {
			return null; // already active
		}

		// Network-only plugins on multisite must be network-activated (mirrors core).
		$network_wide = is_multisite() && ! empty( $data['Network'] );

		// Sandbox-load the plugin before committing activation. Output buffering keeps
		// any stray output emitted on load from corrupting the JSON-RPC response; a
		// throwable aborts WITHOUT committing, so a broken plugin never gets activated.
		if ( ! defined( 'WP_SANDBOX_SCRAPING' ) ) {
			define( 'WP_SANDBOX_SCRAPING', true );
		}
		ob_start();
		try {
			include_once $file;
		} catch ( \Throwable $e ) {
			ob_end_clean();
			return new WP_Error( 'plugin_fatal', 'Plugin could not be activated — it caused an error on load: ' . $e->getMessage() );
		}

		if ( $network_wide ) {
			$current = (array) get_site_option( 'active_sitewide_plugins', [] );
			if ( ! isset( $current[ $plugin ] ) ) {
				$current[ $plugin ] = time();
				update_site_option( 'active_sitewide_plugins', $current );
				do_action( 'activate_plugin', $plugin, true );
				do_action( "activate_{$plugin}", true );
				do_action( 'activated_plugin', $plugin, true );
			}
		} else {
			$current = (array) get_option( 'active_plugins', [] );
			if ( ! in_array( $plugin, $current, true ) ) {
				$current[] = $plugin;
				sort( $current );
				update_option( 'active_plugins', $current );
				do_action( 'activate_plugin', $plugin, false );
				do_action( "activate_{$plugin}", false );
				do_action( 'activated_plugin', $plugin, false );
			}
		}
		ob_end_clean();

		return null;
	}

	/**
	 * Require-free reimplementation of core deactivate_plugins() (single plugin).
	 */
	public static function deactivate_plugin( string $plugin ): void {
		$plugin = plugin_basename( trim( $plugin ) );

		// Network-wide deactivation if the plugin is network-active (multisite).
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', [] );
			if ( isset( $network[ $plugin ] ) ) {
				do_action( 'deactivate_plugin', $plugin, true );
				unset( $network[ $plugin ] );
				update_site_option( 'active_sitewide_plugins', $network );
				do_action( "deactivate_{$plugin}", true );
				do_action( 'deactivated_plugin', $plugin, true );
				return;
			}
		}

		$current = (array) get_option( 'active_plugins', [] );
		$key     = array_search( $plugin, $current, true );
		if ( $key === false ) {
			return;
		}

		do_action( 'deactivate_plugin', $plugin, false );
		unset( $current[ $key ] );
		$current = array_values( $current );
		update_option( 'active_plugins', $current );
		do_action( "deactivate_{$plugin}", false );
		do_action( 'deactivated_plugin', $plugin, false );
	}

	/* ============================================================
	 * Users  (replaces wp-admin/includes/user.php :: wp_delete_user)
	 * ============================================================ */

	/**
	 * Require-free reimplementation of core wp_delete_user().
	 *
	 * Faithfully mirrors core: fires delete_user, deletes or reassigns the
	 * user's posts and links, deletes user meta and the user row, clears caches,
	 * and fires deleted_user. Uses only wp-includes core functions + $wpdb.
	 *
	 * @param int      $id       User ID to delete.
	 * @param int|null $reassign User ID to reassign content to, or null to delete content.
	 * @return bool True on success, false if the user does not exist.
	 */
	public static function delete_user( int $id, ?int $reassign = null ): bool {
		global $wpdb;

		$user = new WP_User( $id );
		if ( ! $user->exists() ) {
			return false;
		}

		if ( $reassign !== null ) {
			$reassign = (int) $reassign;
		}

		/** Fires immediately before a user is deleted from the database. */
		do_action( 'delete_user', $id, $reassign, $user );

		if ( $reassign === null ) {
			// Delete the user's content.
			$post_types_to_delete = [];
			foreach ( get_post_types( [], 'objects' ) as $post_type ) {
				if ( $post_type->delete_with_user ) {
					$post_types_to_delete[] = $post_type->name;
				} elseif ( $post_type->delete_with_user === null && post_type_supports( $post_type->name, 'author' ) ) {
					$post_types_to_delete[] = $post_type->name;
				}
			}
			$post_types_to_delete = apply_filters( 'post_types_to_delete_with_user', $post_types_to_delete, $id );

			if ( ! empty( $post_types_to_delete ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $post_types_to_delete ), '%s' ) );
				$post_ids = $wpdb->get_col(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a list of %s tokens, bound below.
						"SELECT ID FROM {$wpdb->posts} WHERE post_author = %d AND post_type IN ( $placeholders )",
						array_merge( [ $id ], $post_types_to_delete )
					)
				);
				foreach ( (array) $post_ids as $post_id ) {
					wp_delete_post( (int) $post_id, true );
				}
			}

			// Delete the user's links/bookmarks.
			$link_ids = $wpdb->get_col( $wpdb->prepare( "SELECT link_id FROM {$wpdb->links} WHERE link_owner = %d", $id ) );
			foreach ( (array) $link_ids as $link_id ) {
				self::delete_link( (int) $link_id );
			}
		} else {
			// Reassign the user's content.
			$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_author = %d", $id ) );
			$wpdb->update( $wpdb->posts, [ 'post_author' => $reassign ], [ 'post_author' => $id ], [ '%d' ], [ '%d' ] );
			foreach ( (array) $post_ids as $post_id ) {
				clean_post_cache( (int) $post_id );
			}

			$link_ids = $wpdb->get_col( $wpdb->prepare( "SELECT link_id FROM {$wpdb->links} WHERE link_owner = %d", $id ) );
			$wpdb->update( $wpdb->links, [ 'link_owner' => $reassign ], [ 'link_owner' => $id ], [ '%d' ], [ '%d' ] );
			foreach ( (array) $link_ids as $link_id ) {
				clean_bookmark_cache( (int) $link_id );
			}
		}

		// Delete user meta by meta id (fires the usual meta hooks).
		$meta_ids = $wpdb->get_col( $wpdb->prepare( "SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id = %d", $id ) );
		foreach ( (array) $meta_ids as $mid ) {
			delete_metadata_by_mid( 'user', $mid );
		}

		// Remove any residual meta rows then the user row.
		$wpdb->delete( $wpdb->usermeta, [ 'user_id' => $id ], [ '%d' ] );
		$wpdb->delete( $wpdb->users, [ 'ID' => $id ], [ '%d' ] );

		clean_user_cache( $user );

		/** Fires immediately after a user is deleted from the database. */
		do_action( 'deleted_user', $id, $reassign, $user );

		return true;
	}

	/**
	 * Require-free reimplementation of core wp_delete_link() (used by delete_user).
	 */
	private static function delete_link( int $link_id ): void {
		global $wpdb;
		do_action( 'delete_link', $link_id );
		wp_delete_object_term_relationships( $link_id, get_object_taxonomies( 'link' ) );
		$wpdb->delete( $wpdb->links, [ 'link_id' => $link_id ], [ '%d' ] );
		do_action( 'deleted_link', $link_id );
		clean_bookmark_cache( $link_id );
	}

	/* ============================================================
	 * Media  (replaces media.php :: media_handle_sideload and
	 *         image.php :: wp_generate_attachment_metadata)
	 * ============================================================ */

	/**
	 * Require-free reimplementation of core media_handle_sideload().
	 *
	 * Validates the file type against allowed mimes, moves it into the uploads
	 * directory, inserts the attachment post, and generates metadata.
	 *
	 * @param array       $file      ['name' => ..., 'tmp_name' => ...].
	 * @param int         $post_id   Parent post ID (0 for none).
	 * @param string|null $desc      Optional attachment title/description.
	 * @param array       $post_data Optional overrides for the attachment post array.
	 * @return int|WP_Error Attachment ID on success.
	 */
	public static function handle_sideload( array $file, int $post_id = 0, ?string $desc = null, array $post_data = [] ) {
		if ( empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return new WP_Error( 'sideload_error', 'Temporary upload file is missing or unreadable.' );
		}

		$name = $file['name'] ?? wp_basename( $file['tmp_name'] );

		// Validate file type against allowed mimes (core).
		$filetype = wp_check_filetype_and_ext( $file['tmp_name'], $name );
		$ext      = $filetype['ext'];
		$type     = $filetype['type'];
		if ( ! empty( $filetype['proper_filename'] ) ) {
			$name = $filetype['proper_filename'];
		}
		if ( ! $type || ! $ext ) {
			wp_delete_file( $file['tmp_name'] );
			return new WP_Error( 'invalid_type', 'Sorry, this file type is not permitted for security reasons.' );
		}

		// Resolve the upload target directory.
		$time = ( $post_id && get_post( $post_id ) ) ? get_post_time( 'Y/m', true, $post_id ) : null;
		$uploads = wp_upload_dir( $time );
		if ( ! empty( $uploads['error'] ) ) {
			return new WP_Error( 'upload_dir_error', $uploads['error'] );
		}

		$filename = wp_unique_filename( $uploads['path'], $name );
		$new_file = $uploads['path'] . "/$filename";

		// Move the temp file into uploads (native, atomic where possible).
		// WP_Filesystem::move() would require loading wp-admin/includes/file.php,
		// which this plugin never does (see Cowboy_MCP_Compat); native rename is
		// atomic here and falls back to copy+delete.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
		if ( ! @rename( $file['tmp_name'], $new_file ) ) {
			if ( ! @copy( $file['tmp_name'], $new_file ) ) {
				return new WP_Error( 'move_failed', 'Failed to move the uploaded file into the uploads directory.' );
			}
			wp_delete_file( $file['tmp_name'] );
		}

		// Match WordPress's file permissions. WP_Filesystem::chmod() would require
		// loading wp-admin/includes/file.php, which this plugin never does.
		$stat  = @stat( dirname( $new_file ) );
		$perms = $stat ? ( $stat['mode'] & 0000666 ) : 0644;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		@chmod( $new_file, $perms );

		$url   = $uploads['url'] . "/$filename";
		$title = $desc ?: preg_replace( '/\.[^.]+$/', '', wp_basename( $filename ) );

		$attachment = array_merge(
			[
				'post_mime_type' => $type,
				'guid'           => $url,
				'post_title'     => $title,
				'post_content'   => '',
			],
			$post_data
		);

		$attach_id = wp_insert_attachment( $attachment, $new_file, $post_id, true );
		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $new_file );
			return $attach_id;
		}

		$meta = self::generate_attachment_metadata( (int) $attach_id, $new_file );
		wp_update_attachment_metadata( $attach_id, $meta );

		return (int) $attach_id;
	}

	/**
	 * Require-free reimplementation of core wp_generate_attachment_metadata()
	 * for image attachments. Uses the core WP_Image_Editor (wp-includes) to
	 * generate the registered sub-sizes.
	 *
	 * Note: unlike core 5.3+, this does not down-scale very large originals to
	 * the "big image" threshold (no -scaled variant); the full image is kept.
	 *
	 * @return array Attachment metadata (width, height, file, sizes, image_meta).
	 */
	public static function generate_attachment_metadata( int $attachment_id, string $file ): array {
		$metadata = [];
		if ( ! file_exists( $file ) ) {
			return $metadata;
		}

		$mime = wp_get_image_mime( $file ); // core
		if ( ! $mime || ! str_starts_with( $mime, 'image/' ) ) {
			// Non-image: store filesize only, mirroring core for other types.
			$metadata['filesize'] = wp_filesize( $file ); // core
			return $metadata;
		}

		$imagesize = wp_getimagesize( $file ); // core
		if ( ! $imagesize ) {
			return $metadata;
		}

		$metadata['width']    = (int) $imagesize[0];
		$metadata['height']   = (int) $imagesize[1];
		$metadata['file']     = _wp_relative_upload_path( $file ); // core
		$metadata['filesize'] = wp_filesize( $file );
		$metadata['sizes']    = [];

		// Generate the registered intermediate sizes via the core image editor.
		$editor = wp_get_image_editor( $file ); // core
		if ( ! is_wp_error( $editor ) ) {
			$sizes = wp_get_registered_image_subsizes(); // core (5.3+)
			/** Let plugins add/remove sizes exactly as core does. */
			$sizes = apply_filters( 'intermediate_image_sizes_advanced', $sizes, $metadata, $attachment_id );
			if ( ! empty( $sizes ) ) {
				$resized = $editor->multi_resize( $sizes );
				if ( $resized ) {
					$metadata['sizes'] = $resized;
				}
			}
		}

		$metadata['image_meta'] = self::read_image_metadata( $file );

		/** Mirror core's final filter so dependent plugins still hook in. */
		return (array) apply_filters( 'wp_generate_attachment_metadata', $metadata, $attachment_id, 'create' );
	}

	/**
	 * Require-free reimplementation of core wp_read_image_metadata() (EXIF/IPTC).
	 */
	private static function read_image_metadata( string $file ): array {
		$meta = [
			'aperture'          => 0,
			'credit'            => '',
			'camera'            => '',
			'caption'           => '',
			'created_timestamp' => 0,
			'copyright'         => '',
			'focal_length'      => 0,
			'iso'               => 0,
			'shutter_speed'     => 0,
			'title'             => '',
			'orientation'       => 0,
			'keywords'          => [],
		];

		$info      = [];
		$imagesize = @getimagesize( $file, $info );
		if ( ! $imagesize ) {
			return $meta;
		}
		$image_mime = $imagesize['mime'] ?? '';

		// IPTC (APP13 marker).
		if ( is_callable( 'iptcparse' ) && ! empty( $info['APP13'] ) ) {
			$iptc = @iptcparse( $info['APP13'] );
			if ( is_array( $iptc ) ) {
				if ( ! empty( $iptc['2#105'][0] ) ) {
					$meta['title'] = trim( $iptc['2#105'][0] );
				} elseif ( ! empty( $iptc['2#005'][0] ) ) {
					$meta['title'] = trim( $iptc['2#005'][0] );
				}
				if ( ! empty( $iptc['2#120'][0] ) ) {
					$meta['caption'] = trim( $iptc['2#120'][0] );
				}
				if ( ! empty( $iptc['2#110'][0] ) ) {
					$meta['credit'] = trim( $iptc['2#110'][0] );
				} elseif ( ! empty( $iptc['2#080'][0] ) ) {
					$meta['credit'] = trim( $iptc['2#080'][0] );
				}
				if ( ! empty( $iptc['2#116'][0] ) ) {
					$meta['copyright'] = trim( $iptc['2#116'][0] );
				}
				if ( ! empty( $iptc['2#025'] ) ) {
					$meta['keywords'] = array_values( array_map( 'trim', (array) $iptc['2#025'] ) );
				}
			}
		}

		// EXIF (JPEG / TIFF only).
		if ( is_callable( 'exif_read_data' ) && in_array( $image_mime, [ 'image/jpeg', 'image/tiff' ], true ) ) {
			$exif = @exif_read_data( $file );
			if ( is_array( $exif ) ) {
				if ( empty( $meta['title'] ) && ! empty( $exif['Title'] ) ) {
					$meta['title'] = trim( $exif['Title'] );
				}
				if ( empty( $meta['caption'] ) && ! empty( $exif['ImageDescription'] ) ) {
					$meta['caption'] = trim( $exif['ImageDescription'] );
				}
				if ( ! empty( $exif['Artist'] ) ) {
					$meta['credit'] = trim( $exif['Artist'] );
				} elseif ( ! empty( $exif['Author'] ) ) {
					$meta['credit'] = trim( $exif['Author'] );
				}
				if ( ! empty( $exif['Copyright'] ) ) {
					$meta['copyright'] = trim( $exif['Copyright'] );
				}
				if ( ! empty( $exif['FNumber'] ) && is_scalar( $exif['FNumber'] ) ) {
					$meta['aperture'] = round( self::exif_frac2dec( $exif['FNumber'] ), 2 );
				}
				if ( ! empty( $exif['Model'] ) ) {
					$meta['camera'] = trim( $exif['Model'] );
				}
				if ( ! empty( $exif['DateTimeDigitized'] ) ) {
					$meta['created_timestamp'] = self::exif_date2ts( $exif['DateTimeDigitized'] );
				} elseif ( ! empty( $exif['DateTimeOriginal'] ) ) {
					$meta['created_timestamp'] = self::exif_date2ts( $exif['DateTimeOriginal'] );
				}
				if ( ! empty( $exif['FocalLength'] ) && is_scalar( $exif['FocalLength'] ) ) {
					$meta['focal_length'] = (string) self::exif_frac2dec( $exif['FocalLength'] );
				}
				if ( ! empty( $exif['ISOSpeedRatings'] ) ) {
					$iso          = is_array( $exif['ISOSpeedRatings'] ) ? reset( $exif['ISOSpeedRatings'] ) : $exif['ISOSpeedRatings'];
					$meta['iso']  = (int) $iso;
				}
				if ( ! empty( $exif['ExposureTime'] ) && is_scalar( $exif['ExposureTime'] ) ) {
					$meta['shutter_speed'] = (string) self::exif_frac2dec( $exif['ExposureTime'] );
				}
				if ( ! empty( $exif['Orientation'] ) ) {
					$meta['orientation'] = (int) $exif['Orientation'];
				}
			}
		}

		foreach ( [ 'title', 'caption', 'credit', 'copyright', 'camera' ] as $key ) {
			// Detect non-UTF-8 without WP's seems_utf8() (deprecated in 6.9) or
			// wp_is_valid_utf8() (requires 6.9; min supported is 6.2). PCRE's /u
			// flag makes preg_match() return false on a malformed UTF-8 subject.
			$is_utf8 = ( preg_match( '//u', (string) $meta[ $key ] ) !== false );
			if ( $meta[ $key ] && ! $is_utf8 ) {
				if ( function_exists( 'mb_convert_encoding' ) ) {
					$meta[ $key ] = mb_convert_encoding( $meta[ $key ], 'UTF-8', 'ISO-8859-1' );
				} elseif ( function_exists( 'iconv' ) ) {
					$meta[ $key ] = iconv( 'ISO-8859-1', 'UTF-8', $meta[ $key ] );
				}
			}
		}

		return $meta;
	}

	/** Convert an EXIF rational (e.g. "10/5") to a float. */
	private static function exif_frac2dec( $str ): float {
		if ( is_numeric( $str ) ) {
			return (float) $str;
		}
		$parts = explode( '/', (string) $str );
		if ( count( $parts ) === 2 && is_numeric( $parts[0] ) && is_numeric( $parts[1] ) && (float) $parts[1] !== 0.0 ) {
			return (float) $parts[0] / (float) $parts[1];
		}
		return 0.0;
	}

	/** Convert an EXIF date string ("YYYY:MM:DD HH:MM:SS") to a UNIX timestamp. */
	private static function exif_date2ts( $str ): int {
		if ( preg_match( '/^(\d{4}):(\d{2}):(\d{2}) (\d{2}):(\d{2}):(\d{2})/', (string) $str, $m ) ) {
			return (int) mktime( (int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1] );
		}
		return (int) strtotime( (string) $str );
	}

	/* ============================================================
	 * Site Health  (replaces class-wp-site-health.php +
	 *               class-wp-debug-data.php — rebuilt "lite" from core APIs)
	 * ============================================================ */

	/**
	 * Lite reimplementation of WP_Site_Health's tests, built entirely from core
	 * APIs and update transients. Returns the same shape the wp_site_health tool
	 * exposes: a normalized test list plus a status summary.
	 *
	 * @return array{tests:array<int,array>,summary:array{good:int,recommended:int,critical:int}}
	 */
	public static function site_health_tests(): array {
		$tests = [];

		// PHP version.
		$php = PHP_VERSION;
		if ( version_compare( $php, '7.4', '<' ) ) {
			$tests[] = self::mk_test( 'php_version', 'PHP version', 'critical', 'Performance', "Your site is running PHP {$php}, which is outdated and unsupported. Upgrade to a current PHP version." );
		} elseif ( version_compare( $php, '8.1', '<' ) ) {
			$tests[] = self::mk_test( 'php_version', 'PHP version', 'recommended', 'Performance', "Your site is running PHP {$php}. A newer version (8.1+) is recommended for performance and security." );
		} else {
			$tests[] = self::mk_test( 'php_version', 'PHP version', 'good', 'Performance', "Your site is running a current PHP version ({$php})." );
		}

		// WordPress core updates.
		$core = get_site_transient( 'update_core' );
		$core_update = is_object( $core ) && ! empty( $core->updates[0] ) && isset( $core->updates[0]->response ) && $core->updates[0]->response === 'upgrade';
		$tests[] = $core_update
			? self::mk_test( 'wordpress_version', 'WordPress version', 'critical', 'Security', 'A WordPress core update is available. Keeping core up to date is critical for security.' )
			: self::mk_test( 'wordpress_version', 'WordPress version', 'good', 'Security', 'Your version of WordPress is up to date (or no update has been detected).' );

		// Plugin updates.
		$plugin_updates = get_site_transient( 'update_plugins' );
		$plugin_count   = ( is_object( $plugin_updates ) && ! empty( $plugin_updates->response ) && is_array( $plugin_updates->response ) ) ? count( $plugin_updates->response ) : 0;
		$tests[] = $plugin_count > 0
			? self::mk_test( 'plugin_updates', 'Plugin updates', 'recommended', 'Security', "{$plugin_count} plugin update(s) available. Outdated plugins are a common security risk." )
			: self::mk_test( 'plugin_updates', 'Plugin updates', 'good', 'Security', 'All plugins are up to date (or no updates detected).' );

		// Theme updates.
		$theme_updates = get_site_transient( 'update_themes' );
		$theme_count   = ( is_object( $theme_updates ) && ! empty( $theme_updates->response ) && is_array( $theme_updates->response ) ) ? count( $theme_updates->response ) : 0;
		$tests[] = $theme_count > 0
			? self::mk_test( 'theme_updates', 'Theme updates', 'recommended', 'Security', "{$theme_count} theme update(s) available." )
			: self::mk_test( 'theme_updates', 'Theme updates', 'good', 'Security', 'All themes are up to date (or no updates detected).' );

		// HTTPS.
		$tests[] = is_ssl()
			? self::mk_test( 'https_status', 'HTTPS status', 'good', 'Security', 'Your site is being served over HTTPS.' )
			: self::mk_test( 'https_status', 'HTTPS status', 'recommended', 'Security', 'Your site is not using HTTPS. A valid SSL certificate is strongly recommended.' );

		// SSL support for outbound requests.
		$tests[] = wp_http_supports( [ 'ssl' ] )
			? self::mk_test( 'ssl_support', 'Secure communication', 'good', 'Security', 'Your server can communicate securely with other services.' )
			: self::mk_test( 'ssl_support', 'Secure communication', 'recommended', 'Security', 'Your server may be unable to make secure (HTTPS) outbound requests.' );

		// Debug display in production.
		$debug_display = defined( 'WP_DEBUG' ) && WP_DEBUG && ( ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY );
		$tests[] = $debug_display
			? self::mk_test( 'debug_display', 'Debug output', 'recommended', 'Security', 'WP_DEBUG_DISPLAY appears to be enabled. Debug output should not be shown on production sites.' )
			: self::mk_test( 'debug_display', 'Debug output', 'good', 'Security', 'Debug output is not being displayed publicly.' );

		// Memory limit.
		$limit_bytes = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
		$tests[] = ( $limit_bytes > 0 && $limit_bytes < 64 * MB_IN_BYTES )
			? self::mk_test( 'memory_limit', 'PHP memory limit', 'recommended', 'Performance', 'The PHP memory limit is below 64 MB, which may be insufficient for some sites.' )
			: self::mk_test( 'memory_limit', 'PHP memory limit', 'good', 'Performance', 'The PHP memory limit is sufficient.' );

		// Recommended PHP extensions.
		$recommended_ext = [ 'curl', 'dom', 'mbstring', 'imagick', 'gd', 'json', 'mysqli', 'openssl' ];
		$missing         = array_values( array_filter( $recommended_ext, static fn( $e ) => ! extension_loaded( $e ) ) );
		// imagick OR gd is enough for images.
		if ( in_array( 'imagick', $missing, true ) && ! in_array( 'gd', $missing, true ) ) {
			$missing = array_values( array_diff( $missing, [ 'imagick' ] ) );
		}
		$tests[] = empty( $missing )
			? self::mk_test( 'php_extensions', 'PHP modules', 'good', 'Performance', 'All recommended PHP modules are installed.' )
			: self::mk_test( 'php_extensions', 'PHP modules', 'recommended', 'Performance', 'Missing recommended PHP module(s): ' . implode( ', ', $missing ) . '.' );

		// Summary.
		$summary = [ 'good' => 0, 'recommended' => 0, 'critical' => 0 ];
		foreach ( $tests as $t ) {
			if ( isset( $summary[ $t['status'] ] ) ) {
				$summary[ $t['status'] ]++;
			}
		}

		return [ 'tests' => $tests, 'summary' => $summary ];
	}

	/** Build a normalized Site Health test result row. */
	private static function mk_test( string $test, string $label, string $status, string $badge, string $description ): array {
		return [
			'test'        => $test,
			'label'       => $label,
			'status'      => $status,
			'badge'       => $badge,
			'description' => $description,
		];
	}

	/**
	 * Lite reimplementation of WP_Debug_Data::debug_data(), assembled from core
	 * APIs. Returns the same nested { section => { label, fields:{ key:{label,value} } } }
	 * structure the wp_site_health tool flattens for output.
	 *
	 * @return array<string,array>
	 */
	public static function debug_data(): array {
		global $wpdb;

		$theme        = wp_get_theme();
		$parent       = $theme->parent();
		$active       = (array) get_option( 'active_plugins', [] );
		$all_plugins  = self::get_plugins();

		$active_list   = [];
		$inactive_list = [];
		foreach ( $all_plugins as $file => $data ) {
			$line = sprintf( '%s (v%s)', $data['Name'], $data['Version'] ?: '—' );
			if ( in_array( $file, $active, true ) ) {
				$active_list[ $file ] = [ 'label' => $data['Name'], 'value' => $line ];
			} else {
				$inactive_list[ $file ] = [ 'label' => $data['Name'], 'value' => $line ];
			}
		}

		$constants = [];
		foreach ( [ 'WP_DEBUG', 'WP_DEBUG_DISPLAY', 'WP_DEBUG_LOG', 'SCRIPT_DEBUG', 'WP_CACHE', 'CONCATENATE_SCRIPTS', 'COMPRESS_SCRIPTS', 'COMPRESS_CSS', 'WP_ENVIRONMENT_TYPE', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS' ] as $const ) {
			$value             = defined( $const ) ? constant( $const ) : null;
			$constants[ $const ] = [
				'label' => $const,
				'value' => $value === null ? 'undefined' : ( is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value ),
			];
		}

		return [
			'wp-core'    => [
				'label'  => 'WordPress',
				'fields' => [
					'version'      => [ 'label' => 'Version', 'value' => get_bloginfo( 'version' ) ],
					'site_language'=> [ 'label' => 'Site Language', 'value' => get_locale() ],
					'home_url'     => [ 'label' => 'Home URL', 'value' => home_url() ],
					'site_url'     => [ 'label' => 'Site URL', 'value' => site_url() ],
					'permalink'    => [ 'label' => 'Permalink structure', 'value' => get_option( 'permalink_structure' ) ?: 'Default' ],
					'https_status' => [ 'label' => 'Is HTTPS', 'value' => is_ssl() ? 'Yes' : 'No' ],
					'multisite'    => [ 'label' => 'Multisite', 'value' => is_multisite() ? 'Yes' : 'No' ],
					'environment'  => [ 'label' => 'Environment type', 'value' => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown' ],
					'user_count'   => [ 'label' => 'User count', 'value' => (string) ( count_users()['total_users'] ?? 0 ) ],
				],
			],
			'wp-server'  => [
				'label'  => 'Server',
				'fields' => [
					'php_version'        => [ 'label' => 'PHP version', 'value' => PHP_VERSION ],
					'php_sapi'           => [ 'label' => 'PHP SAPI', 'value' => PHP_SAPI ],
					'server_software'    => [ 'label' => 'Web server', 'value' => sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? 'unknown' ) ) ],
					'max_execution_time' => [ 'label' => 'Max execution time', 'value' => (string) ini_get( 'max_execution_time' ) ],
					'memory_limit'       => [ 'label' => 'PHP memory limit', 'value' => (string) ini_get( 'memory_limit' ) ],
					'max_input_vars'     => [ 'label' => 'Max input vars', 'value' => (string) ini_get( 'max_input_vars' ) ],
					'upload_max'         => [ 'label' => 'Upload max filesize', 'value' => (string) ini_get( 'upload_max_filesize' ) ],
					'post_max'           => [ 'label' => 'Post max size', 'value' => (string) ini_get( 'post_max_size' ) ],
					'curl_version'       => [ 'label' => 'cURL version', 'value' => function_exists( 'curl_version' ) ? ( curl_version()['version'] ?? 'enabled' ) : 'not available' ],
				],
			],
			'wp-database'=> [
				'label'  => 'Database',
				'fields' => [
					'extension'      => [ 'label' => 'Extension', 'value' => 'mysqli' ],
					'server_version' => [ 'label' => 'Server version', 'value' => $wpdb->db_version() ],
					'database_name'  => [ 'label' => 'Database name', 'value' => defined( 'DB_NAME' ) ? DB_NAME : 'unknown' ],
					'table_prefix'   => [ 'label' => 'Table prefix', 'value' => $wpdb->prefix ],
					'charset'        => [ 'label' => 'Charset', 'value' => $wpdb->charset ],
					'collate'        => [ 'label' => 'Collation', 'value' => $wpdb->collate ],
				],
			],
			'wp-active-theme' => [
				'label'  => 'Active Theme',
				'fields' => [
					'name'    => [ 'label' => 'Name', 'value' => $theme->get( 'Name' ) ],
					'version' => [ 'label' => 'Version', 'value' => $theme->get( 'Version' ) ],
					'author'  => [ 'label' => 'Author', 'value' => wp_strip_all_tags( (string) $theme->get( 'Author' ) ) ],
					'parent'  => [ 'label' => 'Parent theme', 'value' => $parent ? $parent->get( 'Name' ) : 'none' ],
				],
			],
			'wp-plugins-active'   => [
				'label'  => 'Active Plugins',
				'fields' => $active_list ?: [ 'none' => [ 'label' => 'None', 'value' => 'No active plugins' ] ],
			],
			'wp-plugins-inactive' => [
				'label'  => 'Inactive Plugins',
				'fields' => $inactive_list ?: [ 'none' => [ 'label' => 'None', 'value' => 'No inactive plugins' ] ],
			],
			'wp-media'   => [
				'label'  => 'Media Handling',
				'fields' => [
					'image_editor' => [ 'label' => 'Image editor', 'value' => extension_loaded( 'imagick' ) ? 'Imagick' : ( extension_loaded( 'gd' ) ? 'GD' : 'none' ) ],
					'gd_version'   => [ 'label' => 'GD', 'value' => function_exists( 'gd_info' ) ? ( gd_info()['GD Version'] ?? 'enabled' ) : 'not available' ],
					'imagick'      => [ 'label' => 'Imagick', 'value' => extension_loaded( 'imagick' ) ? 'enabled' : 'not available' ],
				],
			],
			'wp-constants' => [
				'label'  => 'WordPress Constants',
				'fields' => $constants,
			],
		];
	}
}
