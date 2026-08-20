<?php
namespace MetaSlider\Lightbox;

/**
 * Schema.org structured data for rendered galleries.
 *
 * @package MetaSlider\Lightbox
 * @since   2.37.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Collects the galleries rendered on the current page and prints a single
 * JSON-LD graph of ImageGallery nodes in the footer.
 *
 * @since 2.37.0
 */
class MetaSliderLightboxGallerySchema {

    /**
     * Galleries collected during the current request, keyed by gallery ID.
     *
     * @var array<int,array>
     */
    private static $queued = array();

    /**
     * @since 2.37.0
     * @return void
     */
    public function __construct() {
        add_action( 'wp_footer', array( $this, 'printSchema' ) );
    }

    /**
     * Whether schema output is enabled for a gallery.
     *
     * @since 2.37.0
     * @param int $gallery_id Gallery post ID.
     * @return bool
     */
    public static function isEnabled( $gallery_id ) {
        $options = get_option( 'ml_lightbox_options', array() );

        $enabled = ! is_array( $options )
            || ! isset( $options['gallery_schema'] )
            || ! empty( $options['gallery_schema'] );

        /**
         * Filter whether Schema.org markup is emitted for a gallery.
         *
         * @since 2.37.0
         * @param bool $enabled    Whether to emit markup.
         * @param int  $gallery_id Gallery post ID.
         */
        return (bool) apply_filters( 'ml_gallery_schema_enabled', $enabled, (int) $gallery_id );
    }

    /**
     * Queue a rendered gallery for footer output.
     *
     * @since 2.37.0
     * @param int    $gallery_id Gallery post ID.
     * @param array  $items      List of array( 'id' => int, 'caption' => string ).
     * @param string $page_url   Permalink of the page carrying the gallery.
     * @return void
     */
    public static function collect( $gallery_id, array $items, $page_url = '' ) {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        $gallery_id = absint( $gallery_id );
        if ( ! $gallery_id || empty( $items ) ) {
            return;
        }

        if ( ! self::isEnabled( $gallery_id ) ) {
            return;
        }

        self::$queued[ $gallery_id ] = array(
            'items'    => $items,
            'page_url' => is_string( $page_url ) ? $page_url : '',
        );
    }

    /**
     * Galleries queued for this request.
     *
     * @since 2.37.0
     * @return array<int,array>
     */
    public static function queued() {
        return self::$queued;
    }

    /**
     * Clear the queue.
     *
     * @since 2.37.0
     * @return void
     */
    public static function reset() {
        self::$queued = array();
    }

    /**
     * Build the ImageGallery graph for the galleries queued this request.
     *
     * @since 2.37.0
     * @return array
     */
    public static function buildGraph() {
        $graph = array();

        foreach ( self::$queued as $gallery_id => $entry ) {
            $media = array();

            foreach ( $entry['items'] as $item ) {
                $node = self::buildImageNode(
                    isset( $item['id'] ) ? $item['id'] : 0,
                    isset( $item['caption'] ) ? $item['caption'] : ''
                );

                if ( ! empty( $node ) ) {
                    $media[] = $node;
                }
            }

            if ( empty( $media ) ) {
                continue;
            }

            $gallery = array( '@type' => 'ImageGallery' );

            if ( '' !== $entry['page_url'] ) {
                $url            = esc_url_raw( $entry['page_url'] );
                $gallery['@id'] = $url . '#ml-gallery-' . $gallery_id;
                $gallery['url'] = $url;
            }

            $name = self::plainText( get_the_title( $gallery_id ) );
            if ( '' !== $name ) {
                $gallery['name'] = $name;
            }

            $gallery['associatedMedia'] = $media;

            $graph[] = $gallery;
        }

        return $graph;
    }

    /**
     * Print the JSON-LD graph.
     *
     * @since 2.37.0
     * @return void
     */
    public function printSchema() {
        /**
         * Filter the Schema.org graph emitted for galleries on this page.
         *
         * @since 2.37.0
         * @param array $graph List of ImageGallery nodes.
         */
        $graph = (array) apply_filters( 'ml_gallery_schema', self::buildGraph() );

        if ( empty( $graph ) ) {
            return;
        }

        $payload = wp_json_encode(
            array(
                '@context' => 'https://schema.org',
                '@graph'   => $graph,
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG
        );

        if ( ! $payload ) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON_HEX_TAG escapes angle brackets.
        echo '<script type="application/ld+json" id="ml-gallery-schema">' . $payload . '</script>' . "\n";
    }

    /**
     * Build a single ImageObject node.
     *
     * @since 2.37.0
     * @param int    $image_id Attachment ID.
     * @param string $caption  Caption already resolved by the gallery renderer.
     * @return array Empty when the attachment has no usable full-size URL.
     */
    public static function buildImageNode( $image_id, $caption = '' ) {
        $image_id = absint( $image_id );
        $src      = wp_get_attachment_image_src( $image_id, 'full' );

        if ( ! $src || empty( $src[0] ) ) {
            return array();
        }

        $node = array(
            '@type'      => 'ImageObject',
            'contentUrl' => esc_url_raw( $src[0] ),
        );

        $thumb = wp_get_attachment_image_url( $image_id, 'medium' );
        if ( $thumb && $thumb !== $src[0] ) {
            $node['thumbnailUrl'] = esc_url_raw( $thumb );
        }

        $name = self::plainText( $caption );
        if ( '' === $name ) {
            $name = self::plainText( get_post_meta( $image_id, '_wp_attachment_image_alt', true ) );
        }
        if ( '' !== $name ) {
            $node['name'] = $name;
        }

        $description = self::plainText( get_post_field( 'post_content', $image_id ) );
        if ( '' !== $description && $description !== $name ) {
            $node['description'] = $description;
        }

        if ( ! empty( $src[1] ) ) {
            $node['width'] = (int) $src[1];
        }
        if ( ! empty( $src[2] ) ) {
            $node['height'] = (int) $src[2];
        }

        $uploaded = get_post_time( 'c', true, $image_id );
        if ( $uploaded ) {
            $node['uploadDate'] = $uploaded;
        }

        return $node;
    }

    /**
     * Entity-decoded, tag-stripped, trimmed text for a schema string field.
     *
     * @since 2.37.0
     * @param string $value Raw value.
     * @return string
     */
    private static function plainText( $value ) {
        $decoded = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );

        return trim( wp_strip_all_tags( $decoded, true ) );
    }
}
