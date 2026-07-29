<?php
namespace MetaSlider\Lightbox;

/**
 * Converts a MetaSlider slideshow into a new MetaSlider Gallery.
 *
 * The entry point is a link on MetaSlider's own "Edit slideshow" screen (in
 * the metaslider plugin's toolbar, see admin/views/pages/parts/toolbar.php
 * and admin/lib/gallery-convert.php in that repo), which points here via
 * admin-post.php?action=ml_convert_slideshow.
 *
 * Kept in its own file/class — rather than folded into
 * MetaSliderLightboxGallery — so that a future slideshow-conversion tweak
 * doesn't collide with unrelated edits to class-ml-gallery.php, and vice versa.
 *
 * @package MetaSlider\Lightbox
 * @since   2.35.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MetaSliderLightboxSlideshowConverter {

    /** @var MetaSliderLightboxGallery Used to create the new gallery post. */
    private $gallery;

    /**
     * @since 2.35.0
     * @param MetaSliderLightboxGallery $gallery Gallery post factory (see createGalleryFromImages()).
     */
    public function __construct( MetaSliderLightboxGallery $gallery ) {
        $this->gallery = $gallery;
        add_action( 'admin_post_ml_convert_slideshow', array( $this, 'convert' ) );
    }

    /**
     * Handle admin-post.php?action=ml_convert_slideshow&slideshow_id=X requests.
     *
     * Reads the slideshow's images and captions, creates an equivalent
     * ml_gallery post, and redirects into its editor.
     *
     * @since 2.35.0
     * @return void
     */
    public function convert() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ml-slider-lightbox' ) );
        }

        $slideshow_id = isset( $_GET['slideshow_id'] ) ? absint( $_GET['slideshow_id'] ) : 0;
        $nonce        = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';

        if ( ! $slideshow_id || ! wp_verify_nonce( $nonce, 'ml_convert_slideshow_' . $slideshow_id ) ) {
            wp_die( esc_html__( 'Security check failed.', 'ml-slider-lightbox' ) );
        }

        $slideshow = get_post( $slideshow_id );
        if ( ! $slideshow || 'ml-slider' !== $slideshow->post_type ) {
            wp_die( esc_html__( 'Slideshow not found.', 'ml-slider-lightbox' ) );
        }

        // slide_id (the ml-slider taxonomy member — an `ml-slide` wrapper post
        // for anything created by a current MetaSlider version, or a bare
        // `attachment` for pre-`ml-slide`-era slideshows) => real attachment ID.
        $slides = $this->slideshowSlides( $slideshow_id );

        if ( empty( $slides ) ) {
            // Reads straight from the database, which only reflects a slideshow's
            // slides/order once it's been saved — a slideshow edited but not yet
            // saved (or one with no image-type slides) resolves to zero images here.
            wp_die( esc_html__( 'This slideshow has no saved images to convert. Save the slideshow, then try again.', 'ml-slider-lightbox' ) );
        }

        $image_ids = array_values( $slides );
        $captions  = $this->slideshowCaptions( $slides );
        $title     = $slideshow->post_title ? $slideshow->post_title : __( 'Untitled Slideshow', 'ml-slider-lightbox' );

        $gallery_id = $this->gallery->createGalleryFromImages( $image_ids, $captions, $title );

        if ( is_wp_error( $gallery_id ) ) {
            wp_die( esc_html( $gallery_id->get_error_message() ) );
        }

        update_post_meta( $gallery_id, '_ml_gallery_source_slideshow', $slideshow_id );

        wp_safe_redirect( admin_url( 'admin.php?page=ml-gallery-editor&id=' . $gallery_id . '&converted=1' ) );
        exit;
    }

    /**
     * Ordered, image-only slides for a slideshow, mapped to their real
     * attachment IDs: slide_id => attachment_id.
     *
     * A "slide" in `active_slide_ids()`'s result is the object actually tagged
     * into the `ml-slider` taxonomy — for any slide created by a current
     * MetaSlider version that's an `ml-slide` wrapper post (see
     * MetaSlide::insert_slide() in the metaslider plugin), NOT the image
     * attachment itself; the attachment is only reachable via the wrapper's
     * post thumbnail (`get_post_thumbnail_id()`). Pre-`ml-slide`-era
     * slideshows tag the bare `attachment` post directly, so that case is
     * handled too. Slide-level meta (type, caption) lives on the slide_id,
     * not the attachment, hence keeping both around here.
     *
     * @since 2.35.0
     * @param int $slideshow_id Slideshow (ml-slider) post ID.
     * @return array<int,int> slide_id => attachment_id, in slide order.
     */
    private function slideshowSlides( $slideshow_id ) {
        if ( class_exists( '\MetaSlider_Slideshows' ) && method_exists( '\MetaSlider_Slideshows', 'active_slide_ids' ) ) {
            $slide_ids = array_map( 'absint', \MetaSlider_Slideshows::active_slide_ids( $slideshow_id ) );
        } else {
            $slide_ids = array_map( 'absint', get_posts( array(
                'force_no_custom_order' => true,
                'orderby'               => 'menu_order',
                'order'                 => 'ASC',
                'post_type'             => array( 'attachment', 'ml-slide' ),
                'post_status'           => array( 'inherit', 'publish' ),
                'lang'                  => '',
                'posts_per_page'        => -1,
                'fields'                => 'ids',
                'tax_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
                    array(
                        'taxonomy' => 'ml-slider',
                        'field'    => 'slug',
                        'terms'    => $slideshow_id,
                    ),
                ),
            ) ) );
        }

        $slides = array();
        foreach ( $slide_ids as $slide_id ) {
            if ( ! $this->slideHasMediaImage( $slide_id ) ) {
                continue;
            }

            $attachment_id = $this->resolveAttachmentId( $slide_id );
            if ( $attachment_id ) {
                $slides[ $slide_id ] = $attachment_id;
            }
        }

        return $slides;
    }

    /**
     * Slide types (Pro's `ml-slider_type` values) that carry a real,
     * user-picked WordPress media-library image reachable via
     * get_post_thumbnail_id() — the same mechanism a plain image slide uses.
     * Slides with no explicit type are pre-`ml-slide`-era plain images.
     *
     * Deliberately excludes: `external` (remote image URL, not a local
     * attachment), `gradient` ("Background Color", no image at all),
     * `custom_html`, `post_feed`, `woocommerce`, `folder` (these last three
     * resolve images from OTHER posts/products/a live media query at render
     * time, not a fixed image stored on the slide), and `post_images` (images
     * resolved from whatever page is being viewed, not stored on the slide).
     *
     * @since 2.35.0
     * @return string[]
     */
    private function imageCapableSlideTypes() {
        // '' = a legacy pre-`ml-slide` slide (the attachment itself, no
        // `ml-slider_type` meta ever set) — treated the same as 'image'.
        return array( '', 'image', 'vimeo', 'youtube', 'tiktok', 'local_video', 'external_video', 'html_overlay' );
    }

    /**
     * Whether a slide resolves to a real media-library image worth including
     * in the gallery.
     *
     * @since 2.35.0
     * @param int $slide_id Slide (or legacy attachment) post ID.
     * @return bool
     */
    private function slideHasMediaImage( $slide_id ) {
        $type = get_post_meta( $slide_id, 'ml-slider_type', true );
        if ( ! in_array( $type, $this->imageCapableSlideTypes(), true ) ) {
            return false;
        }

        // A Layer slide's background is either an image or a local video
        // (`ml-slider_video_source` is 'image'/'local'); only the former has
        // a real media-library image to pull in.
        if ( 'html_overlay' === $type && 'image' !== get_post_meta( $slide_id, 'ml-slider_video_source', true ) ) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a slide's real image attachment ID.
     *
     * @since 2.35.0
     * @param int $slide_id ID returned by active_slide_ids() — an `ml-slide`
     *                      wrapper post, or (legacy) the attachment itself.
     *                      For Pro types (vimeo/youtube/tiktok/local_video/
     *                      external_video/layer) the image is a cover/poster/
     *                      thumbnail image, set the same way as a plain image
     *                      slide: via set_post_thumbnail(). Some of these
     *                      (local_video, external_video, layer) treat the
     *                      cover as optional, so this can legitimately return 0.
     * @return int Attachment ID, or 0 if it can't be resolved.
     */
    private function resolveAttachmentId( $slide_id ) {
        $slide = get_post( $slide_id );
        if ( ! $slide ) {
            return 0;
        }

        if ( 'attachment' === $slide->post_type ) {
            return $slide_id;
        }

        return absint( get_post_thumbnail_id( $slide_id ) );
    }

    /**
     * Map slide captions to the gallery's caption shape (attachment ID =>
     * plain-text caption).
     *
     * MetaSlider's caption field allows HTML; the gallery caption here is
     * text-only, so any markup is stripped rather than carried over.
     *
     * @since 2.35.0
     * @param array<int,int> $slides slide_id => attachment_id.
     * @return array<int,string>
     */
    private function slideshowCaptions( array $slides ) {
        $captions = array();
        foreach ( $slides as $slide_id => $attachment_id ) {
            $caption = $this->rawSlideCaption( $slide_id );
            $caption = is_string( $caption ) ? trim( wp_strip_all_tags( $caption ) ) : '';
            if ( '' !== $caption ) {
                $captions[ $attachment_id ] = $caption;
            }
        }
        return $captions;
    }

    /**
     * Read a slide's caption from wherever its type actually stores it —
     * each MetaSlider slide type has its own field for this, there's no
     * single shared meta key:
     *  - image (and legacy pre-`ml-slide` attachments): the slide/attachment
     *    post's own `post_excerpt` — the same field WordPress's media library
     *    labels "Caption" (see MetaImageSlide save/read of `post_excerpt` in
     *    inc/slide/metaslide.image.class.php in the metaslider plugin).
     *  - vimeo / youtube / local_video / external_video: postmeta
     *    `ml-slider_caption` (metaslider-pro's modules/{type}/slide.php,
     *    via $this->add_or_update_or_delete_meta($slide_id, 'caption', ...)).
     *  - html_overlay (Layer slides) / tiktok: no caption concept at all.
     *    (`ml-slider_title` on a Layer slide is the background image's `title`
     *    HTML attribute — SEO/accessibility metadata, labeled "Background
     *    Image Title Text" in its admin tab, same category as alt text — not
     *    a caption, so it's deliberately not used as a stand-in for one.)
     *
     * @since 2.35.0
     * @param int $slide_id Slide (or legacy attachment) post ID.
     * @return string Raw (possibly HTML) caption, or '' if the type has none.
     */
    private function rawSlideCaption( $slide_id ) {
        $slide = get_post( $slide_id );
        if ( ! $slide ) {
            return '';
        }

        $type = get_post_meta( $slide_id, 'ml-slider_type', true );

        switch ( $type ) {
            // '' = a legacy pre-`ml-slide` slide (the attachment itself, no
            // `ml-slider_type` meta ever set) — same caption field as 'image'.
            case '':
            case 'image':
                return $slide->post_excerpt;

            case 'vimeo':
            case 'youtube':
            case 'local_video':
            case 'external_video':
                return get_post_meta( $slide_id, 'ml-slider_caption', true );

            default:
                return '';
        }
    }
}
