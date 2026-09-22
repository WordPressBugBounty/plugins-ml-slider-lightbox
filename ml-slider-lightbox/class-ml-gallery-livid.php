<?php
namespace MetaSlider\Lightbox;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Livid.com videos, stored as file-less attachments.
 *
 * @since 2.38.0
 */
class Livid {

    const META_KEY   = '_ml_livid_id';
    const MIME       = 'video/x-livid';
    const ID_PATTERN = '[A-Za-z0-9_-]+';

    /**
     * Video ID from a livid.com watch, video or embed URL, or '' when the URL isn't one.
     */
    public static function parseId( $url ) {
        $parts = wp_parse_url( trim( (string) $url ) );

        if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
            return '';
        }

        $scheme = strtolower( $parts['scheme'] ?? '' );
        $host   = strtolower( $parts['host'] );

        if ( ! in_array( $scheme, array( 'http', 'https' ), true )
            || ! in_array( $host, array( 'livid.com', 'www.livid.com' ), true ) ) {
            return '';
        }

        return preg_match( '#^/(?:watch|video|embed)/(' . self::ID_PATTERN . ')/?$#', $parts['path'], $m ) ? $m[1] : '';
    }

    public static function watchUrl( $livid_id ) {
        return 'https://livid.com/watch/' . $livid_id;
    }

    public static function embedUrl( $livid_id ) {
        return 'https://livid.com/embed/' . $livid_id;
    }

    /**
     * The Livid video ID stored on an attachment, or '' for any other attachment.
     */
    public static function lividId( $attachment_id ) {
        $value = get_post_meta( $attachment_id, self::META_KEY, true );

        return ( is_string( $value ) && preg_match( '#^' . self::ID_PATTERN . '$#', $value ) ) ? $value : '';
    }

    /**
     * Existing attachment for a Livid video, or 0.
     */
    public static function findAttachment( $livid_id ) {
        $ids = get_posts(
            array(
                'post_type'        => 'attachment',
                'post_status'      => 'inherit',
                'meta_key'         => self::META_KEY,
                'meta_value'       => $livid_id,
                'fields'           => 'ids',
                'numberposts'      => 1,
                'no_found_rows'    => true,
                'suppress_filters' => true,
            )
        );

        return $ids ? (int) $ids[0] : 0;
    }

    /**
     * Title, size and thumbnail for a Livid video from its oEmbed endpoint.
     *
     * @return array|\WP_Error
     */
    public static function fetchOembed( $livid_id ) {
        $response = wp_safe_remote_get(
            add_query_arg(
                array(
                    'url'    => rawurlencode( self::watchUrl( $livid_id ) ),
                    'format' => 'json',
                ),
                'https://livid.com/oembed'
            ),
            array( 'timeout' => 10 )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

        if ( 200 !== (int) wp_remote_retrieve_response_code( $response )
            || ! is_array( $data )
            || 'video' !== ( $data['type'] ?? '' ) ) {
            return new \WP_Error( 'ml_livid_oembed', 'Livid oEmbed lookup failed.' );
        }

        if ( empty( $data['thumbnail_url'] ) ) {
            return new \WP_Error( 'ml_livid_unavailable', 'Livid video not found or private.' );
        }

        return $data;
    }

    /**
     * Create the file-less attachment for a Livid video and download its thumbnail.
     *
     * @return int Attachment ID, or 0 on failure.
     */
    public static function createAttachment( $livid_id, array $oembed ) {
        $title = sanitize_text_field( (string) ( $oembed['title'] ?? '' ) );

        $id = wp_insert_attachment(
            array(
                'post_title'     => '' !== $title ? $title : $livid_id,
                'post_mime_type' => self::MIME,
                'post_status'    => 'inherit',
                'guid'           => self::watchUrl( $livid_id ),
            ),
            false,
            0,
            true
        );

        if ( is_wp_error( $id ) || ! $id ) {
            return 0;
        }

        update_post_meta( $id, self::META_KEY, $livid_id );
        wp_update_attachment_metadata(
            $id,
            array(
                'width'  => absint( $oembed['width'] ?? 0 ),
                'height' => absint( $oembed['height'] ?? 0 ),
            )
        );

        self::sideloadThumbnail( $id, $livid_id, (string) ( $oembed['thumbnail_url'] ?? '' ), $title );

        return (int) $id;
    }

    private static function sideloadThumbnail( $attachment_id, $livid_id, $url, $title ) {
        if ( ! wp_http_validate_url( $url ) ) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url, 15 );
        if ( is_wp_error( $tmp ) ) {
            return;
        }

        $thumb_id = media_handle_sideload(
            array(
                'name'     => 'livid-' . $livid_id . '.jpg',
                'tmp_name' => $tmp,
            ),
            $attachment_id,
            $title
        );

        if ( is_wp_error( $thumb_id ) ) {
            @unlink( $tmp );
            return;
        }

        set_post_thumbnail( $attachment_id, $thumb_id );
    }
}
