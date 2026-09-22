<?php
namespace MetaSlider\Lightbox;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Resolves a gallery's ordered attachment IDs into typed item records.
 *
 * Classification and video data only — image URLs stay in the render loop
 * because they depend on per-gallery size settings, not on item data.
 *
 * @since 2.38.0
 */
class GalleryItems {

    const DEFAULT_WIDTH  = 1280;
    const DEFAULT_HEIGHT = 720;

    /**
     * @param int[]                 $ids        Ordered attachment IDs.
     * @param int                   $gallery_id Gallery post id.
     * @param array<int,string>     $captions   Caption text keyed by attachment id.
     * @param string                $context    Consumer of the list: 'render' or 'editor'.
     * @param array|null            $settings   The settings the render is using, unsaved ones
     *                                          included; null for the editor grid.
     * @return array<int,array<string,mixed>>
     */
    public static function resolve( array $ids, $gallery_id, array $captions = array(), $context = 'render', $settings = null ) {
        $items      = array();
        $gallery_id = absint( $gallery_id );
        $posters    = self::posterOverrides( $gallery_id );

        foreach ( $ids as $raw_id ) {
            $id = absint( $raw_id );
            if ( ! $id ) {
                continue;
            }

            /**
             * Short-circuit classification for one attachment.
             *
             * Add-ons that store a remote video as a file-less attachment cannot be
             * detected by wp_attachment_is(), which bails when there is no file.
             *
             * @since 2.38.0
             * @param array|null $item    Typed item record, or null to classify normally.
             * @param int        $id      Attachment ID.
             * @param array      $posters Poster overrides for this gallery.
             * @param string     $context 'render' or 'editor'.
             */
            $item = apply_filters( 'ml_gallery_resolve_item', null, $id, $posters, $context );

            if ( null === $item ) {
                $livid_id = Livid::lividId( $id );
                if ( '' !== $livid_id ) {
                    $item = self::lividItem( $id, $livid_id, $posters );
                } else {
                    $item = wp_attachment_is( 'video', $id ) ? self::videoItem( $id, $posters ) : self::imageItem( $id );
                }
            }
            if ( null === $item ) {
                continue;
            }

            $item['caption'] = isset( $captions[ $id ] ) ? (string) $captions[ $id ] : '';
            $items[]         = $item;
        }

        /**
         * Filter the resolved gallery items.
         *
         * The editor renders each record with wp_get_attachment_image(), so an item
         * injected for the frontend should check $context before joining both lists.
         *
         * @since 2.38.0
         * @param array      $items      Typed item records.
         * @param int        $gallery_id Gallery post id.
         * @param string     $context    'render' for the frontend gallery, 'editor' for the item grid.
         * @param array|null $settings   The settings the render is using, unsaved ones included —
         *                               read these, not saved meta, which a live preview has not
         *                               written. Null for the editor grid.
         */
        return apply_filters( 'ml_gallery_items', $items, $gallery_id, $context, $settings );
    }

    /**
     * URL of the poster placeholder used when a video has no cover art.
     */
    public static function placeholderUrl() {
        return apply_filters(
            'ml_gallery_video_placeholder',
            plugin_dir_url( __FILE__ ) . 'assets/images/video-placeholder.svg'
        );
    }

    /**
     * JSON payload for an anchor's data-video attribute.
     *
     * Keys are defaulted because ml_gallery_items lets third parties supply records.
     */
    public static function videoData( array $item, array $settings = array() ) {
        $attributes = array(
            'preload'  => 'none',
            'controls' => true,
        );

        if ( ! empty( $item['poster'] ) && is_string( $item['poster'] ) ) {
            $attributes['poster'] = $item['poster'];
        }

        if ( ! empty( $settings['video_loop'] ) ) {
            $attributes['loop'] = true;
        }

        // Browsers refuse to autoplay video with sound, so autoplay implies muted.
        if ( ! empty( $settings['video_muted'] )
            || ! empty( $settings['video_autoplay'] )
            || ! empty( $settings['video_autoplay_each'] ) ) {
            $attributes['muted'] = true;
        }

        return wp_json_encode(
            array(
                'source'     => array(
                    array(
                        'src'  => $item['src'] ?? '',
                        'type' => $item['mime'] ?? '',
                    ),
                ),
                'attributes' => $attributes,
            )
        );
    }

    /**
     * Player URL for an embedded video item, with the gallery's loop and mute settings.
     */
    public static function embedSrc( array $item, array $settings = array() ) {
        $params = array();

        if ( ! empty( $settings['video_loop'] ) ) {
            $params['loop'] = 1;
        }

        if ( ! empty( $settings['video_muted'] )
            || ! empty( $settings['video_autoplay'] )
            || ! empty( $settings['video_autoplay_each'] ) ) {
            $params['muted'] = 1;
        }

        $src = (string) ( $item['src'] ?? '' );

        return $params ? $src . '?' . http_build_query( $params ) : $src;
    }

    /**
     * Player URL for a video item that plays inside an iframe, or '' when the item
     * plays from its own file and needs no player.
     *
     * Livid stores its embed URL already; the remote providers store a watch URL,
     * which their player cannot load.
     */
    public static function playerUrl( array $item, array $settings = array() ) {
        $provider = (string) ( $item['provider'] ?? '' );
        $src      = (string) ( $item['src'] ?? '' );
        $url      = '';

        if ( 'livid' === $provider ) {
            $url = self::embedSrc( $item, $settings );
        } elseif ( 'youtube' === $provider && preg_match( '#[?&]v=([A-Za-z0-9_-]{11})#', $src, $m ) ) {
            $url = 'https://www.youtube.com/embed/' . $m[1];
        } elseif ( 'vimeo' === $provider && preg_match( '#vimeo\.com/([0-9]+)(?:/([0-9A-Za-z]+))?#', $src, $m ) ) {
            $url = 'https://player.vimeo.com/video/' . $m[1];
            if ( ! empty( $m[2] ) ) {
                $url .= '?h=' . $m[2];
            }
        }

        /**
         * Filter the inline player URL for a gallery video item.
         *
         * Lets an add-on supply the player for a provider this plugin cannot
         * derive one for.
         *
         * @since 2.38.0
         * @param string $url      Player URL, or '' when the item has none.
         * @param array  $item     Typed item record.
         * @param array  $settings The settings this render is using.
         */
        return (string) apply_filters( 'ml_gallery_item_player_url', $url, $item, $settings );
    }

    private static function imageItem( $id ) {
        return array(
            'type'   => 'image',
            'key'    => $id,
            'src'    => '',
            'poster' => '',
            'thumb'  => '',
            'width'  => 0,
            'height' => 0,
            'mime'   => (string) get_post_mime_type( $id ),
        );
    }

    private static function videoItem( $id, array $posters = array() ) {
        $src = wp_get_attachment_url( $id );
        if ( ! $src ) {
            return null;
        }

        $poster     = self::posterUrl( $id, $posters );
        $dimensions = self::videoDimensions( $id );

        return array(
            'type'   => 'video',
            'key'    => $id,
            'src'    => (string) $src,
            'poster' => $poster,
            'thumb'  => '' !== $poster ? $poster : self::placeholderUrl(),
            'width'  => $dimensions[0],
            'height' => $dimensions[1],
            'mime'   => (string) get_post_mime_type( $id ),
        );
    }

    private static function lividItem( $id, $livid_id, array $posters = array() ) {
        $poster     = self::posterUrl( $id, $posters );
        $dimensions = self::videoDimensions( $id );

        return array(
            'type'     => 'video',
            'provider' => 'livid',
            'key'      => $id,
            'src'      => Livid::embedUrl( $livid_id ),
            'link'     => Livid::watchUrl( $livid_id ),
            'poster'   => $poster,
            'thumb'    => '' !== $poster ? $poster : self::placeholderUrl(),
            'width'    => $dimensions[0],
            'height'   => $dimensions[1],
            'mime'     => Livid::MIME,
        );
    }

    private static function posterUrl( $id, array $posters ) {
        $thumb_id = isset( $posters[ $id ] ) ? (int) $posters[ $id ] : (int) get_post_thumbnail_id( $id );
        if ( ! $thumb_id ) {
            return '';
        }

        $url = wp_get_attachment_image_url( $thumb_id, 'large' );

        return $url ? (string) $url : '';
    }

    /**
     * The thumb a video shows with no poster override: its own cover art, else
     * the placeholder.
     */
    public static function nativeThumb( $attachment_id ) {
        $thumb_id = (int) get_post_thumbnail_id( $attachment_id );
        $url      = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'large' ) : '';

        return (string) ( $url ?: self::placeholderUrl() );
    }

    /**
     * Per-item poster images chosen in the editor: item id => poster attachment id.
     */
    public static function posterOverrides( $gallery_id ) {
        $saved = $gallery_id ? get_post_meta( $gallery_id, '_ml_gallery_posters', true ) : array();

        if ( ! is_array( $saved ) ) {
            return array();
        }

        $clean = array();
        foreach ( $saved as $item_id => $poster_id ) {
            $item_id   = absint( $item_id );
            $poster_id = absint( $poster_id );
            if ( $item_id && $poster_id ) {
                $clean[ $item_id ] = $poster_id;
            }
        }

        return $clean;
    }

    /**
     * True pixel dimensions recorded by wp_read_video_metadata(), or 16:9.
     */
    private static function videoDimensions( $id ) {
        $meta   = wp_get_attachment_metadata( $id );
        $width  = ( is_array( $meta ) && ! empty( $meta['width'] ) ) ? (int) $meta['width'] : 0;
        $height = ( is_array( $meta ) && ! empty( $meta['height'] ) ) ? (int) $meta['height'] : 0;

        if ( $width < 1 || $height < 1 ) {
            return array( self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );
        }

        return array( $width, $height );
    }
}
