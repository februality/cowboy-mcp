<?php
/**
 * Cowboy MCP – WP.org package installer
 *
 * Native install/update/delete of plugins and themes from WordPress.org,
 * reimplementing the wp-admin-only upgrader machinery (plugins_api,
 * download_url, unzip_file, Plugin_Upgrader) with wp-includes core +
 * ZipArchive only. See docs spec 2026-07-20-wporg-installer-design.md.
 */

defined( 'ABSPATH' ) || exit;

class Cowboy_MCP_Installer {

	/** Tools handled by this class (self-journaled; bypassed in Rollback::begin). */
	const TOOLS = [
		'wp_install_plugin', 'wp_update_plugin', 'wp_delete_plugin',
		'wp_install_theme', 'wp_update_theme', 'wp_delete_theme',
	];

	const API_PLUGINS = 'https://api.wordpress.org/plugins/info/1.2/';
	const API_THEMES  = 'https://api.wordpress.org/themes/info/1.2/';

	/* ── Working directory ─────────────────────────────────── */

	/** Guarded backups/temp dir (mirrors Cowboy_MCP_Checkpoint::dir()). */
	public static function backups_dir(): string|WP_Error {
		$base = wp_upload_dir()['basedir'] ?? '';
		if ( $base === '' ) {
			return new WP_Error( 'fs_not_writable', 'Uploads directory unavailable.' );
		}
		$dir = $base . '/cowboy-mcp/backups';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'fs_not_writable', 'Could not create the backups directory.' );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/* ── WP.org info API (plugins_api/themes_api replacement) ── */

	public static function plugin_info( string $slug, ?string $version = null ): array|WP_Error {
		return self::wporg_info( self::API_PLUGINS, 'plugin_information', $slug, $version );
	}

	public static function theme_info( string $slug, ?string $version = null ): array|WP_Error {
		return self::wporg_info( self::API_THEMES, 'theme_information', $slug, $version );
	}

	private static function wporg_info( string $api, string $action, string $slug, ?string $version ): array|WP_Error {
		$query = [
			'action'  => $action,
			'request' => [
				'slug'   => $slug,
				'fields' => [ 'sections' => 0, 'versions' => $version !== null ? 1 : 0 ],
			],
		];
		$r = wp_safe_remote_get( $api . '?' . http_build_query( $query ), [ 'timeout' => 15 ] );
		if ( is_wp_error( $r ) ) {
			return new WP_Error( 'wporg_unreachable', 'Could not reach api.wordpress.org: ' . $r->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$body = json_decode( (string) wp_remote_retrieve_body( $r ), true );
		if ( $code === 404 || isset( $body['error'] ) ) {
			return new WP_Error( 'not_on_wporg', "'{$slug}' was not found in the WordPress.org directory. Only WordPress.org packages can be installed or updated by this tool." );
		}
		if ( $code !== 200 || ! is_array( $body ) || empty( $body['download_link'] ) ) {
			return new WP_Error( 'wporg_unreachable', "Unexpected response from api.wordpress.org (HTTP {$code})." );
		}

		$info = [
			'name'          => (string) ( $body['name'] ?? $slug ),
			'slug'          => (string) ( $body['slug'] ?? $slug ),
			'version'       => (string) ( $body['version'] ?? '' ),
			'download_link' => (string) $body['download_link'],
			'requires'      => (string) ( $body['requires'] ?? '' ),
			'requires_php'  => (string) ( $body['requires_php'] ?? '' ),
		];

		// Version pin: must exist in the API's versions map; take ITS url.
		if ( $version !== null && $version !== '' && $version !== $info['version'] ) {
			$versions = is_array( $body['versions'] ?? null ) ? $body['versions'] : [];
			if ( empty( $versions[ $version ] ) ) {
				return new WP_Error( 'version_not_found', "Version {$version} of '{$slug}' is not available on WordPress.org." );
			}
			$info['version']       = $version;
			$info['download_link'] = (string) $versions[ $version ];
			// Historical releases predate the current requires headers; the gate
			// below still runs against the latest-version values as best effort.
		}

		// Requirements gate — fail BEFORE any download.
		if ( $info['requires_php'] !== '' && version_compare( PHP_VERSION, $info['requires_php'], '<' ) ) {
			return new WP_Error( 'requirements_unmet', "'{$slug}' requires PHP {$info['requires_php']}; this server runs " . PHP_VERSION . '.' );
		}
		if ( $info['requires'] !== '' && version_compare( get_bloginfo( 'version' ), $info['requires'], '<' ) ) {
			return new WP_Error( 'requirements_unmet', "'{$slug}' requires WordPress {$info['requires']}; this site runs " . get_bloginfo( 'version' ) . '.' );
		}
		return $info;
	}

	/* ── Download + zip plumbing ───────────────────────────── */

	/** Download a WP.org package to a temp file. Returns the temp zip path. */
	public static function download_package( string $url ): string|WP_Error {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( wp_parse_url( $url, PHP_URL_SCHEME ) !== 'https' || $host !== 'downloads.wordpress.org' ) {
			return new WP_Error( 'invalid_package_url', 'Refusing to download: package URL is not https://downloads.wordpress.org.' );
		}
		$dir = self::backups_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$tmp = $dir . '/dl-' . wp_generate_password( 12, false ) . '.zip';
		$r   = wp_safe_remote_get( $url, [ 'timeout' => 300, 'stream' => true, 'filename' => $tmp ] );
		if ( is_wp_error( $r ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return new WP_Error( 'wporg_unreachable', 'Package download failed: ' . $r->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code !== 200 || ! is_file( $tmp ) || filesize( $tmp ) === 0 ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			return new WP_Error( 'wporg_unreachable', "Package download failed (HTTP {$code})." );
		}
		return $tmp;
	}

	/** True when ZipArchive is usable; shared error otherwise. */
	private static function zip_available(): true|WP_Error {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'zip_missing', 'The PHP zip extension is not available on this host; native install/update is not possible.' );
		}
		return true;
	}

	/** Zip-slip guard: reject traversal/absolute entry names. */
	private static function entry_name_ok( string $name ): bool {
		return $name !== '' && $name[0] !== '/' && ! str_contains( $name, '..' ) && ! str_contains( $name, '\\' );
	}

	/**
	 * Extract a WP.org package zip. Enforces the single-top-level-folder shape.
	 * Returns the absolute path of the extracted folder inside $dest_dir.
	 */
	public static function extract_package( string $zip_path, string $dest_dir ): string|WP_Error {
		$ok = self::zip_available();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$zip = new ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			return new WP_Error( 'package_invalid', 'The downloaded package is not a readable zip archive.' );
		}
		$tops = [];
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( ! self::entry_name_ok( $name ) ) {
				$zip->close();
				return new WP_Error( 'package_invalid', 'The package contains an unsafe path entry.' );
			}
			$tops[ strstr( $name, '/', true ) ?: rtrim( $name, '/' ) ] = true;
		}
		if ( count( $tops ) !== 1 ) {
			$zip->close();
			return new WP_Error( 'package_invalid', 'The package does not contain a single top-level directory.' );
		}
		if ( ! wp_mkdir_p( $dest_dir ) || ! $zip->extractTo( $dest_dir ) ) {
			$zip->close();
			return new WP_Error( 'fs_not_writable', 'Could not extract the package (disk full or permissions?).' );
		}
		$zip->close();
		$folder = $dest_dir . '/' . array_key_first( $tops );
		return is_dir( $folder ) ? $folder : new WP_Error( 'package_invalid', 'Extraction did not produce the expected directory.' );
	}

	/**
	 * Create a backup zip of a directory (entries prefixed "$prefix/…") or a
	 * single file (stored as "$prefix").
	 */
	public static function zip_directory( string $src, string $zip_path, string $prefix ): true|WP_Error {
		$ok = self::zip_available();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$zip = new ZipArchive();
		if ( $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
			return new WP_Error( 'fs_not_writable', 'Could not create the backup archive.' );
		}
		if ( is_file( $src ) ) {
			$zip->addFile( $src, $prefix );
		} else {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $it as $item ) {
				$rel = $prefix . '/' . ltrim( substr( (string) $item->getPathname(), strlen( $src ) ), '/' );
				if ( $item->isDir() ) {
					$zip->addEmptyDir( $rel );
				} else {
					$zip->addFile( (string) $item->getPathname(), $rel );
				}
			}
		}
		if ( ! $zip->close() ) {
			return new WP_Error( 'fs_not_writable', 'Could not finalize the backup archive.' );
		}
		return true;
	}

	/** Extract a backup zip into the plugins/themes root (entries carry their folder prefix). */
	public static function extract_backup( string $zip_path, string $dest_root ): true|WP_Error {
		$ok = self::zip_available();
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$zip = new ZipArchive();
		if ( $zip->open( $zip_path ) !== true ) {
			return new WP_Error( 'backup_missing', 'The backup archive could not be opened.' );
		}
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			if ( ! self::entry_name_ok( (string) $zip->getNameIndex( $i ) ) ) {
				$zip->close();
				return new WP_Error( 'package_invalid', 'The backup archive contains an unsafe path entry.' );
			}
		}
		$extracted = $zip->extractTo( $dest_root );
		$zip->close();
		return $extracted ? true : new WP_Error( 'fs_not_writable', 'Could not extract the backup archive.' );
	}

	/** Recursive rm -rf (native FS ops; WP_Filesystem needs wp-admin files). */
	public static function delete_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			if ( $item->isDir() ) {
				@rmdir( (string) $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( (string) $item->getPathname() );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/* ── Lookup helpers ────────────────────────────────────── */

	/** Map a folder slug (or single-file name) to the installed plugin_file key. */
	public static function find_plugin_file( string $folder_or_file ): ?string {
		foreach ( array_keys( Cowboy_MCP_Compat::get_plugins() ) as $file ) {
			if ( $file === $folder_or_file || str_starts_with( $file, $folder_or_file . '/' ) ) {
				return $file;
			}
		}
		return null;
	}

	/** Self-protection: never let the agent touch cowboy-mcp itself. */
	public static function is_self( string $slug_or_file ): bool {
		$first = strstr( $slug_or_file, '/', true ) ?: $slug_or_file;
		return strtolower( $first ) === 'cowboy-mcp';
	}
}
