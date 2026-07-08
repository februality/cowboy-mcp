<?php
defined( 'ABSPATH' ) || exit;

/**
 * Resolve a relative path to an absolute wp-content path, with security check.
 */
function cowboy_mcp_resolve_wp_content_path( string $relative ): string|WP_Error {
    // Reject null bytes before any path resolution (never useful, even in Power mode).
    if ( str_contains( $relative, "\0" ) ) {
        return new WP_Error( 'path_escape', 'Path must not contain null bytes.' );
    }

    // Power mode: allow absolute paths and '..' traversal; no wp-content containment
    // check. Absolute paths are used as-is; relative paths still anchor to wp-content
    // for backward compatibility. The PHP process's file permissions are the boundary.
    if ( Cowboy_MCP_Security::power_mode_enabled() ) {
        $candidate = ( $relative !== '' && $relative[0] === '/' )
            ? $relative
            : Cowboy_MCP_Compat::content_dir() . '/' . $relative;
        $full = realpath( $candidate );
        if ( $full === false ) {
            $parent = realpath( dirname( $candidate ) );
            $full   = ( $parent !== false ) ? $parent . '/' . basename( $candidate ) : $candidate;
        }
        if ( Cowboy_MCP_Security::is_protected_storage_path( $full ) ) {
            return new WP_Error( 'path_protected', 'This path is inside Cowboy MCP private storage and cannot be accessed via file tools.' );
        }
        return $full;
    }

    // Standard mode: reject '..' and confine to wp-content.
    if ( str_contains( $relative, '..' ) ) {
        return new WP_Error( 'path_escape', 'Path must not contain ".." segments.' );
    }

    $base      = Cowboy_MCP_Compat::content_dir() . '/';
    $base_real = realpath( Cowboy_MCP_Compat::content_dir() ) ?: Cowboy_MCP_Compat::content_dir();
    $full      = realpath( $base . $relative );

    // For not-yet-existing targets (e.g. wp_write_file), resolve the closest existing
    // ancestor so a symlinked parent directory can't escape via the lexical fallback.
    if ( $full === false ) {
        $parent_real = realpath( dirname( $base . $relative ) );
        $full = ( $parent_real !== false )
            ? $parent_real . '/' . basename( $relative )
            : $base . $relative;
    }

    // Ensure the path stays inside wp-content. The trailing separator prevents
    // sibling-prefix matches (e.g. wp-content-evil).
    if ( ! str_starts_with( $full . '/', $base_real . '/' ) ) {
        return new WP_Error( 'path_escape', 'Path must be within wp-content/.' );
    }
    if ( Cowboy_MCP_Security::is_protected_storage_path( $full ) ) {
        return new WP_Error( 'path_protected', 'This path is inside Cowboy MCP private storage and cannot be accessed via file tools.' );
    }
    return $full;
}

/**
 * Whether a resolved path would write an executable file into the uploads directory
 * (a web-served webshell vector). Theme/plugin .php editing elsewhere stays allowed.
 */
function cowboy_mcp_is_blocked_upload_write( string $full ): bool {
    // Power mode lifts the uploads webshell-write guard.
    if ( Cowboy_MCP_Security::power_mode_enabled() ) {
        return false;
    }
    $uploads = wp_upload_dir();
    $base    = $uploads['basedir'] ?? '';
    if ( $base === '' ) {
        return false;
    }
    $uploads_real = realpath( $base ) ?: $base;
    if ( ! str_starts_with( $full . '/', $uploads_real . '/' ) ) {
        return false; // not in uploads
    }
    $ext = strtolower( pathinfo( $full, PATHINFO_EXTENSION ) );
    return in_array( $ext, [ 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'pht', 'htaccess' ], true );
}

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_read_file', '[Files] Read the contents of a theme or plugin file.', [
            'path' => [ 'type' => 'string', 'description' => 'Path relative to wp-content/ (e.g. themes/flavor/style.css)', 'required' => true ],
        ], [ 'title' => 'Read File', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ]),

        Cowboy_MCP_Tools::tool( 'wp_write_file', '[Files] Write or overwrite a theme/plugin file. Creates parent directories if needed.', [
            'path'    => [ 'type' => 'string', 'description' => 'Path relative to wp-content/', 'required' => true ],
            'content' => [ 'type' => 'string', 'description' => 'File content', 'required' => true ],
        ], [ 'title' => 'Write File', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ]),

        Cowboy_MCP_Tools::tool( 'wp_list_directory', '[Files] List files and subdirectories in a wp-content path.', [
            'path'      => [ 'type' => 'string',  'description' => 'Path relative to wp-content/', 'default' => '' ],
            'recursive' => [ 'type' => 'boolean', 'description' => 'Include subdirectories', 'default' => false ],
        ], [ 'title' => 'List Directory', 'readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false ]),

        Cowboy_MCP_Tools::tool( 'wp_delete_file', '[Files] Delete a file inside wp-content.', [
            'path' => [ 'type' => 'string', 'description' => 'Path relative to wp-content/', 'required' => true ],
        ], [ 'title' => 'Delete File', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ]),
    ],
    'handlers' => [
        'wp_read_file' => function ( array $a ) {
            $path = cowboy_mcp_resolve_wp_content_path( $a['path'] );
            if ( is_wp_error( $path ) ) return $path;
            if ( ! is_file( $path ) ) {
                return new WP_Error( 'not_found', "File not found: {$a['path']}" );
            }
            $size = filesize( $path );
            return [
                'path'     => $a['path'],
                'size'     => $size,
                'modified' => gmdate( 'Y-m-d H:i:s', filemtime( $path ) ),
                'content'  => ( $size > 512000 ) ? '(File too large to return — ' . size_format( $size ) . ')' : file_get_contents( $path ),
            ];
        },

        'wp_write_file' => function ( array $a ) {
            $content_len = strlen( $a['content'] );
            if ( $content_len > 10 * 1024 * 1024 ) {
                return new WP_Error( 'file_too_large', 'Content exceeds 10 MB write limit (' . size_format( $content_len ) . ').' );
            }

            $path = cowboy_mcp_resolve_wp_content_path( $a['path'] );
            if ( is_wp_error( $path ) ) return $path;

            if ( cowboy_mcp_is_blocked_upload_write( $path ) ) {
                return new WP_Error( 'blocked_extension', 'Refusing to write executable files (.php/.phar/.htaccess) into the uploads directory.' );
            }

            $dir = dirname( $path );
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }

            $tmp = $path . '.mcp_tmp_' . uniqid( '', true );
            $bytes = file_put_contents( $tmp, $a['content'] );
            if ( $bytes === false ) {
                wp_delete_file( $tmp );
                return new WP_Error( 'write_failed', "Could not write to {$a['path']}. Check permissions." );
            }
            // Atomic replace via native rename. $tmp lives in the same directory as
            // $path, so this is atomic and needs no WP_Filesystem/admin include.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            if ( ! @rename( $tmp, $path ) ) {
                wp_delete_file( $tmp );
                return new WP_Error( 'write_failed', "Atomic rename failed for {$a['path']}." );
            }
            return [ 'written' => true, 'path' => $a['path'], 'bytes' => $bytes ];
        },

        'wp_list_directory' => function ( array $a ): array|WP_Error {
            $path = $a['path'] ?? '';
            if ( $path === '' ) {
                $base = Cowboy_MCP_Compat::content_dir();
            } else {
                $base = cowboy_mcp_resolve_wp_content_path( $path );
                if ( is_wp_error( $base ) ) return $base;
            }
            if ( ! is_dir( $base ) ) return [];

            $recursive = ! empty( $a['recursive'] );
            $items     = [];

            $iterator = $recursive
                ? new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST )
                : new DirectoryIterator( $base );

            $count = 0;
            foreach ( $iterator as $item ) {
                if ( $item->isDot() ) continue;
                if ( Cowboy_MCP_Security::is_protected_storage_path( $item->getPathname() ) ) {
                    continue;
                }
                if ( ++$count > 500 ) break;    // safety limit

                $relative = str_replace( Cowboy_MCP_Compat::content_dir() . '/', '', $item->getPathname() );
                $items[]  = [
                    'name'     => $item->getFilename(),
                    'path'     => $relative,
                    'type'     => $item->isDir() ? 'directory' : 'file',
                    'size'     => $item->isFile() ? $item->getSize() : null,
                    'modified' => gmdate( 'Y-m-d H:i:s', $item->getMTime() ),
                ];
            }
            return $items;
        },

        'wp_delete_file' => function ( array $a ) {
            $path = cowboy_mcp_resolve_wp_content_path( $a['path'] );
            if ( is_wp_error( $path ) ) return $path;
            if ( ! file_exists( $path ) ) {
                return new WP_Error( 'not_found', "File not found: {$a['path']}" );
            }
            if ( is_dir( $path ) ) {
                return new WP_Error( 'is_directory', 'Cannot delete directories. Remove files individually.' );
            }
            wp_delete_file( $path );
            return [ 'deleted' => true, 'path' => $a['path'] ];
        },
    ],
];
