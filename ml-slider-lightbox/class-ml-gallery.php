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
        add_shortcode( 'ml_gallery',                           array( $this, 'galleryShortcode' ) );
        add_action( 'wp',                                      array( $this, 'detectPageGalleries' ) );
        add_action( 'admin_bar_menu',                          array( $this, 'registerAdminBar' ), 100 );
        add_action( 'wp_enqueue_scripts',                      array( $this, 'enqueueFrontendAssets' ) );
        add_action( 'wp_footer',                               array( $this, 'printInlineCss' ) );
        add_filter( 'metaslider_lightbox_load_assets',         array( $this, 'forceLoadAssetsForBlock' ), 10, 1 );
        add_filter( 'manage_ml_gallery_posts_columns',         array( $this, 'addListColumns' ) );
        add_action( 'manage_ml_gallery_posts_custom_column',   array( $this, 'renderListColumn' ), 10, 2 );
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

    private function allowedModes() {
        return array(
            'lg-fade'        => __( 'Fade', 'ml-slider-lightbox' ),
            'lg-slide'       => __( 'Slide', 'ml-slider-lightbox' ),
            'lg-zoom-in-out' => __( 'Zoom', 'ml-slider-lightbox' ),
        );
    }

    private function defaultSettings() {
        return array(
            'mode'        => 'lg-fade',
            'controls'    => 1,
            'counter'     => 1,
            'thumbnails'  => 1,
            'download'    => 0,
            'captions'    => 1,
            'loop'        => 1,
            'swipe_close' => 1,
            'mousewheel'  => 1,
            'keyboard'    => 1,
            'layout'           => 'grid',
            'columns'          => 3,
            'columns_mobile'   => 1,
            'gap'              => 8,
            'lightbox_size'    => 'full',
            'open_in_lightbox' => 1,
            // Pro-only settings (default off; rendered as locked for free users)
            'zoom'        => 0,
            'fullscreen'  => 0,
            'rotate'      => 0,
            'autoplay'    => 0,
            'share'       => 0,
        );
    }

    private function allowedLayouts() {
        return array( 'grid', 'masonry', 'justified', 'carousel', 'showcase' );
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
        );
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
                'methods'             => \WP_REST_Server::READABLE,
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

        self::$queued_css = array();
        $content          = $this->galleryShortcode( array( 'id' => $id ) );
        $inline_css       = implode( '', self::$queued_css );

        // Detect layout and features from rendered HTML — avoids a second get_post_meta call.
        $is_carousel = strpos( $content, 'data-ml-layout="carousel"' ) !== false;

        $plugin_url = plugin_dir_url( __FILE__ );

        $html  = '<!DOCTYPE html><html><head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<style>*,*::before,*::after{box-sizing:border-box}body{margin:0;padding:8px;overflow-x:hidden}</style>';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/lightgallery.min.css' ) . '">';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/lg-transitions.min.css' ) . '">';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/ml-lightbox-public.css' ) . '">';
        $html .= '<link rel="stylesheet" href="' . esc_url( $plugin_url . 'assets/css/ml-gallery-public.css' ) . '">';
        if ( $this->is_pro ) {
            $html .= '<link rel="stylesheet" href="' . esc_url( plugins_url( 'ml-slider-lightbox-pro/assets/css/public.css' ) ) . '">';
        }
        if ( $inline_css ) {
            $html .= '<style>' . $inline_css . '</style>';
        }
        if ( ! $is_carousel ) {
            $html .= '<style>.ml-gallery-lightgallery a{pointer-events:none;cursor:default}</style>';
        }
        $html .= '</head><body>';
        $html .= $content;
        $html .= '<script src="' . esc_url( includes_url( 'js/jquery/jquery.min.js' ) ) . '"></script>';
        $html .= '<script src="' . esc_url( $plugin_url . 'assets/js/lightgallery.min.js' ) . '"></script>';
        if ( $is_carousel ) {
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

            $settings_array = apply_filters( 'ml_gallery_preview_settings', array(
                'enable_galleries'   => true,
                'enable_on_content'  => false,
                'page_excluded'      => false,
                'license_key'        => '',
                'view_image_label'   => __( 'View image', 'ml-slider-lightbox' ),
                'metaslider_options' => $metaslider_options,
            ), $id );

            $html .= '<script>window.mlLightboxSettings=' . wp_json_encode( $settings_array ) . ';</script>';
            $html .= '<script src="' . esc_url( $plugin_url . 'assets/js/ml-lightgallery-init.js' ) . '"></script>';

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
                               class="ml-gallery-toolbar-btn">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                                </svg>
                                <span><?php esc_html_e( 'Galleries', 'ml-slider-lightbox' ); ?></span>
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

                        <div id="ml-gallery-preview"
                             class="<?php echo empty( $image_ids ) ? 'is-empty' : ''; ?>"
                             data-empty-label="<?php esc_attr_e( 'No images added yet', 'ml-slider-lightbox' ); ?>">
                            <?php foreach ( $image_ids as $image_id ) : ?>
                                <?php $img = wp_get_attachment_image( $image_id, 'thumbnail' ); ?>
                                <?php if ( $img ) : ?>
                                    <?php $item_caption = $captions[ $image_id ] ?? ''; ?>
                                    <div class="ml-gallery-item<?php echo $item_caption ? ' has-caption' : ''; ?>"
                                         data-id="<?php echo esc_attr( $image_id ); ?>">
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
                                                class="ml-gallery-edit-caption"
                                                aria-label="<?php esc_attr_e( 'Edit caption', 'ml-slider-lightbox' ); ?>">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                        </button>
                                        <input type="hidden"
                                               class="ml-gallery-caption-input"
                                               name="ml_gallery_captions[<?php echo esc_attr( $image_id ); ?>]"
                                               value="<?php echo esc_attr( $item_caption ); ?>">
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>

                        <button type="button" id="ml-gallery-add-images" class="button button-primary ml-gallery-add-images-btn">
                            <?php esc_html_e( 'Add Images', 'ml-slider-lightbox' ); ?>
                        </button>
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
                            <div class="ml-gallery-setting ml-gallery-columns-row<?php echo $hide_columns ? ' is-hidden' : ''; ?>">
                                <label for="ml_gallery_columns" class="ml-tipsy" title="<?php esc_attr_e( 'Number of columns to show on desktop screens', 'ml-slider-lightbox' ); ?>"><?php esc_html_e( 'Columns', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_columns" name="ml_gallery_settings[columns]">
                                    <?php foreach ( range( 2, 6 ) as $n ) : ?>
                                        <option value="<?php echo esc_attr( $n ); ?>" <?php selected( $lg_settings['columns'], $n ); ?>><?php echo esc_html( $n ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting ml-gallery-mobile-columns-row<?php echo $hide_columns ? ' is-hidden' : ''; ?>">
                                <label for="ml_gallery_columns_mobile" class="ml-tipsy" title="<?php esc_attr_e( 'Number of columns to show on phones and small screens', 'ml-slider-lightbox' ); ?>"><?php esc_html_e( 'Columns (mobile)', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_columns_mobile" name="ml_gallery_settings[columns_mobile]">
                                    <?php foreach ( range( 1, 6 ) as $n ) : ?>
                                        <option value="<?php echo esc_attr( $n ); ?>" <?php selected( (int) ( $lg_settings['columns_mobile'] ?? 1 ), $n ); ?>><?php echo esc_html( $n ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col ml-gallery-gap-row<?php echo in_array( $lg_settings['layout'], array( 'carousel', 'showcase' ), true ) ? ' is-hidden' : ''; ?>">
                                <label for="ml_gallery_gap" class="ml-tipsy" title="<?php esc_attr_e( 'Space between images', 'ml-slider-lightbox' ); ?>">
                                    <?php esc_html_e( 'Gap', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo absint( $lg_settings['gap'] ); ?>px</span>
                                </label>
                                <input type="range"
                                       id="ml_gallery_gap"
                                       name="ml_gallery_settings[gap]"
                                       min="0" max="32" step="2"
                                       value="<?php echo absint( $lg_settings['gap'] ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_lightbox_size" class="ml-tipsy" title="<?php esc_attr_e( 'The size of the image shown when a visitor clicks to view it in full', 'ml-slider-lightbox' ); ?>"><?php esc_html_e( 'Image Size', 'ml-slider-lightbox' ); ?></label>
                                <select id="ml_gallery_lightbox_size" name="ml_gallery_settings[lightbox_size]">
                                    <?php foreach ( $size_options as $val => $label ) : ?>
                                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $saved_lb_size, $val ); ?>><?php echo esc_html( $label ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                        </div>

                        <div class="ml-gallery-sidebar-panel">
                            <h3><?php esc_html_e( 'Gallery Settings', 'ml-slider-lightbox' ); ?></h3>

                            <?php
                            $pro_tooltips = array(
                                'zoom'       => __( 'Zoom controls require MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'fullscreen' => __( 'Fullscreen mode requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'rotate'     => __( 'Image rotation requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'autoplay'   => __( 'Autoplay requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'share'      => __( 'Social sharing requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'pager'      => __( 'Pager requires MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                                'hash'       => __( 'Unique Image URLs require MetaSlider Gallery Pro', 'ml-slider-lightbox' ),
                            );

                            $descriptions = array(
                                'controls'    => __( 'Show left and right navigation arrows', 'ml-slider-lightbox' ),
                                'counter'     => __( 'Display the current image number and total count', 'ml-slider-lightbox' ),
                                'thumbnails'  => __( 'Show a strip of thumbnail images at the bottom of the gallery', 'ml-slider-lightbox' ),
                                'captions'    => __( 'Display image captions in the gallery', 'ml-slider-lightbox' ),
                                'pager'       => __( 'Show dot navigation area below each image.', 'ml-slider-lightbox' ),
                                'zoom'        => __( 'Pinch or scroll to zoom in and out of gallery images. The Zoom control will appear only if the original size is larger than the gallery size.', 'ml-slider-lightbox' ),
                                'fullscreen'  => __( 'Expand the gallery window to fill the whole screen.', 'ml-slider-lightbox' ),
                                'rotate'      => __( 'Users can rotate images left or right, plus flip them vertically or horizontally.', 'ml-slider-lightbox' ),
                                'autoplay'    => __( 'Automatically advance through the images without the user clicking.', 'ml-slider-lightbox' ),
                                'share'       => __( 'Enable users to share images on Facebook, X, or Pinterest.', 'ml-slider-lightbox' ),
                                'hash'        => __( 'Create a unique URL for each image to enable direct linking inside a gallery.', 'ml-slider-lightbox' ),
                                'keyboard'    => __( 'Navigate images using left and right arrow keys', 'ml-slider-lightbox' ),
                                'mousewheel'  => __( 'Scroll through images using the mouse wheel', 'ml-slider-lightbox' ),
                                'swipe_close' => __( 'Swipe up or down to close the gallery on touch devices', 'ml-slider-lightbox' ),
                                'loop'             => __( 'Cycle back to the first image after reaching the last', 'ml-slider-lightbox' ),
                                'download'         => __( 'Show a download button for each image', 'ml-slider-lightbox' ),
                                'open_in_lightbox' => __( 'Open images in a window overlay when clicked. Applies to Grid, Masonry, and Justified layouts.', 'ml-slider-lightbox' ),
                            );

                            $render_toggle = function( $key, $label, $extra_class = '' ) use ( $lg_settings, $pro_tooltips, $descriptions ) {
                                $is_pro_key = isset( $pro_tooltips[ $key ] );
                                $locked     = $is_pro_key && ! $this->is_pro;
                                $title      = isset( $descriptions[ $key ] ) ? $descriptions[ $key ] : '';
                                $class_attr = $extra_class ? ' ' . esc_attr( $extra_class ) : '';
                                if ( $locked ) : ?>
                                    <div class="ml-gallery-setting ml-gallery-setting--pro-locked<?php echo $class_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
                                        <label<?php if ( $title ) : ?> class="ml-tipsy" title="<?php echo esc_attr( $title ); ?>"<?php endif; ?>><?php echo esc_html( $label ); ?></label>
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
                                        <label for="ml_gallery_<?php echo esc_attr( $key ); ?>"<?php if ( $title ) : ?> class="ml-tipsy" title="<?php echo esc_attr( $title ); ?>"<?php endif; ?>><?php echo esc_html( $label ); ?></label>
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
                            ?>

                            <p class="ml-settings-section-label"><?php esc_html_e( 'Display', 'ml-slider-lightbox' ); ?></p>
                            <div class="ml-gallery-setting">
                                <label for="ml_gallery_mode" class="ml-tipsy" title="<?php esc_attr_e( 'Animation effect when moving between images', 'ml-slider-lightbox' ); ?>"><?php esc_html_e( 'Transition', 'ml-slider-lightbox' ); ?></label>
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
                            <?php $render_toggle( 'captions',   __( 'Captions',      'ml-slider-lightbox' ) ); ?>
                            <?php if ( ! $this->is_pro ) : ?>
                                <?php $render_toggle( 'pager', __( 'Pager', 'ml-slider-lightbox' ) ); ?>
                            <?php else : ?>
                                <?php do_action( 'ml_gallery_pro_display_fields', $gallery_id ); ?>
                            <?php endif; ?>

                            <p class="ml-settings-section-label"><?php esc_html_e( 'Toolbar Controls', 'ml-slider-lightbox' ); ?></p>
                            <?php if ( ! $this->is_pro ) : ?>
                                <?php $render_toggle( 'share',      __( 'Share',           'ml-slider-lightbox' ) ); ?>
                                <?php $render_toggle( 'autoplay',   __( 'Autoplay',         'ml-slider-lightbox' ) ); ?>
                                <?php $render_toggle( 'rotate',     __( 'Rotate and Flip',  'ml-slider-lightbox' ) ); ?>
                                <?php $render_toggle( 'fullscreen', __( 'Fullscreen',        'ml-slider-lightbox' ) ); ?>
                                <?php $render_toggle( 'zoom',       __( 'Zoom',              'ml-slider-lightbox' ) ); ?>
                            <?php else : ?>
                                <?php do_action( 'ml_gallery_pro_toolbar_fields', $gallery_id ); ?>
                            <?php endif; ?>
                            <?php $render_toggle( 'download', __( 'Download', 'ml-slider-lightbox' ) ); ?>

                            <p class="ml-settings-section-label"><?php esc_html_e( 'Advanced Features', 'ml-slider-lightbox' ); ?></p>
                            <?php $render_toggle( 'open_in_lightbox', __( 'Show in Gallery Window', 'ml-slider-lightbox' ), 'ml-show-in-modal-row' ); ?>
                            <?php $render_toggle( 'loop', __( 'Loop', 'ml-slider-lightbox' ) ); ?>
                            <?php if ( ! $this->is_pro ) : ?>
                                <?php $render_toggle( 'hash', __( 'Unique Image URLs', 'ml-slider-lightbox' ) ); ?>
                            <?php else : ?>
                                <?php do_action( 'ml_gallery_pro_advanced_fields', $gallery_id ); ?>
                            <?php endif; ?>
                            <?php $render_toggle( 'keyboard',    __( 'Keyboard',       'ml-slider-lightbox' ) ); ?>
                            <?php $render_toggle( 'mousewheel',  __( 'Mouse wheel',    'ml-slider-lightbox' ) ); ?>
                            <?php $render_toggle( 'swipe_close', __( 'Swipe to close', 'ml-slider-lightbox' ) ); ?>

                        </div>

                        <div class="ml-gallery-sidebar-panel">
                            <h3><?php esc_html_e( 'Appearance', 'ml-slider-lightbox' ); ?></h3>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_bg_color"><?php esc_html_e( 'Background Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_bg_color"
                                       name="ml_gallery_appearance[bg_color]"
                                       value="<?php echo esc_attr( $appearance['bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_bg_opacity">
                                    <?php esc_html_e( 'Background Opacity', 'ml-slider-lightbox' ); ?>
                                    <span class="ml-gallery-range-value"><?php echo esc_html( (string) min( 1.0, max( 0.0, (float) $appearance['bg_opacity'] ) ) ); ?></span>
                                </label>
                                <input type="range"
                                       id="ml_gallery_bg_opacity"
                                       name="ml_gallery_appearance[bg_opacity]"
                                       min="0" max="1" step="0.05"
                                       value="<?php echo esc_attr( (string) min( 1.0, max( 0.0, (float) $appearance['bg_opacity'] ) ) ); ?>"
                                       class="ml-gallery-range widefat">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_arrow_color"><?php esc_html_e( 'Arrow Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_arrow_color"
                                       name="ml_gallery_appearance[arrow_color]"
                                       value="<?php echo esc_attr( $appearance['arrow_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_arrow_bg_color"><?php esc_html_e( 'Arrow Background', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_arrow_bg_color"
                                       name="ml_gallery_appearance[arrow_bg_color]"
                                       value="<?php echo esc_attr( $appearance['arrow_bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_close_color"><?php esc_html_e( 'Close Icon Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_close_color"
                                       name="ml_gallery_appearance[close_color]"
                                       value="<?php echo esc_attr( $appearance['close_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_close_bg_color"><?php esc_html_e( 'Close Background', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_close_bg_color"
                                       name="ml_gallery_appearance[close_bg_color]"
                                       value="<?php echo esc_attr( $appearance['close_bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_toolbar_color"><?php esc_html_e( 'Toolbar Icon Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_toolbar_color"
                                       name="ml_gallery_appearance[toolbar_color]"
                                       value="<?php echo esc_attr( $appearance['toolbar_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_toolbar_bg_color"><?php esc_html_e( 'Toolbar Background', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_toolbar_bg_color"
                                       name="ml_gallery_appearance[toolbar_bg_color]"
                                       value="<?php echo esc_attr( $appearance['toolbar_bg_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#000000">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_thumbnail_border_color"><?php esc_html_e( 'Thumbnail Border Color', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_thumbnail_border_color"
                                       name="ml_gallery_appearance[thumbnail_border_color]"
                                       value="<?php echo esc_attr( $appearance['thumbnail_border_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#ffffff">
                            </div>

                            <div class="ml-gallery-setting ml-gallery-setting--col">
                                <label for="ml_gallery_thumbnail_border_hover_color"><?php esc_html_e( 'Thumbnail Border Active and Hover', 'ml-slider-lightbox' ); ?></label>
                                <input type="text"
                                       id="ml_gallery_thumbnail_border_hover_color"
                                       name="ml_gallery_appearance[thumbnail_border_hover_color]"
                                       value="<?php echo esc_attr( $appearance['thumbnail_border_hover_color'] ); ?>"
                                       class="ml-gallery-color-picker"
                                       data-default-color="#dd6923">
                            </div>

                            <?php do_action( 'ml_gallery_pro_appearance_fields', $gallery_id ); ?>

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
                            <button type="button" id="ml-caption-close" aria-label="<?php esc_attr_e( 'Close', 'ml-slider-lightbox' ); ?>">&times;</button>
                        </div>
                        <div id="ml-caption-modal-body">
                            <div id="ml-caption-preview-wrap">
                                <img id="ml-caption-preview-img" src="" alt="">
                            </div>
                            <div id="ml-caption-editor-wrap">
                                <label for="ml-gallery-caption-editor" class="screen-reader-text">
                                    <?php esc_html_e( 'Caption', 'ml-slider-lightbox' ); ?>
                                </label>
                                <textarea id="ml-gallery-caption-editor"></textarea>
                            </div>
                        </div>
                        <div id="ml-caption-modal-footer">
                            <button type="button" id="ml-caption-cancel" class="button">
                                <?php esc_html_e( 'Cancel', 'ml-slider-lightbox' ); ?>
                            </button>
                            <button type="button" id="ml-caption-save" class="button button-primary">
                                <?php esc_html_e( 'Save Caption', 'ml-slider-lightbox' ); ?>
                            </button>
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
            $bg_opacity = isset( $app_raw['bg_opacity'] ) ? min( 1.0, max( 0.0, (float) $app_raw['bg_opacity'] ) ) : 0.9;
            update_post_meta( $gallery_id, '_ml_gallery_appearance', array(
                'bg_color'               => sanitize_hex_color( $app_raw['bg_color'] ?? '' ) ?: '#000000',
                'bg_opacity'             => (string) $bg_opacity,
                'arrow_color'            => sanitize_hex_color( $app_raw['arrow_color'] ?? '' ) ?: '#ffffff',
                'arrow_bg_color'         => sanitize_hex_color( $app_raw['arrow_bg_color'] ?? '' ) ?: '#000000',
                'close_color'            => sanitize_hex_color( $app_raw['close_color'] ?? '' ) ?: '#ffffff',
                'close_bg_color'         => sanitize_hex_color( $app_raw['close_bg_color'] ?? '' ) ?: '#000000',
                'toolbar_color'          => sanitize_hex_color( $app_raw['toolbar_color'] ?? '' ) ?: '#ffffff',
                'toolbar_bg_color'       => sanitize_hex_color( $app_raw['toolbar_bg_color'] ?? '' ) ?: '#000000',
                'thumbnail_border_color'       => sanitize_hex_color( $app_raw['thumbnail_border_color'] ?? '' ) ?: '#ffffff',
                'thumbnail_border_hover_color' => sanitize_hex_color( $app_raw['thumbnail_border_hover_color'] ?? '' ) ?: '#dd6923',
            ) );

            $raw_layout = isset( $settings_raw['layout'] ) ? sanitize_key( $settings_raw['layout'] ) : 'grid';
            update_post_meta( $gallery_id, '_ml_gallery_settings', array(
                'mode'             => in_array( $raw_mode, array_keys( $this->allowedModes() ), true ) ? $raw_mode : 'lg-fade',
                'controls'         => ! empty( $settings_raw['controls'] ) ? 1 : 0,
                'counter'          => ! empty( $settings_raw['counter'] ) ? 1 : 0,
                'thumbnails'       => ! empty( $settings_raw['thumbnails'] ) ? 1 : 0,
                'download'         => ! empty( $settings_raw['download'] ) ? 1 : 0,
                'captions'         => ! empty( $settings_raw['captions'] ) ? 1 : 0,
                'loop'             => ! empty( $settings_raw['loop'] ) ? 1 : 0,
                'swipe_close'      => ! empty( $settings_raw['swipe_close'] ) ? 1 : 0,
                'mousewheel'       => ! empty( $settings_raw['mousewheel'] ) ? 1 : 0,
                'keyboard'         => ! empty( $settings_raw['keyboard'] ) ? 1 : 0,
                'layout'           => in_array( $raw_layout, $this->allowedLayouts(), true ) ? $raw_layout : 'grid',
                'columns'          => min( 6, max( 2, (int) ( $settings_raw['columns'] ?? 3 ) ) ),
                'columns_mobile'   => min( 6, max( 1, (int) ( $settings_raw['columns_mobile'] ?? 1 ) ) ),
                'gap'              => min( 32, max( 0, (int) ( $settings_raw['gap'] ?? 8 ) ) ),
                'lightbox_size'    => $this->sanitizeImageSize( $settings_raw['lightbox_size'] ?? 'full' ),
                'open_in_lightbox' => in_array( $raw_layout, array( 'carousel', 'showcase' ), true ) ? 1 : ( ! empty( $settings_raw['open_in_lightbox'] ) ? 1 : 0 ),
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
                $this->version,
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

        wp_enqueue_script(
            'ml-gallery-admin',
            plugin_dir_url( __FILE__ ) . 'assets/js/ml-gallery-admin.js',
            array( 'jquery', 'jquery-ui-sortable', 'media-upload', 'wp-color-picker', 'jquery-tipsy' ),
            $this->version,
            true
        );

        wp_localize_script(
            'ml-gallery-admin',
            'mlGalleryAdmin',
            array(
                'selectTitle'      => __( 'Select Gallery Images', 'ml-slider-lightbox' ),
                'selectButton'     => __( 'Add to Gallery', 'ml-slider-lightbox' ),
                'removeLabel'      => __( 'Remove image', 'ml-slider-lightbox' ),
                'editCaptionLabel' => __( 'Edit caption', 'ml-slider-lightbox' ),
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

        $saved_app  = get_post_meta( $gallery_id, '_ml_gallery_appearance', true );
        $appearance = wp_parse_args( is_array( $saved_app ) ? $saved_app : array(), $this->defaultAppearance() );

        $bg_color      = esc_html( sanitize_hex_color( $appearance['bg_color'] )         ?: '#000000' );
        $bg_opacity    = esc_html( (string) min( 1.0, max( 0.0, (float) $appearance['bg_opacity'] ) ) );
        $arrow_color   = esc_html( sanitize_hex_color( $appearance['arrow_color'] )      ?: '#ffffff' );
        $arrow_bg      = esc_html( sanitize_hex_color( $appearance['arrow_bg_color'] )   ?: '#000000' );
        $close_color   = esc_html( sanitize_hex_color( $appearance['close_color'] )      ?: '#ffffff' );
        $close_bg      = esc_html( sanitize_hex_color( $appearance['close_bg_color'] )   ?: '#000000' );
        $toolbar_color    = esc_html( sanitize_hex_color( $appearance['toolbar_color'] )    ?: '#ffffff' );
        $toolbar_bg       = esc_html( sanitize_hex_color( $appearance['toolbar_bg_color'] ) ?: '#000000' );
        $thumbnail_border       = esc_html( sanitize_hex_color( $appearance['thumbnail_border_color'] )       ?: '#ffffff' );
        $thumbnail_border_hover = esc_html( sanitize_hex_color( $appearance['thumbnail_border_hover_color'] ) ?: '#dd6923' );

        $lg_class = 'ml-gallery-' . $gallery_id;

        $inline_css = "
            #ml-gallery-{$gallery_id} {
                --ml-arrow-color: {$arrow_color};
                --ml-arrow-bg:    {$arrow_bg};
                --ml-toolbar-color: {$toolbar_color};
                --ml-toolbar-bg:    {$toolbar_bg};
            }
            .lg-container.{$lg_class} {
                --ml-lightbox-arrow-color: {$arrow_color} !important;
                --ml-lightbox-close-icon-color: {$close_color} !important;
                --ml-lightbox-toolbar-icon-color: {$toolbar_color} !important;
                --ml-lightbox-thumbnail-border-color: {$thumbnail_border} !important;
                --ml-lightbox-thumbnail-border-hover-color: {$thumbnail_border_hover} !important;
            }
            .lg-container.{$lg_class} .lg-backdrop,
            .lg-container.{$lg_class} .lg-thumb-outer {
                background-color: {$bg_color} !important;
                opacity: {$bg_opacity} !important;
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
        ";

        self::$queued_css[ $gallery_id ] = $inline_css;

        $layout  = in_array( $lg['layout'], $this->allowedLayouts(), true ) ? $lg['layout'] : 'grid';
        $columns = min( 6, max( 2, (int) $lg['columns'] ) );
        $gap     = min( 32, max( 0, (int) $lg['gap'] ) );

        $extra_attrs = (array) apply_filters( 'ml_gallery_data_attributes', array(), $gallery_id );

        ob_start();
        ?>
        <div id="ml-gallery-<?php echo esc_attr( $gallery_id ); ?>"
             class="ml-gallery-container ml-layout-<?php echo esc_attr( $layout ); ?>"
             style="--ml-columns:<?php echo esc_attr( $columns ); ?>;--ml-columns-mobile:<?php echo absint( $lg['columns_mobile'] ?? 1 ); ?>;--ml-gap:<?php echo esc_attr( $gap ); ?>px"
             data-ml-gallery="true"
             data-ml-lightbox="<?php echo $lg['open_in_lightbox'] ? '1' : '0'; ?>"
             data-ml-layout="<?php echo esc_attr( $layout ); ?>"
             data-lg-class="<?php echo esc_attr( $lg_class ); ?>"
             data-lg-mode="<?php echo esc_attr( $lg['mode'] ); ?>"
             data-lg-controls="<?php echo $lg['controls'] ? '1' : '0'; ?>"
             data-lg-counter="<?php echo $lg['counter'] ? '1' : '0'; ?>"
             data-lg-thumbnails="<?php echo $lg['thumbnails'] ? '1' : '0'; ?>"
             data-lg-download="<?php echo $lg['download'] ? '1' : '0'; ?>"
             data-lg-captions="<?php echo $lg['captions'] ? '1' : '0'; ?>"
             data-lg-loop="<?php echo $lg['loop'] ? '1' : '0'; ?>"
             data-lg-swipe-close="<?php echo $lg['swipe_close'] ? '1' : '0'; ?>"
             data-lg-mousewheel="<?php echo $lg['mousewheel'] ? '1' : '0'; ?>"
             data-lg-keyboard="<?php echo $lg['keyboard'] ? '1' : '0'; ?>"
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
                $caption = isset( $gallery_captions[ $image_id ] ) && '' !== $gallery_captions[ $image_id ]
                    ? $gallery_captions[ $image_id ]
                    : wp_get_attachment_caption( $image_id );

                if ( ! $full_url ) {
                    continue;
                }
                ?>
                <a href="<?php echo esc_url( $full_url ); ?>"
                   data-src="<?php echo esc_url( $full_url ); ?>"
                   data-thumb="<?php echo esc_url( $thumb_url ? $thumb_url : $full_url ); ?>"
                   <?php if ( $caption ) : ?>
                   data-sub-html="<?php echo esc_html( $caption ); ?>"
                   <?php endif; ?>
                   <?php // esc_html() not esc_attr(): data-sub-html is rendered as HTML by lightGallery.
                         // esc_attr() would double-encode entities (& → &amp;amp;), producing visible artefacts. ?>>
                    <?php echo wp_get_attachment_image( $image_id, $lightbox_size, false, array( 'alt' => $alt ) ); ?>
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
            $this->version
        );

        wp_enqueue_script(
            'ml-gallery-layout',
            plugin_dir_url( __FILE__ ) . 'assets/js/ml-gallery-layout.js',
            array( 'ml-lightgallery-clean' ),
            $this->version,
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
}
