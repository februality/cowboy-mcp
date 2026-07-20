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
	private static function zip_available(): bool|WP_Error {
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
	public static function zip_directory( string $src, string $zip_path, string $prefix ): bool|WP_Error {
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
	public static function extract_backup( string $zip_path, string $dest_root ): bool|WP_Error {
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

	/* ── Journal + settings helpers ────────────────────────── */

	private static function undo_enabled(): bool {
		return ! empty( Cowboy_MCP_Tools::get_settings()['undo_enabled'] ?? true );
	}

	/** Journal one package change. Returns the journal row id (null when undo off/failed). */
	private static function journal( string $tool, string $action, string $type, string $id, string $label, ?array $before ): ?int {
		if ( ! self::undo_enabled() ) {
			return null;
		}
		$obj_type = $type === 'plugin' ? 'plugin_files' : 'theme_files';
		return Cowboy_MCP_Rollback::insert_row( [
			'tool'         => $tool,
			'action'       => $action,
			'object_type'  => $obj_type,
			'object_id'    => $id,
			'object_label' => $label,
			'before_state' => $before,
			'after_hash'   => Cowboy_MCP_Rollback::state_hash( Cowboy_MCP_Rollback::snapshot( $obj_type, $id ) ),
		] );
	}

	private static function flush_caches( string $type ): void {
		if ( $type === 'plugin' ) {
			Cowboy_MCP_Compat::flush_plugins_cache();
			wp_cache_delete( 'plugins', 'plugins' );
		} else {
			wp_clean_themes_cache();
		}
	}

	private static function root_dir( string $type ): string {
		return $type === 'plugin' ? Cowboy_MCP_Compat::plugins_dir() : get_theme_root();
	}

	/* ── Health check ──────────────────────────────────────── */

	/**
	 * Loopback GET of the homepage. Deliberately bypasses SSRF validation
	 * (same rationale as Cowboy_MCP_Doctor::loopback): the URL is built from
	 * home_url(), never caller input.
	 */
	public static function loopback_ok(): array {
		$r = wp_remote_request( home_url( '/' ), [
			'method'      => 'GET',
			'timeout'     => 10,
			'redirection' => 2,
			'sslverify'   => false,
			'headers'     => [ 'Accept' => 'text/html' ],
		] );
		if ( is_wp_error( $r ) ) {
			return [ 'ok' => false, 'reachable' => false, 'status' => null, 'evidence' => 'Loopback failed: ' . $r->get_error_message() ];
		}
		$code     = (int) wp_remote_retrieve_response_code( $r );
		$body     = (string) wp_remote_retrieve_body( $r );
		$critical = str_contains( $body, 'There has been a critical error' );
		return [
			'ok'        => $code < 500 && ! $critical,
			'reachable' => true,
			'status'    => $code,
			'evidence'  => 'HTTP ' . $code . ( $critical ? ' + WP critical-error page' : '' ),
		];
	}

	/* ── Install ───────────────────────────────────────────── */

	public static function install( string $type, string $slug, ?string $version, bool $activate ): array|WP_Error {
		if ( ! preg_match( '/^[a-z0-9-]+$/', $slug ) ) {
			return new WP_Error( 'invalid_params', 'Slug must be a lowercase WordPress.org slug (letters, numbers, hyphens).' );
		}
		if ( self::is_self( $slug ) ) {
			return new WP_Error( 'self_target', 'Refusing to manage the cowboy-mcp plugin itself; update it from wp-admin.' );
		}
		$root = self::root_dir( $type );
		if ( ! wp_is_writable( $root ) ) {
			return new WP_Error( 'fs_not_writable', "The {$type}s directory is not writable by PHP: {$root}" );
		}
		$already = $type === 'plugin' ? self::find_plugin_file( $slug ) !== null : wp_get_theme( $slug )->exists();
		if ( $already ) {
			return new WP_Error( 'already_installed', "'{$slug}' is already installed. Use the update tool to change its version." );
		}

		$info = $type === 'plugin' ? self::plugin_info( $slug, $version ) : self::theme_info( $slug, $version );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		$zip = self::download_package( $info['download_link'] );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		$bdir = self::backups_dir(); // validated by download_package already
		$tmp  = $bdir . '/tmp-' . wp_generate_password( 8, false );
		$src  = self::extract_package( $zip, $tmp );
		wp_delete_file( $zip );
		if ( is_wp_error( $src ) ) {
			self::delete_dir( $tmp );
			return $src;
		}
		// Structure validation.
		$valid = $type === 'plugin' ? (bool) glob( $src . '/*.php' ) : is_file( $src . '/style.css' );
		if ( ! $valid ) {
			self::delete_dir( $tmp );
			return new WP_Error( 'package_invalid', "The package does not look like a valid {$type}." );
		}
		$folder = basename( $src );
		$dest   = $root . '/' . $folder;
		if ( file_exists( $dest ) ) {
			self::delete_dir( $tmp );
			return new WP_Error( 'already_installed', "Directory '{$folder}' already exists in the {$type}s directory." );
		}
		if ( ! @rename( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- atomic move; WP_Filesystem requires wp-admin includes (hard invariant)
			self::delete_dir( $tmp );
			return new WP_Error( 'fs_not_writable', 'Could not move the package into place.' );
		}
		self::delete_dir( $tmp );
		self::flush_caches( $type );

		$result = [
			'installed' => true,
			'type'      => $type,
			'slug'      => $info['slug'],
			'name'      => $info['name'],
			'version'   => $info['version'],
			'folder'    => $folder,
		];
		if ( $type === 'plugin' ) {
			$result['plugin_file'] = self::find_plugin_file( $folder );
		} else {
			$result['stylesheet'] = $folder;
		}

		$change_id = self::journal(
			"wp_install_{$type}", 'create', $type, $folder,
			"Install {$info['name']} {$info['version']}", null
		);
		if ( $change_id !== null ) {
			$result['change_id'] = $change_id;
		}

		if ( $activate ) {
			if ( $type === 'plugin' && $result['plugin_file'] ) {
				$act = Cowboy_MCP_Compat::activate_plugin( $result['plugin_file'] );
				$result['activated'] = ! is_wp_error( $act );
				if ( is_wp_error( $act ) ) {
					$result['activation_error'] = $act->get_error_message();
				}
			} elseif ( $type === 'theme' ) {
				switch_theme( $folder );
				$result['activated'] = true;
			}
		} else {
			$result['activated'] = false;
		}
		return $result;
	}

	/* ── Update ────────────────────────────────────────────── */

	/** Resolve target folder/current version/slug for an installed package. */
	private static function resolve_target( string $type, string $target ): array|WP_Error {
		if ( self::is_self( $target ) ) {
			return new WP_Error( 'self_target', 'Refusing to manage the cowboy-mcp plugin itself; update it from wp-admin.' );
		}
		if ( $type === 'plugin' ) {
			if ( ! str_contains( $target, '/' ) ) {
				return self::find_plugin_file( $target ) !== null && str_contains( (string) self::find_plugin_file( $target ), '/' )
					? self::resolve_target( 'plugin', (string) self::find_plugin_file( $target ) )
					: new WP_Error( 'not_supported', 'Single-file plugins cannot be updated by this tool.' );
			}
			$plugins = Cowboy_MCP_Compat::get_plugins();
			if ( ! isset( $plugins[ $target ] ) ) {
				return new WP_Error( 'not_found', "Plugin '{$target}' is not installed." );
			}
			$folder = dirname( $target );
			$upd    = get_site_transient( 'update_plugins' );
			$slug   = $upd->response[ $target ]->slug ?? $upd->no_update[ $target ]->slug ?? $folder;
			return [
				'folder'  => $folder,
				'file'    => $target,
				'slug'    => (string) $slug,
				'name'    => (string) ( $plugins[ $target ]['Name'] ?? $folder ),
				'current' => (string) ( $plugins[ $target ]['Version'] ?? '' ),
				'active'  => Cowboy_MCP_Compat::is_plugin_active( $target ),
			];
		}
		$theme = wp_get_theme( $target );
		if ( ! $theme->exists() ) {
			return new WP_Error( 'not_found', "Theme '{$target}' is not installed." );
		}
		return [
			'folder'  => $target,
			'file'    => $target,
			'slug'    => $target,
			'name'    => (string) $theme->get( 'Name' ),
			'current' => (string) $theme->get( 'Version' ),
			'active'  => $target === get_stylesheet() || $target === get_template(),
		];
	}

	public static function update_one( string $type, string $target, ?string $version ): array|WP_Error {
		$t = self::resolve_target( $type, $target );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		// Checkpoint is taken lazily inside update_single — after the
		// already_latest check and the fail-early download, right before the
		// swap — so no-op and failed updates never create one.
		return self::update_single(
			$type, $t, $version, "wp_update_{$type}",
			static fn (): ?int => Cowboy_MCP_Checkpoint::maybe_update_checkpoint( "Before {$type} update: {$t['name']}" )
		);
	}

	/** Core single-package update. $t is a resolve_target() array; $pre_swap runs just before the swap (returns checkpoint id). */
	private static function update_single( string $type, array $t, ?string $version, string $tool, ?callable $pre_swap = null ): array|WP_Error {
		$root = self::root_dir( $type );
		if ( ! wp_is_writable( $root ) ) {
			return new WP_Error( 'fs_not_writable', "The {$type}s directory is not writable by PHP: {$root}" );
		}
		$info = $type === 'plugin' ? self::plugin_info( $t['slug'], $version ) : self::theme_info( $t['slug'], $version );
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( $info['version'] === $t['current'] ) {
			return [ 'updated' => false, 'already_latest' => true, 'target' => $t['file'], 'version' => $t['current'] ];
		}

		// 1. Download + extract FIRST — live folder untouched on any failure here.
		$zip = self::download_package( $info['download_link'] );
		if ( is_wp_error( $zip ) ) {
			return $zip;
		}
		$bdir = self::backups_dir();
		$tmp  = $bdir . '/tmp-' . wp_generate_password( 8, false );
		$src  = self::extract_package( $zip, $tmp );
		wp_delete_file( $zip );
		if ( is_wp_error( $src ) ) {
			self::delete_dir( $tmp );
			return $src;
		}
		if ( basename( $src ) !== $t['folder'] ) {
			self::delete_dir( $tmp );
			return new WP_Error( 'package_mismatch', "The package folder '" . basename( $src ) . "' does not match the installed folder '{$t['folder']}'." );
		}

		// 2. Backup the live folder.
		$live   = $root . '/' . $t['folder'];
		$bkname = sprintf( 'pkgbak-%s-%s-%s-%d.zip', $type, $t['folder'], $t['current'] !== '' ? $t['current'] : 'unknown', time() );
		$backup = $bdir . '/' . $bkname;
		$bk     = self::zip_directory( $live, $backup, $t['folder'] );
		if ( is_wp_error( $bk ) ) {
			self::delete_dir( $tmp );
			return $bk;
		}

		// 3. DB checkpoint (update_one's lazy callback; update_all checkpoints once upfront).
		$cp = $pre_swap !== null ? $pre_swap() : null;

		// 4. Baseline health (only meaningful when the package is active).
		$baseline = $t['active'] ? self::loopback_ok() : null;

		// 5. Two-rename swap.
		$aside = $root . '/' . $t['folder'] . '-cowboy-aside-' . time();
		if ( ! @rename( $live, $aside ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap; WP_Filesystem requires wp-admin includes (hard invariant)
			self::delete_dir( $tmp );
			wp_delete_file( $backup );
			return new WP_Error( 'fs_not_writable', 'Could not move the current version aside.' );
		}
		if ( ! @rename( $src, $live ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- atomic swap; WP_Filesystem requires wp-admin includes (hard invariant)
			@rename( $aside, $live ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- rollback of failed swap
			self::delete_dir( $tmp );
			wp_delete_file( $backup );
			return new WP_Error( 'fs_not_writable', 'Could not move the new version into place; the previous version was restored.' );
		}
		self::delete_dir( $tmp );
		self::flush_caches( $type );

		// 6. Post-swap health check (active packages, when baseline was healthy).
		$health = 'not_applicable';
		if ( $t['active'] ) {
			if ( $baseline === null || ! $baseline['reachable'] || ! $baseline['ok'] ) {
				$health = 'skipped';
			} else {
				$post = self::loopback_ok();
				if ( ! $post['ok'] ) {
					// Auto-restore: new version out, old version back.
					self::delete_dir( $live );
					@rename( $aside, $live ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- health-check auto-restore
					self::flush_caches( $type );
					wp_delete_file( $backup );
					$fail = [
						'updated'             => false,
						'target'              => $t['file'],
						'health_check_failed' => true,
						'restored'            => true,
						'evidence'            => $post['evidence'],
						'from_version'        => $t['current'],
						'attempted_version'   => $info['version'],
					];
					if ( $cp !== null ) {
						$fail['checkpoint_id'] = $cp;
					}
					return $fail;
				}
				$health = 'ok';
			}
		}
		self::delete_dir( $aside );

		$result = [
			'updated'      => true,
			'target'       => $t['file'],
			'name'         => $t['name'],
			'from_version' => $t['current'],
			'to_version'   => $info['version'],
			'health_check' => $health,
		];
		if ( $cp !== null ) {
			$result['checkpoint_id'] = $cp;
		}
		$change_id = self::journal(
			$tool, 'update', $type, $t['folder'],
			"Update {$t['name']} {$t['current']} → {$info['version']}",
			[ 'version' => $t['current'], 'backup_zip' => $backup, 'folder' => $t['folder'] ]
		);
		if ( $change_id !== null ) {
			$result['change_id'] = $change_id;
		} else {
			wp_delete_file( $backup ); // undo disabled → no consumer for the zip
		}
		return $result;
	}

	public static function update_all( string $type ): array {
		if ( $type === 'plugin' ) {
			wp_update_plugins();
			$upd     = get_site_transient( 'update_plugins' );
			$targets = array_keys( (array) ( $upd->response ?? [] ) );
		} else {
			wp_update_themes();
			$upd     = get_site_transient( 'update_themes' );
			$targets = array_keys( (array) ( $upd->response ?? [] ) );
		}

		$results = [];
		$prev    = Cowboy_MCP_Rollback::$batch_id;
		if ( $prev === null ) {
			Cowboy_MCP_Rollback::$batch_id = wp_generate_uuid4();
		}
		$cp = empty( $targets ) ? null : Cowboy_MCP_Checkpoint::maybe_update_checkpoint( "Before {$type} updates (" . count( $targets ) . ')' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( $targets as $target ) {
			$target = (string) $target;
			if ( self::is_self( $target ) ) {
				$results[] = [ 'target' => $target, 'updated' => false, 'skipped' => 'self_target' ];
				continue;
			}
			$t = self::resolve_target( $type, $target );
			if ( is_wp_error( $t ) ) {
				$results[] = [ 'target' => $target, 'updated' => false, 'error' => $t->get_error_code(), 'message' => $t->get_error_message() ];
				continue;
			}
			$r = self::update_single( $type, $t, null, "wp_update_{$type}" );
			$results[] = is_wp_error( $r )
				? [ 'target' => $target, 'updated' => false, 'error' => $r->get_error_code(), 'message' => $r->get_error_message() ]
				: $r;
		}
		$batch                         = Cowboy_MCP_Rollback::$batch_id;
		Cowboy_MCP_Rollback::$batch_id = $prev;

		return [
			'results'       => $results,
			'total'         => count( $results ),
			'succeeded'     => count( array_filter( $results, static fn( $r ) => ! empty( $r['updated'] ) ) ),
			'failed'        => count( array_filter( $results, static fn( $r ) => isset( $r['error'] ) || ! empty( $r['health_check_failed'] ) ) ),
			'checkpoint_id' => $cp,
			'batch_id'      => $batch,
		];
	}

	/* ── Delete ────────────────────────────────────────────── */

	public static function delete( string $type, string $target ): array|WP_Error {
		if ( self::is_self( $target ) ) {
			return new WP_Error( 'self_target', 'Refusing to manage the cowboy-mcp plugin itself.' );
		}
		$root = self::root_dir( $type );
		if ( $type === 'plugin' ) {
			$plugins = Cowboy_MCP_Compat::get_plugins();
			if ( ! isset( $plugins[ $target ] ) ) {
				return new WP_Error( 'not_found', "Plugin '{$target}' is not installed." );
			}
			if ( Cowboy_MCP_Compat::is_plugin_active( $target ) || Cowboy_MCP_Compat::is_plugin_active_for_network( $target ) ) {
				return new WP_Error( 'active_delete', "Plugin '{$target}' is active. Deactivate it first with wp_deactivate_plugin." );
			}
			$name    = (string) ( $plugins[ $target ]['Name'] ?? $target );
			$version = (string) ( $plugins[ $target ]['Version'] ?? '' );
			$id      = str_contains( $target, '/' ) ? dirname( $target ) : $target;
		} else {
			$theme = wp_get_theme( $target );
			if ( ! $theme->exists() ) {
				return new WP_Error( 'not_found', "Theme '{$target}' is not installed." );
			}
			if ( $target === get_stylesheet() || $target === get_template() ) {
				return new WP_Error( 'active_delete', "Theme '{$target}' is the active theme (or its parent). Switch themes first with wp_switch_theme." );
			}
			$name    = (string) $theme->get( 'Name' );
			$version = (string) $theme->get( 'Version' );
			$id      = $target;
		}

		$path = $root . '/' . $id;
		$bdir = self::backups_dir();
		if ( is_wp_error( $bdir ) ) {
			return $bdir;
		}
		$backup = $bdir . '/' . sprintf( 'pkgbak-%s-%s-%s-%d.zip', $type, str_replace( '/', '_', $id ), $version !== '' ? $version : 'unknown', time() );
		$bk     = self::zip_directory( $path, $backup, $id );
		if ( is_wp_error( $bk ) ) {
			return $bk;
		}
		if ( is_dir( $path ) ) {
			self::delete_dir( $path );
		} else {
			wp_delete_file( $path );
		}
		self::flush_caches( $type );

		$result    = [ 'deleted' => true, 'type' => $type, 'target' => $target, 'name' => $name, 'version' => $version ];
		$change_id = self::journal(
			"wp_delete_{$type}", 'delete', $type, $id,
			"Delete {$name} {$version}",
			[ 'version' => $version, 'backup_zip' => $backup, 'folder' => $id ]
		);
		if ( $change_id !== null ) {
			$result['change_id'] = $change_id;
		} else {
			wp_delete_file( $backup );
		}
		return $result;
	}

	/* ── Dry-run plan (called from generate_dry_run_preview) ── */

	public static function dry_run_plan( string $tool, array $args ): array {
		$type = str_contains( $tool, '_theme' ) ? 'theme' : 'plugin';
		try {
			if ( str_starts_with( $tool, 'wp_install_' ) ) {
				$info = $type === 'plugin'
					? self::plugin_info( (string) ( $args['slug'] ?? '' ), $args['version'] ?? null )
					: self::theme_info( (string) ( $args['slug'] ?? '' ), $args['version'] ?? null );
				if ( is_wp_error( $info ) ) {
					return [ 'error' => $info->get_error_code(), 'message' => $info->get_error_message() ];
				}
				return [
					'would_install' => $info['name'] . ' ' . $info['version'],
					'download_from' => $info['download_link'],
					'activate'      => ! empty( $args['activate'] ),
				];
			}
			if ( str_starts_with( $tool, 'wp_update_' ) ) {
				if ( ! empty( $args['all'] ) ) {
					$type === 'plugin' ? wp_update_plugins() : wp_update_themes();
					$upd = get_site_transient( $type === 'plugin' ? 'update_plugins' : 'update_themes' );
					return [ 'would_update_all' => array_keys( (array) ( $upd->response ?? [] ) ), 'with_backup_and_checkpoint' => true ];
				}
				$t = self::resolve_target( $type, (string) ( $args[ $type === 'plugin' ? 'plugin_file' : 'stylesheet' ] ?? '' ) );
				if ( is_wp_error( $t ) ) {
					return [ 'error' => $t->get_error_code(), 'message' => $t->get_error_message() ];
				}
				$info = $type === 'plugin' ? self::plugin_info( $t['slug'], $args['version'] ?? null ) : self::theme_info( $t['slug'], $args['version'] ?? null );
				if ( is_wp_error( $info ) ) {
					return [ 'error' => $info->get_error_code(), 'message' => $info->get_error_message() ];
				}
				return [
					'would_update'              => $t['name'],
					'from_version'              => $t['current'],
					'to_version'                => $info['version'],
					'already_latest'            => $info['version'] === $t['current'],
					'with_backup_and_checkpoint' => true,
					'health_check_after'        => $t['active'],
				];
			}
			// delete
			$t = self::resolve_target( $type, (string) ( $args[ $type === 'plugin' ? 'plugin_file' : 'stylesheet' ] ?? '' ) );
			if ( is_wp_error( $t ) ) {
				return [ 'error' => $t->get_error_code(), 'message' => $t->get_error_message() ];
			}
			return [ 'would_delete' => $t['name'] . ' ' . $t['current'], 'with_backup' => true, 'currently_active' => $t['active'] ];
		} catch ( \Throwable $e ) {
			return [ 'error' => 'preview_failed', 'message' => $e->getMessage() ];
		}
	}
}
