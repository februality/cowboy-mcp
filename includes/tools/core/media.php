<?php
defined( 'ABSPATH' ) || exit;

/** Shape an attachment post for tool output. */
function cowboy_mcp_format_attachment( WP_Post $p ): array {
    $file = get_attached_file( $p->ID );
    $meta = wp_get_attachment_metadata( $p->ID );
    return [
        'attachment_id' => (int) $p->ID,
        'title'         => $p->post_title,
        'filename'      => is_string( $file ) && $file !== '' ? basename( $file ) : '',
        'url'           => wp_get_attachment_url( $p->ID ),
        'mime_type'     => $p->post_mime_type,
        'filesize'      => ( is_string( $file ) && is_file( $file ) ) ? (int) filesize( $file ) : null,
        'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : null,
        'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : null,
        'alt_text'      => (string) get_post_meta( $p->ID, '_wp_attachment_image_alt', true ),
        'caption'       => $p->post_excerpt,
        'description'   => $p->post_content,
        'post_parent'   => (int) $p->post_parent,
        'date'          => $p->post_date_gmt,
    ];
}

/**
 * Every file belonging to an attachment, as paths relative to the uploads basedir.
 * Covers the (possibly -scaled) primary file, every generated size, and the
 * pre-scaling original — miss the last one and undo restores thumbnails with no
 * source, which nobody notices until they try to re-crop.
 */
function cowboy_mcp_media_file_set( int $attachment_id ): array {
    $base = wp_upload_dir()['basedir'] ?? '';
    if ( $base === '' ) {
        return [];
    }
    $relative = [];

    $file = get_attached_file( $attachment_id );
    if ( is_string( $file ) && $file !== '' && str_starts_with( $file, $base . '/' ) ) {
        $relative[] = substr( $file, strlen( $base ) + 1 );
    }

    $meta = wp_get_attachment_metadata( $attachment_id );
    if ( is_array( $meta ) ) {
        $subdir = ! empty( $meta['file'] ) ? ltrim( dirname( $meta['file'] ), './' ) : '';
        $prefix = ( $subdir !== '' && $subdir !== '.' ) ? $subdir . '/' : '';
        foreach ( (array) ( $meta['sizes'] ?? [] ) as $size ) {
            if ( ! empty( $size['file'] ) ) {
                $relative[] = $prefix . $size['file'];
            }
        }
        if ( ! empty( $meta['original_image'] ) ) {
            $relative[] = $prefix . $meta['original_image'];
        }
    }

    return array_values( array_unique( array_filter( $relative ) ) );
}

/**
 * Everywhere an attachment is referenced.
 *
 * Captured before deletion and re-applied on undo: wp_delete_attachment() drops
 * every _thumbnail_id row pointing at the attachment, so without this an undone
 * deletion returns the image but leaves the posts that used it with no featured
 * image — a silent half-undo.
 */
function cowboy_mcp_media_references( int $attachment_id ): array {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
    $thumbnail_post_ids = $wpdb->get_col( $wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
        $attachment_id
    ) );
    return [
        'thumbnail_post_ids' => array_map( 'intval', (array) $thumbnail_post_ids ),
        'site_icon'          => (int) get_option( 'site_icon' ) === $attachment_id,
        'custom_logo'        => (int) get_theme_mod( 'custom_logo' ) === $attachment_id,
    ];
}

/** Human-readable summary of cowboy_mcp_media_references(), for warnings. */
function cowboy_mcp_media_usage( int $attachment_id ): array {
    $refs    = cowboy_mcp_media_references( $attachment_id );
    $used_as = [];
    $count   = count( $refs['thumbnail_post_ids'] );
    if ( $count > 0 ) {
        $used_as[] = "featured image of {$count} post(s)";
    }
    if ( $refs['site_icon'] ) {
        $used_as[] = 'the site icon';
    }
    if ( $refs['custom_logo'] ) {
        $used_as[] = 'the site logo';
    }
    return $used_as;
}

/** Largest file wp_upload_media downloads from a url or a chat attachment, in bytes. */
function cowboy_mcp_media_max_bytes(): int {
    /**
     * Filter the largest file wp_upload_media will download, in bytes.
     *
     * @param int $bytes Default 50 MB.
     */
    return (int) apply_filters( 'cowboy_mcp_media_max_bytes', 50 * MB_IN_BYTES );
}

/**
 * Download $url to a temp file. Redirects are followed by hand (at most 3) so every
 * hop passes the SSRF check, and the body streams to disk with a size cap.
 *
 * @param bool $chat_file A ChatGPT attachment link: the address check stays on in
 *                        Power mode, and refusals read as an expired link.
 * @return string|WP_Error Temp file path.
 */
function cowboy_mcp_media_download( string $url, string $name_hint, bool $chat_file = false ) {
    $max       = cowboy_mcp_media_max_bytes();
    $unguarded = ! $chat_file && Cowboy_MCP_Security::power_mode_enabled();

    for ( $hop = 0; $hop <= 3; $hop++ ) {
        $check = Cowboy_MCP_Security::validate_url_ssrf( $url, $chat_file );
        if ( is_wp_error( $check ) ) {
            return $check;
        }

        $tmp  = Cowboy_MCP_Compat::tempnam( $name_hint );
        $args = [
            'timeout'             => 30,
            'redirection'         => 0,
            'stream'              => true,
            'filename'            => $tmp,
            'limit_response_size' => $max + 1, // one byte over the cap = too large
        ];
        // Power mode uses the unguarded transport so internal/private addresses are reachable.
        $response = $unguarded ? wp_remote_get( $url, $args ) : wp_safe_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) {
            wp_delete_file( $tmp );
            return $response;
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $location = wp_remote_retrieve_header( $response, 'location' );
        $location = (string) ( is_array( $location ) ? end( $location ) : $location );
        if ( in_array( $code, [ 301, 302, 303, 307, 308 ], true ) && '' !== $location ) {
            wp_delete_file( $tmp );
            $url = WP_Http::make_absolute_url( $location, $url );
            continue;
        }
        if ( 200 !== $code ) {
            wp_delete_file( $tmp );
            if ( $chat_file ) {
                return new WP_Error( 'download_failed', "The chat file link was refused (HTTP {$code}). These links expire: attach the file to the chat again and retry." );
            }
            return new WP_Error( 'download_failed', "Failed to download URL (HTTP {$code})." );
        }

        clearstatcache( true, $tmp );
        $size = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
        if ( $size > $max ) {
            wp_delete_file( $tmp );
            return new WP_Error( 'file_too_large', sprintf( 'The file is larger than %s, the most this site downloads.', size_format( $max ) ) );
        }
        if ( 0 === $size ) {
            wp_delete_file( $tmp );
            return new WP_Error( 'download_failed', 'Downloaded file is empty.' );
        }
        return $tmp;
    }

    return new WP_Error( 'download_failed', 'Too many redirects.' );
}

/**
 * File name for a chat attachment: its own name, else one derived from the MIME
 * type or the content. Never the download URL, which is an opaque signed link.
 */
function cowboy_mcp_media_chat_filename( array $file, string $tmp ): string {
    $name = sanitize_file_name( wp_basename( (string) ( $file['file_name'] ?? '' ) ) );
    if ( '' === pathinfo( $name, PATHINFO_EXTENSION ) ) {
        $mime = (string) ( $file['mime_type'] ?? '' );
        if ( '' === $mime || 'application/octet-stream' === $mime ) {
            $mime = (string) wp_get_image_mime( $tmp );
        }
        $ext  = '' !== $mime ? wp_get_default_extension_for_mime_type( $mime ) : false;
        $name = ( '' !== $name ? $name : 'upload' ) . ( $ext ? ".{$ext}" : '' );
    }
    return $name;
}

/**
 * Optionally resize and/or convert an image before it enters the library
 * (convert_to, max_width, max_height, quality). Uses core's WP_Image_Editor.
 *
 * @return array{0:string,1:string}|WP_Error [temp path, file name].
 */
function cowboy_mcp_media_process_image( string $tmp, string $name, array $a ) {
    $convert = (string) ( $a['convert_to'] ?? '' );
    $max_w   = max( 0, (int) ( $a['max_width'] ?? 0 ) );
    $max_h   = max( 0, (int) ( $a['max_height'] ?? 0 ) );
    $quality = (int) ( $a['quality'] ?? 0 );
    if ( '' === $convert && ! $max_w && ! $max_h && ! $quality ) {
        return [ $tmp, $name ];
    }

    $mime = wp_get_image_mime( $tmp );
    if ( ! $mime ) {
        return new WP_Error( 'not_an_image', 'convert_to, max_width, max_height and quality apply to images only.' );
    }
    $target = match ( $convert ) {
        ''      => $mime,
        'jpeg'  => 'image/jpeg',
        'png'   => 'image/png',
        'webp'  => 'image/webp',
        'avif'  => 'image/avif',
        default => null,
    };
    if ( null === $target ) {
        return new WP_Error( 'invalid_params', 'convert_to must be jpeg, png, webp or avif.' );
    }
    if ( ! wp_image_editor_supports( [ 'mime_type' => $target ] ) ) {
        return new WP_Error( 'convert_unsupported', "This server's image library cannot write {$target}." );
    }

    $editor = wp_get_image_editor( $tmp );
    if ( is_wp_error( $editor ) ) {
        return $editor;
    }
    $editor->maybe_exif_rotate();
    $size = $editor->get_size();
    if ( ( $max_w && $size['width'] > $max_w ) || ( $max_h && $size['height'] > $max_h ) ) {
        $resized = $editor->resize( $max_w ?: null, $max_h ?: null, false );
        if ( is_wp_error( $resized ) ) {
            return $resized;
        }
    }
    if ( $quality ) {
        $editor->set_quality( min( 100, max( 1, $quality ) ) );
    }

    $ext   = wp_get_default_extension_for_mime_type( $target ) ?: 'img';
    $saved = $editor->save( get_temp_dir() . 'cmcp-' . wp_generate_password( 8, false ) . '.' . $ext, $target );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    wp_delete_file( $tmp );
    $base = preg_replace( '/\.[^.]*$/', '', $name );
    return [ $saved['path'], ( '' !== $base ? $base : 'upload' ) . '.' . pathinfo( $saved['path'], PATHINFO_EXTENSION ) ];
}

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_upload_media', '[Media] Upload a media file from a ChatGPT chat attachment, a remote URL, or base64 data. Images can be resized and converted (jpeg, png, webp, avif) on the way in. ChatGPT: pass the attached file as cowboy_run\'s top-level file input, not inside arguments.', [
            'source_type'  => [ 'type' => 'string',  'description' => 'Where the file comes from: file (a ChatGPT chat attachment), url, or base64. Inferred from which of file, url or data is given.', 'enum' => [ 'file', 'url', 'base64' ] ],
            'file'         => [ 'type' => 'object',  'description' => 'A file attached in the ChatGPT conversation', 'properties' => Cowboy_MCP_Tools::file_param_properties(), 'required' => [ 'download_url', 'file_id' ] ],
            'data'         => [ 'type' => 'string',  'description' => 'Base64-encoded file data (when source_type is base64)' ],
            'url'          => [ 'type' => 'string',  'description' => 'Remote URL to download (when source_type is url)' ],
            'filename'     => [ 'type' => 'string',  'description' => 'Desired filename (required for base64)' ],
            'title'        => [ 'type' => 'string',  'description' => 'Attachment title' ],
            'alt_text'     => [ 'type' => 'string',  'description' => 'Image alt text' ],
            'caption'      => [ 'type' => 'string',  'description' => 'Attachment caption' ],
            'description'  => [ 'type' => 'string',  'description' => 'Attachment description' ],
            'post_id'      => [ 'type' => 'integer', 'description' => 'Parent post ID to attach to' ],
            'convert_to'   => [ 'type' => 'string',  'description' => 'Images only: convert to this format', 'enum' => [ 'jpeg', 'png', 'webp', 'avif' ] ],
            'max_width'    => [ 'type' => 'integer', 'description' => 'Images only: scale down to at most this width in pixels (keeps aspect ratio)', 'minimum' => 1 ],
            'max_height'   => [ 'type' => 'integer', 'description' => 'Images only: scale down to at most this height in pixels (keeps aspect ratio)', 'minimum' => 1 ],
            'quality'      => [ 'type' => 'integer', 'description' => 'Images only: encoder quality 1-100 for jpeg/webp/avif', 'minimum' => 1, 'maximum' => 100 ],
        ], [
            'title'           => 'Upload Media',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

        Cowboy_MCP_Tools::tool( 'wp_list_media', '[Media] List media library attachments with filtering and pagination. Use missing_alt: true to find images with no alt text.', [
            'search'      => [ 'type' => 'string',  'description' => 'Search titles, captions, and filenames' ],
            'mime_type'   => [ 'type' => 'string',  'description' => 'Filter by MIME type or prefix (e.g. image, image/jpeg, application/pdf)' ],
            'post_parent' => [ 'type' => 'integer', 'description' => 'Only attachments attached to this post ID' ],
            'unattached'  => [ 'type' => 'boolean', 'description' => 'Only attachments with no parent post', 'default' => false ],
            'missing_alt' => [ 'type' => 'boolean', 'description' => 'Only images with empty or absent alt text', 'default' => false ],
            'date_after'  => [ 'type' => 'string',  'description' => 'Only items uploaded on/after this date (YYYY-MM-DD)' ],
            'date_before' => [ 'type' => 'string',  'description' => 'Only items uploaded on/before this date (YYYY-MM-DD)' ],
            'orderby'     => [ 'type' => 'string',  'description' => 'Sort field (default date)', 'default' => 'date', 'enum' => [ 'date', 'title', 'ID' ] ],
            'per_page'    => [ 'type' => 'integer', 'description' => 'Results per page, max 100 (default 50)', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ],
            'page'        => [ 'type' => 'integer', 'description' => 'Page number (default 1)', 'default' => 1, 'minimum' => 1 ],
        ], [
            'title'           => 'List Media',
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_update_media', '[Media] Update an attachment\'s metadata. Only provided fields change.', [
            'attachment_id' => [ 'type' => 'integer', 'description' => 'Attachment ID', 'required' => true ],
            'alt_text'      => [ 'type' => 'string',  'description' => 'Image alt text' ],
            'title'         => [ 'type' => 'string',  'description' => 'Attachment title' ],
            'caption'       => [ 'type' => 'string',  'description' => 'Attachment caption' ],
            'description'   => [ 'type' => 'string',  'description' => 'Attachment description' ],
            'post_parent'   => [ 'type' => 'integer', 'description' => 'Parent post ID (0 to detach)' ],
        ], [
            'title'           => 'Update Media',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

        Cowboy_MCP_Tools::tool( 'wp_delete_media', '[Media] Delete an attachment. Its files are moved to a recoverable trash area so the deletion can be undone until the undo journal is pruned.', [
            'attachment_id' => [ 'type' => 'integer', 'description' => 'Attachment ID', 'required' => true ],
        ], [
            'title'           => 'Delete Media',
            'readOnlyHint'    => false,
            'destructiveHint' => true,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ] ),

    ],
    'handlers' => [
        'wp_upload_media' => function ( array $a ) {

            $post_id = (int) ( $a['post_id'] ?? 0 );
            $file    = $a['file'] ?? null;
            if ( is_string( $file ) ) {
                $file = json_decode( $file, true ); // some clients send the object as JSON text
            }
            $source_type = (string) ( $a['source_type'] ?? '' );
            if ( '' === $source_type ) {
                $source_type = match ( true ) {
                    ! empty( $file )      => 'file',
                    ! empty( $a['url'] )  => 'url',
                    ! empty( $a['data'] ) => 'base64',
                    default               => '',
                };
            }

            if ( $source_type === 'file' ) {
                if ( ! is_array( $file ) ) {
                    return new WP_Error( 'missing_file', 'file is required when source_type is file (a ChatGPT attachment with download_url and file_id).' );
                }
                $download = trim( (string) ( $file['download_url'] ?? '' ) );
                if ( '' === $download ) {
                    return new WP_Error( 'missing_file', 'The attachment arrived without a download link. Attach the file to the chat again, or pass a public url instead.' );
                }
                if ( ! preg_match( '#^https?://#i', $download ) ) {
                    return new WP_Error( 'invalid_file', "'{$download}' is a path inside ChatGPT's code sandbox, not a file this site can download. Attach the file to the chat message itself, then pass it as cowboy_run's file input." );
                }

                $tmp = cowboy_mcp_media_download( $download, (string) ( $file['file_name'] ?? 'upload' ), true );
                if ( is_wp_error( $tmp ) ) {
                    return $tmp;
                }
                $filename = ! empty( $a['filename'] ) ? sanitize_file_name( $a['filename'] ) : cowboy_mcp_media_chat_filename( $file, $tmp );
                // Chat attachments are arbitrary user files: refuse types a browser
                // would run, even where a plugin lets the media library accept them.
                if ( preg_match( '/\.(svgz?|x?html?|xml|m?js|css|php\d*|phtml|phar)$/i', $filename ) ) {
                    wp_delete_file( $tmp );
                    return new WP_Error( 'invalid_type', 'SVG, HTML, XML, JavaScript, CSS and PHP files are not accepted from a chat attachment.' );
                }
            } elseif ( $source_type === 'url' ) {
                if ( empty( $a['url'] ) ) {
                    return new WP_Error( 'missing_url', 'URL is required when source_type is url.' );
                }
                $url      = esc_url_raw( $a['url'] );
                $url_name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ?: 'download';
                $tmp      = cowboy_mcp_media_download( $url, $url_name );
                if ( is_wp_error( $tmp ) ) {
                    return $tmp;
                }
                $filename = sanitize_file_name( $a['filename'] ?? $url_name );
            } elseif ( $source_type === 'base64' ) {
                if ( empty( $a['data'] ) ) {
                    return new WP_Error( 'missing_data', 'Base64 data is required when source_type is base64.' );
                }
                if ( empty( $a['filename'] ) ) {
                    return new WP_Error( 'missing_filename', 'Filename is required when source_type is base64.' );
                }

                $decoded = base64_decode( $a['data'], true );
                if ( $decoded === false ) {
                    return new WP_Error( 'invalid_base64', 'Could not decode base64 data.' );
                }

                $tmp = Cowboy_MCP_Compat::tempnam( $a['filename'] );
                if ( file_put_contents( $tmp, $decoded ) === false ) {
                    wp_delete_file( $tmp );
                    return new WP_Error( 'write_failed', 'Failed to write decoded data to temp location.' );
                }
                $filename = sanitize_file_name( $a['filename'] );
            } else {
                return new WP_Error( 'invalid_source', 'Pass a file (ChatGPT attachment), a url, or base64 data with a filename.' );
            }

            $processed = cowboy_mcp_media_process_image( $tmp, $filename, $a );
            if ( is_wp_error( $processed ) ) {
                wp_delete_file( $tmp );
                return $processed;
            }
            [ $tmp, $filename ] = $processed;

            $attachment_id = Cowboy_MCP_Compat::handle_sideload( [ 'name' => $filename, 'tmp_name' => $tmp ], $post_id );
            if ( is_wp_error( $attachment_id ) ) {
                wp_delete_file( $tmp );
                return $attachment_id;
            }

            // Set optional metadata.
            if ( ! empty( $a['title'] ) || ! empty( $a['caption'] ) || ! empty( $a['description'] ) ) {
                $update = [ 'ID' => $attachment_id ];
                if ( isset( $a['title'] ) )       $update['post_title']   = $a['title'];
                if ( isset( $a['caption'] ) )     $update['post_excerpt'] = $a['caption'];
                if ( isset( $a['description'] ) ) $update['post_content'] = $a['description'];
                wp_update_post( $update );
            }
            if ( ! empty( $a['alt_text'] ) ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $a['alt_text'] ) );
            }

            $meta = wp_get_attachment_metadata( $attachment_id );
            return [
                'created'       => true,
                'attachment_id' => $attachment_id,
                'url'           => wp_get_attachment_url( $attachment_id ),
                'filename'      => basename( get_attached_file( $attachment_id ) ),
                'mime_type'     => get_post_mime_type( $attachment_id ),
                'width'         => isset( $meta['width'] ) ? (int) $meta['width'] : null,
                'height'        => isset( $meta['height'] ) ? (int) $meta['height'] : null,
            ];
        },

        'wp_list_media' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'upload_files' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot access the media library.' );
            }
            $per_page = min( max( (int) ( $a['per_page'] ?? 50 ), 1 ), 100 );
            $page     = max( (int) ( $a['page'] ?? 1 ), 1 );

            $query_args = [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => sanitize_key( $a['orderby'] ?? 'date' ),
                'order'          => 'DESC',
            ];
            if ( ! empty( $a['search'] ) ) {
                $query_args['s'] = sanitize_text_field( $a['search'] );
            }
            if ( ! empty( $a['mime_type'] ) ) {
                $query_args['post_mime_type'] = sanitize_text_field( $a['mime_type'] );
            }
            if ( isset( $a['post_parent'] ) ) {
                $query_args['post_parent'] = (int) $a['post_parent'];
            }
            if ( ! empty( $a['unattached'] ) ) {
                $query_args['post_parent'] = 0;
            }
            $date_query = [];
            if ( ! empty( $a['date_after'] ) ) {
                $date_query['after'] = sanitize_text_field( $a['date_after'] );
            }
            if ( ! empty( $a['date_before'] ) ) {
                $date_query['before'] = sanitize_text_field( $a['date_before'] );
            }
            if ( $date_query ) {
                $date_query['inclusive']  = true;
                $query_args['date_query'] = [ $date_query ];
            }
            if ( ! empty( $a['missing_alt'] ) ) {
                // Alt text only applies to images; an absent row and an empty
                // string both count as missing.
                if ( empty( $query_args['post_mime_type'] ) ) {
                    $query_args['post_mime_type'] = 'image';
                }
                $query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                    'relation' => 'OR',
                    [ 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '=' ],
                ];
            }

            $query = new WP_Query( $query_args );

            return [
                'media'    => array_map( 'cowboy_mcp_format_attachment', $query->posts ),
                'total'    => (int) $query->found_posts,
                'pages'    => (int) $query->max_num_pages,
                'page'     => $page,
                'per_page' => $per_page,
            ];
        },

        'wp_update_media' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'upload_files' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot edit media.' );
            }
            $attachment_id = (int) $a['attachment_id'];
            $post          = get_post( $attachment_id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                return new WP_Error( 'not_found', "Attachment {$attachment_id} not found." );
            }

            $update = [ 'ID' => $attachment_id ];
            if ( isset( $a['title'] ) ) {
                $update['post_title'] = sanitize_text_field( $a['title'] );
            }
            if ( isset( $a['caption'] ) ) {
                $update['post_excerpt'] = sanitize_textarea_field( $a['caption'] );
            }
            if ( isset( $a['description'] ) ) {
                $update['post_content'] = sanitize_textarea_field( $a['description'] );
            }
            if ( isset( $a['post_parent'] ) ) {
                $update['post_parent'] = (int) $a['post_parent'];
            }

            $has_alt = isset( $a['alt_text'] );
            if ( count( $update ) === 1 && ! $has_alt ) {
                return new WP_Error( 'invalid_params', 'No fields provided to update.' );
            }
            if ( count( $update ) > 1 ) {
                $result = wp_update_post( wp_slash( $update ), true );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
            }
            if ( $has_alt ) {
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $a['alt_text'] ) );
            }

            return [ 'updated' => true ] + cowboy_mcp_format_attachment( get_post( $attachment_id ) );
        },

        'wp_delete_media' => function ( array $a ): array|WP_Error {
            if ( ! current_user_can( 'delete_posts' ) ) {
                return new WP_Error( 'forbidden', 'The authenticated user cannot delete media.' );
            }
            $attachment_id = (int) $a['attachment_id'];
            $post          = get_post( $attachment_id );
            if ( ! $post || $post->post_type !== 'attachment' ) {
                return new WP_Error( 'not_found', "Attachment {$attachment_id} not found." );
            }

            // No dry_run branch here: Cowboy_MCP_Tools::call_tool() intercepts
            // dry_run before the handler runs. The preview is built in
            // generate_dry_run_preview(), same as the installer tools.
            $files   = cowboy_mcp_media_file_set( $attachment_id );
            $used_as = cowboy_mcp_media_usage( $attachment_id );

            $base  = wp_upload_dir()['basedir'] ?? '';
            $trash = Cowboy_MCP_Rollback::trash_dir();
            if ( is_wp_error( $trash ) ) {
                return $trash;
            }
            $target = $trash . '/' . uniqid( 'att' . $attachment_id . '-', false );
            if ( ! wp_mkdir_p( $target ) ) {
                return new WP_Error( 'fs_not_writable', 'Could not create the trash directory for this attachment.' );
            }

            // Capture BEFORE the files move; the journal row is written only after
            // the delete succeeds, so a half-finished move never leaves a bogus row.
            $before = [
                // Columns only: get_post( ARRAY_A ) also returns derived fields that
                // are not wp_posts columns and would break the restore INSERT.
                'post'            => Cowboy_MCP_Rollback::post_columns_only( (array) get_post( $attachment_id, ARRAY_A ) ),
                'meta'            => get_post_meta( $attachment_id ),
                'attachment_meta' => wp_get_attachment_metadata( $attachment_id ),
                'references'      => cowboy_mcp_media_references( $attachment_id ),
                'trash_dir'       => $target,
                'files'           => [],
            ];

            $moved = [];
            foreach ( $files as $rel ) {
                $from = $base . '/' . $rel;
                $to   = $target . '/' . $rel;
                if ( ! is_file( $from ) ) {
                    continue;
                }
                wp_mkdir_p( dirname( $to ) );
                if ( ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- atomic move aside; WP_Filesystem requires wp-admin includes (hard invariant)
                    // All-or-nothing: put back whatever moved, touch no database rows.
                    foreach ( $moved as $done ) {
                        @rename( $target . '/' . $done, $base . '/' . $done ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- rollback of a partial move
                    }
                    return new WP_Error( 'fs_not_writable', "Could not move {$rel} into the trash directory; nothing was deleted." );
                }
                $moved[] = $rel;
            }
            $before['files'] = $moved;

            // Files are already aside; wp_delete_attachment tolerates missing files.
            $deleted = wp_delete_attachment( $attachment_id, true );
            if ( ! $deleted ) {
                foreach ( $moved as $done ) {
                    @rename( $target . '/' . $done, $base . '/' . $done ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- restore files after a failed delete
                }
                return new WP_Error( 'delete_failed', "Failed to delete attachment {$attachment_id}." );
            }

            $change_id = Cowboy_MCP_Rollback::insert_row( [
                'tool'         => 'wp_delete_media',
                'action'       => 'delete',
                'object_type'  => 'media_file',
                'object_id'    => (string) $attachment_id,
                'object_label' => $post->post_title !== '' ? $post->post_title : "attachment #{$attachment_id}",
                'before_state' => $before,
                'after_hash'   => null,
            ] );

            return [
                'deleted'       => true,
                'attachment_id' => $attachment_id,
                'title'         => $post->post_title,
                'files_trashed' => count( $moved ),
                'used_as'       => $used_as,
                'change_id'     => $change_id,
            ];
        },

    ],
];
