<?php
namespace MetaSlider\Lightbox;

/**
 * ML Gallery — Custom Post Type, admin editor, and shortcode.
 *
 * @package MetaSlider\Lightbox
 * @since   2.23.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the ml_gallery custom post type.
 * The list table is handled by WordPress core; add/edit uses a custom editor page.
 *
 * @since 2.23.0
 */
class MetaSliderLightboxGallery {

    /** @var string Plugin version, read from the plugin header at runtime. */
    private $version;

    /** @var bool Whether the Pro add-on is active. */
    private $is_pro;

    /**
     * Per-gallery inline CSS collected during shortcode execution, flushed in wp_footer.
     * Keyed by gallery ID to avoid duplicates when the same gallery appears twice.
     *
     * @var array<int,string>
     */
    private static $queued_css = array();

    /** @var int[] Gallery IDs rendered on the current page, keyed by ID to avoid duplicates. */
    private static $rendered_ids = array();

    /** @var bool|null Cached result of pageHasGalleryBlock() for the current request. */
    private $has_gallery_block = null;

    /**
     * @since 2.23.0
     * @param string $version Plugin version passed from the parent class.
     * @param bool   $is_pro  Whether the Pro add-on is currently active.
     * @return void
     */
    public function __construct( $version = '1.0.0', $is_pro = false ) {
        $this->version = $version;
        $this->is_pro  = $is_pro;

        add_action( 'init',                       array( $this, 'registerPostType' ) );
        add_action( 'init',                       array( $this, 'registerBlock' ) );
        add_action( 'rest_api_init',              array( $this, 'registerPreviewRoute' ) );
        add_action( 'admin_menu',                 array( $this, 'registerEditorPage' ) );
        add_action( 'admin_head',                 array( $this, 'hideEditorMenuItem' ) );
        add_action( 'load-post.php',              array( $this, 'redirectToCustomEditor' ) );
        add_action( 'load-post-new.php',          array( $this, 'redirectToCustomEditor' ) );
        add_action( 'admin_post_ml_save_gallery',              array( $this, 'saveGallery' ) );
        add_action( 'admin_post_ml_duplicate_gallery',         array( $this, 'duplicateGallery' ) );
        add_action( 'admin_enqueue_scripts',                   array( $this, 'enqueueAdminAssets' ) );
        add_action( 'all_admin_notices',                       array( $this, 'renderListHeader' ) );
        add_action( 'all_admin_notices',                       array( $this, 'renderEmptyState' ), 12 );
        add_filter( 'admin_body_class',                        array( $this, 'emptyStateBodyClass' ) );
        add_shortcode( 'ml_gallery',                           array( $this, 'galleryShortcode' ) );
        add_action( 'wp',                                      array( $this, 'detectPageGalleries' ) );
        add_action( 'admin_bar_menu',                          array( $this, 'registerAdminBar' ), 100 );
        add_action( 'wp_enqueue_scripts',                      array( $this, 'enqueueFrontendAssets' ) );
        add_action( 'wp_footer',                               array( $this, 'printInlineCss' ) );
        add_filter( 'metaslider_lightbox_load_assets',         array( $this, 'forceLoadAssetsForBlock' ), 10, 1 );
        add_filter( 'manage_ml_gallery_posts_columns',         array( $this, 'addListColumns' ) );
        add_action( 'manage_ml_gallery_posts_custom_column',   array( $this, 'renderListColumn' ), 10, 2 );
        add_action( 'wp_ajax_ml_gallery_browse_folder', array( $this, 'ajaxBrowseFolder' ) );
        add_action( 'wp_ajax_ml_gallery_import_folder', array( $this, 'ajaxImportFolder' ) );
        add_action( 'wp_ajax_ml_gallery_import_zip', array( $this, 'ajaxImportZip' ) );
        add_action( 'wp_ajax_ml_get_caption_fields', array( $this, 'ajaxGetCaptionFields' ) );
        add_action( 'wp_ajax_ml_save_caption',       array( $this, 'ajaxSaveCaptionField' ) );
        add_filter( 'ml_gallery_setting_icon', array( $this, 'settingIconFilter' ), 10, 2 );

        // Slideshow → Gallery conversion. Kept in its own file/class (given its
        // own admin_post hook) so it doesn't collide with edits made here.
        require_once plugin_dir_path( __FILE__ ) . 'class-ml-gallery-slideshow-convert.php';
        new MetaSliderLightboxSlideshowConverter( $this );
    }

    /**
     * Filter bridge so the Pro add-on can reuse the editor setting icons.
     *
     * Pro renders its own setting rows (share, autoplay, zoom, …) via the
     * ml_gallery_pro_*_fields hooks, so it can't call settingIcon() directly.
     * It pulls the matching glyph with apply_filters( 'ml_gallery_setting_icon',
     * '', $key ) instead, keeping a single source of truth for the icon set.
     *
     * @since 2.35.0
     * @param string $default Fallback markup (usually '').
     * @param string $key     Setting name.
     * @return string Icon markup, or $default when the key has no icon.
     */
    public function settingIconFilter( $default, $key ) {
        $icon = $this->settingIcon( $key );
        return '' !== $icon ? $icon : $default;
    }

    /**
     * Generate a Pro lock icon linking to the upgrade page.
     *
     * @param string $text Tooltip text shown on hover.
     * @return string HTML anchor with dashicon lock.
     */
    private function renderProLockIcon( $text = '' ) {
        if ( empty( $text ) ) {
            $text = __( 'Some of these features are available in MetaSlider Gallery Pro', 'ml-slider-lightbox' );
        }
        return '<a class="dashicons dashicons-lock ml-gallery-pro-lock ml-tipsy" title="' .
            esc_attr( $text ) . '" href="https://www.metaslider.com/upgrade-gallery/" target="_blank" rel="noopener"></a>';
    }

    /**
     * Inline SVG icon shown beside a gallery setting's label.
     *
     * Each key maps 1:1 to a lightGallery control (or, for config-only settings,
     * a representative glyph) so the editor row mirrors the button the visitor
     * sees and clicks in the gallery-window toolbar. Icons inherit the admin gray
     * via `currentColor` (styled by .ml-setting-icon). Returns '' for unmapped
     * keys so rows without a front-end counterpart render unchanged.
     *
     * @since 2.35.0
     * @param string $key Setting name.
     * @return string Icon markup wrapped in a span, or '' when the key has no icon.
     */
    private function settingIcon( $key ) {
        $a = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">';
        $z = '</svg>';

        $icons = array(
            // Layout panel.
            'columns'        => $a . '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/><path d="M15 3v18"/>' . $z,
            'columns_mobile' => $a . '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>' . $z,
            'height'         => $a . '<path d="M12 3v18"/><path d="m8 7 4-4 4 4"/><path d="m8 17 4 4 4-4"/>' . $z,
            'gap'            => $a . '<path d="M16 12h6"/><path d="M8 12H2"/><path d="M12 2v2"/><path d="M12 8v2"/><path d="M12 14v2"/><path d="M12 20v2"/><path d="m19 15 3-3-3-3"/><path d="m5 9-3 3 3 3"/>' . $z,
            // Gallery panel.
            'open_in_lightbox'     => $a . '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 8h20"/><path d="M6 6h.01"/><path d="M10 6h.01"/>' . $z,
            'show_lightbox_button' => $a . '<rect width="20" height="12" x="2" y="6" rx="2"/>' . $z,
            'button_icon'          => $a . '<path d="M8.3 10a.7.7 0 0 1-.626-1.079L11.4 3a.7.7 0 0 1 1.199-.043L16.3 8.9a.7.7 0 0 1-.573 1.1Z"/><rect x="3" y="14" width="7" height="7" rx="1"/><circle cx="17.5" cy="17.5" r="3.5"/>' . $z,
            'button_text'          => $a . '<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>' . $z,
            'button_position'      => $a . '<path d="M12 2v20"/><path d="m15 5-3-3-3 3"/><path d="m15 19-3 3-3-3"/><path d="M2 12h20"/><path d="m5 9-3 3 3 3"/><path d="m19 9 3 3-3 3"/>' . $z,
            'image_protection'     => $a . '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/>' . $z,
            // Display panel.
            'mode'          => $a . '<path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/>' . $z,
            'lightbox_size' => $a . '<path d="M11 19H5v-6"/><path d="M19 5v6h-6"/><path d="M5 19 19 5"/>' . $z,
            'controls'      => $a . '<path d="m11 17-5-5 5-5"/><path d="m18 17-5-5 5-5"/>' . $z,
            'counter'       => $a . '<circle cx="7" cy="8" r="1.4" fill="currentColor" stroke="none"/><path d="M16 5 8 19"/><circle cx="17" cy="16" r="1.4" fill="currentColor" stroke="none"/>' . $z,
            'thumbnails'    => $a . '<rect x="2.5" y="9" width="4.5" height="6" rx="1"/><rect x="9.75" y="9" width="4.5" height="6" rx="1"/><rect x="17" y="9" width="4.5" height="6" rx="1"/>' . $z,
            'pager'         => $a . '<circle cx="5" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="12" cy="12" r="1.6" fill="currentColor" stroke="none"/><circle cx="19" cy="12" r="1.6" fill="currentColor" stroke="none"/>' . $z,
            // Navigation.
            'loop'          => $a . '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>' . $z,
            'keyboard'      => $a . '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="M6 8h.01"/><path d="M10 8h.01"/><path d="M14 8h.01"/><path d="M18 8h.01"/><path d="M8 12h.01"/><path d="M12 12h.01"/><path d="M16 12h.01"/><path d="M7 16h10"/>' . $z,
            'mousewheel'    => $a . '<rect x="5" y="2" width="14" height="20" rx="7"/><path d="M12 6v4"/>' . $z,
            'swipe_close'   => $a . '<path d="M3 19V5"/><path d="m13 6-6 6 6 6"/><path d="M7 12h14"/>' . $z,
            // Toolbar panel.
            'download'      => $a . '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>' . $z,
            'share'         => $a . '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4"/><path d="m15.4 6.5-6.8 4"/>' . $z,
            'autoplay'      => '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z"/></svg>',
            'rotate'        => $a . '<path d="M21 12a9 9 0 1 1-3-6.7L21 8"/><path d="M21 3v5h-5"/>' . $z,
            'fullscreen'    => $a . '<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>' . $z,
            'zoom'          => $a . '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/><path d="M11 8v6"/><path d="M8 11h6"/>' . $z,
            'hash'          => $a . '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>' . $z,
            // Captions panel.
            'caption_display'    => $a . '<rect width="18" height="14" x="3" y="5" rx="2" ry="2"/><path d="M7 15h4"/><path d="M15 15h2"/><path d="M7 11h2"/><path d="M13 11h4"/>' . $z,
            'caption_source'     => $a . '<path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>' . $z,
            'caption_text_size'  => $a . '<path d="M21 14h-5"/><path d="M16 16v-3.5a2.5 2.5 0 0 1 5 0V16"/><path d="M4.5 13h6"/><path d="m3 16 4.5-9 4.5 9"/>' . $z,
            'caption_text_color' => $a . '<path d="M4 20h16"/><path d="m6 16 6-12 6 12"/><path d="M8 12h8"/>' . $z,
            'caption_bg_color'   => $a . '<path d="m19 11-8-8-8.6 8.6a2 2 0 0 0 0 2.8l5.2 5.2c.8.8 2 .8 2.8 0L19 11Z"/><path d="m5 2 5 5"/><path d="M2 13h15"/><path d="M22 20a2 2 0 1 1-4 0c0-1.6 1.7-2.4 2-4 .3 1.6 2 2.4 2 4Z"/>' . $z,
            'caption_transition' => $a . '<path d="M9.94 15.5A2 2 0 0 0 8.5 14.06l-6.14-1.58a.5.5 0 0 1 0-.96L8.5 9.94A2 2 0 0 0 9.94 8.5l1.58-6.14a.5.5 0 0 1 .96 0L14.06 8.5A2 2 0 0 0 15.5 9.94l6.14 1.58a.5.5 0 0 1 0 .96L15.5 14.06a2 2 0 0 0-1.44 1.44l-1.58 6.14a.5.5 0 0 1-.96 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/>' . $z,
            // Appearance panel.
            'bg_color'      => $a . '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.1-3.1a2 2 0 0 0-2.8 0L6 21"/>' . $z,
            'bg_opacity'                  => $a . '<circle cx="9" cy="9" r="7"/><circle cx="15" cy="15" r="7"/>' . $z,
            'arrow_color'                 => $a . '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>' . $z,
            'arrow_bg_color'              => $a . '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M8 12h8"/><path d="m12 8 4 4-4 4"/>' . $z,
            'close_color'                 => $a . '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>' . $z,
            'close_bg_color'              => $a . '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>' . $z,
            'toolbar_color'               => $a . '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/>' . $z,
            'toolbar_bg_color'            => $a . '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/>' . $z,
            'thumbnail_border_color'       => $a . '<line x1="22" x2="2" y1="6" y2="6"/><line x1="22" x2="2" y1="18" y2="18"/><line x1="6" x2="6" y1="2" y2="22"/><line x1="18" x2="18" y1="2" y2="22"/>' . $z,
            'thumbnail_border_hover_color' => $a . '<line x1="22" x2="2" y1="6" y2="6"/><line x1="22" x2="2" y1="18" y2="18"/><line x1="6" x2="6" y1="2" y2="22"/><line x1="18" x2="18" y1="2" y2="22"/>' . $z,
            // "Open in Gallery" button / icon trigger colours.
            'button_text_color'           => $a . '<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>' . $z,
            'button_hover_text_color'     => $a . '<path d="M4 7V4h16v3"/><path d="M9 20h6"/><path d="M12 4v16"/>' . $z,
            'button_color'                => $a . '<rect width="20" height="12" x="2" y="6" rx="2"/>' . $z,
            'button_hover_color'          => $a . '<rect width="20" height="12" x="2" y="6" rx="2"/>' . $z,
            'icon_color'                  => $a . '<path d="M8.3 10a.7.7 0 0 1-.626-1.079L11.4 3a.7.7 0 0 1 1.199-.043L16.3 8.9a.7.7 0 0 1-.573 1.1Z"/><rect x="3" y="14" width="7" height="7" rx="1"/><circle cx="17.5" cy="17.5" r="3.5"/>' . $z,
            'icon_hover_color'            => $a . '<path d="M8.3 10a.7.7 0 0 1-.626-1.079L11.4 3a.7.7 0 0 1 1.199-.043L16.3 8.9a.7.7 0 0 1-.573 1.1Z"/><rect x="3" y="14" width="7" height="7" rx="1"/><circle cx="17.5" cy="17.5" r="3.5"/>' . $z,
            'icon_background_color'       => $a . '<rect width="18" height="18" x="3" y="3" rx="2"/>' . $z,
            'icon_background_hover_color' => $a . '<rect width="18" height="18" x="3" y="3" rx="2"/>' . $z,
            // Image Styles panel.
            'filter'        => $a . '<circle cx="12" cy="12" r="10"/><path d="m14.31 8 5.74 9.94"/><path d="M9.69 8h11.48"/><path d="m7.38 12 5.74-9.94"/><path d="M9.69 16 3.95 6.06"/><path d="M14.31 16H2.83"/><path d="m16.62 12-5.74 9.94"/>' . $z,
            'corner_radius' => $a . '<path d="M4 20v-8a8 8 0 0 1 8-8h8"/>' . $z,
            'border_width'  => $a . '<rect width="18" height="18" x="3" y="3" rx="2"/>' . $z,
            'border_style'  => $a . '<path d="M5 3a2 2 0 0 0-2 2"/><path d="M19 3a2 2 0 0 1 2 2"/><path d="M21 19a2 2 0 0 1-2 2"/><path d="M5 21a2 2 0 0 1-2-2"/><path d="M9 3h1"/><path d="M14 3h1"/><path d="M9 21h1"/><path d="M14 21h1"/><path d="M3 9v1"/><path d="M21 9v1"/><path d="M3 14v1"/><path d="M21 14v1"/>' . $z,
            'border_color'  => $a . '<circle cx="13.5" cy="6.5" r=".5" fill="currentColor" stroke="none"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor" stroke="none"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor" stroke="none"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor" stroke="none"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.555-2.503 5.555-5.554C21.965 6.012 17.461 2 12 2z"/>' . $z,
            'box_shadow'    => $a . '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>' . $z,
            'opacity'       => $a . '<circle cx="9" cy="9" r="7"/><circle cx="15" cy="15" r="7"/>' . $z,
            'flip'          => $a . '<path d="M8 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3"/><path d="M16 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-3"/><path d="M12 20v2"/><path d="M12 14v2"/><path d="M12 8v2"/><path d="M12 2v2"/>' . $z,
            // Pro-only settings (rendered by the Pro add-on via ml_gallery_setting_icon).
            'autoplay_interval'           => $a . '<path d="M10 2h4"/><path d="M12 14v-4"/><circle cx="12" cy="14" r="8"/>' . $z,
            'autoplay_progress_bar_color' => '<svg viewBox="0 0 24 24" fill="currentColor" stroke="none" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7z"/></svg>',
        );

        if ( empty( $icons[ $key ] ) ) {
            return '';
        }

        return '<span class="ml-setting-icon" aria-hidden="true">' . $icons[ $key ] . '</span>';
    }

    private function allowedModes() {
        return array(
            'lg-fade'        => __( 'Fade', 'ml-slider-lightbox' ),
            'lg-slide'       => __( 'Slide', 'ml-slider-lightbox' ),
            'lg-zoom-in-out' => __( 'Zoom', 'ml-slider-lightbox' ),
        );
    }

    private function allowedCaptionTransitions() {
        return array(
            'none'       => __( 'None', 'ml-slider-lightbox' ),
            'fade'       => __( 'Fade', 'ml-slider-lightbox' ),
            'slide-up'   => __( 'Slide Up', 'ml-slider-lightbox' ),
            'slide-down' => __( 'Slide Down', 'ml-slider-lightbox' ),
        );
    }

    /**
     * Allowed caption display modes for a gallery.
     *
     * Drives the editor dropdown and saveGallery() validation.
     *
     * @since 2.23.0
     * @return array<string,string> value => translatable label
     */
    private function allowedCaptionDisplay() {
        return array(
            'hidden'   => __( 'Hidden', 'ml-slider-lightbox' ),
            'both'     => __( 'Gallery + Window', 'ml-slider-lightbox' ),
            'lightbox' => __( 'Window Only', 'ml-slider-lightbox' ),
            'gallery'  => __( 'Gallery Only', 'ml-slider-lightbox' ),
        );
    }

    /**
     * Caption content sources for a gallery, keyed by stored value.
     *
     * Filterable so Pro (e.g. EXIF/metadata sources) can append entries without
     * forking the control — the same bridge pattern as ml_gallery_setting_icon.
     * This is the single source of truth for both the editor <select> options
     * and the save-time whitelist, so any Pro-registered key survives save.
     *
     * @since 2.35.0
     * @return array<string,string> value => translated label.
     */
    private function captionSources() {
        return apply_filters(
            'ml_gallery_caption_sources',
            array(
                'manual'            => __( 'Manual entry', 'ml-slider-lightbox' ),
                'media_caption'     => __( 'Media caption', 'ml-slider-lightbox' ),
                'media_description' => __( 'Media description', 'ml-slider-lightbox' ),
            )
        );
    }

    /**
     * Whether a caption source's field is user-editable from the inline editor.
     *
     * Parallel to captionSources() (which stays value => label for #563). Pro
     * read-only sources (e.g. EXIF/GPS) register false via the filter so the
     * modal degrades them to a read-only tab with no further editor changes.
     *
     * @since 2.36.0
     * @param string $source A captionSources() key.
     * @return bool
     */
    private function captionSourceEditable( $source ) {
        $editable = in_array( $source, array( 'manual', 'media_caption', 'media_description' ), true );

        /**
         * Filter whether a caption source is editable inline.
         *
         * @param bool   $editable Default editability.
         * @param string $source   The caption source key.
         */
        return (bool) apply_filters( 'ml_gallery_caption_source_editable', $editable, $source );
    }

    /**
     * Resolve the caption text for one gallery item from the chosen source.
     *
     * manual            → the typed caption, and only that; empty stays empty.
     *                      No fallback — "Manual" means exactly what the user typed,
     *                      so images without a caption show none (matches the editor).
     * media_caption     → the attachment caption; if empty, the manual caption.
     * media_description → the attachment description (post_content); if empty, manual.
     * Unknown/Pro sources hit the manual branch unless Pro filters resolution.
     *
     * @since 2.35.0
     * @param int    $image_id Attachment ID.
     * @param string $manual   The gallery's manually-typed caption for this item.
     * @param string $source   A captionSources() key.
     * @return string Caption text (may contain HTML for descriptions/manual).
     */
    private function resolveItemCaption( $image_id, $manual, $source ) {
        $manual = (string) $manual;

        switch ( $source ) {
            case 'media_caption':
                $text = (string) wp_get_attachment_caption( $image_id );
                return '' !== $text ? $text : $manual;

            case 'media_description':
                $post = get_post( $image_id );
                $text = $post ? (string) $post->post_content : '';
                return '' !== $text ? $text : $manual;

            case 'manual':
            default:
                return $manual;
        }
    }

    /**
     * Raw stored value of ONE caption source's backing field (no fallback).
     *
     * Unlike resolveItemCaption(), this never falls back between fields — the
     * inline editor needs each tab to show its own true value.
     *
     * @since 2.36.0
     * @param int    $image_id   Attachment ID.
     * @param string $source     A captionSources() key.
     * @param int    $gallery_id Gallery post ID (0 for an unsaved gallery).
     * @return string
     */
    private function captionFieldRaw( $image_id, $source, $gallery_id ) {
        switch ( $source ) {
            case 'media_caption':
                return (string) get_post_field( 'post_excerpt', $image_id );

            case 'media_description':
                return (string) get_post_field( 'post_content', $image_id );

            case 'manual':
                if ( ! $gallery_id ) {
                    return '';
                }
                $captions = get_post_meta( $gallery_id, '_ml_gallery_captions', true );
                return is_array( $captions ) && isset( $captions[ $image_id ] )
                    ? (string) $captions[ $image_id ]
                    : '';

            default:
                return '';
        }
    }

    /**
     * Effective editability of a source for a given attachment + current user.
     *
     * @since 2.36.0
     * @param int    $image_id Attachment ID.
     * @param string $source   A captionSources() key.
     * @return array{0:bool,1:string} [ editable, reason ] reason: '', 'readonly', 'perm'.
     */
    private function captionFieldEditable( $image_id, $source ) {
        if ( 'manual' === $source ) {
            return array( true, '' );
        }

        if ( ! $this->captionSourceEditable( $source ) ) {
            return array( false, 'readonly' );
        }

        // Media sources write to the attachment: require edit_post on it.
        if ( in_array( $source, array( 'media_caption', 'media_description' ), true )
            && ! current_user_can( 'edit_post', $image_id ) ) {
            return array( false, 'perm' );
        }

        return array( true, '' );
    }

    /**
     * Resolve the caption display mode from raw saved settings, migrating the
     * legacy boolean `captions` flag.
     *
     * Order: an explicit valid `caption_display` wins; else legacy `captions`
     * (1 => lightbox, 0 => hidden); else default `lightbox`.
     *
     * @since 2.23.0
     * @param mixed $saved Raw _ml_gallery_settings meta (array or not).
     * @return string One of array_keys( allowedCaptionDisplay() ).
     */
    private function resolveCaptionDisplay( $saved ) {
        $saved = is_array( $saved ) ? $saved : array();

        if ( isset( $saved['caption_display'] )
            && array_key_exists( $saved['caption_display'], $this->allowedCaptionDisplay() ) ) {
            return $saved['caption_display'];
        }

        if ( isset( $saved['captions'] ) ) {
            return 1 === (int) $saved['captions'] ? 'lightbox' : 'hidden';
        }

        return 'lightbox';
    }

    /**
     * Sanitize a hex color, returning the default when empty or invalid.
     *
     * @param string|null $raw     Raw color value (may be unset/empty).
     * @param string      $default Fallback color when sanitization yields nothing.
     * @return string Sanitized hex color, or the default.
     */
    private function sanitizeColorValue( $raw, $default ) {
        return sanitize_hex_color( $raw ?? '' ) ?: $default;
    }

    /**
     * Convert a 3- or 6-digit hex color to an rgba() string at the given alpha.
     *
     * @param string $hex   Hex color (e.g. #fff or #ffffff); assumed already
     *                      validated via sanitize_hex_color().
     * @param float  $alpha Alpha 0.0–1.0.
     * @return string rgba(r, g, b, a)
     */
    private function hexToRgba( $hex, $alpha ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        return sprintf(
            'rgba(%d, %d, %d, %s)',
            $r,
            $g,
            $b,
            rtrim( rtrim( sprintf( '%.2f', $alpha ), '0' ), '.' )
        );
    }

    /**
     * Clamp an opacity value to the 0.0–1.0 range.
     *
     * @param mixed $value Raw opacity value.
     * @return float Clamped opacity.
     */
    private function clampOpacity( $value ) {
        return min( 1.0, max( 0.0, (float) $value ) );
    }

    private function defaultSettings() {
        return array(
            'mode'        => 'lg-fade',
            'controls'    => 1,
            'counter'     => 1,
            'thumbnails'  => 1,
            'download'    => 0,
            'caption_display' => 'lightbox',
            'caption_source'  => 'manual',
            'loop'        => 1,
            'swipe_close' => 1,
            'mousewheel'  => 1,
            'keyboard'    => 1,
            'layout'           => 'grid',
            'columns'          => 3,
            'columns_mobile'   => 1,
            'height'           => 220,
            'gap'              => 8,
            'lightbox_size'    => 'full',
            'add_position'     => 'end',
            'open_in_lightbox' => 1,
            'show_lightbox_button' => 0,
            'button_icon'          => 0,
            'button_text'          => '',
            'button_position'      => 'top-right',
            // Pro-only settings (default off; rendered as locked for free users)
            'zoom'        => 0,
            'fullscreen'  => 0,
            'rotate'      => 0,
            'autoplay'    => 0,
            'share'       => 0,
            'image_protection' => 0,
        );
    }

    private function allowedLayouts() {
        return array( 'grid', 'masonry', 'justified', 'carousel', 'showcase' );
    }

    private function allowedButtonPositions() {
        return array( 'top-right', 'top-left', 'bottom-right', 'bottom-left', 'center' );
    }

    private function sanitizeImageSize( $size ) {
        $allowed = array_merge( get_intermediate_image_sizes(), array( 'full' ) );
        return in_array( $size, $allowed, true ) ? $size : 'full';
    }

    /**
     * Default appearance settings for a gallery.
     *
     * @since 2.23.0
     * @return array<string,string>
     */
    private function defaultAppearance() {
        return array(
            'bg_color'               => '#000000',
            'bg_opacity'             => '0.9',
            'arrow_color'            => '#ffffff',
            'arrow_bg_color'         => '#000000',
            'close_color'            => '#ffffff',
            'close_bg_color'         => '#000000',
            'toolbar_color'          => '#ffffff',
            'toolbar_bg_color'       => '#000000',
            'thumbnail_border_color'       => '#ffffff',
            'thumbnail_border_hover_color' => '#dd6923',
            // "Open in Gallery" button colours.
            'button_text_color'       => '#ffffff',
            'button_hover_text_color' => '#000000',
            'button_color'            => '#000000',
            'button_hover_color'      => '#f0f0f0',
            // Trigger icon colours.
            'icon_color'                  => '#ffffff',
            'icon_hover_color'            => '#000000',
            'icon_background_color'       => '#000000',
            'icon_background_hover_color' => '#f0f0f0',
            // Autoplay progress bar (Pro).
            'autoplay_progress_bar_color' => '#a90707',
            'caption_text_color' => '#ffffff',
            'caption_bg_color'   => '#000000',
            'caption_text_size'  => '14',
            'caption_transition' => 'none',
        );
    }

    /**
     * Default "Image Styles" settings for a gallery.
     *
     * These apply to every image in the gallery grid (filter, rounded corners,
     * border, box shadow, opacity, rotate, flip).
     *
     * @return array<string,mixed>
     */
    private function defaultImageStyles() {
        return array(
            'filter'        => '',
            'corner_radius' => 0,
            'border_width'  => 0,
            'border_style'  => 'solid',
            'border_color'  => '#dddddd',
            'box_shadow'    => 'none',
            'opacity'       => 100,
            'rotate'        => '0',
            'flip'          => 'none',
        );
    }

    /**
     * CSS `filter` recipes keyed by preset slug.
     *
     * @return array<string,string>
     */
    private function filterPresets() {
        return array(
            'noir'     => 'grayscale(100%) contrast(120%)',
            'silver'   => 'grayscale(100%) contrast(130%) brightness(105%)',
            'vintage'  => 'sepia(55%) contrast(110%) brightness(105%)',
            'golden'   => 'sepia(35%) saturate(150%) hue-rotate(-15deg) brightness(105%)',
            'toaster'  => 'sepia(40%) contrast(120%) brightness(95%) saturate(110%)',
            'warm'     => 'saturate(130%) sepia(20%)',
            'cool'     => 'saturate(110%) hue-rotate(15deg) brightness(105%)',
            'fade'     => 'contrast(85%) brightness(110%) saturate(80%)',
            'matte'    => 'contrast(80%) brightness(112%) saturate(85%)',
            'pastel'   => 'brightness(115%) saturate(75%) contrast(90%)',
            'vivid'    => 'saturate(160%) contrast(110%)',
            'crisp'    => 'contrast(140%) saturate(135%) brightness(102%)',
            'dramatic' => 'contrast(140%) brightness(95%) saturate(120%)',
            'negative' => 'invert(100%)',
        );
    }

    /**
     * Human-readable labels for the filter presets (keyed by slug, '' = None).
     *
     * @return array<string,string>
     */
    private function filterPresetLabels() {
        return array(
            ''         => __( 'None', 'ml-slider-lightbox' ),
            'noir'     => __( 'Noir (B&W)', 'ml-slider-lightbox' ),
            'silver'   => __( 'Silver (B&W)', 'ml-slider-lightbox' ),
            'vintage'  => __( 'Vintage', 'ml-slider-lightbox' ),
            'golden'   => __( 'Golden Hour', 'ml-slider-lightbox' ),
            'toaster'  => __( 'Toaster', 'ml-slider-lightbox' ),
            'warm'     => __( 'Warm', 'ml-slider-lightbox' ),
            'cool'     => __( 'Cool', 'ml-slider-lightbox' ),
            'fade'     => __( 'Fade', 'ml-slider-lightbox' ),
            'matte'    => __( 'Matte', 'ml-slider-lightbox' ),
            'pastel'   => __( 'Pastel', 'ml-slider-lightbox' ),
            'vivid'    => __( 'Vivid', 'ml-slider-lightbox' ),
            'crisp'    => __( 'Crisp', 'ml-slider-lightbox' ),
            'dramatic' => __( 'Dramatic', 'ml-slider-lightbox' ),
            'negative' => __( 'Negative', 'ml-slider-lightbox' ),
        );
    }

    /**
     * Box-shadow recipes keyed by preset slug.
     *
     * @param string $key Shadow preset (none|light|medium|heavy).
     * @return string The box-shadow value, or '' for none/unknown.
     */
    private function shadowRecipe( $key ) {
        $map = array(
            'light'  => '0 2px 8px rgba(0,0,0,0.15)',
            'medium' => '0 4px 16px rgba(0,0,0,0.25)',
            'heavy'  => '0 8px 30px rgba(0,0,0,0.35)',
        );
        return isset( $map[ $key ] ) ? $map[ $key ] : '';
    }

    /**
     * Build the scoped front-end CSS for a gallery's Image Styles.
     *
     * Content effects (filter, opacity, transform) target the thumbnails
     * (`#ml-gallery-{id} img`); frame effects (corner radius, border, box
     * shadow) target the link wrappers (`#ml-gallery-{id} > a`). The filter
     * also carries through to the lightbox overlay image so the styled look is
     * consistent; other effects (opacity, frame, transform) stay on the grid.
     *
     * @param int   $gallery_id Gallery (post) ID.
     * @param array $styles     Resolved image-style settings.
     * @return string CSS (may be empty if no styles are active).
     */
    private function buildImageStylesCss( $gallery_id, $styles ) {
        $sel  = "#ml-gallery-{$gallery_id} img";
        $css  = '';

        // Filter.
        $presets = $this->filterPresets();
        $filter  = ( '' !== $styles['filter'] && isset( $presets[ $styles['filter'] ] ) ) ? $presets[ $styles['filter'] ] : '';
        if ( '' !== $filter ) {
            $css .= "\n{$sel}{-webkit-filter:{$filter};filter:{$filter};}";
            $lightbox_sel = ".lg-container.ml-gallery-{$gallery_id} .lg-image,"
                . ".lg-container.ml-gallery-{$gallery_id} .lg-thumb-item img";
            $css .= "\n{$lightbox_sel}{-webkit-filter:{$filter};filter:{$filter};}";
        }

        // Split declarations across two selectors:
        //  - Content effects (opacity, transform) stay on the image itself.
        //  - Frame effects (corner radius, border, box shadow) go on the link
        //    wrapper. This keeps the `filter` above (on the image) from
        //    desaturating the border colour, and stops the wrapper's own
        //    `overflow:hidden` from clipping the box shadow.
        $sel_frame  = "#ml-gallery-{$gallery_id} > a";
        $img_decl   = array();
        $frame_decl = array();

        $radius = max( 0, (int) $styles['corner_radius'] );
        if ( $radius > 0 ) {
            $frame_decl[] = "border-radius:{$radius}px";
        }

        $bw = max( 0, (int) $styles['border_width'] );
        if ( $bw > 0 ) {
            $bs = in_array( $styles['border_style'], array( 'solid', 'dashed', 'dotted', 'double' ), true ) ? $styles['border_style'] : 'solid';
            $bc = sanitize_hex_color( $styles['border_color'] ) ? sanitize_hex_color( $styles['border_color'] ) : '#dddddd';
            $frame_decl[] = "border:{$bw}px {$bs} {$bc}";
        }

        $shadow = $this->shadowRecipe( $styles['box_shadow'] );
        if ( '' !== $shadow ) {
            $frame_decl[] = "box-shadow:{$shadow}";
        }

        $opacity = max( 0, min( 100, (int) $styles['opacity'] ) );
        if ( $opacity < 100 ) {
            $img_decl[] = 'opacity:' . round( $opacity / 100, 2 );
        }

        $transform = array();
        $deg = (int) $styles['rotate'];
        if ( 0 !== $deg ) {
            $transform[] = "rotate({$deg}deg)";
        }
        if ( 'h' === $styles['flip'] ) {
            $transform[] = 'scaleX(-1)';
        } elseif ( 'v' === $styles['flip'] ) {
            $transform[] = 'scaleY(-1)';
        } elseif ( 'both' === $styles['flip'] ) {
            $transform[] = 'scale(-1,-1)';
        }
        if ( ! empty( $transform ) ) {
            $img_decl[] = 'transform:' . implode( ' ', $transform );
        }

        if ( ! empty( $img_decl ) ) {
            $css .= "\n{$sel}{" . implode( ';', $img_decl ) . ';}';
        }
        if ( ! empty( $frame_decl ) ) {
            $css .= "\n{$sel_frame}{" . implode( ';', $frame_decl ) . ';}';
        }

        return $css;
    }

    /**
     * Register the ml_gallery custom post type.
     *
     * @since 2.23.0
     * @return void
     */
    public function registerPostType() {
        $labels = array(
            'name'               => __( 'Galleries', 'ml-slider-lightbox' ),
            'singular_name'      => __( 'Gallery', 'ml-slider-lightbox' ),
            'add_new'            => __( 'Add New Gallery', 'ml-slider-lightbox' ),
            'add_new_item'       => __( 'Add New Gallery', 'ml-slider-lightbox' ),
            'edit_item'          => __( 'Edit Gallery', 'ml-slider-lightbox' ),
            'new_item'           => __( 'New Gallery', 'ml-slider-lightbox' ),
            'search_items'       => __( 'Search Galleries', 'ml-slider-lightbox' ),
            'not_found'          => __( 'No galleries found.', 'ml-slider-lightbox' ),
            'not_found_in_trash' => __( 'No galleries found in trash.', 'ml-slider-lightbox' ),
            'menu_name'          => __( 'Galleries', 'ml-slider-lightbox' ),
        );

        register_post_type(
            'ml_gallery',
            array(
                'labels'             => $labels,
                'public'             => false,
                'show_ui'            => true,
                'show_in_menu'       => 'metaslider-lightbox',
                'show_in_admin_bar'  => false,
                'publicly_queryable' => false,
                'show_in_rest'       => true,
                'rest_base'          => 'ml-galleries',
                'query_var'          => false,
                'rewrite'            => false,
                'supports'           => array( 'title' ),
                'has_archive'        => false,
                'hierarchical'       => false,
                'capability_type'    => 'post',
                'capabilities'       => array(
                    'create_posts' => 'manage_options',
                    'edit_posts'   => 'manage_options',
                ),
                'map_meta_cap'       => true,
            )
        );
    }

    /**
     * Register the Gutenberg block.
     *
     * @since 2.23.0
     * @return void
     */
    public function registerBlock() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }
        register_block_type(
            plugin_dir_path( __FILE__ ) . 'blocks/ml-gallery/block.json',
            array( 'render_callback' => array( $this, 'renderBlock' ) )
        );
    }

    /**
     * Register the REST route used by the block editor preview iframe.
     *
     * @since 2.23.0
     * @return void
     */
    public function registerPreviewRoute() {
        register_rest_route(
            'ml-slider-lightbox/v1',
            '/gallery/preview',
            array(
                'methods'             => \WP_REST_Server::READABLE . ',' . \WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'previewGallery' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                },
                'args'                => array(
                    'id' => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                ),
            )
        );
    }

    /**
     * Add the MetaSlider Gallery node to the WordPress admin bar on the frontend.
     * Shows "All Galleries", "Create Gallery", and an edit link for each gallery
     * rendered on the current page.
     *
     * @since 2.23.0
     * @param \WP_Admin_Bar $wp_admin_bar Admin bar instance.
     * @return void
     */

    /**
     * Scan the current page's post content for gallery shortcodes and blocks
     * so the admin bar can be populated before content is rendered.
     * Runs on the `wp` hook, before wp_body_open fires the admin bar.
     *
     * @return void
     */
    public function detectPageGalleries() {
        if ( is_admin() || ! is_singular() ) {
            return;
        }

        $post = get_post();
        if ( ! $post ) {
            return;
        }

        $content = $post->post_content;
        $ids     = array();

        // [ml_gallery id="X"] shortcodes
        preg_match_all( '/\[ml_gallery[^\]]*\bid=["\']?(\d+)["\']?/i', $content, $m );
        $ids = $m[1];

        // Gutenberg block: "galleryId":X — require , or } after to avoid prefix collisions
        preg_match_all( '/"galleryId"\s*:\s*(\d+)[,}]/', $content, $m );
        $ids = array_merge( $ids, $m[1] );

        $ids = array_unique( array_map( 'intval', $ids ) );
        if ( empty( $ids ) ) {
            return;
        }

        $gallery_posts = get_posts( array(
            'post__in'       => $ids,
            'post_type'      => 'ml_gallery',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
        ) );

        foreach ( $gallery_posts as $gallery_post ) {
            self::$rendered_ids[ $gallery_post->ID ] = $gallery_post;
        }
    }

    public function registerAdminBar( $wp_admin_bar ) {
        if ( is_admin() || ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
            return;
        }

        if ( empty( self::$rendered_ids ) ) {
            return;
        }

        $icon = '<div id="metaslider-main-menu-icon" class="ab-item svg" style="background-image:url(\'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHN2ZyBmaWxsPSIjYTdhYWFkIiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4IiB2aWV3Qm94PSIwIDAgMjU1LjggMjU1LjgiIHN0eWxlPSJmaWxsOiNhN2FhYWQiIHhtbDpzcGFjZT0icHJlc2VydmUiPjxnPjxwYXRoIGQ9Ik0xMjcuOSwwQzU3LjMsMCwwLDU3LjMsMCwxMjcuOWMwLDcwLjYsNTcuMywxMjcuOSwxMjcuOSwxMjcuOWM3MC42LDAsMTI3LjktNTcuMywxMjcuOS0xMjcuOUMyNTUuOCw1Ny4zLDE5OC41LDAsMTI3LjksMHogTTE2LjQsMTc3LjFsOTIuNS0xMTcuNUwxMjQuMiw3OWwtNzcuMyw5OC4xSDE2LjR6IE0xNzAuNSwxNzcuMWwtMzguOS00OS40bDE1LjUtMTkuNmw1NC40LDY5SDE3MC41eiBNMjA4LjUsMTc3LjFMMTQ2LjksOTkgbC02MS42LDc4LjJoLTMxbDkyLjUtMTE3LjVsOTIuNSwxMTcuNUgyMDguNXoiLz48L2c+PC9zdmc+Cg==\') !important;display:inline-block;width:20px;height:20px;vertical-align:middle;position:relative;top:-1px;margin-right:4px;background-size:contain;background-repeat:no-repeat;background-position:center"></div>';

        $wp_admin_bar->add_node( array(
            'id'    => 'ml-gallery',
            'title' => $icon . __( 'Gallery', 'ml-slider-lightbox' ),
            'href'  => admin_url( 'edit.php?post_type=ml_gallery' ),
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'ml-gallery',
            'id'     => 'ml-gallery-all',
            'title'  => __( 'All Galleries', 'ml-slider-lightbox' ),
            'href'   => admin_url( 'edit.php?post_type=ml_gallery' ),
        ) );

        $wp_admin_bar->add_node( array(
            'parent' => 'ml-gallery',
            'id'     => 'ml-gallery-new',
            'title'  => __( 'Create Gallery', 'ml-slider-lightbox' ),
            'href'   => admin_url( 'post-new.php?post_type=ml_gallery' ),
        ) );

        foreach ( self::$rendered_ids as $gallery_id => $post ) {
            $wp_admin_bar->add_node( array(
                'parent' => 'ml-gallery',
                'id'     => 'ml-gallery-edit-' . $gallery_id,
                'title'  => sprintf(
                    /* translators: %s: gallery title */
                    __( 'Edit &#8220;%s&#8221;', 'ml-slider-lightbox' ),
                    esc_html( $post->post_title )
                ),
                'href'   => admin_url( 'post.php?post=' . $gallery_id . '&action=edit' ),
            ) );
        }
    }

    /**
     * Return a self-contained HTML document for the block editor iframe preview.
     *
     * @since 2.23.0
     * @param WP_REST_Request $request REST request.
     * @return WP_REST_Response|WP_Error
     */
    public function previewGallery( $request ) {
        $id = $request->get_param( 'id' ); // already absint via route sanitize_callback
        if ( ! $id ) {
            return new \WP_Error( 'invalid_id', __( 'Invalid gallery ID.', 'ml-slider-lightbox' ), array( 'status' => 400 ) );
        }

        $interactive = (bool) $request->get_param( 'interactive' );

        // Preview-only viewport switch. 'mobile' clamps the iframe (client-side)
        // and drops the desktop-column override below so columns_mobile applies.
        $viewport = 'mobile' === $request->get_param( 'viewport' ) ? 'mobile' : 'desktop';

        self::$queued_css = array();
        if ( 'POST' === $request->get_method() ) {
            $content = $this->renderGallery( $this->previewStateFromRequest( $id, $request ) );
        } else {
            $content = $this->galleryShortcode( array( 'id' => $id ) );
        }
        $inline_css = implode( '', self::$queued_css );

        // Fast-path: image-styles/appearance edits only change the per-gallery CSS.
        // Return just that block so the live editor can patch it into the open
        // preview iframe in place — no reload, so an open lightbox stays open.
        if ( $request->get_param( 'css_only' ) ) {
            return new \WP_REST_Response( array( 'css' => $inline_css ) );
        }

        // Detect layout and features from rendered HTML — avoids a second get_post_meta call.
        $is_carousel = strpos( $content, 'data-ml-layout="carousel"' ) !== false;

        $plugin_url = plugin_dir_url( __FILE__ );

        $html  = '<!DOCTYPE html><html><head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<style>*,*::before,*::after{box-sizing:border-box}body{margin:0;padding:8px;overflow-x:hidden}</style>';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/lightgallery.min.css' ) . '">';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/lg-thumbnail.css' ) . '">';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/lg-transitions.min.css' ) . '">';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/ml-lightbox-public.css' ) . '">';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/ml-gallery-public.css' ) . '">';
        if ( $this->is_pro ) {
            $html .= '<link rel="stylesheet" href="' . esc_url( plugins_url( 'ml-slider-lightbox-pro/assets/css/public.css' ) ) . '">';
        }
        $html .= '<style>' . MetaSliderLightboxPlugin::getInstance()->getCustomLightboxCss() . '</style>';
        // Stable id so the live editor can patch this block in place (css_only path).
        $html .= '<style id="ml-preview-inline-css">' . $inline_css . '</style>';
        // Interactive editor preview lets clicks open the real lightbox on every
        // layout; the block preview (non-interactive) keeps clicks suppressed.
        if ( ! $is_carousel && ! $interactive ) {
            $html .= '<style>.ml-gallery-lightgallery a{pointer-events:none;cursor:default}</style>';
        }
        // The preview renders inside a narrow editor iframe, so the frontend's
        // "max-width: 768px" mobile breakpoint fires and the gallery shows the
        // mobile column count. For the desktop viewport, re-assert the desktop
        // column count here (later in source order, so it wins) so the preview
        // matches the frontend. For the mobile viewport we deliberately let the
        // breakpoint stand so columns_mobile is what the editor sees.
        if ( 'mobile' !== $viewport ) {
            $html .= '<style>@media (max-width:768px){'
                . '.ml-gallery-container.ml-layout-grid{grid-template-columns:repeat(var(--ml-columns),1fr)}'
                . '.ml-gallery-container.ml-layout-masonry{columns:var(--ml-columns)}'
                . '}</style>';
        }
        $html .= '</head><body>';
        $html .= $content;
        $html .= '<script src="' . esc_url( includes_url( 'js/jquery/jquery.min.js' ) ) . '"></script>';
        $html .= '<script src="' . esc_url( $plugin_url . 'assets/js/lightgallery.min.js' ) . '"></script>';
        // Thumbnails is a free feature, but its lightGallery plugin isn't part of
        // the core bundle. The init only adds it when `lgThumbnail` is defined, so
        // load it here (mirroring the front-end enqueue) or the thumbnail strip
        // never renders in the preview even with the setting on.
        $html .= '<script src="' . esc_url( $plugin_url . 'assets/js/lg-thumbnail.min.js' ) . '"></script>';
        if ( $is_carousel || $interactive ) {
            // Each Pro feature maps: data attribute → mlLightboxSettings key + JS/CSS filenames.
            // Read Pro feature flags from the rendered HTML so Pro plugin hooks are respected.
            $pro_features = array(
                'data-lg-captions="1"'   => array( 'setting' => 'show_captions',    'js' => null,                  'css' => null ),
                'data-lg-zoom="1"'       => array( 'setting' => 'enable_zoom',      'js' => 'lg-zoom.min.js',      'css' => 'lg-zoom.css' ),
                'data-lg-fullscreen="1"' => array( 'setting' => 'enable_fullscreen','js' => 'lg-fullscreen.min.js','css' => 'lg-fullscreen.css' ),
                'data-lg-rotate="1"'     => array( 'setting' => 'enable_rotate',    'js' => 'lg-rotate.min.js',    'css' => 'lg-rotate.css' ),
                'data-lg-autoplay="1"'   => array( 'setting' => 'enable_autoplay',  'js' => 'lg-autoplay.min.js',  'css' => 'lg-autoplay.css' ),
                'data-lg-share="1"'      => array( 'setting' => 'enable_share',     'js' => 'lg-share.min.js',     'css' => 'lg-share.css' ),
                'data-lg-pager="1"'      => array( 'setting' => null,               'js' => 'lg-pager.min.js',     'css' => 'lg-pager.css' ),
                'data-lg-hash="1"'       => array( 'setting' => null,               'js' => 'lg-hash.min.js',      'css' => null ),
            );

            $metaslider_options = array();
            foreach ( $pro_features as $attr => $feature ) {
                if ( $feature['setting'] ) {
                    $metaslider_options[ $feature['setting'] ] = strpos( $content, $attr ) !== false;
                }
            }

            // Mirror the front-end's global manual-button flag. Without this key the
            // init JS reads `undefined !== false` as true and forces the "Open in
            // Gallery" button on every grid gallery, ignoring the per-gallery toggle
            // (data-ml-show-button). Match enqueueFrontendAssets()'s localization.
            $global_lb_options = get_option( 'ml_lightbox_options', array() );
            $metaslider_options['show_lightbox_button'] = isset( $global_lb_options['show_lightbox_button'] )
                ? (bool) $global_lb_options['show_lightbox_button']
                : true;

            $settings_array = apply_filters( 'ml_gallery_preview_settings', array(
                'enable_galleries'   => true,
                'enable_on_content'  => false,
                'page_excluded'      => false,
                'license_key'        => '',
                'view_image_label'   => __( 'View image', 'ml-slider-lightbox' ),
                'metaslider_options' => $metaslider_options,
            ), $id );

            $html .= '<script>window.mlLightboxSettings=' . wp_json_encode( $settings_array ) . ';</script>';
            $init_js_path = plugin_dir_path( __FILE__ ) . 'assets/js/ml-lightgallery-init.js';
            $init_js_ver  = file_exists( $init_js_path ) ? filemtime( $init_js_path ) : '';
            $html .= '<script src="' . esc_url( $plugin_url . 'assets/js/ml-lightgallery-init.js?ver=' . $init_js_ver ) . '"></script>';

            if ( $this->is_pro ) {
                $pro_js_base  = plugins_url( 'ml-slider-lightbox-pro/assets/plugins/' );
                $pro_css_base = plugins_url( 'ml-slider-lightbox-pro/assets/css/' );
                foreach ( $pro_features as $attr => $feature ) {
                    if ( $feature['js'] && strpos( $content, $attr ) !== false ) {
                        if ( $feature['css'] ) {
                            $html .= '<link rel="stylesheet" href="' . esc_url( $pro_css_base . $feature['css'] ) . '">';
                        }
                        $html .= '<script src="' . esc_url( $pro_js_base . $feature['js'] ) . '"></script>';
                    }
                }
                $html .= '<script src="' . esc_url( plugins_url( 'ml-slider-lightbox-pro/assets/js/public.js' ) ) . '"></script>';
            }

            foreach ( (array) apply_filters( 'ml_gallery_preview_scripts', array(), $id ) as $script_url ) {
                $html .= '<script src="' . esc_url( $script_url ) . '"></script>';
            }
        }
        $html .= '<script src="' . esc_url( $plugin_url . 'assets/js/ml-gallery-layout.js' ) . '"></script>';
        $html .= '</body></html>';

        return new \WP_REST_Response( array( 'html' => $html ) );
    }

    /**
     * Build a renderGallery() state array from a live editor POST body.
     *
     * @since 2.35.0
     * @param int             $id      Gallery id (for attachment lookups + CSS scope).
     * @param WP_REST_Request $request Request whose JSON body carries the unsaved form state.
     * @return array
     */
    private function previewStateFromRequest( $id, $request ) {
        $ids_raw   = (array) $request->get_param( 'image_ids' );
        $image_ids = array_values( array_filter( array_map( 'absint', $ids_raw ) ) );

        $captions_in = (array) $request->get_param( 'captions' );
        $captions    = array();
        foreach ( $captions_in as $cid => $text ) {
            $captions[ absint( $cid ) ] = wp_kses_post( (string) $text );
        }

        $settings = wp_parse_args( (array) $request->get_param( 'settings' ), $this->defaultSettings() );
        $settings['columns']        = max( 1, absint( $settings['columns'] ?? 0 ) ) ?: $this->defaultSettings()['columns'];
        $settings['columns_mobile'] = max( 1, absint( $settings['columns_mobile'] ?? 0 ) ) ?: $this->defaultSettings()['columns_mobile'];
        $settings['height']         = min( 800, max( 80, absint( $settings['height'] ?? 0 ) ?: 220 ) );
        $settings['gap']            = absint( $settings['gap'] ?? 0 );
        $settings['download']       = empty( $settings['download'] ) ? 0 : 1;

        if ( in_array( $settings['layout'] ?? 'grid', array( 'carousel', 'showcase' ), true ) ) {
            $settings['open_in_lightbox'] = 1;
        }

        $pro_in       = (array) $request->get_param( 'pro_settings' );
        $pro_settings = array();
        foreach ( $pro_in as $key => $value ) {
            if ( is_scalar( $value ) ) {
                $pro_settings[ sanitize_key( $key ) ] = sanitize_text_field( (string) $value );
            }
        }

        return array(
            'id'           => $id,
            'image_ids'    => $image_ids,
            'captions'     => $captions,
            'settings'     => $settings,
            'appearance'   => wp_parse_args( (array) $request->get_param( 'appearance' ), $this->defaultAppearance() ),
            'image_styles' => wp_parse_args( (array) $request->get_param( 'image_styles' ), $this->defaultImageStyles() ),
            'pro_settings' => $pro_settings,
        );
    }

    /**
     * Server-side render callback for the ml-slider-lightbox/gallery block.
     *
     * @since 2.23.0
     * @param array $attributes Block attributes.
     * @return string HTML output.
     */
    public function renderBlock( $attributes ) {
        if ( empty( $attributes['galleryId'] ) ) {
            return '';
        }
        $output = $this->galleryShortcode( array( 'id' => (int) $attributes['galleryId'] ) );
        $class  = 'ml-gallery-block' . ( ! empty( $attributes['isFullWidth'] ) ? ' is-full-width' : '' );
        return '<div class="' . esc_attr( $class ) . '">' . $output . '</div>';
    }

    /**
     * Register the hidden editor page (no menu entry).
     * Accessed via redirect from post.php / post-new.php.
     *
     * @since 2.23.0
     * @return void
     */
    public function registerEditorPage() {
        add_submenu_page(
            'metaslider-lightbox',
            __( 'Edit Gallery', 'ml-slider-lightbox' ),
            __( 'Edit Gallery', 'ml-slider-lightbox' ),
            'manage_options',
            'ml-gallery-editor',
            array( $this, 'renderEditPage' )
        );
    }

    /**
     * Output a small inline style on every admin page to suppress the
     * auto-generated "Edit Gallery" submenu link. The rule must be global
     * because the sidebar renders on all admin screens.
     *
     * @since 2.23.0
     * @return void
     */
    public function hideEditorMenuItem() {
        echo '<style>#adminmenu a[href="admin.php?page=ml-gallery-editor"]{display:none!important}</style>';
    }

    /**
     * Redirect WP's default post.php and post-new.php to our custom editor
     * whenever the post type is ml_gallery.
     *
     * @since 2.23.0
     * @return void
     */
    public function redirectToCustomEditor() {
        $post_id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';

        if ( $post_id ) {
            $post = get_post( $post_id );
            if ( $post && 'ml_gallery' === $post->post_type ) {
                wp_safe_redirect( admin_url( 'admin.php?page=ml-gallery-editor&id=' . $post_id ) );
                exit;
            }
        } elseif ( 'ml_gallery' === $post_type ) {
            wp_safe_redirect( admin_url( 'admin.php?page=ml-gallery-editor' ) );
            exit;
        }
    }

    /**
     * Render the custom gallery add/edit page.
     * Layout: top bar | left sidebar (details) | right main (image picker).
     *
     * @since 2.23.0
     * @return void
     */
    public function renderEditPage() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'ml-slider-lightbox' ) );
        }

        $gallery_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $post       = $gallery_id ? get_post( $gallery_id ) : null;
        $is_new     = ! $post || 'ml_gallery' !== $post->post_type;

        if ( $gallery_id && $is_new ) {
            wp_die( esc_html__( 'Gallery not found.', 'ml-slider-lightbox' ) );
        }

        $title = $post ? $post->post_title : __( 'New Gallery', 'ml-slider-lightbox' );
        $image_ids = $post ? get_post_meta( $post->ID, '_ml_gallery_images', true ) : array();
        $image_ids = is_array( $image_ids )
            ? array_values( array_filter( array_map( 'absint', $image_ids ) ) )
            : array();

        $saved_settings = $post ? get_post_meta( $post->ID, '_ml_gallery_settings', true ) : array();
        $lg_settings    = wp_parse_args( is_array( $saved_settings ) ? $saved_settings : array(), $this->defaultSettings() );

        $saved_appearance = $post ? get_post_meta( $post->ID, '_ml_gallery_appearance', true ) : array();
        $appearance       = wp_parse_args( is_array( $saved_appearance ) ? $saved_appearance : array(), $this->defaultAppearance() );
        $caption_display = $this->resolveCaptionDisplay( $saved_settings );
        $caption_source  = array_key_exists( $lg_settings['caption_source'] ?? '', $this->captionSources() )
            ? $lg_settings['caption_source'] : 'manual';

        $saved_image_styles = $post ? get_post_meta( $post->ID, '_ml_gallery_image_styles', true ) : array();
        $image_styles       = wp_parse_args( is_array( $saved_image_styles ) ? $saved_image_styles : array(), $this->defaultImageStyles() );

        $saved_captions = $post ? get_post_meta( $post->ID, '_ml_gallery_captions', true ) : array();
        $captions       = is_array( $saved_captions ) ? $saved_captions : array();

        $page_heading = $is_new
            ? __( 'Add New Gallery', 'ml-slider-lightbox' )
            : __( 'Edit Gallery', 'ml-slider-lightbox' );
        $save_label   = $is_new
            ? __( 'Save Gallery', 'ml-slider-lightbox' )
            : __( 'Update Gallery', 'ml-slider-lightbox' );
        ?>
        <div class="ml-lightbox-wrap">

            <?php do_action( 'metaslider_lightbox_admin_notices' ); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'ml_save_gallery', 'ml_gallery_nonce' ); ?>
                <input type="hidden" name="action"     value="ml_save_gallery">
                <input type="hidden" name="gallery_id" value="<?php echo absint( $gallery_id ); ?>">

                <div class="ml-gallery-header">
                    <div class="ml-gallery-header-inner">
                        <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ml_gallery' ) ); ?>"
                           class="ml-gallery-logo-link"
                           title="<?php esc_attr_e( 'Back to Galleries', 'ml-slider-lightbox' ); ?>">
                            <div class="ml-gallery-logo">
                                <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">
                                    <g><path d="M127.9,0C57.3,0,0,57.3,0,127.9c0,70.6,57.3,127.9,127.9,127.9c70.6,0,127.9-57.3,127.9-127.9C255.8,57.3,198.5,0,127.9,0z M16.4,177.1l92.5-117.5L124.2,79l-77.3,98.1H16.4z M170.5,177.1l-38.9-49.4l15.5-19.6l54.4,69H170.5z M208.5,177.1L146.9,99 l-61.6,78.2h-31l92.5-117.5l92.5,117.5H208.5z"/></g>
                                </svg>
                            </div>
                            <span class="ml-gallery-logo-title"><?php esc_html_e( 'MetaSlider Gallery', 'ml-slider-lightbox' ); ?></span>
                        </a>

                        <div class="ml-gallery-header-actions">
                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=ml_gallery' ) ); ?>"
                               class="ml-gallery-toolbar-btn ml-tipsy-bottom"
                               title="<?php esc_attr_e( 'Back to Galleries', 'ml-slider-lightbox' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                <span><?php esc_html_e( 'Galleries', 'ml-slider-lightbox' ); ?></span>
                            </a>

                            <span class="ml-gallery-toolbar-sep"></span>

                            <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=ml_gallery' ) ); ?>"
                               class="ml-gallery-toolbar-btn ml-tipsy-bottom"
                               title="<?php esc_attr_e( 'Create New Gallery', 'ml-slider-lightbox' ); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span><?php esc_html_e( 'New', 'ml-slider-lightbox' ); ?></span>
                            </a>

                            <span class="ml-gallery-toolbar-sep"></span>

                            <?php if ( ! $is_new ) : ?>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ml_duplicate_gallery&gallery_id=' . absint( $gallery_id ) ), 'ml_duplicate_gallery_' . absint( $gallery_id ) ) ); ?>"
                                   class="ml-gallery-toolbar-btn">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                                    </svg>
                                    <span><?php esc_html_e( 'Duplicate', 'ml-slider-lightbox' ); ?></span>
                                </a>

                                <span class="ml-gallery-toolbar-sep"></span>
                            <?php endif; ?>

                            <button type="submit" class="ml-gallery-toolbar-btn ml-gallery-toolbar-btn--save">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4" />
                                </svg>
                                <span><?php echo esc_html( $save_label ); ?></span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="ml-lightbox-content">

                <?php if ( isset( $_GET['saved'] ) && '1' === sanitize_key( $_GET['saved'] ) ) : ?>
                    <div class="notice notice-success is-dismissible">
                        <p><?php esc_html_e( 'Gallery saved.', 'ml-slider-lightbox' ); ?></p>
                    </div>
                <?php elseif ( isset( $_GET['converted'] ) && '1' === sanitize_key( $_GET['converted'] ) ) : ?>
                    <div class="notice notice-success is-dismissible">
                        <p>
                        <?php
                        $source_id    = $post ? (int) get_post_meta( $post->ID, '_ml_gallery_source_slideshow', true ) : 0;
                        $source_title = $source_id ? get_the_title( $source_id ) : '';
                        if ( $source_title ) {
                            printf(
                                /* translators: %s: slideshow title */
                                esc_html__( 'Gallery created from the slideshow "%s". Review the settings below, then save.', 'ml-slider-lightbox' ),
                                esc_html( $source_title )
                            );
                        } else {
                            esc_html_e( 'Gallery created from your slideshow. Review the settings below, then save.', 'ml-slider-lightbox' );
                        }
                        ?>
                        </p>
                    </div>
                <?php endif; ?>

                <div class="ml-gallery-editor-body">

                    <main class="ml-gallery-main">
                        <input type="text"
                               id="ml_gallery_title"
                               name="ml_gallery_title"
                               value="<?php echo esc_attr( $title ); ?>"
                               placeholder="<?php esc_attr_e( 'Enter gallery title', 'ml-slider-lightbox' ); ?>"
                               class="ml-gallery-title-input">

                        <h3 class="ml-gallery-main-heading"><?php esc_html_e( 'Images', 'ml-slider-lightbox' ); ?></h3>

                        <input type="hidden"
                               id="ml_gallery_images"
                               name="ml_gallery_images"
                               value="<?php echo esc_attr( implode( ',', $image_ids ) ); ?>">

                        <?php $preview_default_mode = empty( $image_ids ) ? 'arrange' : 'preview'; ?>
                        <div class="ml-gallery-preview-modes" data-mode="<?php echo esc_attr( $preview_default_mode ); ?>" data-viewport="desktop">
                            <div class="ml-preview-toolbar">
                                <div class="ml-mode-toggle" role="group" aria-label="<?php esc_attr_e( 'Preview or arrange images', 'ml-slider-lightbox' ); ?>">
                                    <button type="button" class="ml-mode-btn" data-mode-target="preview">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
                                        <?php esc_html_e( 'Preview', 'ml-slider-lightbox' ); ?>
                                    </button>
                                    <button type="button" class="ml-mode-btn" data-mode-target="arrange">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="7" height="7" x="3" y="3" rx="1"/><rect width="7" height="7" x="14" y="3" rx="1"/><rect width="7" height="7" x="14" y="14" rx="1"/><rect width="7" height="7" x="3" y="14" rx="1"/></svg>
                                        <?php esc_html_e( 'Arrange', 'ml-slider-lightbox' ); ?>
                                    </button>
                                </div>
                                <div class="ml-viewport-toggle" role="group" aria-label="<?php esc_attr_e( 'Preview at desktop or mobile width', 'ml-slider-lightbox' ); ?>">
                                    <button type="button" class="ml-viewport-btn is-active ml-tipsy-bottom" data-viewport-target="desktop" aria-label="<?php esc_attr_e( 'Desktop width', 'ml-slider-lightbox' ); ?>" title="<?php esc_attr_e( 'Desktop width', 'ml-slider-lightbox' ); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>
                                    </button>
                                    <button type="button" class="ml-viewport-btn ml-tipsy-bottom" data-viewport-target="mobile" aria-label="<?php esc_attr_e( 'Mobile width', 'ml-slider-lightbox' ); ?>" title="<?php esc_attr_e( 'Mobile width', 'ml-slider-lightbox' ); ?>">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/></svg>
                                    </button>
                                </div>
                            </div>
                        <div id="ml-gallery-preview"
                             class="<?php echo empty( $image_ids ) ? 'is-empty' : ''; ?>"
                             data-empty-label="<?php esc_attr_e( 'No images added yet', 'ml-slider-lightbox' ); ?>">
                            <?php foreach ( $image_ids as $image_id ) : ?>
                                <?php $img = wp_get_attachment_image( $image_id, 'medium' ); ?>
                                <?php if ( $img ) : ?>
                                    <?php
                                    // Hidden input persists the manual caption; the visible bar
                                    // reflects the gallery's chosen source (so Arrange matches
                                    // Preview and the front end). An empty media field falls back
                                    // to manual via resolveItemCaption().
                                    $manual_caption  = $captions[ $image_id ] ?? '';
                                    $display_caption = $this->resolveItemCaption( $image_id, $manual_caption, $caption_source );
                                    // Full-size URL for the caption modal's image preview.
                                    $full_src = wp_get_attachment_image_url( $image_id, 'full' );
                                    ?>
                                    <div class="ml-gallery-item<?php echo $display_caption ? ' has-caption' : ''; ?>"
                                         data-id="<?php echo esc_attr( $image_id ); ?>"
                                         data-full="<?php echo esc_url( $full_src ); ?>">
                                        <?php
                                        // wp_get_attachment_image output is already escaped by WordPress core.
                                        echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                                        ?>
                                        <button type="button"
                                                class="ml-gallery-remove"
                                                aria-label="<?php esc_attr_e( 'Remove image', 'ml-slider-lightbox' ); ?>">
                                            &times;
                                        </button>
                                        <button type="button"
                                                class="ml-gallery-caption-bar"
                                                aria-label="<?php echo $display_caption ? esc_attr__( 'Edit caption', 'ml-slider-lightbox' ) : esc_attr__( 'Add caption', 'ml-slider-lightbox' ); ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            <span class="ml-gallery-caption-bar-text"><?php echo $display_caption ? esc_html( wp_strip_all_tags( $display_caption ) ) : esc_html__( 'Add caption', 'ml-slider-lightbox' ); ?></span>
                                        </button>
                                        <input type="hidden"
                                               class="ml-gallery-caption-input"
                                               name="ml_gallery_captions[<?php echo esc_attr( $image_id ); ?>]"
                                               value="<?php echo esc_attr( $manual_caption ); ?>">
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                            <iframe class="ml-gallery-preview-frame" title="<?php esc_attr_e( 'Gallery preview', 'ml-slider-lightbox' ); ?>"></iframe>
                        </div><!-- .ml-gallery-preview-modes -->

                        <div class="ml-gallery-add-bar">
                            <div class="ml-gallery-add-split">
                                <button type="button" id="ml-gallery-add-images" class="button button-primary ml-gallery-add-images-btn">
                                    <?php esc_html_e( 'Add Images', 'ml-slider-lightbox' ); ?>
                                </button>
                                <button type="button" class="button button-primary ml-gallery-add-caret"
                                        aria-haspopup="true" aria-expanded="false"
                                        aria-label="<?php esc_attr_e( 'More ways to add images', 'ml-slider-lightbox' ); ?>">
                                    <span class="ml-caret" aria-hidden="true">&#9662;</span>
                                </button>
                                <ul class="ml-gallery-add-menu" role="menu" hidden>
                                    <li role="none">
                                        <button type="button" role="menuitem" data-method="upload">
                                            <?php esc_html_e( 'Upload', 'ml-slider-lightbox' ); ?>
                                        </button>
                                    </li>
                                    <li role="none">
                                        <button type="button" role="menuitem" data-method="browse">
                                            <?php esc_html_e( 'Media Library', 'ml-slider-lightbox' ); ?>
                                        </button>
                                    </li>
                                    <li role="none">
                                        <button type="button" role="menuitem" data-method="server">
                                            <?php esc_html_e( 'Server Folder', 'ml-slider-lightbox' ); ?>
                                        </button>
                                    </li>
                                    <li role="none">
                                        <button type="button" role="menuitem" data-method="zip">
                                            <?php esc_html_e( 'ZIP', 'ml-slider-lightbox' ); ?>
                                        </button>
                                    </li>
                                </ul>
                            </div>

                            <div class="ml-add-position" role="group" aria-label="<?php esc_attr_e( 'Where to add new images', 'ml-slider-lightbox' ); ?>">
                                <span class="ml-add-position-label"><?php esc_html_e( 'Add new images at:', 'ml-slider-lightbox' ); ?></span>
                                <label class="ml-add-position-option">
                                    <input type="radio" name="ml_gallery_settings[add_position]" value="start"
                                           <?php checked( $lg_settings['add_position'], 'start' ); ?>>
                                    <span><?php esc_html_e( 'Start', 'ml-slider-lightbox' ); ?></span>
                                </label>
                                <label class="ml-add-position-option">
                                    <input type="radio" name="ml_gallery_settings[add_position]" value="end"
                                           <?php checked( $lg_settings['add_position'], 'end' ); ?>>
                                    <span><?php esc_html_e( 'End', 'ml-slider-lightbox' ); ?></span>
                                </label>
                            </div>
                        </div>
                    </main>

                    <aside class="ml-gallery-sidebar">

                        <div class="ml-gallery-sidebar-panel">
                            <h3><?php esc_html_e( 'Layout', 'ml-slider-lightbox' ); ?></h3>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <div class="ml-layout-picker" role="group" aria-label="<?php esc_attr_e( 'Gallery layout', 'ml-slider-lightbox' ); ?>">

                                    <label class="ml-layout-option ml-tipsy" title="<?php esc_attr_e( 'Evenly sized images arranged in a fixed grid', 'ml-slider-lightbox' ); ?>">
                                        <input type="radio" name="ml_gallery_settings[layout]" value="grid"
                                               <?php checked( $lg_settings['layout'], 'grid' ); ?>>
                                        <span class="ml-layout-icon">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                                                <rect x="2"  y="2"  width="9" height="9" rx="1"/>
                                                <rect x="13" y="2"  width="9" height="9" rx="1"/>
                                                <rect x="2"  y="13" width="9" height="9" rx="1"/>
                                                <rect x="13" y="13" width="9" height="9" rx="1"/>
                                            </svg>
                                            <span><?php esc_html_e( 'Grid', 'ml-slider-lightbox' ); ?></span>
                                        </span>
                                    </label>

                                    <label class="ml-layout-option ml-tipsy" title="<?php esc_attr_e( 'Images at their natural heights, fitting together like a brick wall', 'ml-slider-lightbox' ); ?>">
                                        <input type="radio" name="ml_gallery_settings[layout]" value="masonry"
                                               <?php checked( $lg_settings['layout'], 'masonry' ); ?>>
                                        <span class="ml-layout-icon">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                                                <rect x="2"  y="2"  width="9" height="13" rx="1"/>
                                                <rect x="2"  y="17" width="9" height="5"  rx="1"/>
                                                <rect x="13" y="2"  width="9" height="5"  rx="1"/>
                                                <rect x="13" y="9"  width="9" height="13" rx="1"/>
                                            </svg>
                                            <span><?php esc_html_e( 'Masonry', 'ml-slider-lightbox' ); ?></span>
                                        </span>
                                    </label>

                                    <label class="ml-layout-option ml-tipsy" title="<?php esc_attr_e( 'Images scaled to fill each row edge-to-edge with a consistent height', 'ml-slider-lightbox' ); ?>">
                                        <input type="radio" name="ml_gallery_settings[layout]" value="justified"
                                               <?php checked( $lg_settings['layout'], 'justified' ); ?>>
                                        <span class="ml-layout-icon">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                                                <rect x="2"  y="2"  width="7"  height="6" rx="1"/>
                                                <rect x="11" y="2"  width="11" height="6" rx="1"/>
                                                <rect x="2"  y="10" width="11" height="6" rx="1"/>
                                                <rect x="15" y="10" width="7"  height="6" rx="1"/>
                                                <rect x="2"  y="18" width="9"  height="4" rx="1"/>
                                                <rect x="13" y="18" width="9"  height="4" rx="1"/>
                                            </svg>
                                            <span><?php esc_html_e( 'Justified', 'ml-slider-lightbox' ); ?></span>
                                        </span>
                                    </label>

                                    <label class="ml-layout-option ml-tipsy" title="<?php esc_attr_e( 'Images displayed one at a time in a scrollable slideshow', 'ml-slider-lightbox' ); ?>">
                                        <input type="radio" name="ml_gallery_settings[layout]" value="carousel"
                                               <?php checked( $lg_settings['layout'], 'carousel' ); ?>>
                                        <span class="ml-layout-icon">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                                                <rect x="1"  y="6" width="4"  height="12" rx="1"/>
                                                <rect x="7"  y="3" width="10" height="18" rx="1"/>
                                                <rect x="19" y="6" width="4"  height="12" rx="1"/>
                                            </svg>
                                            <span><?php esc_html_e( 'Carousel', 'ml-slider-lightbox' ); ?></span>
                                        </span>
                                    </label>

                                    <label class="ml-layout-option ml-tipsy" title="<?php esc_attr_e( 'Large main image with a scrollable thumbnail strip below', 'ml-slider-lightbox' ); ?>">
                                        <input type="radio" name="ml_gallery_settings[layout]" value="showcase"
                                               <?php checked( $lg_settings['layout'], 'showcase' ); ?>>
                                        <span class="ml-layout-icon">
                                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" fill="currentColor">
                                                <rect x="2"  y="2"  width="20" height="13" rx="1"/>
                                                <rect x="2"  y="17" width="5"  height="5"  rx="1"/>
                                                <rect x="9"  y="17" width="5"  height="5"  rx="1"/>
                                                <rect x="16" y="17" width="6"  height="5"  rx="1"/>
                                            </svg>
                                            <span><?php esc_html_e( 'Showcase', 'ml-slider-lightbox' ); ?></span>
                                        </span>
                                    </label>

                                </div>
                            </div>

                            <?php
                            $hide_columns  = in_array( $lg_settings['layout'], array( 'justified', 'carousel', 'showcase' ), true );
                            $hide_height   = in_array( $lg_settings['layout'], array( 'masonry', 'carousel', 'showcase' ), true );
                            ?>
                            <div class="ml-gallery-setting ml-gallery-columns-row<?php echo $hide_columns ? ' is-hidden' : ''; ?>">
                                <label for="ml_gallery_columns" class="ml-tipsy" title="<?php esc_attr_e( 'Number of columns to show on desktop screens', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'columns' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Columns', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_columns" name="ml_gallery_settings[columns]">
                                    <?php foreach ( range( 2, 6 ) as $n ) : ?>
                                        <option value="<?php echo esc_attr( $n ); ?>" <?php selected( $lg_settings['columns'], $n ); ?>><?php echo esc_html( $n ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting ml-gallery-mobile-columns-row<?php echo $hide_columns ? ' is-hidden' : ''; ?>">
                                <label for="ml_gallery_columns_mobile" class="ml-tipsy" title="<?php esc_attr_e( 'Number of columns to show on phones and small screens', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'columns_mobile' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Columns (mobile)', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_columns_mobile" name="ml_gallery_settings[columns_mobile]">
                                    <?php foreach ( range( 1, 6 ) as $n ) : ?>
                                        <option value="<?php echo esc_attr( $n ); ?>" <?php selected( (int) ( $lg_settings['columns_mobile'] ?? 1 ), $n ); ?>><?php echo esc_html( $n ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col ml-gallery-height-row<?php echo $hide_height ? ' is-hidden' : ''; ?>">
                                <label for="ml_gallery_height" class="ml-tipsy" title="<?php esc_attr_e( 'Height of the gallery images, in pixels', 'ml-slider-lightbox' ); ?>">
                                    <?php echo $this->settingIcon( 'height' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Height', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo absint( $lg_settings['height'] ?? 220 ); ?>px</span>
                                </label>
                                <input type="range"
                                       id="ml_gallery_height"
                                       name="ml_gallery_settings[height]"
                                       min="80" max="800" step="10"
                                       value="<?php echo absint( $lg_settings['height'] ?? 220 ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col ml-gallery-gap-row<?php echo in_array( $lg_settings['layout'], array( 'carousel', 'showcase' ), true ) ? ' is-hidden' : ''; ?>">
                                <label for="ml_gallery_gap" class="ml-tipsy" title="<?php esc_attr_e( 'Space between images', 'ml-slider-lightbox' ); ?>">
                                    <?php echo $this->settingIcon( 'gap' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Gap', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo absint( $lg_settings['gap'] ); ?>px</span>
                                </label>
                                <input type="range"
                                       id="ml_gallery_gap"
                                       name="ml_gallery_settings[gap]"
                                       min="0" max="32" step="2"
                                       value="<?php echo absint( $lg_settings['gap'] ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                        </div>

                        <div class="ml-gallery-sidebar-panel">
                            <h3><?php esc_html_e( 'Gallery', 'ml-slider-lightbox' ); ?></h3>

                            <?php
                            $pro_tooltips = array(
                                'zoom'       => __( 'Zoom controls require MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'fullscreen' => __( 'Fullscreen mode requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'rotate'     => __( 'Image rotation requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'autoplay'   => __( 'Autoplay requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'share'      => __( 'Social sharing requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'pager'      => __( 'Pager requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'hash'       => __( 'Unique Image URLs require MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'image_protection' => __( 'Image protection requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                            );

                            $descriptions = array(
                                'controls'    => __( 'Show left and right navigation arrows', 'ml-slider-lightbox' ),
                                'counter'     => __( 'Display the current image number and total count', 'ml-slider-lightbox' ),
                                'thumbnails'  => __( 'Show a strip of thumbnail images at the bottom of the gallery', 'ml-slider-lightbox' ),
                                'pager'       => __( 'Show dot navigation area below each image.', 'ml-slider-lightbox' ),
                                'zoom'        => __( 'Pinch or scroll to zoom in and out of gallery images. The Zoom control will appear only if the original size is larger than the gallery size.', 'ml-slider-lightbox' ),
                                'fullscreen'  => __( 'Expand the gallery window to fill the whole screen.', 'ml-slider-lightbox' ),
                                'rotate'      => __( 'Users can rotate images left or right, plus flip them vertically or horizontally.', 'ml-slider-lightbox' ),
                                'autoplay'    => __( 'Automatically advance through the images without the user clicking.', 'ml-slider-lightbox' ),
                                'share'       => __( 'Enable users to share images on Facebook, X, or Pinterest.', 'ml-slider-lightbox' ),
                                'hash'        => __( 'Create a unique URL for each image to enable direct linking inside a gallery.', 'ml-slider-lightbox' ),
                                'image_protection' => __( 'Discourage casual saving by blocking right-click, drag, and long-press on gallery images.', 'ml-slider-lightbox' ),
                                'keyboard'    => __( 'Navigate images using left and right arrow keys', 'ml-slider-lightbox' ),
                                'mousewheel'  => __( 'Scroll through images using the mouse wheel', 'ml-slider-lightbox' ),
                                'swipe_close' => __( 'Swipe up or down to close the gallery on touch devices', 'ml-slider-lightbox' ),
                                'loop'             => __( 'Cycle back to the first image after reaching the last', 'ml-slider-lightbox' ),
                                'download'         => __( 'Show a download button for each image', 'ml-slider-lightbox' ),
                                'open_in_lightbox' => __( 'Open images in a window overlay when clicked. Applies to Grid, Masonry, and Justified layouts.', 'ml-slider-lightbox' ),
                                'show_lightbox_button' => __( 'Show a button over the gallery that opens the gallery window when clicked.', 'ml-slider-lightbox' ),
                                'button_icon'          => __( 'Show an icon on the button instead of text.', 'ml-slider-lightbox' ),
                            );

                            $render_toggle = function( $key, $label, $extra_class = '' ) use ( $lg_settings, $pro_tooltips, $descriptions ) {
                                $is_pro_key = isset( $pro_tooltips[ $key ] );
                                $locked     = $is_pro_key && ! $this->is_pro;
                                $title      = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
                                $class_attr = $extra_class ? ' ' . esc_attr( $extra_class ) : '';
                                if ( $locked ) : ?>
                                    <div class="ml-gallery-setting ml-gallery-setting--pro-locked<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                                        <label<?php if ( $title ) : ?> class="ml-tipsy" title="<?php echo esc_attr( $title ); ?>"<?php endif; ?>><?php echo $this->settingIcon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $label ); ?></label>
                                        <span class="ml-gallery-pro-controls">
                                            <label class="ml-toggle-switch" aria-hidden="true">
                                                <input type="checkbox" disabled>
                                                <span class="ml-toggle-track"></span>
                                            </label>
                                            <?php echo $this->renderProLockIcon( $pro_tooltips[ $key ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                                        </span>
                                    </div>
                                <?php else : ?>
                                    <div class="ml-gallery-setting<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                                        <label for="ml_gallery_<?php echo esc_attr( $key ); ?>"<?php if ( $title ) : ?> class="ml-tipsy" title="<?php echo esc_attr( $title ); ?>"<?php endif; ?>><?php echo $this->settingIcon( $key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php echo esc_html( $label ); ?></label>
                                        <input type="hidden" name="ml_gallery_settings[<?php echo esc_attr( $key ); ?>]" value="0">
                                        <label class="ml-toggle-switch">
                                            <input type="checkbox"
                                                   id="ml_gallery_<?php echo esc_attr( $key ); ?>"
                                                   name="ml_gallery_settings[<?php echo esc_attr( $key ); ?>]"
                                                   value="1"
                                                   <?php checked( $lg_settings[ $key ], 1 ); ?>>
                                            <span class="ml-toggle-track"></span>
                                        </label>
                                    </div>
                                <?php endif;
                            };

                            $registered    = wp_get_registered_image_subsizes();
                            $size_options  = array();
                            foreach ( get_intermediate_image_sizes() as $s ) {
                                $dims = isset( $registered[ $s ] )
                                    ? ' (' . $registered[ $s ]['width'] . '×' . $registered[ $s ]['height'] . ')'
                                    : '';
                                $size_options[ $s ] = ucfirst( str_replace( '-', ' ', $s ) ) . $dims;
                            }
                            $size_options['full'] = __( 'Full (original)', 'ml-slider-lightbox' );
                            $saved_lb_size        = $lg_settings['lightbox_size'] ?? 'full';
                            ?>

                            <?php $render_toggle( 'open_in_lightbox', __( 'Show in Gallery Window', 'ml-slider-lightbox' ), 'ml-show-in-modal-row' ); ?>

                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_lightbox_size" class="ml-tipsy" title="<?php esc_attr_e( 'The size of the image shown when a visitor clicks to view it in full', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'lightbox_size' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Image Size', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_lightbox_size" name="ml_gallery_settings[lightbox_size]">
                                    <?php foreach ( $size_options as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_lb_size, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php
                            $btn_position   = $lg_settings['button_position'] ?? 'top-right';
                            $btn_text       = $lg_settings['button_text'] ?? '';
                            // The button/icon only apply to grid-like layouts that open a gallery window.
                            $hide_btn_panel = in_array( $lg_settings['layout'], array( 'carousel', 'showcase' ), true ) || empty( $lg_settings['open_in_lightbox'] );
                            $trigger_mode   = TriggerControl::modeFromBooleans(
                                ! empty( $lg_settings['show_lightbox_button'] ),
                                ! empty( $lg_settings['button_icon'] )
                            );
                            ?>
                            <div class="ml-button-settings-group<?php echo $hide_btn_panel ? ' is-hidden' : ''; ?>">
                                <div class="ml-gallery-setting ml-trigger-mode-row">
                                    <label class="ml-tipsy" title="<?php esc_attr_e( 'Choose how visitors open the gallery window: click the image, click an icon, or click a button.', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'show_lightbox_button' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Open with', 'ml-slider-lightbox' ); ?></label>
                                    <?php
                                    TriggerControl::render( array(
                                        'name_show' => 'ml_gallery_settings[show_lightbox_button]',
                                        'name_icon' => 'ml_gallery_settings[button_icon]',
                                        'mode'      => $trigger_mode,
                                    ) );
                                    ?>
                                </div>

                                <div class="ml-button-suboptions">
                                    <div class="ml-gallery-setting ml-button-text-row<?php echo 'button' === $trigger_mode ? '' : ' is-hidden'; ?>">
                                        <label for="ml_gallery_button_text" class="ml-tipsy" title="<?php esc_attr_e( 'Text shown on the button that opens the gallery window', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'button_text' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Button Text', 'ml-slider-lightbox' ); ?></label>
                                        <input type="text"
                                               id="ml_gallery_button_text"
                                               name="ml_gallery_settings[button_text]"
                                               value="<?php echo esc_attr( $btn_text ); ?>"
                                               placeholder="<?php esc_attr_e( 'Open in Gallery', 'ml-slider-lightbox' ); ?>"
                                               class="ml-button-text-input">
                                    </div>

                                    <div class="ml-gallery-setting ml-button-position-row<?php echo 'image' === $trigger_mode ? ' is-hidden' : ''; ?>">
                                        <label for="ml_gallery_button_position" class="ml-tipsy" title="<?php esc_attr_e( 'Where the button or icon sits over the gallery', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'button_position' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Button Position', 'ml-slider-lightbox' ); ?></label>
                                        <select id="ml_gallery_button_position" name="ml_gallery_settings[button_position]">
                                            <option value="top-right"    <?php selected( $btn_position, 'top-right' ); ?>><?php esc_html_e( 'Top Right',    'ml-slider-lightbox' ); ?></option>
                                            <option value="top-left"     <?php selected( $btn_position, 'top-left' ); ?>><?php esc_html_e( 'Top Left',     'ml-slider-lightbox' ); ?></option>
                                            <option value="bottom-right" <?php selected( $btn_position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'ml-slider-lightbox' ); ?></option>
                                            <option value="bottom-left"  <?php selected( $btn_position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left',  'ml-slider-lightbox' ); ?></option>
                                            <option value="center"       <?php selected( $btn_position, 'center' ); ?>><?php esc_html_e( 'Center',       'ml-slider-lightbox' ); ?></option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <?php if ( $this->is_pro ) : ?>
                                <?php do_action( 'ml_gallery_pro_gallery_fields', $gallery_id ); ?>
                            <?php else : ?>
                                <?php $render_toggle( 'image_protection', __( 'Protect images', 'ml-slider-lightbox' ) ); ?>
                            <?php endif; ?>

                            <?php $hide_lightbox_sections = in_array( $lg_settings['layout'], array( 'grid', 'masonry', 'justified' ), true ) && empty( $lg_settings['open_in_lightbox'] ); ?>
                            <div class="ml-lightbox-settings-group<?php echo $hide_lightbox_sections ? ' is-hidden' : ''; ?>">

                            <p class="ml-settings-section-label"><?php esc_html_e( 'Display', 'ml-slider-lightbox' ); ?></p>
                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_mode" class="ml-tipsy" title="<?php esc_attr_e( 'Animation effect when moving between images', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'mode' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Transition', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_mode" name="ml_gallery_settings[mode]" class="ml-gallery-input">
                                    <?php foreach ( $this->allowedModes() as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $lg_settings['mode'], $value ); ?>>
                                            <?php echo esc_html( $label ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php $render_toggle( 'controls',   __( 'Arrows',        'ml-slider-lightbox' ) ); ?>
                            <?php $render_toggle( 'counter',    __( 'Slide Counter', 'ml-slider-lightbox' ) ); ?>
                            <?php $render_toggle( 'thumbnails', __( 'Thumbnails',    'ml-slider-lightbox' ) ); ?>
                            <?php if ( ! $this->is_pro ) : ?>
                                <?php $render_toggle( 'pager', __( 'Pager', 'ml-slider-lightbox' ) ); ?>
                            <?php else : ?>
                                <?php do_action( 'ml_gallery_pro_display_fields', $gallery_id ); ?>
                            <?php endif; ?>

                            <p class="ml-settings-section-label"><?php esc_html_e( 'Navigation', 'ml-slider-lightbox' ); ?></p>
                            <?php $render_toggle( 'loop', __( 'Loop', 'ml-slider-lightbox' ) ); ?>
                            <?php $render_toggle( 'keyboard',    __( 'Keyboard',       'ml-slider-lightbox' ) ); ?>
                            <?php $render_toggle( 'mousewheel',  __( 'Mouse wheel',    'ml-slider-lightbox' ) ); ?>
                            <?php $render_toggle( 'swipe_close', __( 'Swipe to close', 'ml-slider-lightbox' ) ); ?>

                            </div>

                        </div>

                        <div class="ml-gallery-sidebar-panel">
                            <h3><?php esc_html_e( 'Captions', 'ml-slider-lightbox' ); ?></h3>
                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_caption_display" class="ml-tipsy" title="<?php esc_attr_e( 'Where captions appear: on gallery thumbnails, in the gallery window, or both.', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'caption_display' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Captions', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_caption_display" name="ml_gallery_settings[caption_display]">
                                    <?php foreach ( $this->allowedCaptionDisplay() as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $caption_display, $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <?php $caption_style_hidden = 'hidden' === $caption_display ? ' is-hidden' : ''; ?>
                            <?php $caption_transition_hidden = in_array( $caption_display, array( 'hidden', 'gallery' ), true ) ? ' is-hidden' : ''; ?>

                            <div class="ml-gallery-setting ml-caption-style-field<?php echo esc_attr( $caption_style_hidden ); ?>">
                                <label for="ml_gallery_caption_source" class="ml-tipsy" title="<?php esc_attr_e( 'Where each caption\'s text comes from: your typed caption, or the image\'s Media Library caption or description. Empty media fields fall back to your typed caption.', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'caption_source' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Caption Content', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_caption_source" name="ml_gallery_settings[caption_source]">
                                    <?php foreach ( $this->captionSources() as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $caption_source, $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col ml-caption-style-field<?php echo esc_attr( $caption_style_hidden ); ?>">
                                <label for="ml_gallery_caption_text_size" class="ml-tipsy" title="<?php esc_attr_e( 'Caption text size. Applies to both gallery thumbnails and the gallery window.', 'ml-slider-lightbox' ); ?>">
                                    <?php echo $this->settingIcon( 'caption_text_size' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Text Size', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo absint( $appearance['caption_text_size'] ); ?>px</span>
                                </label>
                                <input type="range"
                                       id="ml_gallery_caption_text_size"
                                       name="ml_gallery_appearance[caption_text_size]"
                                       min="10" max="24" step="1"
                                       value="<?php echo absint( $appearance['caption_text_size'] ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col ml-caption-style-field<?php echo esc_attr( $caption_style_hidden ); ?>">
                                <label for="ml_gallery_caption_text_color" class="ml-tipsy" title="<?php esc_attr_e( 'Caption text color. Applies to both gallery thumbnails and the gallery window.', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'caption_text_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Text Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_caption_text_color"
                                       name="ml_gallery_appearance[caption_text_color]"
                                       value="<?php echo esc_attr( $appearance['caption_text_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col ml-caption-style-field<?php echo esc_attr( $caption_style_hidden ); ?>">
                                <label for="ml_gallery_caption_bg_color" class="ml-tipsy" title="<?php esc_attr_e( 'Caption background. Applies to both gallery thumbnails and the gallery window; on thumbnails it fades to transparent for readability.', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'caption_bg_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Background Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_caption_bg_color"
                                       name="ml_gallery_appearance[caption_bg_color]"
                                       value="<?php echo esc_attr( $appearance['caption_bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-caption-style-field ml-caption-window-only<?php echo esc_attr( $caption_transition_hidden ); ?>">
                                <label for="ml_gallery_caption_transition" class="ml-tipsy" title="<?php esc_attr_e( 'How the caption animates in. Applies to the gallery window only — not to gallery thumbnail captions.', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'caption_transition' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Transition', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_caption_transition" name="ml_gallery_appearance[caption_transition]">
                                    <?php foreach ( $this->allowedCaptionTransitions() as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $appearance['caption_transition'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="ml-gallery-sidebar-panel ml-lightbox-only-panel<?php echo $hide_lightbox_sections ? ' is-hidden' : ''; ?>">
                            <h3><?php esc_html_e( 'Toolbar', 'ml-slider-lightbox' ); ?></h3>
                            <?php $render_toggle( 'download', __( 'Download', 'ml-slider-lightbox' ) ); ?>
                            <?php if ( ! $this->is_pro ) : ?>
                                <?php $render_toggle( 'share',      __( 'Share',           'ml-slider-lightbox' ) ); ?>
                                <?php $render_toggle( 'autoplay',   __( 'Autoplay',         'ml-slider-lightbox' ) ); ?>
                                <?php $render_toggle( 'rotate',     __( 'Rotate and Flip',  'ml-slider-lightbox' ) ); ?>
                                <?php $render_toggle( 'fullscreen', __( 'Fullscreen',        'ml-slider-lightbox' ) ); ?>
                                <?php $render_toggle( 'zoom',       __( 'Zoom',              'ml-slider-lightbox' ) ); ?>
                            <?php else : ?>
                                <?php do_action( 'ml_gallery_pro_toolbar_fields', $gallery_id ); ?>
                            <?php endif; ?>
                            <?php if ( ! $this->is_pro ) : ?>
                                <?php $render_toggle( 'hash', __( 'Unique Image URLs', 'ml-slider-lightbox' ) ); ?>
                            <?php else : ?>
                                <?php do_action( 'ml_gallery_pro_advanced_fields', $gallery_id ); ?>
                            <?php endif; ?>
                        </div>

                        <div class="ml-gallery-sidebar-panel ml-lightbox-only-panel<?php echo $hide_lightbox_sections ? ' is-hidden' : ''; ?>">
                            <h3><?php esc_html_e( 'Appearance', 'ml-slider-lightbox' ); ?></h3>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_bg_color" class="ml-tipsy" title="<?php esc_attr_e( 'Background color of the gallery window', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'bg_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Background Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_bg_color"
                                       name="ml_gallery_appearance[bg_color]"
                                       value="<?php echo esc_attr( $appearance['bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_bg_opacity" class="ml-tipsy" title="<?php esc_attr_e( 'How opaque the gallery window background is (0 = transparent, 1 = solid)', 'ml-slider-lightbox' ); ?>">
                                    <?php echo $this->settingIcon( 'bg_opacity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Background Opacity', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo esc_html( (string) $this->clampOpacity( $appearance['bg_opacity'] ) ); ?></span>
                                </label>
                                <input type="range"
                                       id="ml_gallery_bg_opacity"
                                       name="ml_gallery_appearance[bg_opacity]"
                                       min="0" max="1" step="0.05"
                                       value="<?php echo esc_attr( (string) $this->clampOpacity( $appearance['bg_opacity'] ) ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_arrow_color" class="ml-tipsy" title="<?php esc_attr_e( 'Color of the next and previous navigation arrows', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'arrow_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Arrow Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_arrow_color"
                                       name="ml_gallery_appearance[arrow_color]"
                                       value="<?php echo esc_attr( $appearance['arrow_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_arrow_bg_color" class="ml-tipsy" title="<?php esc_attr_e( 'Background color behind the navigation arrows', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'arrow_bg_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Arrow Background', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_arrow_bg_color"
                                       name="ml_gallery_appearance[arrow_bg_color]"
                                       value="<?php echo esc_attr( $appearance['arrow_bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_close_color" class="ml-tipsy" title="<?php esc_attr_e( 'Color of the close (X) icon', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'close_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Close Icon Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_close_color"
                                       name="ml_gallery_appearance[close_color]"
                                       value="<?php echo esc_attr( $appearance['close_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_close_bg_color" class="ml-tipsy" title="<?php esc_attr_e( 'Background color behind the close icon', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'close_bg_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Close Background', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_close_bg_color"
                                       name="ml_gallery_appearance[close_bg_color]"
                                       value="<?php echo esc_attr( $appearance['close_bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_toolbar_color" class="ml-tipsy" title="<?php esc_attr_e( 'Color of the toolbar icons', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'toolbar_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Toolbar Icon Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_toolbar_color"
                                       name="ml_gallery_appearance[toolbar_color]"
                                       value="<?php echo esc_attr( $appearance['toolbar_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_toolbar_bg_color" class="ml-tipsy" title="<?php esc_attr_e( 'Background color of the toolbar', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'toolbar_bg_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Toolbar Background', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_toolbar_bg_color"
                                       name="ml_gallery_appearance[toolbar_bg_color]"
                                       value="<?php echo esc_attr( $appearance['toolbar_bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_thumbnail_border_color" class="ml-tipsy" title="<?php esc_attr_e( 'Border color of the thumbnail strip images', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'thumbnail_border_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Thumbnail Border Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_thumbnail_border_color"
                                       name="ml_gallery_appearance[thumbnail_border_color]"
                                       value="<?php echo esc_attr( $appearance['thumbnail_border_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_thumbnail_border_hover_color" class="ml-tipsy" title="<?php esc_attr_e( 'Border color of the active and hovered thumbnail', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'thumbnail_border_hover_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Thumbnail Border Active and Hover', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_thumbnail_border_hover_color"
                                       name="ml_gallery_appearance[thumbnail_border_hover_color]"
                                       value="<?php echo esc_attr( $appearance['thumbnail_border_hover_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#dd6923">
                            </div>

                            <?php
                            // Button/icon colours only apply to a grid-like gallery that opens
                            // a window with a button/icon trigger; mirrors toggleTriggerColorRows().
                            $trigger_available = ! in_array( $lg_settings['layout'], array( 'carousel', 'showcase' ), true )
                                && ! empty( $lg_settings['open_in_lightbox'] );
                            $show_btn_colors = $trigger_available && 'button' === $trigger_mode;
                            $show_ico_colors = $trigger_available && 'icon' === $trigger_mode;
                            ?>
                            <div class="ml-button-colors-row<?php echo $show_btn_colors ? '' : ' is-hidden'; ?>">
                                <div class="ml-gallery-setting ml-gallery-setting--col">
                                    <label for="ml_gallery_button_text_color" class="ml-tipsy" title="<?php esc_attr_e( 'Text color of the button that opens the gallery window', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'button_text_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Button Text Color', 'ml-slider-lightbox' ); ?></label>
                                    <input type="text" id="ml_gallery_button_text_color" name="ml_gallery_appearance[button_text_color]" value="<?php echo esc_attr( $appearance['button_text_color'] ); ?>" class="ml-gallery-color-picker" data-default-color="#ffffff">
                                </div>
                                <div class="ml-gallery-setting ml-gallery-setting--col">
                                    <label for="ml_gallery_button_hover_text_color" class="ml-tipsy" title="<?php esc_attr_e( 'Button text color on hover', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'button_hover_text_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Button Text Hover', 'ml-slider-lightbox' ); ?></label>
                                    <input type="text" id="ml_gallery_button_hover_text_color" name="ml_gallery_appearance[button_hover_text_color]" value="<?php echo esc_attr( $appearance['button_hover_text_color'] ); ?>" class="ml-gallery-color-picker" data-default-color="#000000">
                                </div>
                                <div class="ml-gallery-setting ml-gallery-setting--col">
                                    <label for="ml_gallery_button_color" class="ml-tipsy" title="<?php esc_attr_e( 'Background color of the button that opens the gallery window', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'button_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Button Background', 'ml-slider-lightbox' ); ?></label>
                                    <input type="text" id="ml_gallery_button_color" name="ml_gallery_appearance[button_color]" value="<?php echo esc_attr( $appearance['button_color'] ); ?>" class="ml-gallery-color-picker" data-default-color="#000000">
                                </div>
                                <div class="ml-gallery-setting ml-gallery-setting--col">
                                    <label for="ml_gallery_button_hover_color" class="ml-tipsy" title="<?php esc_attr_e( 'Button background color on hover', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'button_hover_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Button Background Hover', 'ml-slider-lightbox' ); ?></label>
                                    <input type="text" id="ml_gallery_button_hover_color" name="ml_gallery_appearance[button_hover_color]" value="<?php echo esc_attr( $appearance['button_hover_color'] ); ?>" class="ml-gallery-color-picker" data-default-color="#f0f0f0">
                                </div>
                            </div>

                            <div class="ml-icon-colors-row<?php echo $show_ico_colors ? '' : ' is-hidden'; ?>">
                                <div class="ml-gallery-setting ml-gallery-setting--col">
                                    <label for="ml_gallery_icon_color" class="ml-tipsy" title="<?php esc_attr_e( 'Color of the icon that opens the gallery window', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'icon_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Icon Color', 'ml-slider-lightbox' ); ?></label>
                                    <input type="text" id="ml_gallery_icon_color" name="ml_gallery_appearance[icon_color]" value="<?php echo esc_attr( $appearance['icon_color'] ); ?>" class="ml-gallery-color-picker" data-default-color="#ffffff">
                                </div>
                                <div class="ml-gallery-setting ml-gallery-setting--col">
                                    <label for="ml_gallery_icon_hover_color" class="ml-tipsy" title="<?php esc_attr_e( 'Icon color on hover', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'icon_hover_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Icon Hover Color', 'ml-slider-lightbox' ); ?></label>
                                    <input type="text" id="ml_gallery_icon_hover_color" name="ml_gallery_appearance[icon_hover_color]" value="<?php echo esc_attr( $appearance['icon_hover_color'] ); ?>" class="ml-gallery-color-picker" data-default-color="#000000">
                                </div>
                                <div class="ml-gallery-setting ml-gallery-setting--col">
                                    <label for="ml_gallery_icon_background_color" class="ml-tipsy" title="<?php esc_attr_e( 'Background color behind the icon', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'icon_background_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Icon Background', 'ml-slider-lightbox' ); ?></label>
                                    <input type="text" id="ml_gallery_icon_background_color" name="ml_gallery_appearance[icon_background_color]" value="<?php echo esc_attr( $appearance['icon_background_color'] ); ?>" class="ml-gallery-color-picker" data-default-color="#000000">
                                </div>
                                <div class="ml-gallery-setting ml-gallery-setting--col">
                                    <label for="ml_gallery_icon_background_hover_color" class="ml-tipsy" title="<?php esc_attr_e( 'Icon background color on hover', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'icon_background_hover_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Icon Background Hover', 'ml-slider-lightbox' ); ?></label>
                                    <input type="text" id="ml_gallery_icon_background_hover_color" name="ml_gallery_appearance[icon_background_hover_color]" value="<?php echo esc_attr( $appearance['icon_background_hover_color'] ); ?>" class="ml-gallery-color-picker" data-default-color="#f0f0f0">
                                </div>
                            </div>

                            <?php if ( $this->is_pro ) : ?>
                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_autoplay_progress_bar_color" class="ml-tipsy" title="<?php esc_attr_e( 'Color of the progress bar shown during autoplay', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'autoplay_progress_bar_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Autoplay Progress Bar', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_autoplay_progress_bar_color"
                                       name="ml_gallery_appearance[autoplay_progress_bar_color]"
                                       value="<?php echo esc_attr( $appearance['autoplay_progress_bar_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#a90707">
                            </div>
                            <?php endif; ?>

                            <?php do_action( 'ml_gallery_pro_appearance_fields', $gallery_id ); ?>

                        </div>

                        <div class="ml-gallery-sidebar-panel">
                            <h3><?php esc_html_e( 'Image Styles', 'ml-slider-lightbox' ); ?></h3>

                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_filter" class="ml-tipsy" title="<?php esc_attr_e( 'Apply a filter to every image in the gallery', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'filter' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Filter', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_filter" name="ml_gallery_image_styles[filter]">
                                    <?php foreach ( $this->filterPresetLabels() as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $image_styles['filter'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_corner_radius" class="ml-tipsy" title="<?php esc_attr_e( 'Corner radius in pixels applied to every image', 'ml-slider-lightbox' ); ?>">
                                    <?php echo $this->settingIcon( 'corner_radius' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Rounded Corners', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo esc_html( (string) $image_styles['corner_radius'] ); ?>px</span>
                                </label>
                                <input type="range" min="0" max="200" step="1"
                                       id="ml_gallery_corner_radius"
                                       name="ml_gallery_image_styles[corner_radius]"
                                       value="<?php echo esc_attr( (string) $image_styles['corner_radius'] ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_border_width" class="ml-tipsy" title="<?php esc_attr_e( 'Image border width in pixels (0 = no border)', 'ml-slider-lightbox' ); ?>">
                                    <?php echo $this->settingIcon( 'border_width' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Border Width', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo esc_html( (string) $image_styles['border_width'] ); ?>px</span>
                                </label>
                                <input type="range" min="0" max="50" step="1"
                                       id="ml_gallery_border_width"
                                       name="ml_gallery_image_styles[border_width]"
                                       value="<?php echo esc_attr( (string) $image_styles['border_width'] ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_border_style" class="ml-tipsy" title="<?php esc_attr_e( 'Line style used for the image border', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'border_style' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Border Style', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_border_style" name="ml_gallery_image_styles[border_style]">
                                    <?php foreach ( array(
                                        'solid'  => __( 'Solid', 'ml-slider-lightbox' ),
                                        'dashed' => __( 'Dashed', 'ml-slider-lightbox' ),
                                        'dotted' => __( 'Dotted', 'ml-slider-lightbox' ),
                                        'double' => __( 'Double', 'ml-slider-lightbox' ),
                                    ) as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $image_styles['border_style'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_border_color" class="ml-tipsy" title="<?php esc_attr_e( 'Color of the image border', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'border_color' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Border Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_border_color"
                                       name="ml_gallery_image_styles[border_color]"
                                       value="<?php echo esc_attr( $image_styles['border_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#dddddd">
                            </div>

                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_box_shadow" class="ml-tipsy" title="<?php esc_attr_e( 'Drop shadow depth cast behind each image', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'box_shadow' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Box Shadow', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_box_shadow" name="ml_gallery_image_styles[box_shadow]">
                                    <?php foreach ( array(
                                        'none'   => __( 'None', 'ml-slider-lightbox' ),
                                        'light'  => __( 'Light', 'ml-slider-lightbox' ),
                                        'medium' => __( 'Medium', 'ml-slider-lightbox' ),
                                        'heavy'  => __( 'Heavy', 'ml-slider-lightbox' ),
                                    ) as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $image_styles['box_shadow'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_opacity" class="ml-tipsy" title="<?php esc_attr_e( 'Image opacity as a percentage', 'ml-slider-lightbox' ); ?>">
                                    <?php echo $this->settingIcon( 'opacity' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Opacity', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo esc_html( (string) $image_styles['opacity'] ); ?>%</span>
                                </label>
                                <input type="range" min="0" max="100" step="1"
                                       id="ml_gallery_opacity"
                                       name="ml_gallery_image_styles[opacity]"
                                       value="<?php echo esc_attr( (string) $image_styles['opacity'] ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_rotate" class="ml-tipsy" title="<?php esc_attr_e( 'Rotate every image by a fixed angle', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'rotate' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Rotate', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_rotate" name="ml_gallery_image_styles[rotate]">
                                    <?php foreach ( array(
                                        '0'   => __( 'None', 'ml-slider-lightbox' ),
                                        '90'  => '90°',
                                        '180' => '180°',
                                        '270' => '270°',
                                    ) as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $image_styles['rotate'], (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_flip" class="ml-tipsy" title="<?php esc_attr_e( 'Mirror every image horizontally, vertically, or both', 'ml-slider-lightbox' ); ?>"><?php echo $this->settingIcon( 'flip' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php esc_html_e( 'Flip', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_flip" name="ml_gallery_image_styles[flip]">
                                    <?php foreach ( array(
                                        'none' => __( 'None', 'ml-slider-lightbox' ),
                                        'h'    => __( 'Horizontal', 'ml-slider-lightbox' ),
                                        'v'    => __( 'Vertical', 'ml-slider-lightbox' ),
                                        'both' => __( 'Both', 'ml-slider-lightbox' ),
                                    ) as $value => $label ) : ?>
                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $image_styles['flip'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <?php if ( ! $is_new ) : ?>
                            <div class="ml-gallery-sidebar-panel">
                                <h3><?php esc_html_e( 'Shortcode', 'ml-slider-lightbox' ); ?></h3>
                                <div class="ml-shortcode-row">
                                    <pre class="ml-gallery-shortcode-pre ml-tipsy" title="<?php esc_attr_e( 'Click to copy shortcode.', 'ml-slider-lightbox' ); ?>">[ml_gallery id="<?php echo absint( $gallery_id ); ?>"]</pre>
                                    <button type="button" class="button ml-shortcode-copy-btn">
                                        <span class="dashicons dashicons-clipboard"></span>
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>

                    </aside>

                </div>

                </div>

                <div id="ml-caption-modal" role="dialog" aria-modal="true" aria-labelledby="ml-caption-modal-title">
                    <div id="ml-caption-overlay"></div>
                    <div id="ml-caption-modal-inner">
                        <div id="ml-caption-modal-header">
                            <h2 id="ml-caption-modal-title"><?php esc_html_e( 'Edit Caption', 'ml-slider-lightbox' ); ?></h2>
                            <div id="ml-caption-nav-group">
                                <button type="button" id="ml-caption-prev" class="ml-caption-nav" aria-label="<?php esc_attr_e( 'Previous image', 'ml-slider-lightbox' ); ?>">&lsaquo;</button>
                                <button type="button" id="ml-caption-next" class="ml-caption-nav" aria-label="<?php esc_attr_e( 'Next image', 'ml-slider-lightbox' ); ?>">&rsaquo;</button>
                            </div>
                            <button type="button" id="ml-caption-close" aria-label="<?php esc_attr_e( 'Close', 'ml-slider-lightbox' ); ?>">&times;</button>
                        </div>
                        <div id="ml-caption-modal-body">
                            <div id="ml-caption-preview-wrap">
                                <img id="ml-caption-preview-img" src="" alt="">
                                <div id="ml-caption-preview-overlay" aria-hidden="true"></div>
                            </div>
                            <div id="ml-caption-editor-col">
                                <div id="ml-caption-tabs" role="tablist">
                                    <?php foreach ( $this->captionSources() as $src => $label ) : ?>
                                        <button type="button"
                                                class="ml-caption-tab"
                                                role="tab"
                                                data-source="<?php echo esc_attr( $src ); ?>">
                                            <?php echo esc_html( $label ); ?>
                                            <span class="ml-caption-tab-badge"></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <div id="ml-caption-editor-wrap">
                                    <label for="ml-gallery-caption-editor" class="screen-reader-text">
                                        <?php esc_html_e( 'Caption', 'ml-slider-lightbox' ); ?>
                                    </label>
                                    <textarea id="ml-gallery-caption-editor"></textarea>
                                    <p id="ml-caption-note" hidden></p>
                                    <p id="ml-caption-readonly" hidden></p>
                                </div>
                            </div>
                        </div>
                        <div id="ml-caption-modal-footer">
                            <button type="button" id="ml-caption-done" class="button button-primary">
                                <?php esc_html_e( 'Done', 'ml-slider-lightbox' ); ?>
                            </button>
                        </div>
                    </div>
                </div>

                <div id="ml-folder-modal" role="dialog" aria-modal="true" aria-labelledby="ml-folder-modal-title">
                    <div id="ml-folder-overlay" aria-hidden="true"></div>
                    <div id="ml-folder-modal-inner">
                        <div id="ml-folder-modal-header">
                            <h2 id="ml-folder-modal-title"><?php esc_html_e( 'Import from Server Folder', 'ml-slider-lightbox' ); ?></h2>
                            <button type="button" id="ml-folder-close" aria-label="<?php esc_attr_e( 'Close', 'ml-slider-lightbox' ); ?>">&times;</button>
                        </div>
                        <div id="ml-folder-breadcrumb" class="ml-folder-breadcrumb"></div>
                        <div id="ml-folder-modal-body">
                            <div id="ml-folder-results" class="ml-folder-results"></div>
                        </div>
                        <div id="ml-folder-modal-footer">
                            <span id="ml-folder-status" class="ml-folder-status"></span>
                            <button type="button" id="ml-folder-cancel" class="button"><?php esc_html_e( 'Cancel', 'ml-slider-lightbox' ); ?></button>
                            <button type="button" id="ml-folder-import" class="button button-primary"><?php esc_html_e( 'Import selected', 'ml-slider-lightbox' ); ?></button>
                        </div>
                    </div>
                </div>

                <input type="file" id="ml-zip-input" accept=".zip,application/zip" style="display:none">

                <div id="ml-zip-modal" role="dialog" aria-modal="true" aria-labelledby="ml-zip-modal-title">
                    <div id="ml-zip-overlay" aria-hidden="true"></div>
                    <div id="ml-zip-modal-inner">
                        <div id="ml-zip-modal-header">
                            <h2 id="ml-zip-modal-title"><?php esc_html_e( 'Import from ZIP', 'ml-slider-lightbox' ); ?></h2>
                            <button type="button" id="ml-zip-close" aria-label="<?php esc_attr_e( 'Close', 'ml-slider-lightbox' ); ?>">&times;</button>
                        </div>
                        <div id="ml-zip-modal-body">
                            <p id="ml-zip-status" class="ml-zip-status"></p>
                        </div>
                    </div>
                </div>

            </form>
        </div>
        <?php
    }

    /**
     * Handle gallery save from admin-post.php.
     * Creates or updates the gallery post and redirects back to the editor.
     *
     * @since 2.23.0
     * @return void
     */
    public function saveGallery() {
        if ( ! isset( $_POST['ml_gallery_nonce'] ) ||
             ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['ml_gallery_nonce'] ) ), 'ml_save_gallery' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'ml-slider-lightbox' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'ml-slider-lightbox' ) );
        }

        $gallery_id = isset( $_POST['gallery_id'] ) ? absint( $_POST['gallery_id'] ) : 0;
        $title      = isset( $_POST['ml_gallery_title'] )
            ? sanitize_text_field( wp_unslash( $_POST['ml_gallery_title'] ) )
            : '';
        $raw        = isset( $_POST['ml_gallery_images'] )
            ? sanitize_text_field( wp_unslash( $_POST['ml_gallery_images'] ) )
            : '';
        $ids        = array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );

        if ( $gallery_id ) {
            $result = wp_update_post( array(
                'ID'          => $gallery_id,
                'post_title'  => $title,
                'post_status' => 'publish',
            ) );
            if ( is_wp_error( $result ) || 0 === $result ) {
                wp_die( esc_html__( 'Could not update the gallery. Please try again.', 'ml-slider-lightbox' ) );
            }
        } else {
            $gallery_id = wp_insert_post( array(
                'post_type'   => 'ml_gallery',
                'post_title'  => $title,
                'post_status' => 'publish',
            ) );
        }

        if ( $gallery_id && ! is_wp_error( $gallery_id ) ) {
            update_post_meta( $gallery_id, '_ml_gallery_images', $ids );

            $raw_captions   = isset( $_POST['ml_gallery_captions'] ) && is_array( $_POST['ml_gallery_captions'] )
                ? $_POST['ml_gallery_captions'] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
                : array();
            $clean_captions = array();
            foreach ( $raw_captions as $img_id => $cap ) {
                $clean_id = absint( $img_id );
                if ( $clean_id ) {
                    $clean_captions[ $clean_id ] = wp_kses_post( wp_unslash( (string) $cap ) );
                }
            }
            update_post_meta( $gallery_id, '_ml_gallery_captions', $clean_captions );

            $settings_raw  = isset( $_POST['ml_gallery_settings'] ) && is_array( $_POST['ml_gallery_settings'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ml_gallery_settings'] ) )
                : array();
            $raw_mode      = isset( $settings_raw['mode'] ) ? sanitize_key( $settings_raw['mode'] ) : 'lg-fade';
            $app_raw       = isset( $_POST['ml_gallery_appearance'] ) && is_array( $_POST['ml_gallery_appearance'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ml_gallery_appearance'] ) )
                : array();
            $bg_opacity = isset( $app_raw['bg_opacity'] ) ? $this->clampOpacity( $app_raw['bg_opacity'] ) : 0.9;
            update_post_meta( $gallery_id, '_ml_gallery_appearance', array(
                'bg_color'               => $this->sanitizeColorValue( $app_raw['bg_color'] ?? '', '#000000' ),
                'bg_opacity'             => (string) $bg_opacity,
                'arrow_color'            => $this->sanitizeColorValue( $app_raw['arrow_color'] ?? '', '#ffffff' ),
                'arrow_bg_color'         => $this->sanitizeColorValue( $app_raw['arrow_bg_color'] ?? '', '#000000' ),
                'close_color'            => $this->sanitizeColorValue( $app_raw['close_color'] ?? '', '#ffffff' ),
                'close_bg_color'         => $this->sanitizeColorValue( $app_raw['close_bg_color'] ?? '', '#000000' ),
                'toolbar_color'          => $this->sanitizeColorValue( $app_raw['toolbar_color'] ?? '', '#ffffff' ),
                'toolbar_bg_color'       => $this->sanitizeColorValue( $app_raw['toolbar_bg_color'] ?? '', '#000000' ),
                'thumbnail_border_color'       => $this->sanitizeColorValue( $app_raw['thumbnail_border_color'] ?? '', '#ffffff' ),
                'thumbnail_border_hover_color' => $this->sanitizeColorValue( $app_raw['thumbnail_border_hover_color'] ?? '', '#dd6923' ),
                'button_text_color'       => $this->sanitizeColorValue( $app_raw['button_text_color'] ?? '', '#ffffff' ),
                'button_hover_text_color' => $this->sanitizeColorValue( $app_raw['button_hover_text_color'] ?? '', '#000000' ),
                'button_color'            => $this->sanitizeColorValue( $app_raw['button_color'] ?? '', '#000000' ),
                'button_hover_color'      => $this->sanitizeColorValue( $app_raw['button_hover_color'] ?? '', '#f0f0f0' ),
                'icon_color'                  => $this->sanitizeColorValue( $app_raw['icon_color'] ?? '', '#ffffff' ),
                'icon_hover_color'            => $this->sanitizeColorValue( $app_raw['icon_hover_color'] ?? '', '#000000' ),
                'icon_background_color'       => $this->sanitizeColorValue( $app_raw['icon_background_color'] ?? '', '#000000' ),
                'icon_background_hover_color' => $this->sanitizeColorValue( $app_raw['icon_background_hover_color'] ?? '', '#f0f0f0' ),
                'autoplay_progress_bar_color' => $this->sanitizeColorValue( $app_raw['autoplay_progress_bar_color'] ?? '', '#a90707' ),
                'caption_text_color' => $this->sanitizeColorValue( $app_raw['caption_text_color'] ?? '', '#ffffff' ),
                'caption_bg_color'   => $this->sanitizeColorValue( $app_raw['caption_bg_color'] ?? '', '#000000' ),
                'caption_text_size'  => (string) min( 24, max( 10, (int) ( $app_raw['caption_text_size'] ?? 14 ) ) ),
                'caption_transition' => in_array( $app_raw['caption_transition'] ?? '', array_keys( $this->allowedCaptionTransitions() ), true )
                    ? $app_raw['caption_transition'] : 'none',
            ) );

            $styles_raw = isset( $_POST['ml_gallery_image_styles'] ) && is_array( $_POST['ml_gallery_image_styles'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['ml_gallery_image_styles'] ) )
                : array();
            $raw_filter       = isset( $styles_raw['filter'] ) ? sanitize_key( $styles_raw['filter'] ) : '';
            $raw_border_style = isset( $styles_raw['border_style'] ) ? sanitize_key( $styles_raw['border_style'] ) : 'solid';
            $raw_box_shadow   = isset( $styles_raw['box_shadow'] ) ? sanitize_key( $styles_raw['box_shadow'] ) : 'none';
            $raw_rotate       = isset( $styles_raw['rotate'] ) ? sanitize_key( $styles_raw['rotate'] ) : '0';
            $raw_flip         = isset( $styles_raw['flip'] ) ? sanitize_key( $styles_raw['flip'] ) : 'none';
            update_post_meta( $gallery_id, '_ml_gallery_image_styles', array(
                'filter'        => array_key_exists( $raw_filter, $this->filterPresets() ) ? $raw_filter : '',
                'corner_radius' => min( 200, max( 0, (int) ( $styles_raw['corner_radius'] ?? 0 ) ) ),
                'border_width'  => min( 50, max( 0, (int) ( $styles_raw['border_width'] ?? 0 ) ) ),
                'border_style'  => in_array( $raw_border_style, array( 'solid', 'dashed', 'dotted', 'double' ), true ) ? $raw_border_style : 'solid',
                'border_color'  => sanitize_hex_color( $styles_raw['border_color'] ?? '' ) ?: '#dddddd',
                'box_shadow'    => in_array( $raw_box_shadow, array( 'none', 'light', 'medium', 'heavy' ), true ) ? $raw_box_shadow : 'none',
                'opacity'       => min( 100, max( 0, (int) ( $styles_raw['opacity'] ?? 100 ) ) ),
                'rotate'        => in_array( $raw_rotate, array( '0', '90', '180', '270' ), true ) ? $raw_rotate : '0',
                'flip'          => in_array( $raw_flip, array( 'none', 'h', 'v', 'both' ), true ) ? $raw_flip : 'none',
            ) );

            $raw_layout = isset( $settings_raw['layout'] ) ? sanitize_key( $settings_raw['layout'] ) : 'grid';

            $caption_display = array_key_exists( $settings_raw['caption_display'] ?? '', $this->allowedCaptionDisplay() )
                ? $settings_raw['caption_display'] : 'lightbox';
            // Carousel/Showcase offer only Hidden and Gallery Only (the inline view
            // is the only surface); coerce the lightbox-based values to 'gallery'.
            if ( in_array( $caption_display, array( 'both', 'lightbox' ), true )
                && in_array( $raw_layout, array( 'carousel', 'showcase' ), true ) ) {
                $caption_display = 'gallery';
            }

            update_post_meta( $gallery_id, '_ml_gallery_settings', array(
                'mode'             => in_array( $raw_mode, array_keys( $this->allowedModes() ), true ) ? $raw_mode : 'lg-fade',
                'controls'         => ! empty( $settings_raw['controls'] ) ? 1 : 0,
                'counter'          => ! empty( $settings_raw['counter'] ) ? 1 : 0,
                'thumbnails'       => ! empty( $settings_raw['thumbnails'] ) ? 1 : 0,
                'download'         => ! empty( $settings_raw['download'] ) ? 1 : 0,
                'caption_display'  => $caption_display,
                'caption_source'   => array_key_exists( $settings_raw['caption_source'] ?? '', $this->captionSources() )
                    ? $settings_raw['caption_source'] : 'manual',
                'loop'             => ! empty( $settings_raw['loop'] ) ? 1 : 0,
                'swipe_close'      => ! empty( $settings_raw['swipe_close'] ) ? 1 : 0,
                'mousewheel'       => ! empty( $settings_raw['mousewheel'] ) ? 1 : 0,
                'keyboard'         => ! empty( $settings_raw['keyboard'] ) ? 1 : 0,
                'layout'           => in_array( $raw_layout, $this->allowedLayouts(), true ) ? $raw_layout : 'grid',
                'columns'          => min( 6, max( 2, (int) ( $settings_raw['columns'] ?? 3 ) ) ),
                'columns_mobile'   => min( 6, max( 1, (int) ( $settings_raw['columns_mobile'] ?? 1 ) ) ),
                'height'           => min( 800, max( 80, (int) ( $settings_raw['height'] ?? 220 ) ) ),
                'gap'              => min( 32, max( 0, (int) ( $settings_raw['gap'] ?? 8 ) ) ),
                'lightbox_size'    => $this->sanitizeImageSize( $settings_raw['lightbox_size'] ?? 'full' ),
                'add_position'     => in_array( $settings_raw['add_position'] ?? '', array( 'start', 'end' ), true ) ? $settings_raw['add_position'] : 'end',
                'open_in_lightbox' => in_array( $raw_layout, array( 'carousel', 'showcase' ), true ) ? 1 : ( ! empty( $settings_raw['open_in_lightbox'] ) ? 1 : 0 ),
                'show_lightbox_button' => ! empty( $settings_raw['show_lightbox_button'] ) ? 1 : 0,
                'button_icon'          => ! empty( $settings_raw['button_icon'] ) ? 1 : 0,
                'button_text'          => isset( $settings_raw['button_text'] ) ? sanitize_text_field( $settings_raw['button_text'] ) : '',
                'button_position'      => in_array( $settings_raw['button_position'] ?? '', $this->allowedButtonPositions(), true )
                    ? $settings_raw['button_position'] : 'top-right',
            ) );

            do_action( 'ml_save_gallery_pro', $gallery_id );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ml-gallery-editor&id=' . absint( $gallery_id ) . '&saved=1' ) );
        exit;
    }

    /**
     * Duplicate an existing gallery and redirect to the new gallery's editor.
     *
     * @since 2.23.0
     */
    public function duplicateGallery() {
        $nonce      = isset( $_GET['_wpnonce'] ) ? sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        $gallery_id = isset( $_GET['gallery_id'] ) ? absint( $_GET['gallery_id'] ) : 0;

        if ( ! wp_verify_nonce( $nonce, 'ml_duplicate_gallery_' . $gallery_id ) ||
             ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'ml-slider-lightbox' ) );
        }

        $original = get_post( $gallery_id );
        if ( ! $original || 'ml_gallery' !== $original->post_type ) {
            wp_die( esc_html__( 'Gallery not found.', 'ml-slider-lightbox' ) );
        }

        $new_id = wp_insert_post( array(
            'post_type'   => 'ml_gallery',
            'post_title'  => $original->post_title . ' ' . __( '(Copy)', 'ml-slider-lightbox' ),
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
        ) );

        if ( is_wp_error( $new_id ) ) {
            wp_die( esc_html__( 'Could not duplicate the gallery. Please try again.', 'ml-slider-lightbox' ) );
        }

        foreach ( array( '_ml_gallery_images', '_ml_gallery_settings', '_ml_gallery_appearance', '_ml_gallery_captions' ) as $key ) {
            $value = get_post_meta( $gallery_id, $key, true );
            if ( '' !== $value ) {
                update_post_meta( $new_id, $key, $value );
            }
        }

        do_action( 'ml_duplicate_gallery_pro', $gallery_id, $new_id );

        wp_safe_redirect( admin_url( 'admin.php?page=ml-gallery-editor&id=' . $new_id . '&saved=1' ) );
        exit;
    }

    /**
     * Create a new ml_gallery post from a flat list of attachment IDs,
     * with default appearance/settings/image-style meta.
     *
     * Public so callers outside this class (e.g. MetaSliderLightboxSlideshowConverter)
     * can build a gallery from images without duplicating the default-meta shape
     * saveGallery() writes.
     *
     * @since 2.35.0
     * @param int[]              $image_ids Attachment IDs, in display order.
     * @param array<int,string>  $captions  Attachment ID => caption HTML.
     * @param string             $title     Gallery post title.
     * @return int|\WP_Error New gallery post ID, or WP_Error on failure.
     */
    public function createGalleryFromImages( array $image_ids, array $captions = array(), $title = '' ) {
        $gallery_id = wp_insert_post( array(
            'post_type'   => 'ml_gallery',
            'post_title'  => '' !== $title ? $title : __( 'New Gallery', 'ml-slider-lightbox' ),
            'post_status' => 'publish',
        ), true ); // $wp_error=true: a plain 0 return would pass the is_wp_error() check below and silently write meta to post ID 0.

        if ( is_wp_error( $gallery_id ) ) {
            return $gallery_id;
        }

        $clean_ids = array_values( array_filter( array_map( 'absint', $image_ids ) ) );
        update_post_meta( $gallery_id, '_ml_gallery_images', $clean_ids );

        $clean_captions = array();
        foreach ( $captions as $img_id => $caption ) {
            $clean_id = absint( $img_id );
            if ( $clean_id && '' !== $caption ) {
                $clean_captions[ $clean_id ] = wp_kses_post( (string) $caption );
            }
        }
        update_post_meta( $gallery_id, '_ml_gallery_captions', $clean_captions );

        update_post_meta( $gallery_id, '_ml_gallery_settings', $this->defaultSettings() );
        update_post_meta( $gallery_id, '_ml_gallery_appearance', $this->defaultAppearance() );
        update_post_meta( $gallery_id, '_ml_gallery_image_styles', $this->defaultImageStyles() );

        return $gallery_id;
    }

    /**
     * Resolve the browsable import root (uploads dir), as a canonical real path.
     *
     * @return string|false
     */
    private function importRoot() {
        $uploads = wp_get_upload_dir();
        $base    = isset( $uploads['basedir'] ) ? $uploads['basedir'] : '';
        $base    = apply_filters( 'ml_gallery_import_root', $base );
        $real    = $base ? realpath( $base ) : false;
        return $real ? $real : false;
    }

    /**
     * Resolve a uploads-relative path to a contained absolute path, or false.
     *
     * @param string $relpath Path relative to the import root.
     * @return string|false
     */
    private function resolveImportPath( $relpath ) {
        $root = $this->importRoot();
        if ( ! $root ) {
            return false;
        }
        $relpath = ltrim( (string) $relpath, "/\\" );
        $target  = ( '' === $relpath ) ? $root : $root . DIRECTORY_SEPARATOR . $relpath;
        $real    = realpath( $target );
        if ( false === $real ) {
            return false;
        }
        if ( $real === $root || 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
            return $real;
        }
        return false;
    }

    /**
     * Build the uploads URL for an absolute path inside the uploads dir.
     *
     * @param string $abs_path Absolute file path.
     * @return string Empty string if the path is not under uploads.
     */
    private function uploadsUrlForPath( $abs_path ) {
        $uploads = wp_get_upload_dir();
        $basedir = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
        $real    = realpath( $abs_path );
        if ( ! $basedir || ! $real
            || ( $real !== $basedir && 0 !== strpos( $real, $basedir . DIRECTORY_SEPARATOR ) ) ) {
            return '';
        }
        $rel = ltrim( substr( $real, strlen( $basedir ) ), "/\\" );
        $rel = str_replace( '\\', '/', $rel );
        return trailingslashit( $uploads['baseurl'] ) . $rel;
    }

    /**
     * Whether a ZIP entry name is a safe, importable image (no traversal).
     *
     * @param string $name Entry name from the archive listing.
     * @return bool
     */
    private function isSafeZipImageEntry( $name ) {
        $name = (string) $name;
        if ( '' === $name ) {
            return false;
        }
        // Directory entry.
        if ( '/' === substr( $name, -1 ) || '\\' === substr( $name, -1 ) ) {
            return false;
        }
        // Absolute path or Windows drive prefix.
        if ( '/' === $name[0] || '\\' === $name[0] || preg_match( '#^[A-Za-z]:#', $name ) ) {
            return false;
        }
        // Path traversal (conservative: reject any '..').
        if ( false !== strpos( $name, '..' ) ) {
            return false;
        }
        $allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
        $ext     = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        return in_array( $ext, $allowed, true );
    }

    /**
     * AJAX: list subfolders and images within an uploads-relative folder.
     */
    public function ajaxBrowseFolder() {
        if ( ! check_ajax_referer( 'ml_gallery_folder', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ml-slider-lightbox' ) ), 403 );
            return;
        }

        $rel = isset( $_POST['path'] ) ? sanitize_text_field( wp_unslash( $_POST['path'] ) ) : '';
        $dir = $this->resolveImportPath( $rel );
        if ( ! $dir || ! is_dir( $dir ) ) {
            wp_send_json_error( array( 'message' => __( 'Folder not found.', 'ml-slider-lightbox' ) ), 400 );
            return;
        }

        $root     = $this->importRoot();
        $rel_norm = ltrim( str_replace( '\\', '/', substr( $dir, strlen( $root ) ) ), '/' );

        $entries = @scandir( $dir );
        if ( false === $entries ) {
            wp_send_json_error( array( 'message' => __( 'Folder could not be read.', 'ml-slider-lightbox' ) ), 400 );
            return;
        }

        $allowed   = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
        $limit     = 200;
        $folders   = array();
        $images    = array();
        $truncated = false;

        foreach ( $entries as $entry ) {
            if ( '.' === $entry || '..' === $entry || '.' === $entry[0] ) {
                continue;
            }
            $abs       = $dir . DIRECTORY_SEPARATOR . $entry;
            $child_rel = ( '' === $rel_norm ? '' : $rel_norm . '/' ) . $entry;

            if ( is_dir( $abs ) ) {
                $folders[] = array( 'name' => $entry, 'path' => $child_rel );
            } elseif ( is_file( $abs ) ) {
                $ext = strtolower( pathinfo( $entry, PATHINFO_EXTENSION ) );
                if ( ! in_array( $ext, $allowed, true ) ) {
                    continue;
                }
                if ( count( $images ) >= $limit ) {
                    $truncated = true;
                    continue;
                }
                $url      = $this->uploadsUrlForPath( $abs );
                $id       = $url ? attachment_url_to_postid( $url ) : 0;
                $images[] = array( 'name' => $entry, 'path' => $child_rel, 'url' => $url, 'id' => (int) $id );
            }
        }

        usort( $folders, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        usort( $images, function ( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );

        $crumbs = array( array( 'name' => __( 'Uploads', 'ml-slider-lightbox' ), 'path' => '' ) );
        if ( '' !== $rel_norm ) {
            $acc = '';
            foreach ( explode( '/', $rel_norm ) as $seg ) {
                $acc      = ( '' === $acc ? '' : $acc . '/' ) . $seg;
                $crumbs[] = array( 'name' => $seg, 'path' => $acc );
            }
        }

        wp_send_json_success( array(
            'breadcrumb' => $crumbs,
            'folders'    => $folders,
            'images'     => $images,
            'truncated'  => $truncated,
        ) );
    }

    /**
     * Return an existing attachment id for the file, or create one in place.
     *
     * @param string $abs_path Absolute path to an image already inside uploads.
     * @return int Attachment id, or 0 on failure.
     */
    private function findOrCreateAttachment( $abs_path ) {
        $url = $this->uploadsUrlForPath( $abs_path );
        if ( $url ) {
            $existing = attachment_url_to_postid( $url );
            if ( $existing ) {
                return (int) $existing;
            }
        }

        $filetype = wp_check_filetype( $abs_path );
        if ( empty( $filetype['type'] ) ) {
            return 0;
        }

        $attachment = array(
            'guid'           => $url ? $url : '',
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name( pathinfo( $abs_path, PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attach_id = wp_insert_attachment( $attachment, $abs_path );
        if ( is_wp_error( $attach_id ) || ! $attach_id ) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata( $attach_id, $abs_path );
        wp_update_attachment_metadata( $attach_id, $meta );

        return (int) $attach_id;
    }

    /**
     * AJAX: import selected uploads-relative image paths as attachments.
     */
    public function ajaxImportFolder() {
        if ( ! check_ajax_referer( 'ml_gallery_folder', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ml-slider-lightbox' ) ), 403 );
            return; // Defensive: ensure nothing else runs even if a filter/override prevents wp_die().
        }

        $paths = ( isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) )
            ? array_map( function ( $p ) { return sanitize_text_field( wp_unslash( $p ) ); }, $_POST['paths'] )
            : array();

        $allowed     = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' );
        $attachments = array();
        $skipped     = 0;

        foreach ( $paths as $rel ) {
            $abs = $this->resolveImportPath( $rel );
            $ext = $abs ? strtolower( pathinfo( $abs, PATHINFO_EXTENSION ) ) : '';
            if ( ! $abs || ! is_file( $abs ) || ! in_array( $ext, $allowed, true ) ) {
                $skipped++;
                continue;
            }
            $id = $this->findOrCreateAttachment( $abs );
            if ( ! $id ) {
                $skipped++;
                continue;
            }
            $thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
            $full  = wp_get_attachment_url( $id );
            $attachments[] = array(
                'id'    => $id,
                'url'   => $full ? $full : '',
                'thumb' => $thumb ? $thumb : ( $full ? $full : '' ),
            );
        }

        wp_send_json_success( array( 'attachments' => $attachments, 'skipped' => $skipped ) );
    }

    /**
     * Enqueue admin assets on the custom gallery editor page only.
     *
     * @since 2.23.0
     * @param string $hook Current admin page hook suffix.
     * @return void
     */
    public function enqueueAdminAssets( $hook ) {
        $is_editor = isset( $_GET['page'] ) && 'ml-gallery-editor' === $_GET['page'];
        $is_list   = 'edit.php' === $hook && isset( $_GET['post_type'] ) && 'ml_gallery' === $_GET['post_type'];

        if ( ! $is_editor && ! $is_list ) {
            return;
        }

        wp_enqueue_style(
            'ml-gallery-admin',
            plugin_dir_url( __FILE__ ) . 'assets/css/ml-gallery-admin.css',
            array(),
            $this->version
        );

        if ( ! $is_editor ) {
            wp_enqueue_script(
                'ml-gallery-admin',
                plugin_dir_url( __FILE__ ) . 'assets/js/ml-gallery-admin.js',
                array( 'jquery' ),
                filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/ml-gallery-admin.js' ) ?: $this->version,
                true
            );
            return;
        }

        wp_enqueue_media();
        wp_enqueue_editor();
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_style( 'wp-color-picker' );

        wp_enqueue_style(
            'jquery-tipsy',
            plugin_dir_url( __FILE__ ) . 'assets/css/jquery.tipsy.css',
            array(),
            $this->version
        );

        wp_enqueue_script(
            'jquery-tipsy',
            plugin_dir_url( __FILE__ ) . 'assets/js/jquery.tipsy.js',
            array( 'jquery' ),
            $this->version,
            true
        );

        wp_enqueue_style(
            'ml-trigger-control',
            plugin_dir_url( __FILE__ ) . 'assets/css/ml-trigger-control.css',
            array(),
            $this->version
        );

        wp_enqueue_script(
            'ml-lightbox-trigger',
            plugin_dir_url( __FILE__ ) . 'assets/js/ml-lightbox-trigger.js',
            array(),
            filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/ml-lightbox-trigger.js' ) ?: $this->version,
            true
        );

        wp_enqueue_script(
            'ml-gallery-admin',
            plugin_dir_url( __FILE__ ) . 'assets/js/ml-gallery-admin.js',
            array( 'jquery', 'jquery-ui-sortable', 'media-upload', 'wp-color-picker', 'jquery-tipsy', 'wp-api-fetch', 'ml-lightbox-trigger' ),
            filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/ml-gallery-admin.js' ) ?: $this->version,
            true
        );

        $caption_gallery_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        $caption_settings   = $caption_gallery_id ? get_post_meta( $caption_gallery_id, '_ml_gallery_settings', true ) : array();
        $active_source      = ( is_array( $caption_settings )
            && array_key_exists( $caption_settings['caption_source'] ?? '', $this->captionSources() ) )
            ? $caption_settings['caption_source'] : 'manual';

        wp_localize_script(
            'ml-gallery-admin',
            'mlGalleryAdmin',
            array(
                'galleryId'        => isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0,
                'selectTitle'      => __( 'Select Gallery Images', 'ml-slider-lightbox' ),
                'selectButton'     => __( 'Add to Gallery', 'ml-slider-lightbox' ),
                'removeLabel'      => __( 'Remove image', 'ml-slider-lightbox' ),
                'editCaptionLabel' => __( 'Edit caption', 'ml-slider-lightbox' ),
                'addCaptionLabel'  => __( 'Add caption', 'ml-slider-lightbox' ),
                'captionNonce'          => wp_create_nonce( 'ml_caption' ),
                'captionSource'         => $active_source,
                'captionSourceLabels'   => array(
                    'manual'            => __( 'Manual entry', 'ml-slider-lightbox' ),
                    'media_caption'     => __( 'Media caption', 'ml-slider-lightbox' ),
                    'media_description' => __( 'Media description', 'ml-slider-lightbox' ),
                ),
                'captionMediaNote'      => __( 'Saved to this image in your Media Library — changes appear anywhere the image is used.', 'ml-slider-lightbox' ),
                'captionReadonlyPerm'   => __( 'You do not have permission to edit this image.', 'ml-slider-lightbox' ),
                'captionReadonlySource' => __( 'This caption source is read-only.', 'ml-slider-lightbox' ),
                'captionShownBadge'     => __( 'Shown in this gallery', 'ml-slider-lightbox' ),
                'folderNonce'     => wp_create_nonce( 'ml_gallery_folder' ),
                'folderLoading'   => __( 'Loading…', 'ml-slider-lightbox' ),
                'folderEmpty'     => __( 'No images in this folder.', 'ml-slider-lightbox' ),
                'folderError'     => __( 'Could not load this folder.', 'ml-slider-lightbox' ),
                'folderImporting' => __( 'Importing…', 'ml-slider-lightbox' ),
                'folderTruncated' => __( 'Showing the first 200 images — open a subfolder to narrow the list.', 'ml-slider-lightbox' ),
                'folderNoSel'     => __( 'Select at least one image.', 'ml-slider-lightbox' ),
                'folderImported'  => __( 'Imported %1$d image(s); %2$d image(s) skipped.', 'ml-slider-lightbox' ),
                'zipUploading'    => __( 'Uploading…', 'ml-slider-lightbox' ),
                'zipImporting'    => __( 'Importing images…', 'ml-slider-lightbox' ),
                'zipDone'         => __( 'Imported %1$d image(s); %2$d image(s) skipped.', 'ml-slider-lightbox' ),
                'zipTruncated'    => __( 'Only the first 200 images (or 256 MB) were imported.', 'ml-slider-lightbox' ),
                'zipError'        => __( 'The ZIP could not be imported.', 'ml-slider-lightbox' ),
                'zipRejected'     => __( 'The upload was rejected — the ZIP may be larger than this server allows.', 'ml-slider-lightbox' ),
                'zipNone'         => __( 'No images found in the ZIP.', 'ml-slider-lightbox' ),
            )
        );
    }

    /**
     * Render the [ml_gallery id="X"] shortcode.
     *
     * Returns an empty string for invalid, draft, or empty galleries so the
     * page renders cleanly without orphaned markup.
     *
     * @since 2.23.0
     * @param array $atts Shortcode attributes. Supports 'id' (integer gallery post ID).
     * @return string HTML output.
     */
    public function galleryShortcode( $atts ) {
        $atts = shortcode_atts(
            array( 'id' => 0 ),
            $atts,
            'ml_gallery'
        );

        $gallery_id = absint( $atts['id'] );
        if ( ! $gallery_id ) {
            return '';
        }

        $post = get_post( $gallery_id );
        if ( ! $post || 'ml_gallery' !== $post->post_type || 'publish' !== $post->post_status ) {
            return '';
        }

        $image_ids = get_post_meta( $gallery_id, '_ml_gallery_images', true );
        if ( ! is_array( $image_ids ) || empty( $image_ids ) ) {
            return '';
        }

        self::$rendered_ids[ $gallery_id ] = $post;

        $saved_captions   = get_post_meta( $gallery_id, '_ml_gallery_captions', true );
        $gallery_captions = is_array( $saved_captions ) ? $saved_captions : array();

        $saved = get_post_meta( $gallery_id, '_ml_gallery_settings', true );
        $lg    = wp_parse_args( is_array( $saved ) ? $saved : array(), $this->defaultSettings() );

        // resolveCaptionDisplay() must run against the raw (unmerged) saved meta so
        // legacy galleries without a 'caption_display' key still fall back to their
        // old 'captions' flag instead of silently picking up the merged default.
        // The resolved value is baked into $lg so renderGallery() (which only sees
        // the merged settings) reproduces the same result from $state.
        $lg['caption_display'] = $this->resolveCaptionDisplay( $saved );

        $saved_app  = get_post_meta( $gallery_id, '_ml_gallery_appearance', true );
        $appearance = wp_parse_args( is_array( $saved_app ) ? $saved_app : array(), $this->defaultAppearance() );

        $saved_styles = get_post_meta( $gallery_id, '_ml_gallery_image_styles', true );
        $image_styles = wp_parse_args( is_array( $saved_styles ) ? $saved_styles : array(), $this->defaultImageStyles() );

        return $this->renderGallery(
            array(
                'id'           => $gallery_id,
                'image_ids'    => $image_ids,
                'captions'     => $gallery_captions,
                'settings'     => $lg,
                'appearance'   => $appearance,
                'image_styles' => $image_styles,
            )
        );
    }

    /**
     * Render gallery HTML from an explicit state array.
     *
     * Split out of galleryShortcode() so the same markup can be produced from
     * unsaved editor state (live preview) as well as saved post meta.
     *
     * @since 2.35.0
     * @param array $state id, image_ids, captions, settings, appearance, image_styles.
     * @return string
     */
    private function renderGallery( array $state ) {
        $gallery_id       = (int) $state['id'];
        $image_ids        = $state['image_ids'];
        $gallery_captions = $state['captions'];
        $lg               = $state['settings'];
        $appearance       = $state['appearance'];
        $image_styles     = $state['image_styles'];

        $caption_display       = $this->resolveCaptionDisplay( $lg );
        $show_lightbox_caption = in_array( $caption_display, array( 'both', 'lightbox' ), true );
        $show_thumb_caption    = in_array( $caption_display, array( 'both', 'gallery' ), true );

        $bg_color      = esc_html( $this->sanitizeColorValue( $appearance['bg_color'], '#000000' ) );
        $bg_opacity    = esc_html( (string) $this->clampOpacity( $appearance['bg_opacity'] ) );
        $arrow_color   = esc_html( $this->sanitizeColorValue( $appearance['arrow_color'], '#ffffff' ) );
        $arrow_bg      = esc_html( $this->sanitizeColorValue( $appearance['arrow_bg_color'], '#000000' ) );
        $close_color   = esc_html( $this->sanitizeColorValue( $appearance['close_color'], '#ffffff' ) );
        $close_bg      = esc_html( $this->sanitizeColorValue( $appearance['close_bg_color'], '#000000' ) );
        $toolbar_color    = esc_html( $this->sanitizeColorValue( $appearance['toolbar_color'], '#ffffff' ) );
        $toolbar_bg       = esc_html( $this->sanitizeColorValue( $appearance['toolbar_bg_color'], '#000000' ) );
        $thumbnail_border       = esc_html( $this->sanitizeColorValue( $appearance['thumbnail_border_color'], '#ffffff' ) );
        $thumbnail_border_hover = esc_html( $this->sanitizeColorValue( $appearance['thumbnail_border_hover_color'], '#dd6923' ) );

        $caption_text_color = esc_html( $this->sanitizeColorValue( $appearance['caption_text_color'], '#ffffff' ) );
        $caption_bg_color   = esc_html( $this->sanitizeColorValue( $appearance['caption_bg_color'], '#000000' ) );
        $caption_text_size  = min( 24, max( 10, (int) ( $appearance['caption_text_size'] ?? 14 ) ) );
        $caption_transition = in_array( $appearance['caption_transition'] ?? '', array_keys( $this->allowedCaptionTransitions() ), true )
            ? $appearance['caption_transition'] : 'none';

        $lg_class = 'ml-gallery-' . $gallery_id;

        // On-page caption scrim: the chosen background colour faded to transparent
        // so captions stay legible over any image. Shared by gallery thumbnails and
        // showcase (via --ml-thumb-caption-bg) and the inline carousel (.lg-sub-html).
        $thumb_caption_bg = "linear-gradient(to top, {$this->hexToRgba( $caption_bg_color, 0.7 )}, {$this->hexToRgba( $caption_bg_color, 0 )})";

        $inline_css = "
            #ml-gallery-{$gallery_id} {
                --ml-arrow-color: {$arrow_color};
                --ml-arrow-bg:    {$arrow_bg};
                --ml-toolbar-color: {$toolbar_color};
                --ml-toolbar-bg:    {$toolbar_bg};
                --ml-thumb-caption-color: {$caption_text_color};
                --ml-thumb-caption-size: {$caption_text_size}px;
                --ml-thumb-caption-bg: {$thumb_caption_bg};
            }
            .lg-container.{$lg_class} {
                --ml-lightbox-arrow-color: {$arrow_color} !important;
                --ml-lightbox-close-icon-color: {$close_color} !important;
                --ml-lightbox-toolbar-icon-color: {$toolbar_color} !important;
                --ml-lightbox-thumbnail-border-color: {$thumbnail_border} !important;
                --ml-lightbox-thumbnail-border-hover-color: {$thumbnail_border_hover} !important;
            }
            .lg-container.{$lg_class} .lg-backdrop {
                background-color: {$bg_color} !important;
                opacity: {$bg_opacity} !important;
            }
            .lg-container.{$lg_class} .lg-thumb-outer {
                background-color: {$bg_color} !important;
                opacity: 1 !important;
            }
            .lg-container.{$lg_class} .lg-prev,
            .lg-container.{$lg_class} .lg-next {
                background-color: {$arrow_bg} !important;
                color: {$arrow_color} !important;
            }
            .lg-container.{$lg_class} .lg-close {
                background-color: {$close_bg} !important;
                color: {$close_color} !important;
            }
            .lg-container.{$lg_class} .lg-toolbar > .lg-icon:not(.lg-close),
            .lg-container.{$lg_class} .lg-counter {
                background-color: {$toolbar_bg} !important;
                color: {$toolbar_color} !important;
            }
            .lg-container.{$lg_class} .lg-sub-html,
            .lg-container.{$lg_class} .lg-sub-html p {
                color: {$caption_text_color} !important;
                font-size: {$caption_text_size}px !important;
            }
        ";

        if ( ! in_array( $lg['layout'], array( 'carousel', 'showcase' ), true ) ) {
            $inline_css .= "
                .lg-container.{$lg_class} .lg-sub-html {
                    background-image: none !important;
                    background-color: {$caption_bg_color} !important;
                }
            ";
        } elseif ( 'carousel' === $lg['layout'] ) {
            // Carousel renders its caption through lightGallery's inline .lg-sub-html
            // (showcase uses .ml-showcase-caption instead). Apply the same on-page
            // gradient scrim so the chosen background colour shows and stays legible.
            $inline_css .= "
                .lg-container.{$lg_class} .lg-sub-html {
                    background: {$thumb_caption_bg} !important;
                }
            ";
        }

        // Autoplay progress bar is Pro-only (free galleries have no autoplay).
        if ( $this->is_pro ) {
            $progress_color = esc_html( $this->sanitizeColorValue( $appearance['autoplay_progress_bar_color'] ?? '', '#a90707' ) );
            $inline_css    .= "
                .lg-container.{$lg_class} .lg-progress-bar .lg-progress {
                    background-color: {$progress_color} !important;
                }
            ";
        }

        $inline_css .= $this->buildImageStylesCss( $gallery_id, $image_styles );

        self::$queued_css[ $gallery_id ] = $inline_css;

        $layout  = in_array( $lg['layout'], $this->allowedLayouts(), true ) ? $lg['layout'] : 'grid';
        $columns = min( 6, max( 2, (int) $lg['columns'] ) );
        $gap     = min( 32, max( 0, (int) $lg['gap'] ) );

        // data-sub-html feeds the popup lightbox (grid/masonry/justified) or the
        // inline carousel view. For inline layouts (carousel/showcase) the caption
        // is a gallery-surface concept offered only as Hidden / Gallery Only, so the
        // inline caption shows for any "on" state (this also keeps legacy galleries
        // saved as 'lightbox' working until re-saved). Otherwise gate on the
        // lightbox flag.
        $is_inline_layout = in_array( $layout, array( 'carousel', 'showcase' ), true );
        $emit_sub_html    = $is_inline_layout
            ? ( $show_thumb_caption || $show_lightbox_caption )
            : $show_lightbox_caption;

        /**
         * Filter the extra data-* attributes placed on the gallery container.
         *
         * @since 2.35.0 Added the $pro_settings parameter.
         *
         * @param array      $attrs        name => value pairs.
         * @param int        $gallery_id   Gallery post id.
         * @param array|null $pro_settings Unsaved ml_gallery_pro_settings[*] values during a
         *                                 live preview render, null for saved state.
         */
        $extra_attrs = (array) apply_filters(
            'ml_gallery_data_attributes',
            array(),
            $gallery_id,
            isset( $state['pro_settings'] ) ? (array) $state['pro_settings'] : null
        );

        // The "Open in Gallery" button only applies to grid-like layouts that open a
        // gallery window. Carousel/showcase are inline surfaces with no wrapper button.
        $show_gallery_button = ! empty( $lg['show_lightbox_button'] )
            && ! empty( $lg['open_in_lightbox'] )
            && in_array( $layout, array( 'grid', 'masonry', 'justified' ), true );
        $button_position     = $lg['button_position'] ?? 'top-right';

        // Position the button per gallery via ID-scoped inline CSS so it overrides
        // the default .ml-lightbox-button (top-right) regardless of stylesheet order,
        // and so multiple galleries on one page each keep their own position.
        if ( $show_gallery_button && 'top-right' !== $button_position ) {
            $pos_rules = array(
                'top-left'     => 'top:10px;bottom:auto;left:10px;right:auto;',
                'bottom-right' => 'top:auto;bottom:10px;left:auto;right:10px;',
                'bottom-left'  => 'top:auto;bottom:10px;left:10px;right:auto;',
                'center'       => 'top:50%;bottom:auto;left:50%;right:auto;transform:translate(-50%,-50%);',
            );
            if ( isset( $pos_rules[ $button_position ] ) ) {
                $decls = preg_replace( '/;/', ' !important;', $pos_rules[ $button_position ] );
                self::$queued_css[ $gallery_id ] .= "\n#ml-gallery-{$gallery_id} .ml-lightbox-button{{$decls}}";
            }
        }

        // Button/icon trigger colours. The icon-scoped rules (:has/.ml-lightbox-icon)
        // only bite in icon mode, so one block serves both triggers (mirrors global CSS).
        if ( $show_gallery_button ) {
            $btn_text_color = esc_html( $this->sanitizeColorValue( $appearance['button_text_color'] ?? '', '#ffffff' ) );
            $btn_text_hover = esc_html( $this->sanitizeColorValue( $appearance['button_hover_text_color'] ?? '', '#000000' ) );
            $btn_bg         = esc_html( $this->sanitizeColorValue( $appearance['button_color'] ?? '', '#000000' ) );
            $btn_bg_hover   = esc_html( $this->sanitizeColorValue( $appearance['button_hover_color'] ?? '', '#f0f0f0' ) );
            $ico_color      = esc_html( $this->sanitizeColorValue( $appearance['icon_color'] ?? '', '#ffffff' ) );
            $ico_hover      = esc_html( $this->sanitizeColorValue( $appearance['icon_hover_color'] ?? '', '#000000' ) );
            $ico_bg         = esc_html( $this->sanitizeColorValue( $appearance['icon_background_color'] ?? '', '#000000' ) );
            $ico_bg_hover   = esc_html( $this->sanitizeColorValue( $appearance['icon_background_hover_color'] ?? '', '#f0f0f0' ) );
            $b              = "#ml-gallery-{$gallery_id} .ml-lightbox-button";
            self::$queued_css[ $gallery_id ] .= "
                {$b}{background-color:{$btn_bg} !important;color:{$btn_text_color} !important;}
                {$b}:hover,{$b}:focus{background-color:{$btn_bg_hover} !important;color:{$btn_text_hover} !important;}
                {$b}:has(.ml-lightbox-icon){background-color:{$ico_bg} !important;}
                {$b}:has(.ml-lightbox-icon):hover,{$b}:has(.ml-lightbox-icon):focus{background-color:{$ico_bg_hover} !important;}
                {$b} .ml-lightbox-icon{color:{$ico_color} !important;}
                {$b}:hover .ml-lightbox-icon,{$b}:focus .ml-lightbox-icon{color:{$ico_hover} !important;}
            ";
        }

        ob_start();
        ?>
        <div id="ml-gallery-<?php echo esc_attr( $gallery_id ); ?>"
             class="ml-gallery-container ml-layout-<?php echo esc_attr( $layout ); ?><?php echo $show_thumb_caption ? ' ml-has-thumb-captions' : ''; ?>"
             style="--ml-columns:<?php echo esc_attr( $columns ); ?>;--ml-columns-mobile:<?php echo absint( $lg['columns_mobile'] ?? 1 ); ?>;--ml-row-height:<?php echo absint( $lg['height'] ?? 220 ); ?>px;--ml-gap:<?php echo esc_attr( $gap ); ?>px"
             data-ml-gallery="true"
             data-ml-lightbox="<?php echo $lg['open_in_lightbox'] ? '1' : '0'; ?>"
             data-ml-layout="<?php echo esc_attr( $layout ); ?>"
             data-lg-class="<?php echo esc_attr( $lg_class ); ?>"
             data-lg-mode="<?php echo esc_attr( $lg['mode'] ); ?>"
             data-lg-controls="<?php echo $lg['controls'] ? '1' : '0'; ?>"
             data-lg-counter="<?php echo $lg['counter'] ? '1' : '0'; ?>"
             data-lg-thumbnails="<?php echo $lg['thumbnails'] ? '1' : '0'; ?>"
             data-lg-download="<?php echo $lg['download'] ? '1' : '0'; ?>"
             data-lg-captions="<?php echo $emit_sub_html ? '1' : '0'; ?>"
             data-lg-caption-transition="<?php echo esc_attr( $caption_transition ); ?>"
             data-lg-loop="<?php echo $lg['loop'] ? '1' : '0'; ?>"
             data-lg-swipe-close="<?php echo $lg['swipe_close'] ? '1' : '0'; ?>"
             data-lg-mousewheel="<?php echo $lg['mousewheel'] ? '1' : '0'; ?>"
             data-lg-keyboard="<?php echo $lg['keyboard'] ? '1' : '0'; ?>"
             <?php if ( $show_gallery_button ) : ?>
             data-ml-show-button="1"
             <?php if ( ! empty( $lg['button_icon'] ) ) : ?>data-ml-button-icon="1"<?php endif; ?>
             <?php if ( '' !== ( $lg['button_text'] ?? '' ) ) : ?>data-ml-button-text="<?php echo esc_attr( $lg['button_text'] ); ?>"<?php endif; ?>
             <?php endif; ?>
             <?php foreach ( $extra_attrs as $attr_name => $attr_value ) : ?>
             <?php if ( ! preg_match( '/^data-[a-z][a-z0-9\-]*$/', $attr_name ) ) { continue; } ?>
             <?php echo ' ' . esc_attr( $attr_name ) . '="' . esc_attr( (string) $attr_value ) . '"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
             <?php endforeach; ?>
>

            <?php foreach ( $image_ids as $image_id ) : ?>
                <?php
                $image_id      = absint( $image_id );
                $lightbox_size = $this->sanitizeImageSize( $lg['lightbox_size'] ?? 'full' );
                $full_url  = wp_get_attachment_image_url( $image_id, $lightbox_size );
                $thumb_url = wp_get_attachment_image_url( $image_id, 'medium' );
                $alt     = (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true );
                $manual  = isset( $gallery_captions[ $image_id ] ) ? (string) $gallery_captions[ $image_id ] : '';
                $caption = $this->resolveItemCaption( $image_id, $manual, $lg['caption_source'] ?? 'manual' );

                if ( ! $full_url ) {
                    continue;
                }
                ?>
                <a href="<?php echo esc_url( $full_url ); ?>"
                   data-src="<?php echo esc_url( $full_url ); ?>"
                   data-thumb="<?php echo esc_url( $thumb_url ? $thumb_url : $full_url ); ?>"
                   <?php if ( $caption && $emit_sub_html ) : ?>
                   data-sub-html="<?php echo esc_html( $caption ); ?>"
                   <?php endif; ?>
                   <?php // esc_html() not esc_attr(): data-sub-html is rendered as HTML by lightGallery.
                         // esc_attr() would double-encode entities (& → &amp;amp;), producing visible artefacts.
                         // Do NOT embed markup (e.g. a wrapper <span>) here: wptexturize mangles quotes
                         // around tags inside attributes. The .ml-caption-text wrapper for the caption
                         // transition is added client-side in applyMlCaptionTransition(). ?>>
                    <?php echo wp_get_attachment_image( $image_id, $lightbox_size, false, array( 'alt' => $alt ) ); ?>
                    <?php if ( $caption ) : ?>
                        <span class="ml-gallery-caption"><?php echo wp_kses_post( $caption ); ?></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>

        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Whether the current request needs ml_gallery frontend assets.
     * Checks the queried post's content for the [ml_gallery shortcode tag.
     *
     * @since 2.23.0
     * @return bool
     */
    private function pageHasGalleryShortcode() {
        $post = get_post();
        return $post && has_shortcode( $post->post_content, 'ml_gallery' );
    }

    /**
     * Returns true if the current post contains the gallery block.
     *
     * @since 2.23.0
     * @return bool
     */
    private function pageHasGalleryBlock() {
        if ( null === $this->has_gallery_block ) {
            $post                    = get_post();
            $this->has_gallery_block = $post && has_block( 'ml-slider-lightbox/gallery', $post );
        }
        return $this->has_gallery_block;
    }

    /**
     * Force-load lightGallery frontend assets when the gallery block is on the page.
     * Note: when the block is present this always returns true, overriding
     * any earlier filter that returned false.
     *
     * @since 2.23.0
     * @param bool $should_load Current load decision.
     * @return bool
     */
    public function forceLoadAssetsForBlock( $should_load ) {
        if ( $this->pageHasGalleryBlock() || $this->pageHasGalleryShortcode() ) {
            return true;
        }
        return $should_load;
    }

    /**
     * Enqueue frontend assets on pages that contain an [ml_gallery] shortcode.
     *
     * @since 2.23.0
     * @return void
     */
    public function enqueueFrontendAssets() {
        if ( ! $this->pageHasGalleryShortcode() && ! $this->pageHasGalleryBlock() ) {
            return;
        }

        wp_enqueue_style(
            'ml-gallery-public',
            plugin_dir_url( __FILE__ ) . 'assets/css/ml-gallery-public.css',
            array(),
            filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/ml-gallery-public.css' ) ?: $this->version
        );

        wp_enqueue_script(
            'ml-gallery-layout',
            plugin_dir_url( __FILE__ ) . 'assets/js/ml-gallery-layout.js',
            array( 'ml-lightgallery-clean' ),
            filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/ml-gallery-layout.js' ) ?: $this->version,
            true
        );

        wp_enqueue_style(
            'lightgallery-transitions-css',
            plugin_dir_url( __FILE__ ) . 'assets/css/lg-transitions.min.css',
            array( 'ml-lightgallery-css' ),
            $this->version
        );

        wp_enqueue_style(
            'lightgallery-thumbnail-css',
            plugin_dir_url( __FILE__ ) . 'assets/css/lg-thumbnail.css',
            array( 'ml-lightgallery-css' ),
            $this->version
        );

        wp_enqueue_script(
            'lightgallery-thumbnail',
            plugin_dir_url( __FILE__ ) . 'assets/js/lg-thumbnail.min.js',
            array( 'ml-lightgallery-js' ),
            $this->version,
            true
        );

    }

    /**
     * Output all per-gallery inline CSS collected during shortcode execution.
     * Called on wp_footer so the styles are always present regardless of when
     * the shortcode ran relative to wp_head().
     *
     * @since 2.23.0
     * @return void
     */
    public function printInlineCss() {
        if ( empty( self::$queued_css ) ) {
            return;
        }
        echo '<style id="ml-gallery-inline-css">' . "\n";
        foreach ( self::$queued_css as $css ) {
            echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- values were sanitized in galleryShortcode()
        }
        echo '</style>' . "\n";
    }

    /**
     * Render the toolbar-style header on the ml_gallery list table page.
     *
     * @since 2.23.0
     * @return void
     */
    public function renderListHeader() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-ml_gallery' !== $screen->id ) {
            return;
        }
        ?>
        <div class="ml-gallery-header">
            <div class="ml-gallery-header-inner">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ml-gallery-editor' ) ); ?>"
                   class="ml-gallery-logo-link"
                   title="<?php esc_attr_e( 'Add New Gallery', 'ml-slider-lightbox' ); ?>">
                    <div class="ml-gallery-logo">
                        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256">
                            <g><path d="M127.9,0C57.3,0,0,57.3,0,127.9c0,70.6,57.3,127.9,127.9,127.9c70.6,0,127.9-57.3,127.9-127.9C255.8,57.3,198.5,0,127.9,0z M16.4,177.1l92.5-117.5L124.2,79l-77.3,98.1H16.4z M170.5,177.1l-38.9-49.4l15.5-19.6l54.4,69H170.5z M208.5,177.1L146.9,99 l-61.6,78.2h-31l92.5-117.5l92.5,117.5H208.5z"/></g>
                        </svg>
                    </div>
                    <span class="ml-gallery-logo-title"><?php esc_html_e( 'MetaSlider Gallery', 'ml-slider-lightbox' ); ?></span>
                </a>

            </div>
        </div>
        <?php
    }

    private function isEmptyGalleryList() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-ml_gallery' !== $screen->id ) {
            return false;
        }

        // Skip filtered views (Trash, search) so their normal table shows —
        // otherwise the panel would hide the Trash table and strand its own
        // "View Trash" link.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display decision.
        if ( isset( $_GET['post_status'] ) || isset( $_GET['s'] ) ) {
            return false;
        }

        return 0 === $this->countGalleries();
    }

    private function countGalleries( $trash = false ) {
        $counts = wp_count_posts( 'ml_gallery' );
        if ( $trash ) {
            return isset( $counts->trash ) ? (int) $counts->trash : 0;
        }

        $total = 0;
        foreach ( array( 'publish', 'draft', 'pending', 'private', 'future' ) as $status ) {
            if ( isset( $counts->$status ) ) {
                $total += (int) $counts->$status;
            }
        }
        return $total;
    }

    public function emptyStateBodyClass( $classes ) {
        if ( $this->isEmptyGalleryList() ) {
            $classes .= ' ml-gallery-empty';
        }
        return $classes;
    }

    /**
     * Welcome panel that replaces the bare "No galleries found." empty state on
     * the Galleries list page (issue #535). Fonts are self-hosted from
     * assets/fonts/ (no remote Google Fonts request).
     *
     * @since 2.35.0
     */
    public function renderEmptyState() {
        if ( ! $this->isEmptyGalleryList() ) {
            return;
        }

        $create_url  = admin_url( 'admin.php?page=ml-gallery-editor' );
        $trash_count = $this->countGalleries( true );
        $trash_url   = admin_url( 'edit.php?post_status=trash&post_type=ml_gallery' );
        $fonts_url    = plugin_dir_url( __FILE__ ) . 'assets/fonts/';
        $font_heading = $fonts_url . 'montserrat-latin.woff2';
        $font_body    = $fonts_url . 'source-sans-3-latin.woff2';

        // More tiles than photos so the preview fills the card and fades out at the bottom.
        $tile_heights = array( 150, 96, 120, 104, 158, 110, 112, 140, 98, 132, 150, 104 );

        $images_url = plugin_dir_url( __FILE__ ) . 'assets/images/welcome/';
        $images     = array();
        for ( $n = 1; $n <= 9; $n++ ) {
            $images[] = $images_url . $n . '.jpg';
        }

        $chips = array(
            array( 'dashicons-move',         __( 'Drag-and-drop building', 'ml-slider-lightbox' ) ),
            array( 'dashicons-format-image', __( 'Responsive lightbox', 'ml-slider-lightbox' ) ),
            array( 'dashicons-grid-view',    __( 'Grid · Masonry · Justified', 'ml-slider-lightbox' ) ),
        );
        ?>
        <style>
            @font-face {
                font-family: 'ML Montserrat';
                font-style: normal;
                font-weight: 700;
                font-display: swap;
                src: url('<?php echo esc_url( $font_heading ); ?>') format('woff2');
            }
            @font-face {
                font-family: 'ML Source Sans 3';
                font-style: normal;
                font-weight: 400 700;
                font-display: swap;
                src: url('<?php echo esc_url( $font_body ); ?>') format('woff2');
            }

            body.ml-gallery-empty .wp-heading-inline,
            body.ml-gallery-empty .page-title-action,
            body.ml-gallery-empty .wp-header-end,
            body.ml-gallery-empty .wp-list-table,
            body.ml-gallery-empty .tablenav,
            body.ml-gallery-empty .search-box,
            body.ml-gallery-empty .subsubsub,
            body.ml-gallery-empty .wp-header-end + .clear { display: none; }

            .ml-empty {
                --ml-ink: #211c17;
                --ml-muted: #6f675e;
                --ml-accent: #ef7c22;
                --ml-accent-hover: #e06d15;
                font-family: 'ML Source Sans 3', system-ui, -apple-system, sans-serif;
                max-width: 1120px;
                margin: 24px auto 44px;
                border-radius: 16px;
                overflow: hidden;
                background: #f3f1ee;
                border: 1px solid #d7d1c9;
                box-shadow: 0 24px 60px -28px rgba( 33, 28, 23, .35 );
                -webkit-font-smoothing: antialiased;
                animation: ml-empty-in .55s cubic-bezier( .16, 1, .3, 1 ) both;
            }
            .ml-empty__grid {
                display: grid;
                grid-template-columns: 1fr 1.08fr;
                min-height: 520px;
            }
            .ml-empty__left {
                padding: 66px 56px;
                display: flex;
                flex-direction: column;
                justify-content: center;
                gap: 22px;
            }
            .ml-empty__eyebrow {
                font-weight: 700;
                font-size: 12px;
                letter-spacing: .12em;
                text-transform: uppercase;
                color: #d75f16;
            }
            .ml-empty__title {
                margin: 0;
                font-family: 'ML Montserrat', system-ui, sans-serif;
                font-weight: 700;
                font-size: 44px;
                line-height: 1.05;
                letter-spacing: -0.02em;
                color: var(--ml-ink);
            }
            .ml-empty__lead {
                margin: 0;
                font-size: 17px;
                line-height: 1.55;
                color: var(--ml-muted);
                max-width: 380px;
            }
            .ml-empty__actions {
                display: flex;
                align-items: center;
                flex-wrap: wrap;
                gap: 14px;
                margin-top: 6px;
            }
            .ml-empty__primary {
                display: inline-block;
                font-family: inherit;
                font-weight: 700;
                font-size: 16px;
                color: #fff;
                background: var(--ml-accent);
                border: none;
                padding: 15px 26px;
                border-radius: 11px;
                cursor: pointer;
                text-decoration: none;
                box-shadow: 0 10px 22px -10px rgba( 239, 124, 34, .8 );
                transition: background .15s ease;
            }
            .ml-empty__primary:hover,
            .ml-empty__primary:focus {
                background: var(--ml-accent-hover);
                color: #fff;
            }
            .ml-empty__chips {
                display: flex;
                flex-wrap: wrap;
                gap: 8px;
                margin-top: 14px;
            }
            .ml-empty__chip {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                font-size: 13px;
                font-weight: 500;
                color: var(--ml-muted);
                background: #fff;
                border: 1px solid #ece7e0;
                padding: 7px 12px;
                border-radius: 999px;
            }
            .ml-empty__chip .dashicons {
                font-size: 15px;
                width: 15px;
                height: 15px;
                color: var(--ml-accent);
            }
            .ml-empty__trash-link {
                display: inline-flex;
                align-items: center;
                gap: 5px;
                margin-top: 4px;
                font-size: 13px;
                font-weight: 600;
                color: #847b6f;
                text-decoration: none;
                width: fit-content;
            }
            .ml-empty__trash-link:hover,
            .ml-empty__trash-link:focus { color: var(--ml-ink); }
            .ml-empty__trash-link .dashicons {
                font-size: 15px;
                width: 15px;
                height: 15px;
            }
            .ml-empty__right {
                position: relative;
                overflow: hidden;
                background: #eae5de;
                border-left: 1px solid #e0dad2;
                padding: 40px 40px 0;
                display: flex;
                flex-direction: column;
                gap: 16px;
            }
            .ml-empty__right::after {
                content: '';
                position: absolute;
                left: 0;
                right: 0;
                bottom: 0;
                height: 140px;
                background: linear-gradient( to bottom, rgba( 234, 229, 222, 0 ), #eae5de 88% );
                pointer-events: none;
            }
            .ml-empty__preview-head {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .ml-empty__preview-label {
                font-weight: 700;
                font-size: 11px;
                letter-spacing: .08em;
                text-transform: uppercase;
                color: #8a8175;
            }
            .ml-empty__toggle {
                display: flex;
                gap: 6px;
                margin-left: auto;
                background: #fff;
                padding: 4px;
                border-radius: 9px;
                border: 1px solid #e4ded6;
            }
            .ml-empty__toggle button {
                font-family: inherit;
                font-size: 12px;
                font-weight: 600;
                color: #847b6f;
                background: transparent;
                border: none;
                padding: 5px 12px;
                border-radius: 6px;
                cursor: pointer;
            }
            .ml-empty__toggle button.is-active {
                color: #fff;
                background: var(--ml-ink);
            }
            .ml-empty__preview {
                position: relative;
                flex: 1 1 0;
                min-height: 260px;
                overflow: hidden;
            }
            .ml-empty__stage.is-masonry { columns: 3; column-gap: 12px; }
            .ml-empty__stage.is-grid {
                display: grid;
                grid-template-columns: repeat( 3, 1fr );
                grid-auto-rows: 122px;
                gap: 12px;
            }
            .ml-empty__stage.is-justified {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            .ml-empty__row { display: flex; gap: 12px; }
            .ml-empty__tile {
                border-radius: 10px;
                break-inside: avoid;
                background-color: #e2ddd5;
                background-size: cover;
                background-position: center;
                background-repeat: no-repeat;
            }
            .ml-empty__stage.is-masonry .ml-empty__tile { margin-bottom: 12px; }

            @keyframes ml-empty-in {
                from { opacity: 0; transform: translateY( 12px ); }
                to   { opacity: 1; transform: none; }
            }
            @media ( prefers-reduced-motion: reduce ) {
                .ml-empty { animation: none; }
            }
            @media screen and ( max-width: 960px ) {
                .ml-empty__grid { grid-template-columns: 1fr; min-height: 0; }
                .ml-empty__left { padding: 44px 32px; }
                .ml-empty__title { font-size: 34px; }
                .ml-empty__right { border-left: none; border-top: 1px solid #e0dad2; }
            }
        </style>

        <div class="ml-empty">
            <div class="ml-empty__grid">
                <div class="ml-empty__left">
                    <span class="ml-empty__eyebrow"><?php esc_html_e( 'Get started', 'ml-slider-lightbox' ); ?></span>
                    <h1 class="ml-empty__title"><?php
                        // translators: line break kept between the two words for the heading layout.
                        echo esc_html__( 'Your galleries', 'ml-slider-lightbox' ) . '<br>' . esc_html__( 'live here.', 'ml-slider-lightbox' );
                    ?></h1>
                    <p class="ml-empty__lead"><?php esc_html_e( 'Build beautiful image galleries with a stunning lightbox. Grid, masonry or justified, no code required.', 'ml-slider-lightbox' ); ?></p>
                    <div class="ml-empty__actions">
                        <a href="<?php echo esc_url( $create_url ); ?>" class="ml-empty__primary"><?php esc_html_e( 'Create your first gallery', 'ml-slider-lightbox' ); ?></a>
                    </div>
                    <div class="ml-empty__chips">
                        <?php foreach ( $chips as $chip ) : ?>
                            <span class="ml-empty__chip"><span class="dashicons <?php echo esc_attr( $chip[0] ); ?>"></span><?php echo esc_html( $chip[1] ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php if ( $trash_count > 0 ) : ?>
                        <a class="ml-empty__trash-link" href="<?php echo esc_url( $trash_url ); ?>">
                            <span class="dashicons dashicons-trash"></span>
                            <?php
                            printf(
                                /* translators: %s: number of galleries in the trash. */
                                esc_html( _n( 'View Trash (%s)', 'View Trash (%s)', $trash_count, 'ml-slider-lightbox' ) ),
                                esc_html( number_format_i18n( $trash_count ) )
                            );
                            ?>
                        </a>
                    <?php endif; ?>
                </div>

                <div class="ml-empty__right">
                    <div class="ml-empty__preview-head">
                        <span class="ml-empty__preview-label"><?php esc_html_e( 'Preview', 'ml-slider-lightbox' ); ?></span>
                        <div class="ml-empty__toggle" role="group" aria-label="<?php esc_attr_e( 'Preview layout', 'ml-slider-lightbox' ); ?>">
                            <button type="button" class="is-active" data-ml-layout="masonry"><?php esc_html_e( 'Masonry', 'ml-slider-lightbox' ); ?></button>
                            <button type="button" data-ml-layout="grid"><?php esc_html_e( 'Grid', 'ml-slider-lightbox' ); ?></button>
                            <button type="button" data-ml-layout="justified"><?php esc_html_e( 'Justified', 'ml-slider-lightbox' ); ?></button>
                        </div>
                    </div>
                    <div class="ml-empty__preview" aria-hidden="true">
                        <div class="ml-empty__stage is-masonry">
                            <?php foreach ( $tile_heights as $i => $h ) : ?>
                                <div class="ml-empty__tile" style="height:<?php echo absint( $h ); ?>px;background-image:url('<?php echo esc_url( $images[ $i % count( $images ) ] ); ?>');"></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        ( function () {
            var root = document.querySelector( '.ml-empty' );
            if ( ! root ) { return; }
            var stage  = root.querySelector( '.ml-empty__stage' );
            var toggle = root.querySelector( '.ml-empty__toggle' );
            var imgs = <?php echo wp_json_encode( array_map( 'esc_url_raw', $images ) ); ?>;
            var masonryHeights = [150, 96, 120, 104, 158, 110, 112, 140, 98, 132, 150, 104];
            var justifiedRows = [ [2, 1, 1.4], [1, 1.6, 1], [1.3, 1, 1.5], [1, 1.5, 1.2] ];

            function tile( idx, style ) {
                var d = document.createElement( 'div' );
                d.className = 'ml-empty__tile';
                d.style.backgroundImage = "url('" + imgs[ idx % imgs.length ] + "')";
                for ( var k in style ) { d.style[ k ] = style[ k ]; }
                return d;
            }

            function render( layout ) {
                stage.className = 'ml-empty__stage is-' + layout;
                stage.textContent = '';
                var i, r, c, idx = 0;
                if ( layout === 'grid' ) {
                    for ( i = 0; i < 12; i++ ) { stage.appendChild( tile( i, {} ) ); }
                } else if ( layout === 'justified' ) {
                    for ( r = 0; r < justifiedRows.length; r++ ) {
                        var row = document.createElement( 'div' );
                        row.className = 'ml-empty__row';
                        row.style.height = '118px';
                        for ( c = 0; c < justifiedRows[ r ].length; c++ ) {
                            var t = tile( idx++, {} );
                            t.style.flex = String( justifiedRows[ r ][ c ] );
                            row.appendChild( t );
                        }
                        stage.appendChild( row );
                    }
                } else {
                    for ( i = 0; i < masonryHeights.length; i++ ) {
                        stage.appendChild( tile( i, { height: masonryHeights[ i ] + 'px', marginBottom: '12px' } ) );
                    }
                }
            }

            toggle.addEventListener( 'click', function ( e ) {
                var btn = e.target.closest( 'button[data-ml-layout]' );
                if ( ! btn ) { return; }
                toggle.querySelectorAll( 'button' ).forEach( function ( b ) { b.classList.remove( 'is-active' ); } );
                btn.classList.add( 'is-active' );
                render( btn.getAttribute( 'data-ml-layout' ) );
            } );
        } )();
        </script>
        <?php
    }

    /**
     * Insert Shortcode and Images columns after the Title column
     * in the Galleries list table.
     *
     * @since 2.23.0
     * @param array $columns Default WP list table columns.
     * @return array Modified columns.
     */
    public function addListColumns( $columns ) {
        $new = array();
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'title' === $key ) {
                $new['ml_shortcode']   = __( 'Shortcode', 'ml-slider-lightbox' );
                $new['ml_image_count'] = __( 'Images', 'ml-slider-lightbox' );
                $new['ml_usage']       = __( 'Usage', 'ml-slider-lightbox' );
            }
        }
        return $new;
    }

    /**
     * Output the value for each custom column in the Galleries list table.
     *
     * @since 2.23.0
     * @param string $column  The column key.
     * @param int    $post_id The current row's post ID.
     * @return void
     */
    public function renderListColumn( $column, $post_id ) {
        if ( 'ml_shortcode' === $column ) {
            $shortcode = '[ml_gallery id="' . absint( $post_id ) . '"]';
            echo '<div class="ml-shortcode-wrap">'
                . '<pre class="ml-shortcode-copy ml-tipsy" title="' . esc_attr__( 'Click to copy shortcode.', 'ml-slider-lightbox' ) . '">'
                . '<div class="ml-shortcode-value">' . esc_html( $shortcode ) . '</div>'
                . '</pre>'
                . '<span class="ml-shortcode-copied" style="display:none"><span class="dashicons dashicons-yes"></span></span>'
                . '</div>';
        }

        if ( 'ml_image_count' === $column ) {
            $image_ids = get_post_meta( $post_id, '_ml_gallery_images', true );
            echo absint( is_array( $image_ids ) ? count( $image_ids ) : 0 );
        }

        if ( 'ml_usage' === $column ) {
            $usage_html = $this->getPostsUsingGallery( $post_id );
            if ( ! $usage_html ) {
                echo esc_html__( 'Not found.', 'ml-slider-lightbox' );
            } else {
                $uid = absint( $post_id );
                ?>
                <button type="button" class="ml-usage-btn button" data-id="<?php echo $uid; ?>">
                    <?php esc_html_e( 'View Usage', 'ml-slider-lightbox' ); ?>
                </button>
                <div class="ml-modal-overlay" id="ml-usage-overlay-<?php echo $uid; ?>" data-id="<?php echo $uid; ?>" style="display:none;"></div>
                <div class="ml-usage-modal" id="ml-usage-modal-<?php echo $uid; ?>" data-id="<?php echo $uid; ?>" role="dialog" aria-modal="true" aria-labelledby="ml-usage-title-<?php echo $uid; ?>" style="display:none;">
                    <div class="ml-usage-modal-inner">
                        <button type="button" class="ml-usage-modal-close" data-id="<?php echo $uid; ?>" aria-label="<?php esc_attr_e( 'Close', 'ml-slider-lightbox' ); ?>">&times;</button>
                        <h3 id="ml-usage-title-<?php echo $uid; ?>"><?php esc_html_e( 'Content Using This Gallery', 'ml-slider-lightbox' ); ?></h3>
                        <?php echo $usage_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized in getPostsUsingGallery() ?>
                    </div>
                </div>
                <?php
            }
        }
    }

    /**
     * Find all published posts/pages that embed this gallery via shortcode or block.
     * Returns escaped HTML grouped by post type, or a localised "not used" string.
     *
     * @param  int    $gallery_id
     * @return string Safe HTML.
     */
    private function getPostsUsingGallery( $gallery_id ) {
        global $wpdb;

        $id        = absint( $gallery_id );
        $cache_key = 'ml_gallery_usage_' . $id;
        $cached    = wp_cache_get( $cache_key, 'ml_gallery' );
        if ( false !== $cached ) {
            return $cached;
        }

        // Only search publicly visible post types — excludes internal WP types
        // such as wp_template, wp_navigation, wp_font_face, oembed_cache, etc.
        // Static so get_post_types() runs once per page load across all gallery rows.
        static $public_types = null;
        if ( null === $public_types ) {
            $public_types = array_values( get_post_types( array( 'public' => true ) ) );
        }
        $placeholders = implode( ', ', array_fill( 0, count( $public_types ), '%s' ) );

        // Shortcode: always written as [ml_gallery id="X"] by the plugin.
        $like_shortcode = '%' . $wpdb->esc_like( '[ml_gallery id="' . $id . '"' ) . '%';

        // Block JSON: "galleryId":X is always followed by , (more keys) or } (last key),
        // so we match both to avoid false positives on IDs that share a numeric prefix.
        $like_block_comma = '%' . $wpdb->esc_like( '"galleryId":' . $id . ',' ) . '%';
        $like_block_brace = '%' . $wpdb->esc_like( '"galleryId":' . $id . '}' ) . '%';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders contains only %s literals
        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_type FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type IN ({$placeholders})
                   AND ( post_content LIKE %s
                         OR post_content LIKE %s
                         OR post_content LIKE %s )",
                array_merge( $public_types, array( $like_shortcode, $like_block_comma, $like_block_brace ) )
            )
        );

        if ( empty( $posts ) ) {
            wp_cache_set( $cache_key, false, 'ml_gallery', 5 * MINUTE_IN_SECONDS );
            return false;
        }

        $grouped = array();
        foreach ( $posts as $post ) {
            $pto   = get_post_type_object( $post->post_type );
            $label = $pto ? $pto->labels->singular_name : ucfirst( $post->post_type );
            $title = '' !== $post->post_title
                ? $post->post_title
                : __( '(no title)', 'ml-slider-lightbox' );
            $grouped[ $label ][] = sprintf(
                '<li><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
                esc_url( get_permalink( $post->ID ) ),
                esc_html( $title )
            );
        }

        $output = '';
        foreach ( $grouped as $label => $items ) {
            $output .= '<h5>' . esc_html( $label ) . '</h5>'
                     . '<ul>' . implode( '', $items ) . '</ul>';
        }

        wp_cache_set( $cache_key, $output, 'ml_gallery', 5 * MINUTE_IN_SECONDS );
        return $output;
    }

    /**
     * Recursively delete a temp dir, refusing anything not under uploads.
     *
     * @param string $dir Absolute path.
     */
    private function deleteUploadsTempDir( $dir ) {
        $uploads = wp_get_upload_dir();
        $basedir = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
        $real    = realpath( $dir );
        if ( ! $basedir || ! $real || 0 !== strpos( $real, $basedir . DIRECTORY_SEPARATOR ) ) {
            return;
        }
        $items = @scandir( $real );
        if ( is_array( $items ) ) {
            foreach ( $items as $item ) {
                if ( '.' === $item || '..' === $item ) {
                    continue;
                }
                $path = $real . DIRECTORY_SEPARATOR . $item;
                if ( is_dir( $path ) ) {
                    $this->deleteUploadsTempDir( $path );
                } else {
                    @unlink( $path );
                }
            }
        }
        @rmdir( $real );
    }

    /**
     * AJAX: import images from an uploaded ZIP into the Media Library.
     */
    public function ajaxImportZip() {
        if ( ! check_ajax_referer( 'ml_gallery_folder', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ml-slider-lightbox' ) ), 403 );
            return;
        }

        if ( empty( $_FILES['zip'] ) || ! isset( $_FILES['zip']['tmp_name'] )
            || ! empty( $_FILES['zip']['error'] ) || ! is_uploaded_file( $_FILES['zip']['tmp_name'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Please choose a valid .zip file.', 'ml-slider-lightbox' ) ), 400 );
            return;
        }

        $tmp   = $_FILES['zip']['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $name  = isset( $_FILES['zip']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['zip']['name'] ) ) : 'upload.zip';
        $check = wp_check_filetype_and_ext( $tmp, $name );
        $type  = ! empty( $check['type'] ) ? $check['type'] : '';
        if ( 'application/zip' !== $type && 'application/x-zip-compressed' !== $type ) {
            wp_send_json_error( array( 'message' => __( 'Please choose a valid .zip file.', 'ml-slider-lightbox' ) ), 400 );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
        if ( ! class_exists( 'PclZip' ) ) {
            wp_send_json_error( array( 'message' => __( 'Server cannot read ZIP files.', 'ml-slider-lightbox' ) ), 500 );
            return;
        }

        $archive = new \PclZip( $tmp );
        $list    = $archive->listContent();
        if ( empty( $list ) || ! is_array( $list ) ) {
            wp_send_json_error( array( 'message' => __( 'The ZIP could not be read.', 'ml-slider-lightbox' ) ), 400 );
            return;
        }

        $max_files    = 200;
        $max_bytes    = 256 * 1024 * 1024; // Cumulative real (post-extraction) byte budget.
        $per_file_cap = 64 * 1024 * 1024;  // Reject any single extracted image larger than this.
        $selected     = array();
        $truncated    = false;

        // Select up to $max_files safe image entries by count only. Declared
        // sizes in the central directory are attacker-controlled and NOT trusted
        // for the size budget — that is enforced against the real extracted size
        // below, one entry at a time.
        foreach ( $list as $entry ) {
            $entry_name = isset( $entry['filename'] ) ? $entry['filename'] : '';
            if ( ! $this->isSafeZipImageEntry( $entry_name ) ) {
                continue;
            }
            if ( count( $selected ) >= $max_files ) {
                $truncated = true;
                break;
            }
            $selected[] = $entry_name;
        }

        if ( empty( $selected ) ) {
            wp_send_json_success( array( 'attachments' => array(), 'skipped' => 0, 'truncated' => $truncated ) );
            return;
        }

        $uploads = wp_get_upload_dir();
        $tmpdir  = trailingslashit( $uploads['basedir'] ) . 'ml-gallery-zip-tmp/' . uniqid( 'z', true );
        if ( ! wp_mkdir_p( $tmpdir ) ) {
            wp_send_json_error( array( 'message' => __( 'Could not prepare a temporary folder.', 'ml-slider-lightbox' ) ), 500 );
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $attachments = array();
        $skipped     = 0;
        $real_total  = 0;

        // Extract ONE entry at a time and stat its REAL size, so a zip bomb that
        // lies about declared sizes cannot fill the disk: each extracted file is
        // sideloaded (moved out of the temp dir) before the next is extracted, so
        // transient disk use is bounded to a single file, and the cumulative real
        // byte budget stops the import once reached.
        foreach ( $selected as $entry_name ) {
            // PclZip's extract() only treats args as options when the FIRST arg
            // is a PCLZIP_OPT_* integer constant — it must be the flattened
            // variadic form, NOT a single options array (an array falls through
            // to the legacy "path" argument and fatals in privExtractByRule).
            $res = $archive->extract(
                PCLZIP_OPT_PATH, $tmpdir,
                PCLZIP_OPT_BY_NAME, $entry_name,
                PCLZIP_OPT_REMOVE_ALL_PATH
            );

            $abs = ( is_array( $res ) && ! empty( $res[0]['filename'] ) ) ? $res[0]['filename'] : '';
            if ( ! $abs || ! is_file( $abs ) ) {
                $skipped++;
                continue;
            }

            $fsize = (int) filesize( $abs );

            // Drop a single oversized file.
            if ( $fsize > $per_file_cap ) {
                @unlink( $abs );
                $skipped++;
                continue;
            }

            // Stop before the cumulative real-byte budget is exceeded.
            if ( ( $real_total + $fsize ) > $max_bytes ) {
                @unlink( $abs );
                $truncated = true;
                break;
            }

            $base = wp_basename( $abs );
            if ( ! $this->isSafeZipImageEntry( $base ) ) {
                @unlink( $abs );
                $skipped++;
                continue;
            }

            $id = media_handle_sideload( array( 'name' => $base, 'tmp_name' => $abs ), 0 );
            if ( is_wp_error( $id ) || ! $id ) {
                @unlink( $abs );
                $skipped++;
                continue;
            }

            $real_total   += $fsize;
            $full          = wp_get_attachment_url( $id );
            $thumb         = wp_get_attachment_image_url( $id, 'thumbnail' );
            $attachments[] = array(
                'id'    => (int) $id,
                'url'   => $full ? $full : '',
                'thumb' => $thumb ? $thumb : ( $full ? $full : '' ),
            );
        }

        $this->deleteUploadsTempDir( $tmpdir );

        wp_send_json_success( array(
            'attachments' => $attachments,
            'skipped'     => $skipped,
            'truncated'   => $truncated,
        ) );
    }

    /**
     * AJAX: return every caption source's raw value + editability for one image.
     *
     * @since 2.36.0
     * @return void
     */
    public function ajaxGetCaptionFields() {
        if ( ! check_ajax_referer( 'ml_caption', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ml-slider-lightbox' ) ), 403 );
        }

        $image_id   = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        $gallery_id = isset( $_POST['gallery_id'] ) ? absint( $_POST['gallery_id'] ) : 0;

        if ( ! $image_id || 'attachment' !== get_post_type( $image_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Image not found.', 'ml-slider-lightbox' ) ), 400 );
        }

        $fields = array();
        foreach ( array_keys( $this->captionSources() ) as $source ) {
            list( $editable, $reason ) = $this->captionFieldEditable( $image_id, $source );
            $fields[ $source ] = array(
                'value'    => $this->captionFieldRaw( $image_id, $source, $gallery_id ),
                'editable' => $editable,
                'reason'   => $reason,
            );
        }

        wp_send_json_success( array( 'fields' => $fields ) );
    }

    /**
     * AJAX: write one caption source's field. Media sources hit the attachment
     * (post_excerpt / post_content); manual hits the gallery's caption meta.
     *
     * @since 2.36.0
     * @return void
     */
    public function ajaxSaveCaptionField() {
        if ( ! check_ajax_referer( 'ml_caption', '_wpnonce', false ) || ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'ml-slider-lightbox' ) ), 403 );
        }

        $image_id   = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
        $gallery_id = isset( $_POST['gallery_id'] ) ? absint( $_POST['gallery_id'] ) : 0;
        $source     = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
        $content    = isset( $_POST['content'] )
            ? wp_kses_post( wp_unslash( (string) $_POST['content'] ) )
            : '';

        if ( ! $image_id || 'attachment' !== get_post_type( $image_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Image not found.', 'ml-slider-lightbox' ) ), 400 );
        }

        list( $editable, $reason ) = $this->captionFieldEditable( $image_id, $source );
        if ( ! $editable ) {
            $msg = 'perm' === $reason
                ? __( 'You do not have permission to edit this image.', 'ml-slider-lightbox' )
                : __( 'This caption source is read-only.', 'ml-slider-lightbox' );
            wp_send_json_error( array( 'message' => $msg ), 403 );
        }

        $deferred = false;

        switch ( $source ) {
            case 'media_caption':
                wp_update_post( array( 'ID' => $image_id, 'post_excerpt' => $content ) );
                break;

            case 'media_description':
                wp_update_post( array( 'ID' => $image_id, 'post_content' => $content ) );
                break;

            case 'manual':
                if ( ! $gallery_id ) {
                    // Unsaved gallery: the hidden input + gallery Save will persist it.
                    $deferred = true;
                    break;
                }
                $captions = get_post_meta( $gallery_id, '_ml_gallery_captions', true );
                if ( ! is_array( $captions ) ) {
                    $captions = array();
                }
                $captions[ $image_id ] = $content;
                update_post_meta( $gallery_id, '_ml_gallery_captions', $captions );
                break;

            default:
                wp_send_json_error( array( 'message' => __( 'Unknown caption source.', 'ml-slider-lightbox' ) ), 400 );
        }

        wp_send_json_success( array( 'value' => $content, 'deferred' => $deferred ) );
    }
}
