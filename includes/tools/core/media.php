<?php
defined( 'ABSPATH' ) || exit;

return [
    'tools' => [
        Cowboy_MCP_Tools::tool( 'wp_upload_media', '[Media] Upload a media file from base64 data or a remote URL.', [
            'source_type'  => [ 'type' => 'string',  'description' => 'Source type: base64 or url', 'required' => true, 'enum' => [ 'base64', 'url' ] ],
            'data'         => [ 'type' => 'string',  'description' => 'Base64-encoded file data (when source_type is base64)' ],
            'url'          => [ 'type' => 'string',  'description' => 'Remote URL to download (when source_type is url)' ],
            'filename'     => [ 'type' => 'string',  'description' => 'Desired filename (required for base64)' ],
            'title'        => [ 'type' => 'string',  'description' => 'Attachment title' ],
            'alt_text'     => [ 'type' => 'string',  'description' => 'Image alt text' ],
            'caption'      => [ 'type' => 'string',  'description' => 'Attachment caption' ],
            'description'  => [ 'type' => 'string',  'description' => 'Attachment description' ],
            'post_id'      => [ 'type' => 'integer', 'description' => 'Parent post ID to attach to' ],
        ], [
            'title'           => 'Upload Media',
            'readOnlyHint'    => false,
            'destructiveHint' => false,
            'idempotentHint'  => false,
            'openWorldHint'   => false,
        ]),

    ],
    'handlers' => [
        'wp_upload_media' => function ( array $a ) {
            // Ensure required WP admin includes are loaded.
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';

            $source_type = $a['source_type'];
            $post_id     = (int) ( $a['post_id'] ?? 0 );

            if ( $source_type === 'url' ) {
                if ( empty( $a['url'] ) ) {
                    return new WP_Error( 'missing_url', 'URL is required when source_type is url.' );
                }

                // SSRF protection: only allow http/https and reject private/internal IPs.
                $url       = esc_url_raw( $a['url'] );
                $ssrf_check = Cowboy_MCP_Security::validate_url_ssrf( $url );
                if ( is_wp_error( $ssrf_check ) ) {
                    return $ssrf_check;
                }

                // Use wp_safe_remote_get() for built-in SSRF protection; Power mode uses the
                // unguarded transport so internal/private addresses are reachable.
                $response = Cowboy_MCP_Security::power_mode_enabled()
                    ? wp_remote_get( $url, [ 'timeout' => 30 ] )
                    : wp_safe_remote_get( $url, [ 'timeout' => 30 ] );
                if ( is_wp_error( $response ) ) return $response;

                $response_code = wp_remote_retrieve_response_code( $response );
                if ( $response_code !== 200 ) {
                    return new WP_Error( 'download_failed', "Failed to download URL (HTTP {$response_code})." );
                }

                $body = wp_remote_retrieve_body( $response );
                if ( empty( $body ) ) {
                    return new WP_Error( 'download_failed', 'Downloaded file is empty.' );
                }

                $tmp = wp_tempnam( basename( wp_parse_url( $a['url'], PHP_URL_PATH ) ) ?: 'download' );
                if ( file_put_contents( $tmp, $body ) === false ) {
                    wp_delete_file( $tmp );
                    return new WP_Error( 'write_failed', 'Failed to write downloaded file to temp location.' );
                }

                $filename   = $a['filename'] ?? basename( wp_parse_url( $a['url'], PHP_URL_PATH ) ) ?: 'download';
                $file_array = [
                    'name'     => sanitize_file_name( $filename ),
                    'tmp_name' => $tmp,
                ];

                $attachment_id = media_handle_sideload( $file_array, $post_id );
                if ( is_wp_error( $attachment_id ) ) {
                    wp_delete_file( $tmp );
                    return $attachment_id;
                }
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

                $tmp = wp_tempnam( $a['filename'] );
                if ( file_put_contents( $tmp, $decoded ) === false ) {
                    wp_delete_file( $tmp );
                    return new WP_Error( 'write_failed', 'Failed to write decoded data to temp location.' );
                }

                $file_array = [
                    'name'     => sanitize_file_name( $a['filename'] ),
                    'tmp_name' => $tmp,
                ];

                $attachment_id = media_handle_sideload( $file_array, $post_id );
                if ( is_wp_error( $attachment_id ) ) {
                    wp_delete_file( $tmp );
                    return $attachment_id;
                }
            } else {
                return new WP_Error( 'invalid_source', "source_type must be 'base64' or 'url'." );
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

            return [
                'created'       => true,
                'attachment_id' => $attachment_id,
                'url'           => wp_get_attachment_url( $attachment_id ),
                'filename'      => basename( get_attached_file( $attachment_id ) ),
            ];
        },

    ],
];
