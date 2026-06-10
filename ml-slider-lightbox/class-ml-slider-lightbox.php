<?php

namespace MetaSlider\Lightbox;

if (! defined('ABSPATH')) {
    die('No direct access.');
}

if (!defined('ML_LIGHTGALLERY_LICENSE_KEY')) {
    define('ML_LIGHTGALLERY_LICENSE_KEY', 'E8BD65E9-797F-4DB9-B91D-7D1ECDCA7252');
}

class MetaSliderLightboxPlugin
{
    public $version = '2.32.3';
    protected static $instance = null;
    private $supported_plugins = array();

    /** @var MetaSliderLightboxGallery */
    private $gallery;

    /**
     * Static caches for performance optimization
     *
     * @var array
     * @since 2.0.1
     */
    private static $cached_options = array();
    private static $cached_plugin_checks = array();
    private static $cached_metaslider_active = null;
    private static $cache_recursion_guard = false;
    private static $cached_lightbox_enabled = null;

    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function setup()
    {
        $this->supported_plugins = $this->getSupportedPlugins();
        $this->initializeDefaultOptions();
        $this->addSettings();
        $this->setupCoreFeatures();
        $this->setupMetasliderIntegration();
        $this->setupAdminMenu();
        require_once plugin_dir_path( __FILE__ ) . 'class-ml-gallery.php';
        $this->gallery = new MetaSliderLightboxGallery( $this->version, $this->isProPluginActive() );
    }

    public function __construct()
    {
        add_action('init', array($this, 'initializeWordPressLightboxOverride'));
        add_action('admin_init', array($this, 'migrateSettingsIfNeeded'));
        add_action('admin_init', array($this, 'migrateIconColorSettings'));
        add_action('admin_init', array($this, 'migrateBackgroundColorSettings'));
        add_action('admin_init', array($this, 'migrateEnableOnAllMetaSliderSlideshows'));
        add_action('admin_init', array($this, 'activationRedirect'));
        add_filter('body_class', array($this, 'addContentFilteringBodyClass'));
        add_action( 'init', array( $this, 'store_plugin_data' ) );
    }

    public function addContentFilteringBodyClass($classes)
    {
        if ($this->shouldExcludePage()) {
            $classes[] = 'ml-lightbox-excluded';
        } else {
            $classes[] = 'ml-lightbox-included';
        }
        return $classes;
    }

    public function activationRedirect()
    {
        if (!get_transient('metaslider_lightbox_activation_redirect')) {
            return;
        }

        delete_transient('metaslider_lightbox_activation_redirect');

        if (isset($_GET['activate-multi'])) {
            return;
        }

        if (isset($_GET['post_type']) && $_GET['post_type'] === 'ml_gallery') {
            return;
        }

        wp_safe_redirect(admin_url('edit.php?post_type=ml_gallery'));
        exit;
    }

    public function initializeWordPressLightboxOverride()
    {
        $options = $this->getCachedGeneralOptions();
        $override_enabled = isset($options['override_enlarge_on_click']) ? $options['override_enlarge_on_click'] : false;

        if ($override_enabled) {
            add_filter('wp_theme_json_data_theme', array($this, 'disableWordPressLightbox'));
            add_filter('wp_theme_json_data_user', array($this, 'disableWordPressLightbox'));

            add_action('after_setup_theme', array($this, 'removeWordPressLightboxSupport'), 20);

            add_action('wp_head', array($this, 'disableWordpressLightboxJs'), 5);
        }
    }

    public function disableWordPressLightbox($theme_json)
    {
        $new_data = $theme_json->get_data();
        $new_data['settings']['lightbox']['enabled'] = false;

        return $theme_json->update_with($new_data);
    }

    public function removeWordPressLightboxSupport()
    {
        remove_theme_support('lightbox');
    }

    public function migrateSettingsIfNeeded()
    {
        $migration_done = get_option('metaslider_lightbox_migration_done', false);
        if ($migration_done) {
            return;
        }

        $old_settings = get_option('metaslider_lightbox_general_options', array());
        if (empty($old_settings)) {
            update_option('metaslider_lightbox_migration_done', true);
            return;
        }

        $content_settings = array();
        $content_fields = ['enable_on_content', 'enable_on_widgets', 'enable_galleries', 'enable_featured_images', 'enable_videos'];
        foreach ($content_fields as $field) {
            if (isset($old_settings[$field])) {
                $content_settings[$field] = $old_settings[$field];
            }
        }

        $exclusion_fields = ['exclude_pages', 'exclude_posts', 'exclude_post_types', 'exclude_css_selectors'];
        foreach ($exclusion_fields as $field) {
            if (isset($old_settings[$field])) {
                $content_settings[$field] = $old_settings[$field];
            }
        }

        if (!empty($content_settings)) {
            update_option('metaslider_lightbox_content_options', $content_settings);
        }

        $manual_settings = array();
        if (isset($old_settings['override_enlarge_on_click'])) {
            $manual_settings['override_enlarge_on_click'] = $old_settings['override_enlarge_on_click'];
        }

        if (!empty($manual_settings)) {
            update_option('metaslider_lightbox_manual_options', $manual_settings);
        }

        $appearance_settings = array();

        if (!empty($appearance_settings)) {
            update_option('metaslider_lightbox_appearance_options', $appearance_settings);
        }

        update_option('metaslider_lightbox_migration_done', true);

    }

    /**
     * Migrate old icon_color settings to new granular arrow/close/toolbar color settings
     * Ensures backward compatibility for existing installations
     *
     * @since 2.11.2
     */
    public function migrateIconColorSettings()
    {
        $icon_migration_done = get_option('metaslider_lightbox_icon_color_migration_done', false);
        if ($icon_migration_done) {
            return;
        }

        $appearance_options = get_option('metaslider_lightbox_appearance_options', array());

        if (isset($appearance_options['icon_color']) || isset($appearance_options['icon_hover_color'])) {
            $icon_color = isset($appearance_options['icon_color']) ? $appearance_options['icon_color'] : '#ffffff';
            $icon_hover_color = isset($appearance_options['icon_hover_color']) ? $appearance_options['icon_hover_color'] : '#000000';

            $needs_update = false;

            if (!isset($appearance_options['arrow_color'])) {
                $appearance_options['arrow_color'] = $icon_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['arrow_hover_color'])) {
                $appearance_options['arrow_hover_color'] = $icon_hover_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['close_icon_color'])) {
                $appearance_options['close_icon_color'] = $icon_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['close_icon_hover_color'])) {
                $appearance_options['close_icon_hover_color'] = $icon_hover_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['toolbar_icon_color'])) {
                $appearance_options['toolbar_icon_color'] = $icon_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['toolbar_icon_hover_color'])) {
                $appearance_options['toolbar_icon_hover_color'] = $icon_hover_color;
                $needs_update = true;
            }

            if ($needs_update) {
                update_option('metaslider_lightbox_appearance_options', $appearance_options);
            }
        }

        update_option('metaslider_lightbox_icon_color_migration_done', true);
    }

    /**
     * Migrate background color settings from button_color to granular background colors
     * This allows separate background colors for arrows, close icon, and toolbar icons
     *
     * @since 2.11.2
     */
    public function migrateBackgroundColorSettings()
    {
        $background_migration_done = get_option('metaslider_lightbox_background_color_migration_done', false);
        if ($background_migration_done) {
            return;
        }

        $appearance_options = get_option('metaslider_lightbox_appearance_options', array());

        if (isset($appearance_options['button_color']) || isset($appearance_options['button_hover_color'])) {
            $button_color = isset($appearance_options['button_color']) ? $appearance_options['button_color'] : '#000000';
            $button_hover_color = isset($appearance_options['button_hover_color']) ? $appearance_options['button_hover_color'] : '#f0f0f0';

            $needs_update = false;

            if (!isset($appearance_options['arrow_background_color'])) {
                $appearance_options['arrow_background_color'] = $button_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['arrow_background_hover_color'])) {
                $appearance_options['arrow_background_hover_color'] = $button_hover_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['close_icon_background_color'])) {
                $appearance_options['close_icon_background_color'] = $button_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['close_icon_background_hover_color'])) {
                $appearance_options['close_icon_background_hover_color'] = $button_hover_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['toolbar_icon_background_color'])) {
                $appearance_options['toolbar_icon_background_color'] = $button_color;
                $needs_update = true;
            }

            if (!isset($appearance_options['toolbar_icon_background_hover_color'])) {
                $appearance_options['toolbar_icon_background_hover_color'] = $button_hover_color;
                $needs_update = true;
            }

            if ($needs_update) {
                update_option('metaslider_lightbox_appearance_options', $appearance_options);
            }
        }

        update_option('metaslider_lightbox_background_color_migration_done', true);
    }

    public function migrateEnableOnAllMetaSliderSlideshows()
    {
        $flag = 'metaslider_lightbox_enable_on_all_ms_slideshows_migration_done';
        if (get_option($flag, false)) {
            return;
        }

        $options = get_option('metaslider_lightbox_content_options', array());
        if (!isset($options['enable_on_all_metaslider_slideshows'])) {
            $options['enable_on_all_metaslider_slideshows'] = false;
            update_option('metaslider_lightbox_content_options', $options);
        }

        update_option($flag, true);
    }

    /**
     * Get cached general options to reduce DB queries
     *
     * @return array
     * @since 2.0.1
     */
    private function getCachedGeneralOptions()
    {
        if (self::$cache_recursion_guard) {
            return array();
        }

        if (!isset(self::$cached_options['general'])) {
            self::$cache_recursion_guard = true;

            $content_options = get_option('metaslider_lightbox_content_options', array());
            $manual_options = get_option('metaslider_lightbox_manual_options', array());
            $appearance_options = get_option('metaslider_lightbox_appearance_options', array());

            $default_options = array(
                'enable_on_content' => true,
                'enable_on_widgets' => false,
                'enable_galleries' => false,
                'enable_featured_images' => false,
                'enable_videos' => false,
                'override_enlarge_on_click' => true,
                'override_link_to_image_file' => true,
                'content_processing_mode' => 'include',
                'exclude_pages' => array(),
                'exclude_posts' => array(),
                'exclude_post_types' => array('post'),
                'exclude_css_selectors' => '',
                'enable_on_all_metaslider_slideshows' => false,
            );

            $merged_options = array_merge($default_options, $content_options, $manual_options, $appearance_options);

            self::$cached_options['general'] = $merged_options;

            self::$cache_recursion_guard = false;
        }
        return self::$cached_options['general'];
    }

    /**
     * Get cached MetaSlider options to reduce DB queries
     *
     * @return array
     * @since 2.0.1
     */
    private function getCachedMetaSliderOptions()
    {
        if (self::$cache_recursion_guard) {
            return get_option('ml_lightbox_options', array());
        }
        
        if (!isset(self::$cached_options['metaslider'])) {
            self::$cache_recursion_guard = true;
            self::$cached_options['metaslider'] = get_option('ml_lightbox_options', array());
            self::$cache_recursion_guard = false;
        }
        return self::$cached_options['metaslider'];
    }

    private function clearOptionsCache()
    {
        self::$cached_options = array();
        self::$cached_lightbox_enabled = null;
    }

    private function initializeDefaultOptions()
    {
        $general_options = get_option('metaslider_lightbox_general_options', false);
        if ($general_options === false) {
            $default_general_options = array(
                'background_color' => '#000000',
                'button_color' => '#000000',
                'button_text_color' => '#ffffff',
                'button_hover_color' => '#f0f0f0',
                'button_hover_text_color' => '#000000',
                'icon_color' => '#ffffff',
                'icon_hover_color' => '#000000',
                'icon_background_color' => '#000000',
                'icon_background_hover_color' => '#f0f0f0',
                'arrow_color' => '#ffffff',
                'arrow_hover_color' => '#000000',
                'close_icon_color' => '#ffffff',
                'close_icon_hover_color' => '#000000',
                'toolbar_icon_color' => '#ffffff',
                'toolbar_icon_hover_color' => '#000000',
                'arrow_background_color' => '#000000',
                'arrow_background_hover_color' => '#f0f0f0',
                'close_icon_background_color' => '#000000',
                'close_icon_background_hover_color' => '#f0f0f0',
                'toolbar_icon_background_color' => '#000000',
                'toolbar_icon_background_hover_color' => '#f0f0f0',
                'background_opacity' => '0.9',
                'enable_on_content' => true,
                'enable_on_widgets' => false,
                'enable_galleries' => false,
                'enable_featured_images' => false,
                'enable_videos' => false,
                'override_enlarge_on_click' => true,
                'override_link_to_image_file' => true,
                'content_processing_mode' => 'include',
                'exclude_pages' => array(),
                'exclude_posts' => array(),
                'exclude_post_types' => array('post'),
                'exclude_css_selectors' => '',
            );
            add_option('metaslider_lightbox_general_options', $default_general_options);
            self::$cached_options['general'] = $default_general_options;
        }

        $metaslider_options = get_option('ml_lightbox_options', false);
        if ($metaslider_options === false) {
            $default_metaslider_options = array(
                'show_arrows' => true,
                'show_thumbnails' => true,
                'show_lightbox_button' => false,
                'use_icon_instead_of_button' => false,
                'show_captions' => true,
            );
            add_option('ml_lightbox_options', $default_metaslider_options);
            self::$cached_options['metaslider'] = $default_metaslider_options;
        }
    }

    public function setupCoreFeatures()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueueFrontendAssets'));
        add_shortcode('ml_lightbox', array($this, 'lightboxShortcode'));
        $this->setupContentDetection();

        $this->setupEnlargeOnClickOverride();

        $this->setupWooCommerceIntegration();
    }

    public function setupMetasliderIntegration()
    {
        if (!$this->isMetasliderActive()) {
            return;
        }

        if (is_admin()) {
            add_filter('metaslider_lightbox_settings', array($this, 'addSettings'), 10, 2);
        }

        add_filter('metaslider_flex_slider_anchor_attributes', array($this, 'addLightboxAttributesToSlide'), 10, 3);
        add_filter('metaslider_nivo_slider_anchor_attributes', array($this, 'addLightboxAttributesToSlide'), 10, 3);
        add_filter('metaslider_responsive_slider_anchor_attributes', array($this, 'addLightboxAttributesToSlide'), 10, 3);
        add_filter('metaslider_coin_slider_anchor_attributes', array($this, 'addLightboxAttributesToSlide'), 10, 3);
        add_filter('metaslider_css_classes', array($this, 'addLightboxClassNamesToSlider'), 10, 3);

        $this->registerSlideTypeHandlers();
    }

    private function registerSlideTypeHandlers()
    {
        $slide_types = $this->getSupportedSlideTypes();

        foreach ($slide_types as $slide_type => $config) {
            foreach ($config['filters'] as $filter_name => $priority) {
                add_filter($filter_name, array($this, 'processSlideForLightbox'), $priority, 3);
            }
        }

    }

    /**
     * Get supported slide types and their configuration
     *
     * @return array
     */
    private function getSupportedSlideTypes()
    {
        return array(
            'external' => array(
                'name' => 'External Image Slide',
                'description' => 'External image slides without anchor tags',
                'filters' => array(
                    'metaslider_image_attributes' => 15,
                ),
                'handler' => 'handleExternalImageSlide',
                'has_anchor' => false,
            ),
            'vimeo' => array(
                'name' => 'Vimeo Video Slide',
                'description' => 'Vimeo video slides with lightbox button',
                'filters' => array(
                ),
                'handler' => 'handleVimeoVideoSlide',
                'has_anchor' => false,
            ),
            'youtube' => array(
                'name' => 'YouTube Video Slide',
                'description' => 'YouTube video slides with lightbox button',
                'filters' => array(
                ),
                'handler' => 'handleYoutubeVideoSlide',
                'has_anchor' => false,
            ),
            'external_video' => array(
                'name' => 'External Video Slide',
                'description' => 'External video slides with lightbox button',
                'filters' => array(
                ),
                'handler' => 'handleExternalVideoSlide',
                'has_anchor' => false,
            ),
            'custom_html' => array(
                'name' => 'Custom HTML Slide',
                'description' => 'Custom HTML slides with lightbox button',
                'filters' => array(
                ),
                'handler' => 'handleCustomHtmlSlide',
                'has_anchor' => false,
            ),
            'image_folder' => array(
                'name' => 'Image Folder Slide',
                'description' => 'Image folder slides with gallery lightbox',
                'filters' => array(
                ),
                'handler' => 'handleImageFolderSlide',
                'has_anchor' => false,
            ),
            'postfeed' => array(
                'name' => 'PostFeed Slide',
                'description' => 'PostFeed slides with lightbox support',
                'filters' => array(
                ),
                'handler' => 'handlePostfeedSlide',
                'has_anchor' => false,
            ),
            'layer' => array(
                'name' => 'Layer Slide',
                'description' => 'Layer slides with background image lightbox',
                'filters' => array(
                ),
                'handler' => 'handleLayerSlide',
                'has_anchor' => false,
            ),
        );
    }

    /**
     * Universal slide handler that delegates to specific slide type handlers
     *
     * @param array $attributes
     * @param array $slide
     * @param int $slider_id
     * @return array
     */
    public function processSlideForLightbox($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if (!$this->isLightboxEnabled($enabled)) {
            return $attributes;
        }

        $slide_type = $this->determineSlideType($slide, $attributes);
        $slide_types = $this->getSupportedSlideTypes();

        if (isset($slide_types[$slide_type]) && method_exists($this, $slide_types[$slide_type]['handler'])) {
            $handler_method = $slide_types[$slide_type]['handler'];
            return $this->$handler_method($attributes, $slide, $slider_id);
        }

        return $this->handleGenericSlide($attributes, $slide, $slider_id);
    }

    /**
     * Determine the slide type from slide data
     *
     * @param array $slide
     * @param array $attributes
     * @return string
     */
    private function determineSlideType($slide, $attributes)
    {
        if (isset($slide['type'])) {
            return $slide['type'];
        }

        if (isset($attributes['class'])) {
            $class = $attributes['class'];
            if (strpos($class, 'ms-external') !== false) {
                return 'external';
            }
            if (strpos($class, 'ms-folder') !== false) {
                return 'folder';
            }
            if (strpos($class, 'ms-vimeo') !== false) {
                return 'vimeo';
            }
            if (strpos($class, 'ms-youtube') !== false) {
                return 'youtube';
            }
            if (strpos($class, 'ms-local-video') !== false) {
                return 'local-video';
            }
            if (strpos($class, 'ms-external-video') !== false) {
                return 'external-video';
            }
            if (strpos($class, 'ms-custom-html') !== false) {
                return 'custom-html';
            }
            if (strpos($class, 'ms-postfeed') !== false) {
                return 'postfeed';
            }
            if (strpos($class, 'ms-layer') !== false) {
                return 'layer';
            }
        }

        if (isset($slide['url']) && $this->isVideoUrl($slide['url'])) {
            return 'video';
        }

        if (isset($slide['layer_content'])) {
            return 'layer';
        }

        return 'image';
    }

    /**
     * Cached check if MetaSlider is active to reduce repeated plugin checks
     *
     * @return bool
     * @since 2.0.0
     */
    private function isMetasliderActive()
    {
        if (self::$cached_metaslider_active === null) {
            if (!function_exists('is_plugin_active')) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            self::$cached_metaslider_active = is_plugin_active('ml-slider/ml-slider.php') || is_plugin_active('ml-slider-pro/ml-slider-pro.php');
        }
        return self::$cached_metaslider_active;
    }

    /**
     * Returns a list of supported plugins
     *
     * @return array
     */
    public static function getSupportedPlugins()
    {
        $supported_plugins_list = array(
            'MetaSlider Gallery' => array(
                'location' => 'built-in',
                'settings_url' => 'admin.php?page=metaslider-lightbox',
                'built_in' => true,
                'attributes' => array(
                    'data-lg-size' => ':dimensions',
                    'data-sub-html' => ':caption',
                    'data-src' => ':url'
                )
            ),
            'ARI Fancy Lightbox' => array(
                'location' => 'ari-fancy-lightbox/ari-fancy-lightbox.php',
                'settings_url' => 'admin.php?page=ari-fancy-lightbox',
                'attributes' => array(
                    'class' => 'fb-link ari-fancybox',
                    'data-fancybox-group' => 'gallery',
                    'data-caption' => ':caption'
                )
            ),
            'Easy FancyBox' => array(
                'location' => 'easy-fancybox/easy-fancybox.php',
                'settings_url' => 'options-media.php',
                'rel' => 'lightbox',
            ),
            'FooBox Image Lightbox' => array(
                'location' => 'foobox-image-lightbox/foobox-free.php',
                'settings_url' => 'admin.php?page=foobox-settings',
                'body_class' => 'gallery'
            ),
            'FooBox HTML & Media Lightbox' => array(
                'location' => 'foobox-image-lightbox-premium/foobox-free.php',
                'settings_url' => 'options-general.php?page=foobox',
                'body_class' => 'gallery'
            ),
            'Fancy Lightbox' => array(
                'location' => 'fancy-lightbox/fancy-lightbox.php',
                'settings_url' => '',
                'rel' => 'lightbox'
            ),
            'Gallery Manager Lite' => array(
                'location' => 'fancy-gallery/plugin.php',
                'settings_url' => 'options-general.php?page=gallery-options'
            ),
            'Gallery Manager Pro' => array(
                'location' => 'gallery-manager-pro/plugin.php',
                'settings_url' => 'options-general.php?page=gallery-options'
            ),
            'imageLightbox' => array(
                'location' => 'imagelightbox/imagelightbox.php',
                'settings_url' => '',
                'rel' => 'lightbox',
                'attributes' => array(
                    'data-imagelightbox' => '$slider_id'
                )
            ),
            'jQuery Colorbox' => array(
                'location' => 'jquery-colorbox/jquery-colorbox.php',
                'settings_url' => 'options-general.php?page=jquery-colorbox/jquery-colorbox.php',
                'rel' => 'lightbox'
            ),
            'Lightbox Plus' => array(
                'location' => 'lightbox-plus/lightboxplus.php',
                'settings_url' => 'themes.php?page=lightboxplus',
                'rel' => 'lightbox'
            ),
            'Responsive Lightbox' => array(
                'location' => 'responsive-lightbox/responsive-lightbox.php',
                'settings_url' => 'options-general.php?page=responsive-lightbox',
                'rel' => 'lightbox'
            ),
            'Simple Lightbox' => array(
                'location' => 'simple-lightbox/main.php',
                'settings_url' => 'themes.php?page=slb_options',
                'rel' => 'lightbox',
                'attributes' => array(
                    'class' => 'slb'
                )
            ),
            'WP Colorbox' => array(
                'location' => 'wp-colorbox/main.php',
                'settings_url' => '',
                'rel' => 'lightbox',
                'attributes' => array(
                    'class' => 'wp-colorbox-image cboxElement'
                )
            ),
            'WP Featherlight' => array(
                'location' => 'wp-featherlight/wp-featherlight.php',
                'settings_url' => '',
                'rel' => 'lightbox',
                'body_class' => 'gallery'
            ),
            'wp-jquery-lightbox' => array(
                'location' => 'wp-jquery-lightbox/wp-jquery-lightbox.php',
                'settings_url' => 'options-general.php?page=jquery-lightbox-options',
                'rel' => 'lightbox'
            ),
            'WP Lightbox 2' => array(
                'location' => 'wp-lightbox-2/wp-lightbox-2.php',
                'settings_url' => 'admin.php?page=WP-Lightbox-2',
                'rel' => 'lightbox'
            ),
            'WP Lightbox 2 Pro' => array(
                'location' => 'wp-lightbox-2-pro/wp-lightbox-2-pro.php',
                'settings_url' => 'admin.php?page=WP-Lightbox-2',
                'rel' => 'lightbox'
            ),
            'WP Lightbox Ultimate' => array(
                'location' => 'wp-lightbox-ultimate/wp-lightbox.php',
                'settings_url' => ''
            ),
            'WP Video Lightbox' => array(
                'location' => 'wp-video-lightbox/wp-video-lightbox.php',
                'settings_url' => 'options-general.php?page=wp_video_lightbox',
                'rel' => 'wp-video-lightbox'
            ),
        );

        return apply_filters('metaslider_lightbox_supported_plugins', $supported_plugins_list);
    }

    /**
     * Add classes required by the plugin, or classes used to identify the active version.
     *
     * @param string $attributes HTML attributes
     * @param string $slide The slide
     * @param string $slider_id The slide ID
     *
     * @return string The attributes
     */
    public function addLightboxAttributesToSlide($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;
        $thumbnail_id = ('attachment' === get_post_type($slide['id'])) ? $slide['id'] : get_post_thumbnail_id(
            $slide['id']
        );

        if ($this->isLightboxEnabled($enabled)) {
            $thirdPartyActive = false;
            $activePlugin = null;
            
            foreach ($this->supported_plugins as $name => $plugin) {
                if ($this->checkIfPluginIsActive($name, $plugin['location'])) {
                    if (isset($plugin['built_in']) && $plugin['built_in']) {
                        continue;
                    }
                    $thirdPartyActive = true;
                    $activePlugin = $plugin;
                    break;
                }
            }

            $options = $this->getCachedMetaSliderOptions();
            $showButton = isset($options['show_lightbox_button']) ? $options['show_lightbox_button'] : true;

            if ($thirdPartyActive && $activePlugin) {
                $slide_type = $this->getSlideType($slide);
                if ($slide_type === 'postfeed') {
                    return $attributes;
                }
                
                if (empty($attributes['href'])) {
                    $attributes['href'] = wp_get_attachment_url($thumbnail_id);
                }

                $attributes['rel'] = (isset($activePlugin['rel'])) ? $activePlugin['rel'] . "[{$slider_id}]" : '';

                if (isset($activePlugin['attributes'])) {
                    foreach ($activePlugin['attributes'] as $key => $value) {
                        $attributes[$key] = ('$' === $value[0]) ?
                            ${ltrim($value, '$')} : $value;

                        if (':caption' === $value) {
                            $attributes[$key] = isset($slide['caption']) ? $slide['caption'] : '';
                        }
                        if (':url' === $value) {
                            $attributes[$key] = $attributes['href'];
                        }
                        if (':dimensions' === $value) {
                            $full_size_url = $attributes['href'];
                            $attachment_id = attachment_url_to_postid($full_size_url);

                            if (!$attachment_id) {
                                $attachment_id = $thumbnail_id;
                            }

                            $image_meta = wp_get_attachment_metadata($attachment_id);
                            if ($image_meta && isset($image_meta['width']) && isset($image_meta['height'])) {
                                $attributes[$key] = $image_meta['width'] . '-' . $image_meta['height'];
                            } else {
                                $image_size = wp_getimagesize($full_size_url);
                                if ($image_size && isset($image_size[0]) && isset($image_size[1])) {
                                    $attributes[$key] = $image_size[0] . '-' . $image_size[1];
                                } else {
                                    $attributes[$key] = '1200-800';
                                }
                            }
                        }
                    }
                }
            } else {
                // Built-in lightbox: propagate the slide caption so JS can read it
                // from the existing <a> element (overlay mode) or via extractCaption (button mode).
                if ( ! empty( $slide['caption'] ) ) {
                    $attributes['data-sub-html'] = $slide['caption'];
                }
            }
        }

        return $attributes;
    }

    /**
     * Get slide type from slide data
     * 
     * @param array $slide Slide data from MetaSlider
     * @return string The slide type
     */
    private function getSlideType($slide)
    {
        if (!isset($slide['class'])) {
            return 'unknown';
        }
        
        $class = $slide['class'];
        
        if (strpos($class, 'ms-image') !== false) {
            return 'image';
        }
        if (strpos($class, 'ms-external') !== false) {
            return 'external';
        }
        if (strpos($class, 'ms-vimeo') !== false) {
            return 'vimeo';
        }
        if (strpos($class, 'ms-youtube') !== false) {
            return 'youtube';
        }
        if (strpos($class, 'ms-local-video') !== false) {
            return 'local-video';
        }
        if (strpos($class, 'ms-external-video') !== false) {
            return 'external-video';
        }
        if (strpos($class, 'ms-custom-html') !== false) {
            return 'custom-html';
        }
        if (strpos($class, 'ms-postfeed') !== false) {
            return 'postfeed';
        }
        if (strpos($class, 'ms-folder') !== false) {
            return 'folder';
        }
        if (strpos($class, 'ms-layer') !== false) {
            return 'layer';
        }
        
        return 'unknown';
    }

    /**
     * Add classes required by the plugin, or classes used to identify the active version.
     *
     * @param string $class Class used
     * @param string $slider_id The current slider ID
     * @param string $settings MetaSlider settings
     *
     * @return string The class list
     */
    public function addLightboxClassNamesToSlider($class, $slider_id, $settings)
    {
        $class .= ' ml-slider-lightbox-' . sanitize_title($this->version);

        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if (!$this->isLightboxEnabled($enabled)) {
            return $class . ' lightbox-disabled';
        }

        foreach ($this->supported_plugins as $name => $plugin) {
            if ($path = $this->checkIfPluginIsActive($name, $plugin['location'])) {
                if (isset($plugin['built_in']) && $plugin['built_in']) {
                    $active_lightbox_data = array(
                        'Name' => $name,
                        'Version' => $this->version
                    );
                } else {
                    if ($path && $path !== 'built-in' && file_exists(WP_PLUGIN_DIR . '/' . $path)) {
                        $active_lightbox_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $path);
                    }
                }

                if (isset($plugin['body_class'])) {
                    $class .= ' ' . $plugin['body_class'];
                }
                break;
            }
        }

        if (! isset($active_lightbox_data['Version'])) {
            if ($this->shouldUseBuiltInLightbox()) {
                return $class . ' ml-builtin-lightbox';
            }
            return $class . ' no-active-lightbox';
        }

        return $class . ' ' . sanitize_title($active_lightbox_data['Name'] . ' ' . $active_lightbox_data['Version']);
    }

    /**
     * This function checks whether a specific plugin is installed and active,
     *
     * @param string $name Specify "Plugin Name" to return details about it.
     * @param string $path Expected path to the plugin
     *
     * @return string|bool Returns the plugin path or false.
     */
    private function checkIfPluginIsActive($name, $path = '')
    {
        if ('built-in' === $path) {
            return $this->shouldUseBuiltInLightbox() ? 'built-in' : false;
        }

        if (! function_exists('get_plugins')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        $cache_key = $name . '|' . $path;
        
        if (!isset(self::$cached_plugin_checks[$cache_key])) {
            if (is_plugin_active($path)) {
                self::$cached_plugin_checks[$cache_key] = $path;
            } else {
                $result = false;
                foreach (get_plugins() as $plugin_path => $plugin_data) {
                    if ($name === $plugin_data['Name'] && is_plugin_active($plugin_path)) {
                        $result = $plugin_path;
                        break;
                    }
                }
                self::$cached_plugin_checks[$cache_key] = $result;
            }
        }
        
        return self::$cached_plugin_checks[$cache_key];
    }

    /**
     * Display a warning on the plugins page if a dependancy
     * is missing or a conflict might exist.
     *
     * @return bool
     */
    public function checkDependencies()
    {
        $active_plugin_count = 0;
        $has_active_lightbox = false;
        $has_built_in = false;

        foreach ($this->supported_plugins as $name => $plugin) {
            if ($this->checkIfPluginIsActive($name, $plugin['location'])) {
                $has_active_lightbox = true;
                if (isset($plugin['built_in']) && $plugin['built_in']) {
                    $has_built_in = true;
                } else {
                    $active_plugin_count++;
                }
            }
        }

        if ((1 === $active_plugin_count || $has_built_in) && $has_active_lightbox && $this->checkIfPluginIsActive('MetaSlider')) {
            return true;
        }

        if (! $this->checkIfPluginIsActive('MetaSlider')) {
            add_action('admin_notices', array($this, 'showMetasliderDependencyWarning'));
            add_action('metaslider_admin_notices', array($this, 'showMetasliderDependencyWarning'));
            return false;
        }

        if (! $has_active_lightbox && ! $has_built_in) {
            return true;
        }

        if ($active_plugin_count > 1) {
            add_action('admin_notices', array($this, 'showMultipleLightboxWarning'), 10, 3);
            add_action('metaslider_admin_notices', array($this, 'showMultipleLightboxWarning'), 10, 3);
            return false;
        }
    }

    public function showDependencyWarning()
    {
        ?>
        <div class='metaslider-admin-notice notice notice-error is-dismissible'>
            <p><?php
                _e(
                    'MetaSlider Gallery requires MetaSlider and at least one other supported lightbox plugin to be installed and activated.',
                    'ml-slider-lightbox'
                ); ?> <a href='https://wordpress.org/plugins/ml-slider-lightbox#description-header'
                         target='_blank'><?php
                            _e('More info', 'ml-slider-lightbox'); ?></a></p>
        </div>
        <?php
    }

    public function showMultipleLightboxWarning()
    {
        ?>
        <div class='metaslider-admin-notice error'>
            <p><?php
                _e(
                    'There is more than one lightbox plugin activated. This may cause conflicts with MetaSlider Gallery',
                    'ml-slider-lightbox'
                ); ?></p>
        </div>
        <?php
    }

    /**
     * Add a checkbox to enable the lightbox on the slider.
     * Also links to the settings page
     *
     * @param array $aFields A list of advanced fields
     * @param array $slider The current slideshow ID
     * @return array
     */
    public function addSettings($aFields = array(), $slider = array())
    {
        if (! function_exists('is_plugin_active')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }

        $active_lightbox_data = null;
        $lightbox_settings_url = '';
        $lightbox_name = '';

        foreach ($this->supported_plugins as $name => $plugin) {
            if ($path = $this->checkIfPluginIsActive($name, $plugin['location'])) {
                if (isset($plugin['built_in']) && $plugin['built_in']) {
                    $active_lightbox_data = array(
                        'Name' => $name,
                        'Version' => $this->version
                    );
                    $lightbox_name = $name;
                } else {
                    if ($path && $path !== 'built-in' && file_exists(WP_PLUGIN_DIR . '/' . $path)) {
                        $active_lightbox_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $path);
                        $lightbox_name = $active_lightbox_data['Name'];
                    }
                }
                $lightbox_settings_url = $plugin['settings_url'];
                break;
            }
        }

        if (! isset($active_lightbox_data['Version'])) {
            $active_lightbox_data = array(
                'Name' => 'MetaSlider Gallery',
                'Version' => $this->version
            );
            $lightbox_name = 'MetaSlider Gallery';
            $lightbox_settings_url = 'admin.php?page=metaslider-lightbox';
        }

        if (isset($slider->id)) {
            $settings = get_post_meta($slider->id, 'ml-slider_settings', true);
            $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

            $link = ! empty($lightbox_settings_url) ? sprintf(
                "<br><a href='%s' target='_blank'>%s</a>",
                admin_url($lightbox_settings_url),
                __("Edit Global Settings", "ml-slider-lightbox")
            ) : '';

            $captions_value    = isset($settings['lightbox_captions'])    ? $settings['lightbox_captions']    : 'global';
            $navigation_value  = isset($settings['lightbox_navigation'])  ? $settings['lightbox_navigation']  : 'global';
            $button_value      = isset($settings['lightbox_button'])      ? $settings['lightbox_button']      : 'global';
            $icon_value        = isset($settings['lightbox_icon'])        ? $settings['lightbox_icon']        : 'global';

            $msl_lightbox = array(
                'lightbox' => array(
                    'priority' => 5,
                    'type' => 'checkbox',
                    'label' => __('Open In Gallery', 'ml-slider-lightbox'),
                    'after' => $link,
                    'class' => 'coin flex responsive nivo',
                    'checked' => $this->isLightboxEnabled($enabled) ? 'checked' : '',
                    'helptext' => sprintf(
                        _x("All slides will open in a gallery, using %s", "Name of a plugin", "ml-slider-lightbox"),
                        $lightbox_name
                    ),
                    'dependencies' => array(
                        array('show' => 'lightbox_captions',   'when' => true),
                        array('show' => 'lightbox_navigation', 'when' => true),
                        array('show' => 'lightbox_button',     'when' => true),
                        array('show' => 'lightbox_icon',       'when' => true),
                    ),
                ),
                'lightbox_captions'   => $this->perSliderSelectField(6, __('Show Captions', 'ml-slider-lightbox'), $captions_value, __('Show captions in the gallery for each slide.', 'ml-slider-lightbox')),
                'lightbox_navigation' => $this->perSliderSelectField(7, __('Show Navigation Arrows', 'ml-slider-lightbox'), $navigation_value, __('Show previous/next arrows in the gallery.', 'ml-slider-lightbox')),
                'lightbox_button'     => $this->perSliderSelectField(8, __('Show "Open In Gallery" Button', 'ml-slider-lightbox'), $button_value, __('Show a button on each slide to open the image in the gallery.', 'ml-slider-lightbox')),
                'lightbox_icon'       => $this->perSliderSelectField(9, __('Show An Icon Instead Of Button', 'ml-slider-lightbox'), $icon_value, __('Display an icon instead of a text button on each slide.', 'ml-slider-lightbox')),
            );
            if ($this->isGlobalMetaSliderLightboxEnabled()) {
                $settings_url  = esc_url( admin_url( 'admin.php?page=metaslider-lightbox&tab=detection' ) );
                $callout_msg   = sprintf(
                    __( 'Gallery is enabled for all slideshows via the <a href="%s">MetaSlider Gallery settings</a>.', 'ml-slider-lightbox' ),
                    $settings_url
                );
                $msl_lightbox['lightbox']['after'] .= '<script>
                    (function() {
                        function injectGalleryNotice() {
                            var cb = document.querySelector("input[name=\"settings[lightbox]\"]");
                            if (!cb) return;

                            // Disable the toggle
                            var switchWrap = cb.closest(".ms-switch-button");
                            if (switchWrap) {
                                switchWrap.style.pointerEvents = "none";
                                switchWrap.style.opacity = "0.5";
                            }

                            // Insert callout above the row (only once)
                            if (document.getElementById("ml-global-gallery-notice")) return;
                            var row = cb.closest("tr");
                            if (!row || !row.parentNode) return;
                            var notice = document.createElement("tr");
                            notice.id = "ml-global-gallery-notice";
                            notice.innerHTML = \'<td colspan="2"><div class="notice notice-info ms-crop-source-notice m-0 pt-2 pb-0 pl-2 pr-2"><p>' . wp_kses( $callout_msg, array( 'a' => array( 'href' => array() ) ) ) . '</p></div></td>\';
                            row.parentNode.insertBefore(notice, row);
                        }
                        if (document.readyState === "loading") {
                            document.addEventListener("DOMContentLoaded", injectGalleryNotice);
                        } else {
                            injectGalleryNotice();
                        }
                    })();
                </script>';
            }
            $aFields = array_merge($aFields, $msl_lightbox);
        }

        if ( isset( $aFields['lightbox_ad'] ) ) {
            unset( $aFields['lightbox_ad'] );
        }

        return $aFields;
    }
    /**
     * Check if lightbox is enabled for a slider
     *
     * @param mixed $enabled The lightbox setting value
     * @return bool
     */
    private function isLightboxEnabled($enabled)
    {
        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN)
            || $this->isGlobalMetaSliderLightboxEnabled();
    }

    /**
     * Check if the global MetaSlider lightbox override is enabled
     *
     * @return bool
     */
    private function isGlobalMetaSliderLightboxEnabled(): bool
    {
        $options = $this->getCachedGeneralOptions();
        return !empty($options['enable_on_all_metaslider_slideshows']);
    }

    /**
     * Determine if we should use the built-in lightbox
     *
     * @return bool
     */
    private function shouldUseBuiltInLightbox()
    {
        $options = $this->getPluginOptions();

        switch ($options['lightbox_mode']) {
            case 'builtin':
                return true;
            case 'third_party':
                return false;
            case 'auto':
            default:
                break;
        }

        foreach ($this->supported_plugins as $name => $plugin) {
            if (isset($plugin['built_in']) && $plugin['built_in']) {
                continue;
            }

            $path = $plugin['location'];
            if (! function_exists('get_plugins')) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }

            if (is_plugin_active($path)) {
                return false;
            }

            foreach (get_plugins() as $plugin_path => $plugin_data) {
                if ($name === $plugin_data['Name'] && is_plugin_active($plugin_path)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if any MetaSlider instances have lightbox enabled on current page
     * 
     * @return bool
     * @since 2.0.0
     */
    private function hasLightboxEnabledSliders()
    {
        if (self::$cached_lightbox_enabled !== null) {
            return self::$cached_lightbox_enabled;
        }
        
        if (!$this->isMetasliderActive()) {
            self::$cached_lightbox_enabled = false;
            return false;
        }

        global $post;
        
        if ($post && !empty($post->post_content)) {
            if (has_shortcode($post->post_content, 'metaslider') || has_shortcode($post->post_content, 'ml_slider')) {
                self::$cached_lightbox_enabled = true;
                return true;
            }
        }

        if (is_active_widget(false, false, 'metaslider_widget')) {
            self::$cached_lightbox_enabled = true;
            return true;
        }

        if (is_page() || is_single() || is_archive() || is_home()) {
            self::$cached_lightbox_enabled = true;
            return true;
        }

        if (is_admin()) {
            self::$cached_lightbox_enabled = true;
            return true;
        }

        if (is_archive() || is_home()) {
            self::$cached_lightbox_enabled = true;
            return true;
        }

        self::$cached_lightbox_enabled = false;
        return false;
    }

    public function enqueueFrontendAssets()
    {
        if (is_admin()) {
            return;
        }

        $options = $this->getPluginOptions();
        $should_load_assets = false;

        $metasliderActive = $this->isMetasliderActive();
        $thirdPartyActive = !$this->shouldUseBuiltInLightbox();

        if ($metasliderActive && $thirdPartyActive) {
            return;
        }

        if ($metasliderActive && !$thirdPartyActive && $this->hasLightboxEnabledSliders()) {
            $should_load_assets = true;
        }
        else if (isset($options['load_assets_globally']) && $options['load_assets_globally']) {
            $should_load_assets = true;
        }
        else if (!$thirdPartyActive && ($this->hasContentDetectionEnabled() || $this->hasManualLightboxProcessing())) {
            $should_load_assets = true;
        }
        else if (!$metasliderActive && !$thirdPartyActive) {
            $should_load_assets = true;
        }

        $should_load_assets = apply_filters('metaslider_lightbox_load_assets', $should_load_assets, $options);

        if ($should_load_assets) {
            $this->enqueueLightgalleryAssets();
        }
    }

    private function enqueueLightgalleryAssets()
    {
        $needs_video = $this->pageNeedsVideoAssets();
        $needs_thumbnails = $this->pageNeedsThumbnailAssets();

        wp_enqueue_style(
            'ml-lightgallery-css',
            plugin_dir_url(__FILE__) . 'assets/css/lightgallery.min.css',
            array(),
            $this->version
        );

        $css_dependencies = array('ml-lightgallery-css');

        if ($needs_video) {
            wp_enqueue_style(
                'lightgallery-video-css',
                plugin_dir_url(__FILE__) . 'assets/css/lg-video.css',
                array('ml-lightgallery-css'),
                $this->version
            );
            $css_dependencies[] = 'lightgallery-video-css';
        }

        if ($needs_thumbnails) {
            wp_enqueue_style(
                'lightgallery-thumbnail-css',
                plugin_dir_url(__FILE__) . 'assets/css/lg-thumbnail.css',
                array('ml-lightgallery-css'),
                $this->version
            );
            $css_dependencies[] = 'lightgallery-thumbnail-css';
        }

        wp_enqueue_style(
            'ml-lightbox-public-css',
            plugin_dir_url(__FILE__) . 'assets/css/ml-lightbox-public.css',
            $css_dependencies,
            $this->version
        );

        $this->addCustomLightboxCss();

        wp_enqueue_script(
            'ml-lightgallery-js',
            plugin_dir_url(__FILE__) . 'assets/js/lightgallery.min.js',
            array('jquery'),
            $this->version,
            true
        );

        if ($needs_video) {
            wp_enqueue_script(
                'lightgallery-video',
                plugin_dir_url(__FILE__) . 'assets/js/lg-video.min.js',
                array('ml-lightgallery-js'),
                $this->version,
                true
            );
        }

        if ($needs_thumbnails) {
            wp_enqueue_script(
                'lightgallery-thumbnail',
                plugin_dir_url(__FILE__) . 'assets/js/lg-thumbnail.min.js',
                array('ml-lightgallery-js'),
                $this->version,
                true
            );
        }

        if ($needs_video) {
            wp_enqueue_script(
                'lightgallery-vimeo-thumbnail',
                plugin_dir_url(__FILE__) . 'assets/js/lg-vimeo-thumbnail.min.js',
                array('ml-lightgallery-js'),
                $this->version,
                true
            );
        }

        if ($needs_video) {
            wp_enqueue_style(
                'videojs-css',
                plugin_dir_url(__FILE__) . 'assets/css/video-js.css',
                array(),
                $this->version
            );

            wp_enqueue_script(
                'videojs',
                plugin_dir_url(__FILE__) . 'assets/js/video.min.js',
                array(),
                $this->version,
                true
            );
        }

        $js_dependencies = array('ml-lightgallery-js');
        if ($needs_video) {
            $js_dependencies[] = 'lightgallery-video';
            $js_dependencies[] = 'lightgallery-vimeo-thumbnail';
            $js_dependencies[] = 'videojs';
        }
        if ($needs_thumbnails) {
            $js_dependencies[] = 'lightgallery-thumbnail';
        }

        if ($this->isProPluginActive() && wp_script_is('metaslider-lightbox-pro-init', 'registered')) {
            $js_dependencies[] = 'metaslider-lightbox-pro-init';
        }

        wp_enqueue_script(
            'ml-lightgallery-clean',
            plugin_dir_url(__FILE__) . 'assets/js/ml-lightgallery-init.js',
            $js_dependencies,
            $this->version,
            true
        );

        $options = $this->getPluginOptions();
        $metaslider_options = $this->getCachedMetaSliderOptions();

        $slider_settings = array();
        if (class_exists('MetaSliderPlugin')) {
            global $wpdb;
            $sliders = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s", 'ml-slider', 'publish'));
            foreach ($sliders as $slider) {
                $settings = get_post_meta($slider->ID, 'ml-slider_settings', true);
                $slider_settings[$slider->ID] = array(
                    'lightbox_enabled'    => $this->isLightboxEnabled(isset($settings['lightbox']) ? $settings['lightbox'] : null),
                    'lightbox_captions'   => $this->parsePerSliderBool($settings, 'lightbox_captions'),
                    'lightbox_navigation' => $this->parsePerSliderBool($settings, 'lightbox_navigation'),
                    'lightbox_button'     => $this->parsePerSliderBool($settings, 'lightbox_button'),
                    'lightbox_icon'       => $this->parsePerSliderBool($settings, 'lightbox_icon'),
                );
            }
        }

        $general_options = $this->getCachedGeneralOptions();
        
        $lightbox_settings = array(
            'slider_settings' => $slider_settings,
            'metaslider_options' => array(
                'show_arrows' => isset($metaslider_options['show_arrows']) ? $metaslider_options['show_arrows'] : true,
                'show_thumbnails' => isset($metaslider_options['show_thumbnails']) ? $metaslider_options['show_thumbnails'] : true,
                'show_lightbox_button' => isset($metaslider_options['show_lightbox_button']) ? $metaslider_options['show_lightbox_button'] : true,
                'use_icon_instead_of_button' => isset($metaslider_options['use_icon_instead_of_button']) ? $metaslider_options['use_icon_instead_of_button'] : false,
                'show_captions' => isset($metaslider_options['show_captions']) ? $metaslider_options['show_captions'] : true,
            ),
            'enable_on_content' => isset($general_options['enable_on_content']) ? $general_options['enable_on_content'] : false,
            'enable_on_widgets' => isset($general_options['enable_on_widgets']) ? $general_options['enable_on_widgets'] : false,
            'enable_galleries' => isset($general_options['enable_galleries']) ? $general_options['enable_galleries'] : false,
            'enable_featured_images' => isset($general_options['enable_featured_images']) ? $general_options['enable_featured_images'] : false,
            'enable_videos' => isset($general_options['enable_videos']) ? $general_options['enable_videos'] : false,
            'override_enlarge_on_click' => isset($general_options['override_enlarge_on_click']) ? $general_options['override_enlarge_on_click'] : true,
            'override_link_to_image_file' => isset($general_options['override_link_to_image_file']) ? $general_options['override_link_to_image_file'] : true,
            'button_text' => isset($general_options['button_text']) ? $general_options['button_text'] : __('Open in Gallery', 'ml-slider-lightbox'),
            'minimum_image_width' => isset($general_options['minimum_image_width']) ? absint($general_options['minimum_image_width']) : 200,
            'minimum_image_height' => isset($general_options['minimum_image_height']) ? absint($general_options['minimum_image_height']) : 200,
            'page_excluded' => $this->shouldExcludePage(),
            'manual_excluded' => $this->shouldExcludeManualForPostType(),
            'view_image_label' => __( 'View image', 'ml-slider-lightbox' ),
        );

        $lightbox_settings = apply_filters('ml_lightbox_settings', $lightbox_settings);

        wp_localize_script('ml-lightgallery-clean', 'mlLightboxSettings', $lightbox_settings);
        wp_add_inline_script('ml-lightgallery-clean', 'var _mlLk="' . esc_js(ML_LIGHTGALLERY_LICENSE_KEY) . '";', 'before');
    }

    /**
     * Convert a stored per-slider select value ('true'/'false'/'global') to
     * true, false, or null (global). JS receives a real boolean or null,
     * avoiding string comparisons on the frontend.
     *
     * @param array  $settings Slider post-meta settings array.
     * @param string $key      The setting key to read.
     * @return bool|null
     */
    private function parsePerSliderBool($settings, $key)
    {
        $value = isset($settings[$key]) ? $settings[$key] : 'global';
        if ($value === 'true')  { return true; }
        if ($value === 'false') { return false; }
        return null;
    }

    /**
     * Build a per-slider Global/Yes/No select field definition for addSettings().
     *
     * @param int    $priority  Field priority.
     * @param string $label     Translated label string.
     * @param string $value     Current stored value.
     * @param string $helptext  Translated help text.
     * @return array
     */
    private function perSliderSelectField($priority, $label, $value, $helptext)
    {
        return array(
            'priority' => $priority,
            'type'     => 'select',
            'label'    => $label,
            'class'    => 'coin flex responsive nivo',
            'value'    => $value,
            'helptext' => $helptext,
            'options'  => array(
                'global' => array('label' => __('Global', 'ml-slider-lightbox')),
                'true'   => array('label' => __('Yes',    'ml-slider-lightbox')),
                'false'  => array('label' => __('No',     'ml-slider-lightbox')),
            ),
        );
    }

    private function addCustomLightboxCss()
    {
        $options = $this->getPluginOptions();

        $background_color = isset($options['background_color']) ? $options['background_color'] : '#000000';
        $button_color = isset($options['button_color']) ? $options['button_color'] : '#000000';
        $button_text_color = isset($options['button_text_color']) ? $options['button_text_color'] : '#ffffff';
        $button_hover_color = isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0';
        $button_hover_text_color = isset($options['button_hover_text_color']) ? $options['button_hover_text_color'] : '#000000';

        $arrow_color = isset($options['arrow_color']) ? $options['arrow_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');
        $arrow_hover_color = isset($options['arrow_hover_color']) ? $options['arrow_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000000');
        $close_icon_color = isset($options['close_icon_color']) ? $options['close_icon_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');
        $close_icon_hover_color = isset($options['close_icon_hover_color']) ? $options['close_icon_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000000');
        $toolbar_icon_color = isset($options['toolbar_icon_color']) ? $options['toolbar_icon_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');
        $toolbar_icon_hover_color = isset($options['toolbar_icon_hover_color']) ? $options['toolbar_icon_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000000');

        $arrow_background_color = isset($options['arrow_background_color']) ? $options['arrow_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');
        $arrow_background_hover_color = isset($options['arrow_background_hover_color']) ? $options['arrow_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');
        $close_icon_background_color = isset($options['close_icon_background_color']) ? $options['close_icon_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');
        $close_icon_background_hover_color = isset($options['close_icon_background_hover_color']) ? $options['close_icon_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');
        $toolbar_icon_background_color = isset($options['toolbar_icon_background_color']) ? $options['toolbar_icon_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');
        $toolbar_icon_background_hover_color = isset($options['toolbar_icon_background_hover_color']) ? $options['toolbar_icon_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');

        $icon_color = isset($options['icon_color']) ? $options['icon_color'] : '#ffffff';
        $icon_hover_color = isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000000';
        $icon_background_color = isset($options['icon_background_color']) ? $options['icon_background_color'] : '#000000';
        $icon_background_hover_color = isset($options['icon_background_hover_color']) ? $options['icon_background_hover_color'] : '#f0f0f0';

        $background_opacity = isset($options['background_opacity']) ? $options['background_opacity'] : '0.9';
        $close_button_position = isset($options['close_button_position']) ? $options['close_button_position'] : 'top-right';
        $lightbox_button_position = isset($options['lightbox_button_position']) ? $options['lightbox_button_position'] : 'top-right';
        $autoplay_progress_bar_color = isset($options['autoplay_progress_bar_color']) ? $options['autoplay_progress_bar_color'] : '#a90707';
        $thumbnail_border_color = isset($options['thumbnail_border_color']) ? $options['thumbnail_border_color'] : '#ffffff';
        $thumbnail_border_hover_color = isset($options['thumbnail_border_hover_color']) ? $options['thumbnail_border_hover_color'] : '#dd6923';


        $custom_css = '
            :root {
                --ml-lightbox-arrow-color: ' . esc_html($arrow_color) . ' !important;
                --ml-lightbox-arrow-hover-color: ' . esc_html($arrow_hover_color) . ' !important;
                --ml-lightbox-close-icon-color: ' . esc_html($close_icon_color) . ' !important;
                --ml-lightbox-close-icon-hover-color: ' . esc_html($close_icon_hover_color) . ' !important;
                --ml-lightbox-toolbar-icon-color: ' . esc_html($toolbar_icon_color) . ' !important;
                --ml-lightbox-toolbar-icon-hover-color: ' . esc_html($toolbar_icon_hover_color) . ' !important;
                --ml-lightbox-thumbnail-border-color: ' . esc_html($thumbnail_border_color) . ' !important;
                --ml-lightbox-thumbnail-border-hover-color: ' . esc_html($thumbnail_border_hover_color) . ' !important;
            }

            .lg-backdrop {
                background-color: ' . esc_html($background_color) . ' !important;
                opacity: ' . esc_html($background_opacity) . ' !important;
            }

            .lg-outer .lg-thumb-outer {
                background-color: ' . esc_html($background_color) . ' !important;
                opacity: ' . esc_html($background_opacity) . ' !important;
            }

            .lg-outer .lg-prev,
            .lg-outer .lg-next {
                background-color: ' . esc_html($arrow_background_color) . ' !important;
                color: var(--ml-lightbox-arrow-color) !important;
            }

            .lg-outer .lg-prev:hover,
            .lg-outer .lg-next:hover {
                background-color: ' . esc_html($arrow_background_hover_color) . ' !important;
                color: var(--ml-lightbox-arrow-hover-color) !important;
            }

            .lg-outer .lg-toolbar > .lg-icon:not(.lg-close),
            .lg-outer .lg-counter {
                background-color: ' . esc_html($toolbar_icon_background_color) . ' !important;
                color: var(--ml-lightbox-toolbar-icon-color) !important;
            }

            .lg-outer .lg-toolbar > .lg-icon:not(.lg-close):hover,
            .lg-outer .lg-counter:hover {
                background-color: ' . esc_html($toolbar_icon_background_hover_color) . ' !important;
                color: var(--ml-lightbox-toolbar-icon-hover-color) !important;
            }

            .lg-outer .lg-close {
                background-color: ' . esc_html($close_icon_background_color) . ' !important;
                color: var(--ml-lightbox-close-icon-color) !important;
            }

            .lg-outer .lg-close:hover {
                background-color: ' . esc_html($close_icon_background_hover_color) . ' !important;
                color: var(--ml-lightbox-close-icon-hover-color) !important;
            }

            .ml-lightbox-button,
            .widget .ml-lightbox-enabled a.ml-lightbox-button {
                background-color: ' . esc_html($button_color) . ' !important;
                color: ' . esc_html($button_text_color) . ' !important;
            }

            .ml-lightbox-button:hover,
            .ml-lightbox-button:focus {
                background-color: ' . esc_html($button_hover_color) . ' !important;
                color: ' . esc_html($button_hover_text_color) . ' !important;
            }

            .ml-lightbox-button:has(.ml-lightbox-icon) {
                background-color: ' . esc_html($icon_background_color) . ' !important;
            }

            .ml-lightbox-button:has(.ml-lightbox-icon):hover,
            .ml-lightbox-button:has(.ml-lightbox-icon):focus {
                background-color: ' . esc_html($icon_background_hover_color) . ' !important;
            }

            .ml-lightbox-button .ml-lightbox-icon {
                color: ' . esc_html($icon_color) . ' !important;
            }

            .ml-lightbox-button:hover .ml-lightbox-icon,
            .ml-lightbox-button:focus .ml-lightbox-icon {
                color: ' . esc_html($icon_hover_color) . ' !important;
            }';

        if ($lightbox_button_position === 'top-left') {
            $custom_css .= '
            .ml-lightbox-button,
            .widget .ml-lightbox-enabled a.ml-lightbox-button {
                top: 10px !important;
                left: 10px !important;
                right: auto !important;
                bottom: auto !important;
            }';
        } elseif ($lightbox_button_position === 'bottom-left') {
            $custom_css .= '
            .ml-lightbox-button,
            .widget .ml-lightbox-enabled a.ml-lightbox-button {
                top: auto !important;
                left: 10px !important;
                right: auto !important;
                bottom: 10px !important;
            }';
        } elseif ($lightbox_button_position === 'bottom-right') {
            $custom_css .= '
            .ml-lightbox-button,
            .widget .ml-lightbox-enabled a.ml-lightbox-button {
                top: auto !important;
                left: auto !important;
                right: 10px !important;
                bottom: 10px !important;
            }';
        } else {
            $custom_css .= '
            .ml-lightbox-button,
            .widget .ml-lightbox-enabled a.ml-lightbox-button {
                top: 10px !important;
                left: auto !important;
                right: 10px !important;
                bottom: auto !important;
            }';
        }

        $custom_css .= '

            .lg-progress-bar .lg-progress {
                background-color: ' . esc_html($autoplay_progress_bar_color) . ' !important;
            }

        ';

        $general_options = $this->getCachedGeneralOptions();
        $processing_mode = isset($general_options['content_processing_mode']) ? $general_options['content_processing_mode'] : 'include';

        if ($processing_mode === 'exclude') {
            $exclude_pages = isset($general_options['exclude_pages']) ? $general_options['exclude_pages'] : array();
            $exclude_posts = isset($general_options['exclude_posts']) ? $general_options['exclude_posts'] : array();
            $exclude_post_types = isset($general_options['exclude_post_types']) ? $general_options['exclude_post_types'] : array();

            $all_excluded_ids = array_merge($exclude_pages, $exclude_posts);

            foreach ($general_options as $key => $value) {
                if (strpos($key, 'exclude_cpt_') === 0 && is_array($value)) {
                    $all_excluded_ids = array_merge($all_excluded_ids, $value);
                }
            }

            $all_excluded_ids = array_unique($all_excluded_ids);

            $selectors = array();
            foreach ($all_excluded_ids as $excluded_id) {
                $excluded_id = intval($excluded_id);
                if ($excluded_id > 0) {
                    $selectors[] = '.ml-lightbox-excluded [id$="' . $excluded_id . '"] .ml-lightbox-button';
                    $selectors[] = '.ml-lightbox-excluded [id$="' . $excluded_id . '"] .ml-lightbox-overlay';
                    $selectors[] = '.ml-lightbox-excluded [id$="' . $excluded_id . '"] .ml-video-overlay';
                }
            }

            foreach ($exclude_post_types as $post_type) {
                $post_type = sanitize_html_class($post_type);
                if (!empty($post_type)) {
                    $selectors[] = '.post-type-' . $post_type . ' .ml-lightbox-button';
                    $selectors[] = '.post-type-' . $post_type . ' .ml-lightbox-overlay';
                    $selectors[] = '.post-type-' . $post_type . ' .ml-video-overlay';

                    $selectors[] = '.ml-lightbox-excluded [id*="' . $post_type . '"] .ml-lightbox-button';
                    $selectors[] = '.ml-lightbox-excluded [id*="' . $post_type . '"] .ml-lightbox-overlay';
                    $selectors[] = '.ml-lightbox-excluded [id*="' . $post_type . '"] .ml-video-overlay';
                }
            }

            if (!empty($selectors)) {
                $custom_css .= '
                ' . implode(",\n                ", $selectors) . ' {
                    display: none !important;
                }
                ';
            }
        }

        wp_add_inline_style('ml-lightbox-public-css', $custom_css);
    }

    public function enqueueAdminScripts($hook)
    {
        if (strpos($hook, 'metaslider-lightbox') === false) {
            return;
        }

        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('wp-color-picker');

        wp_enqueue_script('ml-select2', plugin_dir_url(__FILE__) . 'assets/js/select2.min.js', array('jquery'), '4.1.0', true);
        wp_enqueue_style('ml-select2', plugin_dir_url(__FILE__) . 'assets/css/select2.min.css', array(), '4.1.0');

        wp_enqueue_script(
            'ml-lightbox-admin',
            plugin_dir_url(__FILE__) . 'assets/js/ml-lightbox-admin.js',
            array('jquery', 'wp-color-picker', 'ml-select2', 'wp-i18n'),
            $this->version,
            true
        );
    }

    public function showMetasliderDependencyWarning()
    {
        ?>
        <div class='metaslider-admin-notice notice notice-error is-dismissible'>
            <p><?php
                esc_html_e(
                    'MetaSlider Gallery requires MetaSlider to be installed and activated.',
                    'ml-slider-lightbox'
                );
                ?> <a href='<?php echo esc_url(admin_url('plugin-install.php?s=metaslider&tab=search&type=term')); ?>'><?php
                    esc_html_e('Install MetaSlider', 'ml-slider-lightbox');
?></a></p>
        </div>
        <?php
    }

    public function setupAdminMenu()
    {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'addAdminMenu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueueAdminAssets'));
            add_action('updated_option', array($this, 'handleOptionUpdate'), 10, 3);
            add_action('admin_init', array($this, 'registerSettings'));
        }
    }

    /**
     * Clear cache when plugin options are updated
     *
     * @param string $option_name
     * @param mixed $old_value  
     * @param mixed $value
     * @since 2.0.0
     */
    public function handleOptionUpdate($option_name, $old_value, $value)
    {
        if (strpos($option_name, 'metaslider_lightbox_') === 0 || $option_name === 'ml_lightbox_options') {
            $this->clearOptionsCache();
        }
    }

    private function setupEnlargeOnClickOverride()
    {
        add_filter('wp_get_attachment_link', array($this, 'overrideEnlargeOnClick'), 10, 6);
    }

    public function overrideEnlargeOnClick($link, $id, $size, $permalink, $icon, $text)
    {
        if ($permalink || $icon || empty($link)) {
            return $link;
        }

        if (!$this->shouldUseBuiltInLightbox()) {
            return $link;
        }
        if ($this->shouldExcludePage()) {
            return $link;
        }

        $attachment = get_post($id);
        if (!$attachment || !wp_attachment_is_image($attachment)) {
            return $link;
        }

        $full_size_url = wp_get_attachment_image_url($id, 'full');
        if (!$full_size_url) {
            return $link;
        }

        $link = preg_replace_callback(
            '/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is',
            function($matches) use ($full_size_url) {
                $original_href = $matches[1];
                $link_content = $matches[2];
                
                if (strpos($link_content, '<img') !== false && preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $original_href)) {
                    return '<a href="' . esc_url($full_size_url) . '" data-src="' . esc_url($full_size_url) . '" data-thumb="' . esc_url($original_href) . '" class="ml-enlarge-override">' . $link_content . '</a>';
                }
                
                return $matches[0];
            },
            $link
        );

        return $link;
    }

    /**
     * Render custom admin page header
     *
     * @param string $current_page Current page slug
     * @param array $tabs Optional tabs for navigation
     */
    private function renderAdminHeader($current_page = 'main', $tabs = array())
    {
        ?>
        <div class="ml-lightbox-wrap">
            <div class="ml-lightbox-header">
                <div class="ml-lightbox-header-content">
                    <div class="ml-lightbox-logo-title">
                        <div class="ml-lightbox-logo">
                            <svg version="1.1" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="white">
                                <g>
                                    <path d="M127.9,0C57.3,0,0,57.3,0,127.9c0,70.6,57.3,127.9,127.9,127.9c70.6,0,127.9-57.3,127.9-127.9C255.8,57.3,198.5,0,127.9,0z M16.4,177.1l92.5-117.5L124.2,79l-77.3,98.1H16.4z M170.5,177.1l-38.9-49.4l15.5-19.6l54.4,69H170.5z M208.5,177.1L146.9,99 l-61.6,78.2h-31l92.5-117.5l92.5,117.5H208.5z"/>
                                </g>
                            </svg>
                        </div>
                        <h1>
                            <?php
                            if ($this->isProPluginActive()) {
                                _e('MetaSlider Gallery Pro', 'ml-slider-lightbox');
                            } else {
                                _e('MetaSlider Gallery', 'ml-slider-lightbox');
                            }
                            ?>
                        </h1>
                    </div>
                </div>
            </div>
            
            <div class="ml-lightbox-content">
        <?php
    }

    private function renderAdminFooter()
    {
        ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a specific settings section with proper WordPress Settings API
     *
     * @param string $page The page slug
     * @param string $section_id The section ID
     * @since 2.11.2
     */
    private function renderSettingsSection($page, $section_id)
    {
        global $wp_settings_sections, $wp_settings_fields;

        if (isset($wp_settings_sections[$page][$section_id])) {
            $section = $wp_settings_sections[$page][$section_id];

            if ($section['callback']) {
                call_user_func($section['callback'], $section);
            }
        }

        if (isset($wp_settings_fields[$page][$section_id])) {
            echo '<table class="form-table" role="presentation">';
            do_settings_fields($page, $section_id);
            echo '</table>';
        }
    }

    /**
     * Render a modern toggle switch card layout
     *
     * @param string $name The input name attribute
     * @param bool $checked Whether the checkbox should be checked
     * @param string $label The label text
     * @param string $description Optional description text
     * @param bool $disabled Whether the toggle should be disabled
     */
    private function renderToggleSwitch($name, $checked, $label, $description = '', $disabled = false)
    {
        $disabled_attr = $disabled ? ' disabled' : '';
        $checked_attr = checked($checked, true, false);
        $unique_id = str_replace(['[', ']'], ['_', ''], $name);

        echo '<div class="ml-toggle-card">';
        echo '<div class="ml-toggle-content">';
        echo '<h3 class="ml-toggle-title">' . esc_html($label) . '</h3>';
        if (!empty($description)) {
            echo '<div class="ml-toggle-description">' . esc_html($description) . '</div>';
        }
        echo '</div>';
        echo '<div class="ml-toggle-control">';
        echo '<input type="checkbox" name="' . esc_attr($name) . '" value="1" id="' . esc_attr($unique_id) . '"' . $checked_attr . $disabled_attr . ' />';
        echo '<span class="ml-toggle-switch" data-target="' . esc_attr($unique_id) . '"></span>';
        echo '</div>';
        echo '</div>';
    }

    public function enqueueAdminAssets($hook)
    {
        if (strpos($hook, 'metaslider-lightbox') === false) {
            return;
        }

        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('ml-select2', plugin_dir_url(__FILE__) . 'assets/js/select2.min.js', array('jquery'), '4.1.0', true);
        wp_enqueue_style('ml-select2-style', plugin_dir_url(__FILE__) . 'assets/css/select2.min.css', array(), '4.1.0');
        wp_enqueue_style('ml-tipsy-style', plugin_dir_url(__FILE__) . 'assets/vendor/tipsy/tipsy.css', array(), $this->version);
        wp_enqueue_script('ml-tipsy', plugin_dir_url(__FILE__) . 'assets/vendor/tipsy/jquery.tipsy.js', array('jquery'), $this->version, true);
        wp_enqueue_style(
            'ml-lightbox-admin-style',
            plugin_dir_url(__FILE__) . 'assets/css/ml-lightbox-admin.css',
            array(),
            $this->version
        );

        wp_enqueue_script(
            'ml-lightbox-admin-script',
            plugin_dir_url(__FILE__) . 'assets/js/ml-lightbox-admin.js',
            array('jquery', 'wp-color-picker', 'ml-select2', 'ml-tipsy', 'wp-i18n'),
            $this->version,
            true
        );
        wp_localize_script(
            'ml-lightbox-admin-script',
            'mlLightboxText',
            array(
                'select_pages_to' => __('Select pages to %s...', 'ml-slider-lightbox'),
                'select_posts_to' => __('Select posts to %s...', 'ml-slider-lightbox'),
                'select_post_types_to' => __('Select post types to %s...', 'ml-slider-lightbox'),
                'select_cpt_to' => __('Select %1$s to %2$s...', 'ml-slider-lightbox'),
                'select_post_types_to_exclude' => __('Select post types to exclude', 'ml-slider-lightbox'),
                'include' => __('Include', 'ml-slider-lightbox'),
                'exclude' => __('Exclude', 'ml-slider-lightbox')
            )
        );
    }

    public function addAdminMenu()
    {
        $menu_title = $this->isProPluginActive()
            ? __('Gallery Pro', 'ml-slider-lightbox')
            : __('Gallery', 'ml-slider-lightbox');

        $page_title = $this->isProPluginActive()
            ? __('MetaSlider Gallery Pro', 'ml-slider-lightbox')
            : __('MetaSlider Gallery', 'ml-slider-lightbox');

        add_menu_page(
            $page_title,
            $menu_title,
            'manage_options',
            'metaslider-lightbox',
            array($this, 'renderMainPage'),
            'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHN2ZyBmaWxsPSIjZmZmIiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4IiB2aWV3Qm94PSIwIDAgMjU1LjggMjU1LjgiIHN0eWxlPSJmaWxsOiNmZmYiIHhtbDpzcGFjZT0icHJlc2VydmUiPjxnPjxwYXRoIGQ9Ik0xMjcuOSwwQzU3LjMsMCwwLDU3LjMsMCwxMjcuOWMwLDcwLjYsNTcuMywxMjcuOSwxMjcuOSwxMjcuOWM3MC42LDAsMTI3LjktNTcuMywxMjcuOS0xMjcuOUMyNTUuOCw1Ny4zLDE5OC41LDAsMTI3LjksMHogTTE2LjQsMTc3LjFsOTIuNS0xMTcuNUwxMjQuMiw3OWwtNzcuMyw5OC4xSDE2LjR6IE0xNzAuNSwxNzcuMWwtMzguOS00OS40bDE1LjUtMTkuNmw1NC40LDY5SDE3MC41eiBNMjA4LjUsMTc3LjFMMTQ2LjksOTkgbC02MS42LDc4LjJoLTMxbDkyLjUtMTE3LjVsOTIuNSwxMTcuNUgyMDguNXoiLz48L2c+PC9zdmc+Cg=='
        );

        // Explicit Settings submenu so it always appears with the correct label.
        add_submenu_page(
            'metaslider-lightbox',
            $page_title,
            __('Settings', 'ml-slider-lightbox'),
            'manage_options',
            'metaslider-lightbox',
            array($this, 'renderMainPage')
        );
    }

    public function renderMainPage()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'detection';

        $tabs = array(
            'detection' => '🎯 ' . __('Automatic Mode', 'ml-slider-lightbox'),
            'manual' => '👆 ' . __('Manual Mode', 'ml-slider-lightbox'),
            'appearance' => '🎨 ' . __('Appearance', 'ml-slider-lightbox'),
            'behavior' => '⚙️ ' . __('Behavior', 'ml-slider-lightbox')
        );

        // Add Upgrade tab only when Pro is NOT active
        if (!$this->isProPluginActive()) {
            $tabs['upgrade'] = '⭐ ' . __('Upgrade to Pro', 'ml-slider-lightbox');
        }

        $this->renderAdminHeader('metaslider-lightbox', $tabs);
        ?>
        
        <!-- Tab Navigation -->
        <nav class="nav-tab-wrapper">
            <?php foreach ($tabs as $tab_key => $tab_name) : ?>
                <a href="?page=metaslider-lightbox&tab=<?php echo esc_attr($tab_key); ?>" 
                   class="nav-tab <?php echo $current_tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html($tab_name); ?>
                </a>
            <?php endforeach; ?>
        </nav>
        
        <div class="tab-content" style="margin-top: 20px;">
            <?php
            if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
                echo '<div class="notice notice-success is-dismissible ml-lightbox-notice-success">';
                echo '<p>' . __('Settings saved successfully!', 'ml-slider-lightbox') . '</p>';
                echo '</div>';
            }
            ?>
            
            <?php if ($current_tab === 'detection') : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('metaslider_lightbox_content');
                    ?>
                    <h2><?php echo esc_html(__('Where to Enable Automatically', 'ml-slider-lightbox')); ?></h2>
                    <?php $this->contentWhereCallback(); ?>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('metaslider_lightbox_content', 'metaslider_lightbox_content_where'); ?>
                    </table>

                    <?php if ($this->isMetasliderActive()) : ?>
                        <h2><?php echo esc_html(__('MetaSlider Slideshows', 'ml-slider-lightbox')); ?></h2>
                        <?php $this->metaSliderSectionCallback(); ?>
                        <table class="form-table" role="presentation">
                            <?php do_settings_fields('metaslider_lightbox_content', 'metaslider_lightbox_content_metaslider'); ?>
                        </table>
                    <?php endif; ?>

                    <h2><?php echo esc_html(__('Content Filtering', 'ml-slider-lightbox')); ?></h2>
                    <?php $this->contentExclusionsCallback(); ?>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('metaslider_lightbox_content', 'metaslider_lightbox_content_exclusions'); ?>
                    </table>
                    
                    <?php submit_button(); ?>
                </form>
            <?php elseif ($current_tab === 'appearance') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('metaslider_lightbox_appearance'); ?>
                    <h2><?php echo esc_html(__('Appearance', 'ml-slider-lightbox')); ?></h2>
                    <p><?php echo esc_html__('Customize colors, opacity, and visual styling for the gallery overlay and controls.', 'ml-slider-lightbox'); ?></p>

                    <!-- Background & Overlay Section -->
                    <div class="ml-appearance-card">
                        <h2><?php echo esc_html(__('Background & Overlay', 'ml-slider-lightbox')); ?></h2>
                        <?php $this->renderSettingsSection('metaslider_lightbox_appearance', 'metaslider_lightbox_appearance_background'); ?>
                    </div>
                    <!-- Navigation Section -->
                    <div class="ml-appearance-card">
                        <h2><?php echo esc_html(__('Navigation', 'ml-slider-lightbox')); ?></h2>
                        <?php $this->renderSettingsSection('metaslider_lightbox_appearance', 'metaslider_lightbox_appearance_icons'); ?>
                    </div>
                    <!-- Buttons Section -->
                    <div class="ml-appearance-card">
                        <h2><?php echo esc_html(__('"Open in Gallery" Button', 'ml-slider-lightbox')); ?></h2>
                        <?php $this->renderSettingsSection('metaslider_lightbox_appearance', 'metaslider_lightbox_appearance_buttons'); ?>
                    </div>
                    <?php submit_button(); ?>
                </form>
            <?php elseif ($current_tab === 'behavior') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('metaslider_lightbox_settings'); ?>

                    <!-- Navigation & Controls Section -->
                    <h2><?php echo esc_html(__('Navigation & Controls', 'ml-slider-lightbox')); ?></h2>
                    <?php $this->renderSettingsSection('metaslider_lightbox_settings', 'metaslider_lightbox_behavior_navigation'); ?>

                    <?php if ($this->isMetasliderActive() && $this->shouldHideMetaSliderSettings()) : ?>
                        <!-- Show notice only when there's a third-party conflict with MetaSlider active -->
                        <div class="notice notice-info">
                            <p><?php _e('<strong>Third-Party Plugin Detected:</strong> A third-party lightbox plugin is active. These settings may not apply to MetaSlider but will still work for WordPress content lightboxes.', 'ml-slider-lightbox'); ?></p>
                        </div>
                    <?php endif; ?>
                    
                    
                    <?php if ($this->isProPluginActive()) : ?>
                        <?php if ($this->hasProSettingsFields('metaslider_lightbox_pro_zoom_section')) : ?>
                            <h2><?php echo esc_html(__('Enhanced Zoom & Controls', 'ml-slider-lightbox')); ?></h2>
                            <?php $this->renderProZoomSection(); ?>
                            <table class="form-table" role="presentation">
                                <?php do_settings_fields('metaslider_lightbox_settings', 'metaslider_lightbox_pro_zoom_section'); ?>
                            </table>
                        <?php endif; ?>

                        <?php if ($this->hasProSettingsFields('metaslider_lightbox_pro_media_section')) : ?>
                            <h2><?php echo esc_html(__('Media & Video Enhancements', 'ml-slider-lightbox')); ?></h2>
                            <?php $this->renderProMediaSection(); ?>
                            <table class="form-table" role="presentation">
                                <?php do_settings_fields('metaslider_lightbox_settings', 'metaslider_lightbox_pro_media_section'); ?>
                            </table>
                        <?php endif; ?>

                        <?php if ($this->hasProSettingsFields('metaslider_lightbox_pro_social_section')) : ?>
                            <h2><?php echo esc_html(__('Social & Navigation', 'ml-slider-lightbox')); ?></h2>
                            <?php $this->renderProSocialSection(); ?>
                            <table class="form-table" role="presentation">
                                <?php do_settings_fields('metaslider_lightbox_settings', 'metaslider_lightbox_pro_social_section'); ?>
                            </table>
                        <?php endif; ?>

                        <?php if ($this->hasProSettingsFields('metaslider_lightbox_pro_advanced_section')) : ?>
                            <h2><?php echo esc_html(__('Advanced Features', 'ml-slider-lightbox')); ?></h2>
                            <?php $this->renderProAdvancedSection(); ?>
                            <table class="form-table" role="presentation">
                                <?php do_settings_fields('metaslider_lightbox_settings', 'metaslider_lightbox_pro_advanced_section'); ?>
                            </table>
                        <?php endif; ?>
                    <?php else : ?>
                        <?php // Show Pro feature "ads" when Pro is NOT active ?>
                        <?php $this->renderProFeaturesAds(); ?>
                    <?php endif; ?>
                    
                    <?php submit_button(); ?>
                </form>
            <?php elseif ($current_tab === 'manual') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('metaslider_lightbox_manual'); ?>
                    <h2><?php echo esc_html(__('Manual Options', 'ml-slider-lightbox')); ?></h2>
                    <p><?php echo esc_html__('Manual controls and WordPress overrides that work independently of automatic settings.', 'ml-slider-lightbox'); ?></p>
                    <?php
                    global $wp_settings_sections, $wp_settings_fields;
                    $page = 'metaslider_lightbox_manual';
                    $section = 'metaslider_lightbox_manual_options';

                    if (isset($wp_settings_sections[$page][$section])) {
                        echo '<div>';
                        if ($wp_settings_sections[$page][$section]['title']) {
                            echo '<h3>' . esc_html($wp_settings_sections[$page][$section]['title']) . '</h3>';
                        }
                        if ($wp_settings_sections[$page][$section]['callback']) {
                            call_user_func($wp_settings_sections[$page][$section]['callback'], $wp_settings_sections[$page][$section]);
                        }
                        echo '</div>';
                    }
                    ?>

                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('metaslider_lightbox_manual', 'metaslider_lightbox_manual_options'); ?>
                    </table>

                    <?php submit_button(); ?>
                </form>

                <div class="manual-options-content">
                    <?php $this->renderManualOptionsHowTo(); ?>
                </div>
            <?php elseif ($current_tab === 'upgrade' && !$this->isProPluginActive()) : ?>
                <?php $this->renderUpgradeComparisonTable(); ?>
            <?php endif; ?>
        </div>
        
        <?php
        $this->renderAdminFooter();
    }

    public function registerSettings()
    {
        /**
         * Register settings with proper WordPress architecture
         * Each tab has its own settings group to prevent cross-tab interference
         */
        register_setting('metaslider_lightbox_content', 'metaslider_lightbox_content_options', array($this, 'sanitizeContentOptions'));
        register_setting('metaslider_lightbox_manual', 'metaslider_lightbox_manual_options', array($this, 'sanitizeManualOptions'));
        register_setting('metaslider_lightbox_appearance', 'metaslider_lightbox_appearance_options', array($this, 'sanitizeAppearanceOptions'));
        register_setting('metaslider_lightbox_settings', 'ml_lightbox_options', array($this, 'sanitizeMetasliderOptions'));

        /**
         * CONTENT DETECTION TAB
         */

        add_settings_field(
            'enable_on_content',
            '',
            array($this, 'enableOnContentCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_where'
        );

        add_settings_field(
            'enable_galleries',
            '',
            array($this, 'enableGalleriesCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_where'
        );

        add_settings_field(
            'enable_videos',
            '',
            array($this, 'enableVideosCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_where'
        );

        add_settings_field(
            'enable_on_widgets',
            '',
            array($this, 'enableOnWidgetsCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_where'
        );

        add_settings_field(
            'enable_featured_images',
            '',
            array($this, 'enableFeaturedImagesCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_where'
        );

        add_settings_section(
            'metaslider_lightbox_content_metaslider',
            '',
            array($this, 'metaSliderSectionCallback'),
            'metaslider_lightbox_content'
        );

        add_settings_field(
            'enable_on_all_metaslider_slideshows',
            '',
            array($this, 'enableOnAllMetaSliderSlideshowsCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_metaslider'
        );

        add_settings_section(
            'metaslider_lightbox_content_exclusions',
            __('Content Filtering', 'ml-slider-lightbox'),
            array($this, 'contentExclusionsCallback'),
            'metaslider_lightbox_content'
        );

        add_settings_field(
            'content_processing_mode',
            '',
            array($this, 'contentProcessingModeCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'exclude_post_types',
            '',
            array($this, 'excludePostTypesCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'exclude_pages',
            '',
            array($this, 'excludePagesCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'exclude_posts',
            '',
            array($this, 'excludePostsCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'exclude_css_selectors',
            '',
            array($this, 'excludeCssSelectorsCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'minimum_image_width',
            '',
            array($this, 'minimumImageWidthCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'minimum_image_height',
            '',
            array($this, 'minimumImageHeightCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );
        /**
         * APPEARANCE TAB
         */

        add_settings_section(
            'metaslider_lightbox_appearance_background',
            __('Background & Overlay', 'ml-slider-lightbox'),
            array($this, 'appearanceBackgroundCallback'),
            'metaslider_lightbox_appearance'
        );

        add_settings_section(
            'metaslider_lightbox_appearance_icons',
            __('Navigation Icons', 'ml-slider-lightbox'),
            array($this, 'appearanceIconsCallback'),
            'metaslider_lightbox_appearance'
        );

        add_settings_section(
            'metaslider_lightbox_appearance_buttons',
            __('Buttons', 'ml-slider-lightbox'),
            array($this, 'appearanceButtonsCallback'),
            'metaslider_lightbox_appearance'
        );

        add_settings_field(
            'background_color',
            __('Background Color', 'ml-slider-lightbox'),
            array($this, 'backgroundColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_background'
        );

        add_settings_field(
            'background_opacity',
            __('Background Opacity', 'ml-slider-lightbox'),
            array($this, 'backgroundOpacityCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_background'
        );

        add_settings_field(
            'autoplay_progress_bar_color',
            __('Autoplay Progress Bar Color', 'ml-slider-lightbox'),
            array($this, 'autoplayProgressBarColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_background'
        );

        add_settings_field(
            'thumbnail_border_color',
            __('Thumbnail Border Color', 'ml-slider-lightbox'),
            array($this, 'thumbnailBorderColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_background'
        );

        add_settings_field(
            'navigation_arrows',
            __('Arrows', 'ml-slider-lightbox'),
            array($this, 'navigationArrowsGroupCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_icons'
        );

        add_settings_field(
            'navigation_close_button',
            __('Close Button', 'ml-slider-lightbox'),
            array($this, 'navigationCloseButtonGroupCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_icons'
        );

        add_settings_field(
            'navigation_toolbar',
            __('Toolbar', 'ml-slider-lightbox'),
            array($this, 'navigationToolbarGroupCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_icons'
        );

        add_settings_field(
            'button_colors',
            __('Button Colors', 'ml-slider-lightbox'),
            array($this, 'buttonColorsGroupCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_buttons'
        );

        add_settings_field(
            'icon_colors',
            __('Icon Colors', 'ml-slider-lightbox'),
            array($this, 'iconColorsGroupCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_buttons'
        );

        add_settings_field(
            'button_text',
            __('Button Text', 'ml-slider-lightbox'),
            array($this, 'buttonTextCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_buttons'
        );

        add_settings_field(
            'lightbox_button_position',
            __('Button Position', 'ml-slider-lightbox'),
            array($this, 'lightboxButtonPositionCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_buttons'
        );

        /**
         * BEHAVIOR TAB
         */

        add_settings_section(
            'metaslider_lightbox_behavior_navigation',
            __('Navigation & Controls', 'ml-slider-lightbox'),
            array($this, 'behaviorNavigationCallback'),
            'metaslider_lightbox_settings'
        );

        add_settings_field(
            'show_thumbnails',
            '',
            array($this, 'showThumbnailsCallback'),
            'metaslider_lightbox_settings',
            'metaslider_lightbox_behavior_navigation'
        );

        add_settings_field(
            'show_captions',
            '',
            array($this, 'showCaptionsCallback'),
            'metaslider_lightbox_settings',
            'metaslider_lightbox_behavior_navigation'
        );

        add_settings_field(
            'show_arrows',
            '',
            array($this, 'showArrowsCallback'),
            'metaslider_lightbox_settings',
            'metaslider_lightbox_behavior_navigation'
        );

        add_settings_field(
            'show_lightbox_button',
            '',
            array($this, 'showLightboxButtonCallback'),
            'metaslider_lightbox_settings',
            'metaslider_lightbox_behavior_navigation'
        );

        add_settings_field(
            'use_icon_instead_of_button',
            '',
            array($this, 'useIconInsteadOfButtonCallback'),
            'metaslider_lightbox_settings',
            'metaslider_lightbox_behavior_navigation'
        );

        if (!$this->isProPluginActive()) {

        }

        /*
         * MANUAL OPTIONS TAB
         */
        add_settings_field(
            'override_enlarge_on_click',
            '',
            array($this, 'overrideEnlargeOnClickCallback'),
            'metaslider_lightbox_manual',
            'metaslider_lightbox_manual_options'
        );

        add_settings_field(
            'override_link_to_image_file',
            '',
            array($this, 'overrideLinkToImageFileCallback'),
            'metaslider_lightbox_manual',
            'metaslider_lightbox_manual_options'
        );

        add_settings_field(
            'manual_exclude_post_types',
            '',
            array($this, 'manualExcludePostTypesCallback'),
            'metaslider_lightbox_manual',
            'metaslider_lightbox_manual_options'
        );
    }

    public function sanitizeContentOptions($input)
    {
        if (!current_user_can('manage_options')) {
            return get_option('metaslider_lightbox_content_options', array());
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'metaslider_lightbox_content-options')) {
            return get_option('metaslider_lightbox_content_options', array());
        }

        if (!is_array($input)) {
            return get_option('metaslider_lightbox_content_options', array());
        }

        $sanitized = array();

        $content_fields = ['enable_on_content', 'enable_on_widgets', 'enable_galleries', 'enable_featured_images', 'enable_videos', 'enable_on_all_metaslider_slideshows'];

        foreach ($content_fields as $field) {
            $sanitized[$field] = isset($input[$field]) ? true : false;
        }

        if (isset($input['content_processing_mode'])) {
            $allowed_modes = array('exclude', 'include');
            $sanitized['content_processing_mode'] = in_array($input['content_processing_mode'], $allowed_modes)
                ? $input['content_processing_mode']
                : 'include';
        }

        if (isset($input['exclude_pages']) && is_array($input['exclude_pages'])) {
            $sanitized['exclude_pages'] = array_map('intval', $input['exclude_pages']);
        } else {
            $sanitized['exclude_pages'] = array();
        }

        if (isset($input['exclude_posts']) && is_array($input['exclude_posts'])) {
            $sanitized['exclude_posts'] = array_map('intval', $input['exclude_posts']);
        } else {
            $sanitized['exclude_posts'] = array();
        }

        if (isset($input['exclude_post_types']) && is_array($input['exclude_post_types'])) {
            $sanitized['exclude_post_types'] = array_map('sanitize_text_field', $input['exclude_post_types']);
        } else {
            $sanitized['exclude_post_types'] = array();
        }

        if (isset($input['exclude_css_selectors'])) {
            $sanitized['exclude_css_selectors'] = sanitize_textarea_field($input['exclude_css_selectors']);
        }

        if (isset($input['minimum_image_width'])) {
            $sanitized['minimum_image_width'] = absint($input['minimum_image_width']);
        } else {
            $sanitized['minimum_image_width'] = 200;
        }

        if (isset($input['minimum_image_height'])) {
            $sanitized['minimum_image_height'] = absint($input['minimum_image_height']);
        } else {
            $sanitized['minimum_image_height'] = 200;
        }

        foreach ($input as $key => $value) {
            if (strpos($key, 'exclude_cpt_') === 0 && is_array($value)) {
                $sanitized[$key] = array_map('intval', $value);
            }
        }

        if (isset(self::$cached_options['general'])) {
            unset(self::$cached_options['general']);
        }

        return $sanitized;
    }

    public function sanitizeManualOptions($input)
    {

        if (!current_user_can('manage_options')) {
            return get_option('metaslider_lightbox_manual_options', array());
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'metaslider_lightbox_manual-options')) {
            return get_option('metaslider_lightbox_manual_options', array());
        }

        if ($input === null) {
            $input = array();
        }

        if (!is_array($input)) {
            return get_option('metaslider_lightbox_manual_options', array());
        }

        $sanitized = array();
        $sanitized['override_enlarge_on_click'] = isset($input['override_enlarge_on_click']) ? true : false;
        $sanitized['override_link_to_image_file'] = isset($input['override_link_to_image_file']) ? true : false;

        if (isset($input['manual_exclude_post_types']) && is_array($input['manual_exclude_post_types'])) {
            $sanitized['manual_exclude_post_types'] = array_map('sanitize_text_field', $input['manual_exclude_post_types']);
        } else {
            $sanitized['manual_exclude_post_types'] = array();
        }

        $current_options = get_option('metaslider_lightbox_manual_options', array());
        if (isset($current_options['override_link_to_media'])) {
        }

        if (isset(self::$cached_options['general'])) {
            unset(self::$cached_options['general']);
        }

        return $sanitized;
    }

    public function sanitizeAppearanceOptions($input)
    {
        if (!current_user_can('manage_options')) {
            return get_option('metaslider_lightbox_appearance_options', array());
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'metaslider_lightbox_appearance-options')) {
            return get_option('metaslider_lightbox_appearance_options', array());
        }

        if (!is_array($input)) {
            return get_option('metaslider_lightbox_appearance_options', array());
        }

        $sanitized = array();

        if (isset($input['background_color'])) {
            $sanitized['background_color'] = sanitize_hex_color($input['background_color']);
        }

        if (isset($input['button_color'])) {
            $sanitized['button_color'] = sanitize_hex_color($input['button_color']);
        }

        if (isset($input['button_text_color'])) {
            $sanitized['button_text_color'] = sanitize_hex_color($input['button_text_color']);
        }

        if (isset($input['button_hover_color'])) {
            $sanitized['button_hover_color'] = sanitize_hex_color($input['button_hover_color']);
        }

        if (isset($input['button_hover_text_color'])) {
            $sanitized['button_hover_text_color'] = sanitize_hex_color($input['button_hover_text_color']);
        }

        if (isset($input['icon_color'])) {
            $sanitized['icon_color'] = sanitize_hex_color($input['icon_color']);
        }

        if (isset($input['icon_hover_color'])) {
            $sanitized['icon_hover_color'] = sanitize_hex_color($input['icon_hover_color']);
        }

        if (isset($input['icon_background_color'])) {
            $sanitized['icon_background_color'] = sanitize_hex_color($input['icon_background_color']);
        }

        if (isset($input['icon_background_hover_color'])) {
            $sanitized['icon_background_hover_color'] = sanitize_hex_color($input['icon_background_hover_color']);
        }

        if (isset($input['arrow_color'])) {
            $sanitized['arrow_color'] = sanitize_hex_color($input['arrow_color']);
        }

        if (isset($input['arrow_hover_color'])) {
            $sanitized['arrow_hover_color'] = sanitize_hex_color($input['arrow_hover_color']);
        }

        if (isset($input['close_icon_color'])) {
            $sanitized['close_icon_color'] = sanitize_hex_color($input['close_icon_color']);
        }

        if (isset($input['close_icon_hover_color'])) {
            $sanitized['close_icon_hover_color'] = sanitize_hex_color($input['close_icon_hover_color']);
        }

        if (isset($input['toolbar_icon_color'])) {
            $sanitized['toolbar_icon_color'] = sanitize_hex_color($input['toolbar_icon_color']);
        }

        if (isset($input['toolbar_icon_hover_color'])) {
            $sanitized['toolbar_icon_hover_color'] = sanitize_hex_color($input['toolbar_icon_hover_color']);
        }

        if (isset($input['arrow_background_color'])) {
            $sanitized['arrow_background_color'] = sanitize_hex_color($input['arrow_background_color']);
        }

        if (isset($input['arrow_background_hover_color'])) {
            $sanitized['arrow_background_hover_color'] = sanitize_hex_color($input['arrow_background_hover_color']);
        }

        if (isset($input['close_icon_background_color'])) {
            $sanitized['close_icon_background_color'] = sanitize_hex_color($input['close_icon_background_color']);
        }

        if (isset($input['close_icon_background_hover_color'])) {
            $sanitized['close_icon_background_hover_color'] = sanitize_hex_color($input['close_icon_background_hover_color']);
        }

        if (isset($input['toolbar_icon_background_color'])) {
            $sanitized['toolbar_icon_background_color'] = sanitize_hex_color($input['toolbar_icon_background_color']);
        }

        if (isset($input['toolbar_icon_background_hover_color'])) {
            $sanitized['toolbar_icon_background_hover_color'] = sanitize_hex_color($input['toolbar_icon_background_hover_color']);
        }

        if (isset($input['autoplay_progress_bar_color'])) {
            $sanitized['autoplay_progress_bar_color'] = sanitize_hex_color($input['autoplay_progress_bar_color']);
        }

        if (isset($input['thumbnail_border_color'])) {
            $sanitized['thumbnail_border_color'] = sanitize_hex_color($input['thumbnail_border_color']);
        }

        if (isset($input['thumbnail_border_hover_color'])) {
            $sanitized['thumbnail_border_hover_color'] = sanitize_hex_color($input['thumbnail_border_hover_color']);
        }

        if (isset($input['background_opacity'])) {
            $opacity = floatval($input['background_opacity']);
            $sanitized['background_opacity'] = max(0, min(1, $opacity));
        }

        if (isset($input['button_text'])) {
            $sanitized['button_text'] = sanitize_text_field($input['button_text']);
        }

        if (isset($input['close_button_position'])) {
            $allowed_positions = array('top-right', 'top-left', 'bottom-right', 'bottom-left');
            $sanitized['close_button_position'] = in_array($input['close_button_position'], $allowed_positions)
                ? $input['close_button_position']
                : 'top-right';
        }

        if (isset($input['lightbox_button_position'])) {
            $allowed_positions = array('top-right', 'top-left', 'bottom-right', 'bottom-left');
            $sanitized['lightbox_button_position'] = in_array($input['lightbox_button_position'], $allowed_positions)
                ? $input['lightbox_button_position']
                : 'top-right';
        }

        if (isset(self::$cached_options['general'])) {
            unset(self::$cached_options['general']);
        }

        return $sanitized;
    }

    public function sanitizeMetasliderOptions($input)
    {
        if (!current_user_can('manage_options')) {
            return $this->getCachedMetaSliderOptions();
        }

        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'metaslider_lightbox_settings-options')) {
            return $this->getCachedMetaSliderOptions();
        }

        $sanitized = array();

        $sanitized['show_arrows'] = isset($input['show_arrows']) ? true : false;
        $sanitized['show_thumbnails'] = isset($input['show_thumbnails']) ? true : false;
        $sanitized['show_captions'] = isset($input['show_captions']) ? true : false;
        $sanitized['show_lightbox_button'] = isset($input['show_lightbox_button']) ? true : false;
        $sanitized['use_icon_instead_of_button'] = isset($input['use_icon_instead_of_button']) ? true : false;

        return $sanitized;
    }

    public function generalSettingsSectionCallback()
    {
        echo '<p>' . __('Configure general gallery settings that apply site-wide.', 'ml-slider-lightbox') . '</p>';
    }

    public function metasliderSettingsSectionCallback()
    {
        echo '<p>' . __('Configure settings specific to MetaSlider Gallery functionality.', 'ml-slider-lightbox') . '</p>';
    }

    public function contentWhereCallback()
    {
        echo '<p>' . __('Select where the gallery will be enabled automatically.', 'ml-slider-lightbox') . '</p>';
    }


    public function contentExclusionsCallback()
    {
        echo '<p>' . __('Choose how to filter content for gallery processing and specify which pages, posts, or selectors to include or exclude.', 'ml-slider-lightbox') . '</p>';
    }

    public function contentProcessingModeCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $mode = isset($options['content_processing_mode']) ? $options['content_processing_mode'] : 'include';

        ?>
        <div class="ml-settings-field ml-processing-mode-field">
            <div class="ml-settings-field-content">
                <h3 class="ml-settings-field-title">
                    <?php echo esc_html__('Processing Mode', 'ml-slider-lightbox'); ?>
                </h3>
                <div class="ml-settings-field-description ml-mode-description" id="exclude-description" style="<?php echo $mode === 'exclude' ? '' : 'display: none;'; ?>">
                    <?php echo esc_html__('Process all content EXCEPT the items specified below', 'ml-slider-lightbox'); ?>
                </div>
                <div class="ml-settings-field-description ml-mode-description" id="include-description" style="<?php echo $mode === 'include' ? '' : 'display: none;'; ?>">
                    <?php echo esc_html__('ONLY process the items specified below', 'ml-slider-lightbox'); ?>
                </div>
            </div>
            <div class="ml-settings-field-control">
                <div class="ml-toggle-container">
                    <label class="ml-toggle-button <?php echo $mode === 'include' ? 'active' : ''; ?>" data-mode="include">
                        <input type="radio" name="metaslider_lightbox_content_options[content_processing_mode]" value="include" <?php checked($mode, 'include'); ?>>
                        <strong><?php echo esc_html__('Inclusion Mode', 'ml-slider-lightbox'); ?></strong>
                    </label>
                    <label class="ml-toggle-button <?php echo $mode === 'exclude' ? 'active' : ''; ?>" data-mode="exclude">
                        <input type="radio" name="metaslider_lightbox_content_options[content_processing_mode]" value="exclude" <?php checked($mode, 'exclude'); ?>>
                        <strong><?php echo esc_html__('Exclusion Mode', 'ml-slider-lightbox'); ?></strong>
                    </label>
                </div>
            </div>
        </div>
        <?php
    }

    public function enableOnContentCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $enabled = isset($options['enable_on_content']) ? $options['enable_on_content'] : false;

        $this->renderToggleSwitch(
            'metaslider_lightbox_content_options[enable_on_content]',
            $enabled,
            __('Images in post content', 'ml-slider-lightbox'),
            __('When enabled, individual images without links and "Enlarge to Click" set in posts and pages will automatically open in the gallery.', 'ml-slider-lightbox')
        );
    }

    public function enableOnWidgetsCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $enabled = isset($options['enable_on_widgets']) ? $options['enable_on_widgets'] : false;

        $this->renderToggleSwitch(
            'metaslider_lightbox_content_options[enable_on_widgets]',
            $enabled,
            __('Images and videos in widgets and sidebars', 'ml-slider-lightbox'),
            __('When enabled, images and videos in widget areas will automatically open in the gallery.', 'ml-slider-lightbox')
        );
    }

    public function enableGalleriesCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $enabled = isset($options['enable_galleries']) ? $options['enable_galleries'] : false;

        $this->renderToggleSwitch(
            'metaslider_lightbox_content_options[enable_galleries]',
            $enabled,
            __('Gallery shortcodes and blocks', 'ml-slider-lightbox'),
            __('When enabled, images in WordPress [gallery] shortcodes and Gutenberg Gallery blocks will automatically open in the gallery window.', 'ml-slider-lightbox')
        );
    }

    public function enableFeaturedImagesCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $enabled = isset($options['enable_featured_images']) ? $options['enable_featured_images'] : false;

        $this->renderToggleSwitch(
            'metaslider_lightbox_content_options[enable_featured_images]',
            $enabled,
            __('Featured images', 'ml-slider-lightbox'),
            __('When enabled, featured images (post thumbnails) will automatically open in the gallery when clicked.', 'ml-slider-lightbox')
        );
    }

    public function metaSliderSectionCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<p>' . esc_html__('Automatically open all MetaSlider slideshows in the gallery, overriding the per-slider setting.', 'ml-slider-lightbox') . '</p>';
    }

    public function enableOnAllMetaSliderSlideshowsCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $options = $this->getCachedGeneralOptions();
        $enabled = !empty($options['enable_on_all_metaslider_slideshows']);

        $this->renderToggleSwitch(
            'metaslider_lightbox_content_options[enable_on_all_metaslider_slideshows]',
            $enabled,
            __('MetaSlider slideshows', 'ml-slider-lightbox'),
            __('When enabled, all MetaSlider slideshows will open slides in the gallery window, overriding the per-slider setting.', 'ml-slider-lightbox')
        );
    }

    public function enableVideosCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $enabled = isset($options['enable_videos']) ? $options['enable_videos'] : false;

        $this->renderToggleSwitch(
            'metaslider_lightbox_content_options[enable_videos]',
            $enabled,
            __('Videos in post content', 'ml-slider-lightbox'),
            __('When enabled, standalone video blocks (HTML5 videos, YouTube embeds, Vimeo embeds) in post/page content will automatically open in the gallery.', 'ml-slider-lightbox')
        );
    }


    public function backgroundColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['background_color']) ? $options['background_color'] : '#000000';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[background_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for the gallery overlay. Dark colors work best for image viewing.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['button_color']) ? $options['button_color'] : '#000000';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for "Open in Gallery" buttons that appear on slides, images and videos.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonTextColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['button_text_color']) ? $options['button_text_color'] : '#ffffff';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_text_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Text color for "Open in Gallery" buttons that appear on slides, images and videos.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color when hovering over "Open in Gallery" buttons.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonHoverTextColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['button_hover_text_color']) ? $options['button_hover_text_color'] : '#000000';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_hover_text_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Text color when hovering over "Open in Gallery" buttons.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonTextCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $text = isset($options['button_text']) ? $options['button_text'] : __('Open in Gallery', 'ml-slider-lightbox');

        echo '<input type="text" name="metaslider_lightbox_appearance_options[button_text]" value="' . esc_attr($text) . '" class="regular-text" />';
        echo '<p class="description">' . __('Custom text for gallery buttons. This text will appear on all "Open in Gallery" buttons throughout the site.', 'ml-slider-lightbox') . '</p>';
    }

    public function closeButtonPositionCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $position = isset($options['close_button_position']) ? $options['close_button_position'] : 'top-right';

        $positions = array(
            'top-right' => __('Top Right (Default)', 'ml-slider-lightbox'),
            'top-left' => __('Top Left', 'ml-slider-lightbox'),
            'bottom-right' => __('Bottom Right', 'ml-slider-lightbox'),
            'bottom-left' => __('Bottom Left', 'ml-slider-lightbox')
        );

        echo '<select name="metaslider_lightbox_appearance_options[close_button_position]">';
        foreach ($positions as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($position, $value, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Choose where to position the close button (X) in the gallery overlay.', 'ml-slider-lightbox') . '</p>';
    }

    public function lightboxButtonPositionCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $position = isset($options['lightbox_button_position']) ? $options['lightbox_button_position'] : 'top-right';

        $positions = array(
            'top-right' => __('Top Right (Default)', 'ml-slider-lightbox'),
            'top-left' => __('Top Left', 'ml-slider-lightbox'),
            'bottom-right' => __('Bottom Right', 'ml-slider-lightbox'),
            'bottom-left' => __('Bottom Left', 'ml-slider-lightbox')
        );

        echo '<select name="metaslider_lightbox_appearance_options[lightbox_button_position]">';
        foreach ($positions as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($position, $value, false) . '>';
            echo esc_html($label);
            echo '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . __('Choose where to position "Open in Gallery" buttons within slides, images, galleries, and videos.', 'ml-slider-lightbox') . '</p>';
    }

    public function arrowColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['arrow_color']) ? $options['arrow_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[arrow_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Color for previous and next navigation arrows in the gallery.', 'ml-slider-lightbox') . '</p>';
    }

    public function arrowHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['arrow_hover_color']) ? $options['arrow_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000000');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[arrow_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Arrow color when hovering. Creates visual feedback when users move their mouse over the navigation arrows.', 'ml-slider-lightbox') . '</p>';
    }

    public function closeIconColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['close_icon_color']) ? $options['close_icon_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[close_icon_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Color for the close button (X) in the gallery.', 'ml-slider-lightbox') . '</p>';
    }

    public function closeIconHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['close_icon_hover_color']) ? $options['close_icon_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000000');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[close_icon_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Close button color when hovering. Creates visual feedback when users move their mouse over the close button.', 'ml-slider-lightbox') . '</p>';
    }

    public function toolbarIconColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['toolbar_icon_color']) ? $options['toolbar_icon_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[toolbar_icon_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Color for toolbar icons and counter text in the lightbox. Affects zoom, fullscreen icons (Pro), and image counter display.', 'ml-slider-lightbox') . '</p>';
    }

    public function toolbarIconHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['toolbar_icon_hover_color']) ? $options['toolbar_icon_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000000');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[toolbar_icon_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Toolbar icon color when hovering. Creates visual feedback when users move their mouse over toolbar icons and counter.', 'ml-slider-lightbox') . '</p>';
    }

    public function arrowBackgroundColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['arrow_background_color']) ? $options['arrow_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[arrow_background_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for previous and next navigation arrow buttons.', 'ml-slider-lightbox') . '</p>';
    }

    public function arrowBackgroundHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['arrow_background_hover_color']) ? $options['arrow_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[arrow_background_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for arrow buttons when hovering. Creates visual feedback when users move their mouse over the arrows.', 'ml-slider-lightbox') . '</p>';
    }

    public function closeIconBackgroundColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['close_icon_background_color']) ? $options['close_icon_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[close_icon_background_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for the close button (X) in the gallery.', 'ml-slider-lightbox') . '</p>';
    }

    public function closeIconBackgroundHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['close_icon_background_hover_color']) ? $options['close_icon_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[close_icon_background_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for the close button when hovering. Creates visual feedback when users move their mouse over the close button.', 'ml-slider-lightbox') . '</p>';
    }

    public function toolbarIconBackgroundColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['toolbar_icon_background_color']) ? $options['toolbar_icon_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[toolbar_icon_background_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for toolbar icons in the lightbox.', 'ml-slider-lightbox') . '</p>';
    }

    public function toolbarIconBackgroundHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['toolbar_icon_background_hover_color']) ? $options['toolbar_icon_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[toolbar_icon_background_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for toolbar icons when hovering. Creates visual feedback when users move their mouse over toolbar icons.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Render grouped Arrows color fields with inline layout
     */
    public function navigationArrowsGroupCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();

        $arrow_color = isset($options['arrow_color']) ? $options['arrow_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');
        $arrow_hover_color = isset($options['arrow_hover_color']) ? $options['arrow_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000');
        $arrow_bg = isset($options['arrow_background_color']) ? $options['arrow_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');
        $arrow_bg_hover = isset($options['arrow_background_hover_color']) ? $options['arrow_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');

        echo '<div class="ml-color-inline">';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Icon', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[arrow_color]" value="' . esc_attr($arrow_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Icon Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[arrow_hover_color]" value="' . esc_attr($arrow_hover_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[arrow_background_color]" value="' . esc_attr($arrow_bg) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[arrow_background_hover_color]" value="' . esc_attr($arrow_bg_hover) . '" />';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render grouped Close Button color fields with inline layout
     */
    public function navigationCloseButtonGroupCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();

        $close_color = isset($options['close_icon_color']) ? $options['close_icon_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');
        $close_hover_color = isset($options['close_icon_hover_color']) ? $options['close_icon_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000');
        $close_bg = isset($options['close_icon_background_color']) ? $options['close_icon_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');
        $close_bg_hover = isset($options['close_icon_background_hover_color']) ? $options['close_icon_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');

        echo '<div class="ml-color-inline">';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Icon', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[close_icon_color]" value="' . esc_attr($close_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Icon Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[close_icon_hover_color]" value="' . esc_attr($close_hover_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[close_icon_background_color]" value="' . esc_attr($close_bg) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[close_icon_background_hover_color]" value="' . esc_attr($close_bg_hover) . '" />';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render grouped Toolbar color fields with inline layout
     */
    public function navigationToolbarGroupCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();

        $toolbar_color = isset($options['toolbar_icon_color']) ? $options['toolbar_icon_color'] : (isset($options['icon_color']) ? $options['icon_color'] : '#ffffff');
        $toolbar_hover_color = isset($options['toolbar_icon_hover_color']) ? $options['toolbar_icon_hover_color'] : (isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000');
        $toolbar_bg = isset($options['toolbar_icon_background_color']) ? $options['toolbar_icon_background_color'] : (isset($options['button_color']) ? $options['button_color'] : '#000000');
        $toolbar_bg_hover = isset($options['toolbar_icon_background_hover_color']) ? $options['toolbar_icon_background_hover_color'] : (isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0');

        echo '<div class="ml-color-inline">';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Icon', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[toolbar_icon_color]" value="' . esc_attr($toolbar_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Icon Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[toolbar_icon_hover_color]" value="' . esc_attr($toolbar_hover_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[toolbar_icon_background_color]" value="' . esc_attr($toolbar_bg) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[toolbar_icon_background_hover_color]" value="' . esc_attr($toolbar_bg_hover) . '" />';
        echo '</div>';
        echo '</div>';
    }

    public function backgroundOpacityCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $opacity = isset($options['background_opacity']) ? $options['background_opacity'] : '0.9';

        echo '<div style="position: relative; display: inline-block;">';
        echo '<input type="range" name="metaslider_lightbox_appearance_options[background_opacity]" min="0" max="1" step="0.1" value="' . esc_attr($opacity) . '" oninput="this.nextElementSibling.value = this.value" />';
        echo '<output>' . esc_html($opacity) . '</output>';
        echo '</div>';
        echo '<p class="description">' . __('Set the opacity of the gallery background (0 = transparent, 1 = opaque).', 'ml-slider-lightbox') . '</p>';
    }

    public function autoplayProgressBarColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['autoplay_progress_bar_color']) ? $options['autoplay_progress_bar_color'] : '#a90707';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[autoplay_progress_bar_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . esc_html__('Color of the progress bar shown during autoplay slideshows.', 'ml-slider-lightbox') . '</p>';
    }

    public function thumbnailBorderColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['thumbnail_border_color']) ? $options['thumbnail_border_color'] : '#ffffff';
        $hover_color = isset($options['thumbnail_border_hover_color']) ? $options['thumbnail_border_hover_color'] : '#dd6923';

        echo '<div class="ml-color-inline">';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Border', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[thumbnail_border_color]" value="' . esc_attr($color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Border Active and Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[thumbnail_border_hover_color]" value="' . esc_attr($hover_color) . '" />';
        echo '</div>';
        echo '</div>';
    }

    public function showArrowsCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['show_arrows']) ? $options['show_arrows'] : true;

        $this->renderToggleSwitch(
            'ml_lightbox_options[show_arrows]',
            $checked,
            __('Show navigation arrows in gallery', 'ml-slider-lightbox'),
            __('Display left/right arrows for navigation. Also enables keyboard navigation (← → keys, ESC to close). This is available for slideshows and image galleries.', 'ml-slider-lightbox')
        );
    }
    /**
     * New section callbacks for the improved admin UI
     */

    public function appearanceBackgroundCallback()
    {
        echo '<p>' . __('Customize the gallery background and overlay appearance.', 'ml-slider-lightbox') . '</p>';
    }

    public function appearanceIconsCallback()
    {
        echo '<p>' . __('Customize navigation icons (close, previous, next arrows) for gallery.', 'ml-slider-lightbox') . '</p>';
    }

    public function appearanceButtonsCallback()
    {
        echo '<p>' . __('Customize the "Open in Gallery" buttons that can be added to slides, images, galleries, and videos.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Render grouped Button color fields with inline layout
     */
    public function buttonColorsGroupCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();

        $text_color = isset($options['button_text_color']) ? $options['button_text_color'] : '#ffffff';
        $text_hover_color = isset($options['button_hover_text_color']) ? $options['button_hover_text_color'] : '#000000';
        $bg_color = isset($options['button_color']) ? $options['button_color'] : '#000000';
        $bg_hover_color = isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0';

        echo '<div class="ml-color-inline">';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Text', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_text_color]" value="' . esc_attr($text_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Text Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_hover_text_color]" value="' . esc_attr($text_hover_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_color]" value="' . esc_attr($bg_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_hover_color]" value="' . esc_attr($bg_hover_color) . '" />';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Render grouped Icon color fields with inline layout
     */
    public function iconColorsGroupCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();

        $icon_color = isset($options['icon_color']) ? $options['icon_color'] : '#ffffff';
        $icon_hover_color = isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#000000';
        $icon_bg_color = isset($options['icon_background_color']) ? $options['icon_background_color'] : '#000000';
        $icon_bg_hover_color = isset($options['icon_background_hover_color']) ? $options['icon_background_hover_color'] : '#f0f0f0';

        echo '<div class="ml-color-inline">';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Icon', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[icon_color]" value="' . esc_attr($icon_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Icon Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[icon_hover_color]" value="' . esc_attr($icon_hover_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[icon_background_color]" value="' . esc_attr($icon_bg_color) . '" />';
        echo '</div>';
        echo '<div class="ml-color-field" data-label="' . esc_attr__('Background Hover', 'ml-slider-lightbox') . '">';
        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[icon_background_hover_color]" value="' . esc_attr($icon_bg_hover_color) . '" />';
        echo '</div>';
        echo '</div>';
    }

    public function behaviorNavigationCallback()
    {
        echo '<p>' . __('Configure navigation and control options that apply to all MetaSlider galleries.', 'ml-slider-lightbox') . '</p>';
    }
    public function showThumbnailsCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['show_thumbnails']) ? $options['show_thumbnails'] : true;

        $this->renderToggleSwitch(
            'ml_lightbox_options[show_thumbnails]',
            $checked,
            __('Show thumbnails', 'ml-slider-lightbox'),
            __('Display thumbnail images at the bottom of the gallery for easy navigation. This is available for slideshows and image galleries.', 'ml-slider-lightbox')
        );
    }

    public function showLightboxButtonCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['show_lightbox_button']) ? $options['show_lightbox_button'] : true;

        $this->renderToggleSwitch(
            'ml_lightbox_options[show_lightbox_button]',
            $checked,
            __('Show "Open in Gallery" button', 'ml-slider-lightbox'),
            __('When enabled, shows a button to open the gallery. When disabled, clicking the slide, image, or video directly opens the gallery.', 'ml-slider-lightbox')
        );
    }

    public function useIconInsteadOfButtonCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['use_icon_instead_of_button']) ? $options['use_icon_instead_of_button'] : false;
        $button_enabled = isset($options['show_lightbox_button']) ? $options['show_lightbox_button'] : true;

        // Wrapper div with ID for JavaScript targeting and conditional visibility
        $display_style = $button_enabled ? '' : ' style="display: none;"';
        echo '<div id="ml-icon-instead-of-button-setting"' . $display_style . '>';

        $this->renderToggleSwitch(
            'ml_lightbox_options[use_icon_instead_of_button]',
            $checked,
            __('Show an icon instead of button', 'ml-slider-lightbox'),
            __('When enabled, displays a small icon instead of the full button text.', 'ml-slider-lightbox')
        );

        echo '</div>';
    }

    public function showCaptionsCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['show_captions']) ? $options['show_captions'] : true;

        $this->renderToggleSwitch(
            'ml_lightbox_options[show_captions]',
            $checked,
            __('Show captions', 'ml-slider-lightbox'),
            __('When enabled, display captions in the gallery window. This is available for slideshows, images, and image galleries.', 'ml-slider-lightbox')
        );
    }

    public function behaviorZoomCallback()
    {
        if ($this->isProPluginActive()) {
            echo '<p>' . __('Advanced lightbox features are available through MetaSlider Gallery Pro.', 'ml-slider-lightbox') . '</p>';
        } else {
            echo '<p>' . __('Get a preview of the advanced features available in MetaSlider Gallery Pro.', 'ml-slider-lightbox') . '</p>';
            echo '<div class="ml-lightbox-notice" style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 12px; margin: 15px 0 15px 0;">';
            echo '<p><strong>🎆 Pro Features Preview</strong><br>';
            echo __('These advanced LightGallery.js plugins are available in the Pro version with additional customization options.', 'ml-slider-lightbox');
            echo '</p>';
            echo '</div>';
        }
    }

    public function excludePagesCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $selected_pages = isset($options['exclude_pages']) ? $options['exclude_pages'] : array();

        $pages = get_pages(array(
            'sort_column' => 'post_title',
            'sort_order' => 'ASC',
            'post_status' => 'publish',
            'number' => 100
        ));

        ?>
        <div class="ml-settings-field">
            <div class="ml-settings-field-content">
                <h3 class="ml-settings-field-title">
                    <?php echo esc_html__('Include specific Pages', 'ml-slider-lightbox'); ?>
                </h3>
                <div class="ml-settings-field-description ml-filter-description" data-exclude-text="<?php echo esc_attr__('Select pages where gallery should be disabled. Shows up to 100 most recent pages. Type to search.', 'ml-slider-lightbox'); ?>" data-include-text="<?php echo esc_attr__('Select pages where gallery should be enabled. Shows up to 100 most recent pages. Type to search.', 'ml-slider-lightbox'); ?>">
                    <?php echo esc_html__('Select pages where gallery should be enabled. Shows up to 100 most recent pages. Type to search.', 'ml-slider-lightbox'); ?>
                </div>
            </div>
            <div class="ml-settings-field-control">
                <select name="metaslider_lightbox_content_options[exclude_pages][]" multiple class="ml-select2-pages">
                    <?php
                    if (!empty($pages)) {
                        foreach ($pages as $page) {
                            $selected = in_array($page->ID, $selected_pages) ? 'selected' : '';
                            echo '<option value="' . esc_attr($page->ID) . '" ' . $selected . '>';
                            echo esc_html($page->post_title);
                            echo '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
        </div>
        <?php
    }

    public function excludePostsCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $selected_posts = isset($options['exclude_posts']) ? $options['exclude_posts'] : array();

        $posts = get_posts(array(
            'numberposts' => 100,
            'post_type' => 'post',
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        ));

        ?>
        <div class="ml-settings-field">
            <div class="ml-settings-field-content">
                <h3 class="ml-settings-field-title">
                    <?php echo esc_html__('Include specific Posts', 'ml-slider-lightbox'); ?>
                </h3>
                <div class="ml-settings-field-description ml-filter-description" data-exclude-text="<?php echo esc_attr__('Select posts where gallery should be disabled. Shows up to 100 most recent posts. Type to search.', 'ml-slider-lightbox'); ?>" data-include-text="<?php echo esc_attr__('Select posts where gallery should be enabled. Shows up to 100 most recent posts. Type to search.', 'ml-slider-lightbox'); ?>">
                    <?php echo esc_html__('Select posts where gallery should be enabled. Shows up to 100 most recent posts. Type to search.', 'ml-slider-lightbox'); ?>
                </div>
            </div>
            <div class="ml-settings-field-control">
                <select name="metaslider_lightbox_content_options[exclude_posts][]" multiple class="ml-select2-posts">
                    <?php
                    if (!empty($posts)) {
                        foreach ($posts as $post) {
                            $selected = in_array($post->ID, $selected_posts) ? 'selected' : '';
                            echo '<option value="' . esc_attr($post->ID) . '" ' . $selected . '>';
                            echo esc_html($post->post_title);
                            echo '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
        </div>
        <?php

        $this->outputCustomPostTypeRows();
    }

    private function outputCustomPostTypeRows()
    {
        $options = $this->getCachedGeneralOptions();
        $processing_mode = isset($options['content_processing_mode']) ? $options['content_processing_mode'] : 'include';
        $action = $processing_mode === 'include' ? __('include', 'ml-slider-lightbox') : __('exclude', 'ml-slider-lightbox');
        $action_label = ucfirst($action);

        $post_types = get_post_types(array(
            'show_ui' => true,
            '_builtin' => false
        ), 'objects');

        unset($post_types['ml_gallery']);

        if (empty($post_types)) {
            return;
        }

        echo '</td></tr>';

        foreach ($post_types as $post_type) {
            $cpt_name = $post_type->name;
            $cpt_label = $post_type->label;

            $selected_items = isset($options['exclude_cpt_' . $cpt_name]) ? $options['exclude_cpt_' . $cpt_name] : array();

            $items = get_posts(array(
                'post_type' => $cpt_name,
                'posts_per_page' => 100,
                'orderby' => 'date',
                'order' => 'DESC',
                'post_status' => 'publish'
            ));

            if (empty($items)) {
                continue;
            }

            echo '<tr>';
            echo '<th></th>';
            echo '<td>';
            ?>
            <div class="ml-settings-field">
                <div class="ml-settings-field-content">
                    <h3 class="ml-settings-field-title">
                        <?php echo sprintf(esc_html__('%s specific %s', 'ml-slider-lightbox'), $action_label, esc_html($cpt_label)); ?>
                    </h3>
                    <div class="ml-settings-field-description ml-filter-description" data-exclude-text="<?php echo esc_attr(sprintf(__('Shows up to 100 most recent %s. Type to search.', 'ml-slider-lightbox'), strtolower($cpt_label))); ?>" data-include-text="<?php echo esc_attr(sprintf(__('Shows up to 100 most recent %s. Type to search.', 'ml-slider-lightbox'), strtolower($cpt_label))); ?>">
                        <?php echo sprintf(esc_html__('Shows up to 100 most recent %s. Type to search.', 'ml-slider-lightbox'), strtolower($cpt_label)); ?>
                    </div>
                </div>
                <div class="ml-settings-field-control">
                    <select name="metaslider_lightbox_content_options[exclude_cpt_<?php echo esc_attr($cpt_name); ?>][]" multiple class="ml-select2-cpt ml-select2-cpt-<?php echo esc_attr($cpt_name); ?>" data-cpt-name="<?php echo esc_attr($cpt_name); ?>" data-cpt-label="<?php echo esc_attr($cpt_label); ?>">
                        <?php
                        foreach ($items as $item) {
                            $selected = in_array($item->ID, $selected_items) ? 'selected' : '';
                            echo '<option value="' . esc_attr($item->ID) . '" ' . $selected . '>';
                            echo esc_html($item->post_title);
                            echo '</option>';
                        }
                        ?>
                    </select>
                </div>
            </div>
            <?php
            echo '</td>';
            echo '</tr>';
        }

        echo '<tr style="display: none;"><th></th><td>';
    }

    public function excludePostTypesCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $content_options = get_option('metaslider_lightbox_content_options', array());
        $selected_post_types = isset($content_options['exclude_post_types']) ? $content_options['exclude_post_types'] : array();

        $post_types = get_post_types(array(
            'show_ui' => true
        ), 'objects');

        $internal_types = array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'ml_gallery');
        foreach ($internal_types as $internal_type) {
            unset($post_types[$internal_type]);
        }

        ?>
        <div class="ml-settings-field">
            <div class="ml-settings-field-content">
                <h3 class="ml-settings-field-title">
                    <?php echo esc_html__('Include specific post types', 'ml-slider-lightbox'); ?>
                </h3>
                <div class="ml-settings-field-description ml-filter-description" data-exclude-text="<?php echo esc_attr__('Select post types where gallery should be disabled entirely.', 'ml-slider-lightbox'); ?>" data-include-text="<?php echo esc_attr__('Select post types where gallery should be enabled entirely.', 'ml-slider-lightbox'); ?>">
                    <?php echo esc_html__('Select post types where gallery should be enabled entirely.', 'ml-slider-lightbox'); ?>
                </div>
            </div>
            <div class="ml-settings-field-control">
                <select name="metaslider_lightbox_content_options[exclude_post_types][]" multiple class="ml-select2-post-types">
                    <?php
                    if (!empty($post_types)) {
                        foreach ($post_types as $post_type) {
                            $selected = in_array($post_type->name, $selected_post_types) ? 'selected' : '';
                            echo '<option value="' . esc_attr($post_type->name) . '" ' . $selected . '>';
                            echo esc_html($post_type->label . ' (' . $post_type->name . ')');
                            echo '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
        </div>
        <?php
    }

    public function excludeCssSelectorsCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $selectors = isset($options['exclude_css_selectors']) ? $options['exclude_css_selectors'] : '';

        ?>
        <div class="ml-settings-field">
            <div class="ml-settings-field-content">
                <h3 class="ml-settings-field-title">
                    <?php echo esc_html__('Exclude by CSS selector', 'ml-slider-lightbox'); ?>
                </h3>
                <div class="ml-settings-field-description">
                    <?php echo wp_kses_post(__('CSS selectors for elements to exclude from gallery window (one per line). Examples:<br>.no-lightbox<br>.custom-gallery img<br>#sidebar .widget-area', 'ml-slider-lightbox')); ?>
                </div>
            </div>
            <div class="ml-settings-field-control">
                <textarea name="metaslider_lightbox_content_options[exclude_css_selectors]" rows="4" cols="50" class="ml-css-selectors-textarea"><?php echo esc_textarea($selectors); ?></textarea>
            </div>
        </div>
        <?php
    }

    public function minimumImageWidthCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $width = isset($options['minimum_image_width']) ? $options['minimum_image_width'] : 200;

        ?>
        <div class="ml-settings-field">
            <div class="ml-settings-field-content">
                <h3 class="ml-settings-field-title">
                    <?php echo esc_html__('Minimum Image Width', 'ml-slider-lightbox'); ?>
                </h3>
                <div class="ml-settings-field-description">
                    <?php echo esc_html__('Skip images narrower than this width (in pixels). Uses the displayed width, not the actual image file size. This is helpful for excluding small icons, badges, and buttons.', 'ml-slider-lightbox'); ?>
                </div>
            </div>
            <div class="ml-settings-field-control">
                <input type="number" name="metaslider_lightbox_content_options[minimum_image_width]" value="<?php echo esc_attr($width); ?>" min="0" step="1" class="small-text" /> px
            </div>
        </div>
        <?php
    }

    public function minimumImageHeightCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $height = isset($options['minimum_image_height']) ? $options['minimum_image_height'] : 200;

        ?>
        <div class="ml-settings-field">
            <div class="ml-settings-field-content">
                <h3 class="ml-settings-field-title">
                    <?php echo esc_html__('Minimum Image Height', 'ml-slider-lightbox'); ?>
                </h3>
                <div class="ml-settings-field-description">
                    <?php echo esc_html__('Skip images shorter than this height (in pixels). Uses the displayed height, not the actual image file size. This is helpful for excluding small icons, badges, and buttons.', 'ml-slider-lightbox'); ?>
                </div>
            </div>
            <div class="ml-settings-field-control">
                <input type="number" name="metaslider_lightbox_content_options[minimum_image_height]" value="<?php echo esc_attr($height); ?>" min="0" step="1" class="small-text" /> px
            </div>
        </div>
        <?php
    }

    /**
     * Preview section callbacks that mirror Pro plugin organization
     */

    public function proMediaPreviewCallback()
    {
        echo '<p>' . __('Enhanced media handling and video playback features for professional galleries.', 'ml-slider-lightbox') . '</p>';
        echo '<div class="ml-lightbox-notice" style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 12px; margin: 15px 0;">';
        echo '<p><strong>🎆 Pro Features Preview</strong> - Enhanced video support with autoplay and quality controls.</p>';
        echo '</div>';
    }
    public function proAdvancedPreviewCallback()
    {
        echo '<p>' . __('Advanced features for professional lightbox implementations.', 'ml-slider-lightbox') . '</p>';
        echo '<div class="ml-lightbox-notice" style="background: #fff8e5; border-left: 4px solid #ffb900; padding: 12px; margin: 15px 0;">';
        echo '<p><strong>🎆 Pro Features Preview</strong> - Unique URLs, social sharing, and automatic slideshow progression.</p>';
        echo '</div>';
    }

    public function enableZoomCallback()
    {
        $this->renderToggleSwitch(
            'ml_lightbox_options[enable_zoom]',
            false,
            __('Enable Zoom', 'ml-slider-lightbox'),
            __('Double-click to zoom, zoom controls, mouse wheel zoom, and zoom-to-fit options.', 'ml-slider-lightbox'),
            true
        );
    }

    public function enableFullscreenCallback()
    {
        $this->renderToggleSwitch(
            'ml_lightbox_options[enable_fullscreen]',
            false,
            __('Enable Fullscreen', 'ml-slider-lightbox'),
            __('Native HTML5 fullscreen support with keyboard shortcuts (F key) for immersive viewing.', 'ml-slider-lightbox'),
            true
        );
    }

    public function enableRotateCallback()
    {
        $this->renderToggleSwitch(
            'ml_lightbox_options[enable_rotate]',
            false,
            __('Enable Rotate', 'ml-slider-lightbox'),
            __('Rotate images clockwise/anticlockwise, flip horizontal/vertical with toolbar controls.', 'ml-slider-lightbox'),
            true
        );
    }

    public function enableVideoCallback()
    {
        $this->renderToggleSwitch(
            'ml_lightbox_options[enable_video]',
            false,
            __('Enable Video', 'ml-slider-lightbox'),
            __('Enhanced video support for YouTube, Vimeo, HTML5 videos with autoplay and quality controls.', 'ml-slider-lightbox'),
            true
        );
    }

    public function enableHashCallback()
    {
        $this->renderToggleSwitch(
            'ml_lightbox_options[enable_hash]',
            false,
            __('Enable Unique Image URLs', 'ml-slider-lightbox'),
            __('Unique URLs for each gallery image with browser back/forward navigation support.', 'ml-slider-lightbox'),
            true
        );
    }

    public function enableAutoplayCallback()
    {
        $this->renderToggleSwitch(
            'ml_lightbox_options[enable_autoplay]',
            false,
            __('Enable Autoplay', 'ml-slider-lightbox'),
            __('Automatically advance through images in the gallery.', 'ml-slider-lightbox'),
            true
        );
    }

    public function enableShareCallback()
    {
        $this->renderToggleSwitch(
            'ml_lightbox_options[enable_share]',
            false,
            __('Enable Share', 'ml-slider-lightbox'),
            __('Social media sharing buttons for Facebook, Twitter, Pinterest, and direct URL sharing.', 'ml-slider-lightbox'),
            true
        );
    }

    public function enablePagerCallback()
    {
        $this->renderToggleSwitch(
            'ml_lightbox_options[enable_pager]',
            false,
            __('Enable Pager', 'ml-slider-lightbox'),
            __('Minimal pagination dots for clean navigation without thumbnail previews.', 'ml-slider-lightbox'),
            true
        );
    }

    /**
     * Check if MetaSlider Gallery Pro plugin is active
     */
    private function isProPluginActive()
    {
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }
        return is_plugin_active('ml-slider-lightbox-pro/metaslider-lightbox-pro.php');
    }

    /**
     * Check if MetaSlider-specific settings should be hidden
     * Hide when: 
     * 1. MetaSlider is not active (no point showing MetaSlider settings)
     * 2. MetaSlider is active AND third-party lightbox plugin is active (conflict prevention)
     * 
     * @return bool True if settings should be hidden
     */
    private function shouldHideMetaSliderSettings()
    {
        if (!$this->isMetasliderActive()) {
            return true;
        }
        foreach ($this->supported_plugins as $name => $plugin) {
            if (isset($plugin['built_in']) && $plugin['built_in']) {
                continue;
            }
            
            if ($this->checkIfPluginIsActive($name, $plugin['location'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a Pro settings section has registered fields
     */
    private function hasProSettingsFields($section_id)
    {
        global $wp_settings_fields;
        return !empty($wp_settings_fields['metaslider_lightbox_settings'][$section_id]);
    }

    /**
     * Pro section callback methods matching base plugin style
     */
    private function renderProZoomSection()
    {
        echo '<p>' . __('Professional zoom and image interaction controls for enhanced user experience.', 'ml-slider-lightbox') . '</p>';
    }

    private function renderProMediaSection()
    {
        echo '<p>' . __('Enhanced media handling and video playback features for professional galleries.', 'ml-slider-lightbox') . '</p>';
    }

    private function renderProSocialSection()
    {
        echo '<p>' . __('Social sharing and navigation enhancements to increase engagement.', 'ml-slider-lightbox') . '</p>';
    }

    private function renderProAdvancedSection()
    {
        echo '<p>' . __('Advanced functionality including URL management and automation features.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Generate Pro upgrade lock icon HTML (following MetaSlider pattern)
     *
     * @param string $text Custom tooltip text
     * @return string HTML for lock icon link
     */
    private function renderProLockIcon($text = '')
    {
        if (empty($text)) {
            $text = __('This feature is available in MetaSlider Gallery Pro', 'ml-slider-lightbox');
        }

        $link = 'https://www.metaslider.com/upgrade-gallery/';
        return '<a class="dashicons dashicons-lock ml-pro-setting tipsy-tooltip-top" title="' .
            esc_attr($text) . '" href="' .
            esc_url($link) . '" target="_blank" rel="noopener"></a>';
    }

    /**
     * Render a Pro feature "ad" - disabled toggle with lock icon
     *
     * @param string $title Feature title
     * @param string $description Feature description
     * @param bool $default_value Default state to display (checked/unchecked)
     * @param string $tooltip Custom tooltip text for lock icon
     */
    private function renderProFeatureAd($title, $description, $default_value = true, $tooltip = '')
    {
        ?>
        <div class="ml-toggle-card">
            <div class="ml-toggle-content">
                <h3 class="ml-toggle-title"><?php echo esc_html($title); ?></h3>
                <div class="ml-toggle-description"><?php echo esc_html($description); ?></div>
            </div>
            <div class="ml-toggle-control">
                <input type="checkbox" disabled />
                <span class="ml-toggle-switch"></span>
                <?php echo $this->renderProLockIcon($tooltip); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render Pro features section when Pro is NOT active
     * Shows "ads" for Pro features with disabled controls and lock icons
     */
    private function renderProFeaturesAds()
    {
        if ($this->isProPluginActive()) {
            return; // Don't show ads if Pro is active
        }
        ?>

        <!-- Enhanced Zoom & Controls (Pro) -->
        <h2><?php echo esc_html(__('Enhanced Zoom & Controls', 'ml-slider-lightbox')); ?></h2>
        <p><?php _e('Professional zoom and image interaction controls for enhanced user experience.', 'ml-slider-lightbox'); ?></p>
        <table class="form-table ml-pro-features-table" role="presentation">
            <?php
            $this->renderProFeatureAd(
                __('Enable Zoom', 'ml-slider-lightbox'),
                __('Allow users to zoom in and out with double-click, mouse wheel, and zoom controls.', 'ml-slider-lightbox'),
                true,
                __('Zoom controls are available in MetaSlider Gallery Pro', 'ml-slider-lightbox')
            );

            $this->renderProFeatureAd(
                __('Enable Fullscreen', 'ml-slider-lightbox'),
                __('Native HTML5 fullscreen mode for immersive image viewing.', 'ml-slider-lightbox'),
                true,
                __('Fullscreen mode is available in MetaSlider Gallery Pro', 'ml-slider-lightbox')
            );

            $this->renderProFeatureAd(
                __('Enable Rotate', 'ml-slider-lightbox'),
                __('Rotate images clockwise/anticlockwise and flip horizontal/vertical.', 'ml-slider-lightbox'),
                true,
                __('Image rotation is available in MetaSlider Gallery Pro', 'ml-slider-lightbox')
            );
            ?>
        </table>

        <!-- Social & Navigation (Pro) -->
        <h2><?php echo esc_html(__('Social & Navigation', 'ml-slider-lightbox')); ?></h2>
        <p><?php _e('Social sharing and navigation enhancements to increase engagement.', 'ml-slider-lightbox'); ?></p>
        <table class="form-table ml-pro-features-table" role="presentation">
            <?php
            $this->renderProFeatureAd(
                __('Enable Share', 'ml-slider-lightbox'),
                __('Built-in social media sharing for Facebook, Twitter, and Pinterest.', 'ml-slider-lightbox'),
                true,
                __('Social sharing is available in MetaSlider Gallery Pro', 'ml-slider-lightbox')
            );

            $this->renderProFeatureAd(
                __('Enable Pager', 'ml-slider-lightbox'),
                __('Minimal pagination dots instead of thumbnails for a cleaner interface.', 'ml-slider-lightbox'),
                false,
                __('Pager pagination is available in MetaSlider Gallery Pro', 'ml-slider-lightbox')
            );
            ?>
        </table>

        <!-- Advanced Features (Pro) -->
        <h2><?php echo esc_html(__('Advanced Features', 'ml-slider-lightbox')); ?></h2>
        <p><?php _e('Advanced functionality including URL management and automation features.', 'ml-slider-lightbox'); ?></p>
        <table class="form-table ml-pro-features-table" role="presentation">
            <?php
            $this->renderProFeatureAd(
                __('Enable Unique Image URLs', 'ml-slider-lightbox'),
                __('Generate unique URLs for each gallery image with browser back/forward navigation.', 'ml-slider-lightbox'),
                true,
                __('Unique Image URLs are available in MetaSlider Gallery Pro', 'ml-slider-lightbox')
            );

            $this->renderProFeatureAd(
                __('Enable Autoplay', 'ml-slider-lightbox'),
                __('Automatic slideshow with timing controls and progress bar.', 'ml-slider-lightbox'),
                true,
                __('Autoplay is available in MetaSlider Gallery Pro', 'ml-slider-lightbox')
            );
            ?>
        </table>
        <?php
    }

    /**
     * Render upgrade comparison table (following MetaSlider pattern)
     */
    private function renderUpgradeComparisonTable()
    {
        ?>
        <div id="ml-lightbox-upgrade-ui" class="ml-upgrade-content">
            <table id="ml-comparison-chart" class="ml-feat-table">
                <thead>
                    <tr>
                        <th class="ml-dark-blue text-white"><?php _e('Features', 'ml-slider-lightbox'); ?></th>
                        <th class="ml-orange text-white"><?php _e('Free', 'ml-slider-lightbox'); ?></th>
                        <th class="ml-orange text-white"><?php _e('Pro', 'ml-slider-lightbox'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td></td>
                        <td class="ml-installed-status"><?php _e('Installed', 'ml-slider-lightbox'); ?></td>
                        <td class="ml-installed-status">
                            <a href="https://www.metaslider.com/upgrade-gallery/" target="_blank" rel="noopener" class="ml-upgrade-link">
                                <?php _e('Upgrade now', 'ml-slider-lightbox'); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Automatic lightbox for images', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Automatically enable lightbox for all images on your site.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot available"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('MetaSlider integration', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Seamless integration with MetaSlider slideshows.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot available"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('WordPress gallery support', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Support for WordPress native galleries and blocks.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot available"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Appearance customization', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Customize colors, opacity, and button styles.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot available"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Zoom controls', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Allow users to zoom in and out with double-click, mouse wheel, and zoom controls.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot unavailable"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Fullscreen mode', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Native HTML5 fullscreen for immersive image viewing.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot unavailable"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Rotate and flip images', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Rotate images clockwise/anticlockwise and flip horizontal/vertical.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot unavailable"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Social media sharing', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Built-in sharing for Facebook, Twitter, and Pinterest.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot unavailable"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Enable Unique Image URLs', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Generate unique URLs for each gallery image with browser back/forward navigation.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot unavailable"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Autoplay slideshow', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Automatic slideshow with timing controls and progress bar.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot unavailable"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Pager navigation', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Minimal pagination dots instead of thumbnails for a cleaner interface.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot unavailable"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                    <tr>
                        <td>
                            <h4><?php _e('Premium support', 'ml-slider-lightbox'); ?></h4>
                            <p><?php _e('Have your specific queries addressed directly by our experts.', 'ml-slider-lightbox'); ?></p>
                        </td>
                        <td><div class="ml-dot unavailable"></div></td>
                        <td><div class="ml-dot available"></div></td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr>
                        <td></td>
                        <td class="ml-installed-status"><?php _e('Installed', 'ml-slider-lightbox'); ?></td>
                        <td class="ml-installed-status">
                            <a href="https://www.metaslider.com/upgrade-gallery/" target="_blank" rel="noopener" class="ml-upgrade-link">
                                <?php _e('Upgrade now', 'ml-slider-lightbox'); ?>
                            </a>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php
    }

    private function getPluginOptions()
    {
        $defaults = array(
            'lightbox_mode' => 'auto',
            'default_enabled' => false,
            'load_assets_globally' => false,
            'background_color' => '#000000',
            'button_color' => '#000000',
            'button_text_color' => '#ffffff',
            'button_hover_color' => '#f0f0f0',
            'button_hover_text_color' => '#000000',
            'icon_color' => '#ffffff',
            'icon_hover_color' => '#000000',
            'arrow_color' => '#ffffff',
            'arrow_hover_color' => '#000000',
            'close_icon_color' => '#ffffff',
            'close_icon_hover_color' => '#000000',
            'toolbar_icon_color' => '#ffffff',
            'toolbar_icon_hover_color' => '#000000',
            'background_opacity' => '0.9',
            'autoplay_progress_bar_color' => '#a90707',
        );

        $general_options = $this->getCachedGeneralOptions();
        $metaslider_options = $this->getCachedMetaSliderOptions();

        $saved_options = array_merge($general_options, $metaslider_options);

        return wp_parse_args($saved_options, $defaults);
    }

    /**
     * Handle video slides for lightbox
     *
     * @param array $attributes
     * @param array $slide
     * @param int $slider_id
     * @return array
     */
    /**
     * Helper method to add lightbox attributes when button is disabled
     *
     * @param array $attributes
     * @param array $slide  
     * @param int $slider_id
     * @param string $media_url The URL for data-src attribute
     * @param string $media_type Type of media (image, video, etc.)
     * @return array
     */
    private function addDirectClickLightboxAttributes($attributes, $slide, $slider_id, $media_url, $media_type = 'image')
    {
        $options = $this->getCachedMetaSliderOptions();
        $showButton = isset($options['show_lightbox_button']) ? $options['show_lightbox_button'] : true;

        $thirdPartyActive = false;
        foreach ($this->supported_plugins as $name => $plugin) {
            if ($this->checkIfPluginIsActive($name, $plugin['location'])) {
                if (isset($plugin['built_in']) && $plugin['built_in']) {
                    continue;
                }
                $thirdPartyActive = true;
                break;
            }
        }

        if ($thirdPartyActive) {
            return $attributes;
        }

        if (empty($attributes['href'])) {
            $attributes['href'] = $media_url;
        }

        $attributes['data-src'] = $media_url;
        
        $showArrows = isset($options['show_arrows']) ? $options['show_arrows'] : true;
        $showThumbnails = isset($options['show_thumbnails']) ? $options['show_thumbnails'] : true;
        
        $attributes['data-lightbox-arrows'] = $showArrows ? 'true' : 'false';
        $attributes['data-lightbox-thumbnails'] = $showThumbnails ? 'true' : 'false';
        
        if (isset($slide['caption'])) {
            $attributes['data-caption'] = $slide['caption'];
        }
        
        if ($media_type === 'video') {
            $attributes['data-video'] = 'true';
            if (strpos($media_url, 'youtube.com') !== false || strpos($media_url, 'youtu.be') !== false) {
                $attributes['data-lg-size'] = '1920-1080';
            } elseif (strpos($media_url, 'vimeo.com') !== false) {
                $attributes['data-lg-size'] = '1920-1080';  
            }
        } else {
            $thumbnail_id = ('attachment' === get_post_type($slide['id'])) ? $slide['id'] : get_post_thumbnail_id($slide['id']);
            $image_meta = wp_get_attachment_metadata($thumbnail_id);
            if ($image_meta && isset($image_meta['width']) && isset($image_meta['height'])) {
                $attributes['data-lg-size'] = $image_meta['width'] . '-' . $image_meta['height'];
            } else {
                $image_size = wp_getimagesize($media_url);
                if ($image_size && isset($image_size[0]) && isset($image_size[1])) {
                    $attributes['data-lg-size'] = $image_size[0] . '-' . $image_size[1];
                } else {
                    $attributes['data-lg-size'] = '1200-800';
                }
            }
        }
        
        return $attributes;
    }

    public function handleVideoSlides($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if ($this->isLightboxEnabled($enabled)) {
            $video_url = isset($slide['url']) ? $slide['url'] : '';

            if (!empty($video_url) && $this->isVideoUrl($video_url)) {
                $attributes = $this->addDirectClickLightboxAttributes($attributes, $slide, $slider_id, $video_url, 'video');
                
                $attributes['data-video-url'] = $video_url;
                $attributes['data-slider-id'] = $slider_id;
                $attributes['class'] = (isset($attributes['class']) ? $attributes['class'] . ' ' : '') . 'ml-video-slide';
            }
        }

        return $attributes;
    }

    public function handleVimeoVideoSlide($attributes, $slide, $slider_id)
    {
        return $this->handleVideoSlides($attributes, $slide, $slider_id);
    }

    public function handleYoutubeVideoSlide($attributes, $slide, $slider_id)
    {
        return $this->handleVideoSlides($attributes, $slide, $slider_id);
    }

    public function handleExternalVideoSlide($attributes, $slide, $slider_id)
    {
        return $this->handleVideoSlides($attributes, $slide, $slider_id);
    }

    public function handleCustomHtmlSlide($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if ($this->isLightboxEnabled($enabled)) {
            $thumbnail_id = get_post_thumbnail_id($slide['id']);
            if ($thumbnail_id) {
                $image_url = wp_get_attachment_url($thumbnail_id);
                if ($image_url) {
                    $attributes = $this->addDirectClickLightboxAttributes($attributes, $slide, $slider_id, $image_url, 'image');
                }
            }
        }
        
        return $attributes;
    }

    public function handleImageFolderSlide($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if ($this->isLightboxEnabled($enabled)) {
            $thumbnail_id = ('attachment' === get_post_type($slide['id'])) ? $slide['id'] : get_post_thumbnail_id($slide['id']);
            if ($thumbnail_id) {
                $image_url = wp_get_attachment_url($thumbnail_id);
                if ($image_url) {
                    $attributes = $this->addDirectClickLightboxAttributes($attributes, $slide, $slider_id, $image_url, 'image');
                }
            }
        }
        
        return $attributes;
    }

    /**
     * Handle external image slides
     *
     * @param array $attributes
     * @param array $slide
     * @param int $slider_id
     * @return array
     */
    public function handleExternalImageSlide($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if ($this->isLightboxEnabled($enabled)) {
            $image_url = null;
            
            if (isset($slide['url']) && !empty($slide['url'])) {
                $image_url = $slide['url'];
            } elseif (isset($slide['id']) && $slide['id']) {
                $external_url = get_post_meta($slide['id'], 'ml-slider_url', true);
                if ($external_url) {
                    $image_url = $external_url;
                } else {
                    $thumbnail_id = get_post_thumbnail_id($slide['id']);
                    if ($thumbnail_id) {
                        $image_url = wp_get_attachment_url($thumbnail_id);
                    }
                }
            }
            
            if ($image_url) {
                $attributes = $this->addDirectClickLightboxAttributes($attributes, $slide, $slider_id, $image_url, 'image');
            }
        }
        
        return $attributes;
    }

    /**
     * Handle postfeed slide lightbox attributes
     *
     * @param array $attributes
     * @param array $slide
     * @param int $slider_id
     * @return array
     */
    public function handlePostfeedSlide($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if ($this->isLightboxEnabled($enabled)) {
            $thumbnail_id = get_post_thumbnail_id($slide['id']);
            if ($thumbnail_id) {
                $image_url = wp_get_attachment_url($thumbnail_id);
                if ($image_url) {
                    $attributes = $this->addDirectClickLightboxAttributes($attributes, $slide, $slider_id, $image_url, 'image');
                }
            }
        }
        
        return $attributes;
    }

    /**
     * Handle layer slide lightbox attributes
     *
     * @param array $attributes
     * @param array $slide
     * @param int $slider_id
     * @return array
     */
    public function handleLayerSlide($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if ($this->isLightboxEnabled($enabled)) {
            $thumbnail_id = ('attachment' === get_post_type($slide['id'])) ? $slide['id'] : get_post_thumbnail_id($slide['id']);
            if ($thumbnail_id) {
                $image_url = wp_get_attachment_url($thumbnail_id);
                if ($image_url) {
                    $attributes = $this->addDirectClickLightboxAttributes($attributes, $slide, $slider_id, $image_url, 'image');
                }
            }
        }
        
        return $attributes;
    }

    /**
     * Generic slide handler for unknown slide types
     *
     * @param array $attributes
     * @param array $slide
     * @param int $slider_id
     * @return array
     */
    public function handleGenericSlide($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if ($this->isLightboxEnabled($enabled)) {
            $thumbnail_id = ('attachment' === get_post_type($slide['id'])) ? $slide['id'] : get_post_thumbnail_id($slide['id']);
            if ($thumbnail_id) {
                $image_url = wp_get_attachment_url($thumbnail_id);
                if ($image_url) {
                    $attributes = $this->addDirectClickLightboxAttributes($attributes, $slide, $slider_id, $image_url, 'image');
                }
            }
        }

        return $attributes;
    }

    /**
     * Add common lightbox attributes to any slide type
     *
     * @param array $attributes
     * @param array $slide
     * @param int $slider_id
     * @param string $slide_type
     * @return array
     */
    private function addLightboxAttributes($attributes, $slide, $slider_id, $slide_type)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if (!$this->isLightboxEnabled($enabled)) {
            return $attributes;
        }

        $metaslider_options = $this->getCachedMetaSliderOptions();
        $show_arrows = isset($metaslider_options['show_arrows']) ? $metaslider_options['show_arrows'] : true;
        $show_thumbnails = isset($metaslider_options['show_thumbnails']) ? $metaslider_options['show_thumbnails'] : true;

        $attributes['data-lightbox-arrows'] = $show_arrows ? 'true' : 'false';
        $attributes['data-lightbox-thumbnails'] = $show_thumbnails ? 'true' : 'false';
        $attributes['data-slide-type'] = $slide_type;

        $attributes['class'] = (isset($attributes['class']) ? $attributes['class'] . ' ' : '') . 'ml-lightbox-slide';

        return $attributes;
    }

    private function isVideoUrl($url)
    {
        if (empty($url)) {
            return false;
        }

        if (preg_match('/^.*(youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/i', $url)) {
            return true;
        }

        if (preg_match('/^.*(vimeo\.com\/)((channels\/[A-z]+\/)|(groups\/[A-z]+\/videos\/)|(staff\/picks\/)|(videos\/)|)([0-9]+)/i', $url)) {
            return true;
        }

        $video_extensions = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'wmv', 'flv', 'm4v'];
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        return in_array($extension, $video_extensions);
    }

    public function lightboxShortcode($atts)
    {
        return '';
    }

    public function galleryShortcode($atts)
    {
        return '';
    }

    public function autoDetectGalleries($content)
    {
        return $content;
    }

    private function setupContentDetection()
    {
        $options = $this->getCachedGeneralOptions();
        
        add_filter('the_content', array($this, 'enhancePostContent'), 99);
        
        if (isset($options['enable_on_widgets']) && $options['enable_on_widgets']) {
            add_filter('dynamic_sidebar_params', array($this, 'enhanceWidgetContent'));
        }
        
        if (isset($options['enable_galleries']) && $options['enable_galleries']) {
            add_filter('post_gallery', array($this, 'enhanceWordPressGallery'), 10, 3);
            add_filter('render_block', array($this, 'enhanceGutenbergGalleryBlock'), 10, 2);
        }
        
        if (isset($options['enable_featured_images']) && $options['enable_featured_images']) {
            add_filter('post_thumbnail_html', array($this, 'enhanceFeaturedImage'), 10, 5);
            add_filter('render_block', array($this, 'enhanceFeaturedImageBlock'), 10, 2);
        }

        if (isset($options['enable_videos']) && $options['enable_videos']) {
            add_filter('render_block', array($this, 'enhanceVideoBlock'), 10, 2);
        }
    }

    private function setupWooCommerceIntegration()
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('after_setup_theme', array($this, 'disableWooCommerceLightbox'), 99);
        add_filter('woocommerce_single_product_image_thumbnail_html', array($this, 'enhanceWooCommerceGalleryImage'), 10, 2);
        add_action('wp_footer', array($this, 'enqueueWooCommerceScript'));
    }

    public function disableWooCommerceLightbox()
    {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $manual_options = get_option('metaslider_lightbox_manual_options', array());
        $exclude_post_types = isset($manual_options['exclude_post_types']) ? $manual_options['exclude_post_types'] : array();

        if (in_array('product', $exclude_post_types)) {
            return;
        }

        remove_theme_support('wc-product-gallery-lightbox');

        $metaslider_options = $this->getCachedMetaSliderOptions();
        $show_button = isset($metaslider_options['show_lightbox_button']) ? $metaslider_options['show_lightbox_button'] : true;

        if (!$show_button) {
            remove_theme_support('wc-product-gallery-zoom');
            add_filter('woocommerce_single_product_zoom_enabled', '__return_false');
        }
    }

    public function enhanceWooCommerceGalleryImage($html, $attachment_id)
    {
        if ($this->shouldExcludePage()) {
            return $html;
        }

        if (!is_product()) {
            return $html;
        }

        $manual_options = get_option('metaslider_lightbox_manual_options', array());
        $exclude_post_types = isset($manual_options['exclude_post_types']) ? $manual_options['exclude_post_types'] : array();

        if (in_array('product', $exclude_post_types)) {
            return $html;
        }

        $full_size_url = wp_get_attachment_url($attachment_id);
        $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'woocommerce_thumbnail');

        if (!$full_size_url) {
            return $html;
        }

        $metaslider_options = $this->getCachedMetaSliderOptions();
        $show_button = isset($metaslider_options['show_lightbox_button']) ? $metaslider_options['show_lightbox_button'] : true;

        if (!$show_button) {
            $html = preg_replace_callback('/<a([^>]*)>/', function($matches) use ($full_size_url, $thumbnail_url) {
                $attributes = $matches[1];

                $attributes .= ' data-src="' . esc_url($full_size_url) . '"';
                $attributes .= ' data-thumb="' . esc_url($thumbnail_url) . '"';
                $attributes .= ' class="ml-woo-product-image"';

                return '<a' . $attributes . '>';
            }, $html, 1);
        } else {
            $html = preg_replace_callback('/<img([^>]*)>/', function($matches) use ($full_size_url, $thumbnail_url) {
                $img_attributes = $matches[1];
                return '<img' . $img_attributes . ' data-full-url="' . esc_url($full_size_url) . '" data-thumb-url="' . esc_url($thumbnail_url) . '">';
            }, $html, 1);
        }

        return $html;
    }

    public function enqueueWooCommerceScript()
    {
        if (!class_exists('WooCommerce') || !is_product()) {
            return;
        }

        if ($this->shouldExcludePage()) {
            return;
        }

        $manual_options = get_option('metaslider_lightbox_manual_options', array());
        $exclude_post_types = isset($manual_options['exclude_post_types']) ? $manual_options['exclude_post_types'] : array();

        if (in_array('product', $exclude_post_types)) {
            return;
        }

        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $(document).on('wc-product-gallery-after-init', function() {
                if (typeof window.mlInitWooCommerceGallery === 'function') {
                    window.mlInitWooCommerceGallery();
                }
            });
        });
        </script>
        <?php
    }

    public function enhancePostContent($content)
    {
        if (is_admin() || is_feed()) {
            return $content;
        }

        if (!is_main_query() || !in_the_loop()) {
            $content = $this->processManualLightboxOptions($content);
            return $content;
        }

        $content = $this->enhanceContentImages($content);

        return $content;
    }

    public function enhanceWidgetContent($params)
    {
        global $wp_registered_widgets;
        $widget_id = $params[0]['widget_id'];
        
        if (isset($wp_registered_widgets[$widget_id]['callback'])) {
            $original_callback = $wp_registered_widgets[$widget_id]['callback'];
            $wp_registered_widgets[$widget_id]['callback'] = function() use ($original_callback, $params) {
                ob_start();
                if (is_callable($original_callback)) {
                    call_user_func_array($original_callback, func_get_args());
                }
                $widget_content = ob_get_contents();
                ob_end_clean();
                
                $enhanced_content = $this->enhanceWidgetImages($widget_content);
                $enhanced_content = $this->enhanceWidgetVideos($enhanced_content);
                echo $enhanced_content;
            };
        }
        
        return $params;
    }

    private function enhanceWidgetImages($content)
    {

        $protected_links = array();
        $placeholder_index = 0;
        
        $content = preg_replace_callback('/<a[^>]*>.*?<\/a>/is', function($matches) use (&$protected_links, &$placeholder_index) {
            $placeholder = '<!--LINK_PLACEHOLDER_' . $placeholder_index . '-->';
            $protected_links[$placeholder] = $matches[0];
            $placeholder_index++;
            return $placeholder;
        }, $content);
        
        $pattern = '/<img[^>]*src=["\']([^"\']*\.(jpg|jpeg|png|gif|webp|svg))["\'][^>]*>/i';
        
        $content = preg_replace_callback($pattern, function($matches) {
            $img_tag = $matches[0];
            $img_src = $matches[1];

            if (strpos($img_tag, 'data-ml-exclude="true"') !== false) {
                return $img_tag;
            }

            $full_size_url = $this->getFullSizeImageUrl($img_src, $img_tag);

            return '<a href="' . esc_url($full_size_url) . '" data-src="' . esc_url($full_size_url) . '" data-thumb="' . esc_url($img_src) . '" class="ml-widget-lightbox">' . $img_tag . '</a>';
        }, $content);
        
        foreach ($protected_links as $placeholder => $original_link) {
            $content = str_replace($placeholder, $original_link, $content);
        }
        
        return $content;
    }

    private function enhanceWidgetVideos($content)
    {
        if (strpos($content, 'ml-widget-video-lightbox') !== false || strpos($content, 'ml-lightbox-enabled') !== false) {
            return $content;
        }
        
        $protected_links = array();
        $placeholder_index = 0;
        
        $content = preg_replace_callback('/<a[^>]*>.*?<\/a>/is', function($matches) use (&$protected_links, &$placeholder_index) {
            $placeholder = '<!--VIDEO_LINK_PLACEHOLDER_' . $placeholder_index . '-->';
            $protected_links[$placeholder] = $matches[0];
            $placeholder_index++;
            return $placeholder;
        }, $content);

        $content = preg_replace_callback('/<figure([^>]*class=["\'][^"\']*wp-block-embed[^"\']*["\'][^>]*)>(.*?<iframe[^>]*src=["\']https?:\/\/(?:www\.)?youtube\.com\/embed\/[a-zA-Z0-9_-]{11}[^"\']*["\'][^>]*><\/iframe>.*?)<\/figure>/s', function($matches) {
            $figure_attrs = $matches[1];
            $figure_content = $matches[2];

            if (strpos($figure_attrs, 'ml-lightbox-enabled') !== false) {
                return $matches[0];
            }

            if (preg_match('/class=["\']([^"\']*)["\']/', $figure_attrs, $class_matches)) {
                $existing_classes = $class_matches[1];
                $new_attrs = str_replace($class_matches[0], 'class="' . $existing_classes . ' ml-lightbox-enabled"', $figure_attrs);
            } else {
                $new_attrs = $figure_attrs . ' class="ml-lightbox-enabled"';
            }

            return '<figure' . $new_attrs . '>' . $figure_content . '</figure>';
        }, $content);

        $youtube_url_pattern = '/(?:^|\s)(https?:\/\/(?:www\.)?(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]{11})[\S]*)/i';
        $content = preg_replace_callback($youtube_url_pattern, function($matches) {
            $full_url = $matches[1];
            $youtube_id = $matches[2];

            return ' <a href="' . esc_url($full_url) . '" class="ml-lightbox-enabled">' . esc_html($full_url) . '</a>';
        }, $content);

        $content = preg_replace_callback('/<figure([^>]*class=["\'][^"\']*wp-block-embed[^"\']*["\'][^>]*)>(.*?<iframe[^>]*src=["\']https?:\/\/player\.vimeo\.com\/video\/\d+[^"\']*["\'][^>]*><\/iframe>.*?)<\/figure>/s', function($matches) {
            $figure_attrs = $matches[1];
            $figure_content = $matches[2];

            if (strpos($figure_attrs, 'ml-lightbox-enabled') !== false) {
                return $matches[0];
            }

            if (preg_match('/class=["\']([^"\']*)["\']/', $figure_attrs, $class_matches)) {
                $existing_classes = $class_matches[1];
                $new_attrs = str_replace($class_matches[0], 'class="' . $existing_classes . ' ml-lightbox-enabled"', $figure_attrs);
            } else {
                $new_attrs = $figure_attrs . ' class="ml-lightbox-enabled"';
            }

            return '<figure' . $new_attrs . '>' . $figure_content . '</figure>';
        }, $content);

        $vimeo_url_pattern = '/(?:^|\s)(https?:\/\/(?:www\.)?vimeo\.com\/(\d+)[\S]*)/i';
        $content = preg_replace_callback($vimeo_url_pattern, function($matches) {
            $full_url = $matches[1];

            return ' <a href="' . esc_url($full_url) . '" class="ml-lightbox-enabled">' . esc_html($full_url) . '</a>';
        }, $content);

        $video_pattern = '/(?:^|\s)(https?:\/\/[^\s]+\.(mp4|webm|ogg)(?:\?[^\s]*)?)/i';
        $content = preg_replace_callback($video_pattern, function($matches) {
            $full_url = $matches[1];

            return ' <a href="' . esc_url($full_url) . '" class="ml-lightbox-enabled">' . esc_html($full_url) . '</a>';
        }, $content);

        foreach ($protected_links as $placeholder => $original_link) {
            $content = str_replace($placeholder, $original_link, $content);
        }
        
        return $content;
    }

    /**
     * Check if current page/post should be excluded from lightbox processing
     */
    private function shouldExcludePage()
    {
        $options = $this->getCachedGeneralOptions();
        $processing_mode = isset($options['content_processing_mode']) ? $options['content_processing_mode'] : 'include';

        $pages = isset($options['exclude_pages']) ? $options['exclude_pages'] : array();
        $posts = isset($options['exclude_posts']) ? $options['exclude_posts'] : array();
        $post_types = isset($options['exclude_post_types']) ? $options['exclude_post_types'] : array();

        global $post;
        if (!$post) {
            $has_filters = !empty($pages) || !empty($posts) || !empty($post_types);

            foreach ($options as $key => $value) {
                if (strpos($key, 'exclude_cpt_') === 0 && is_array($value) && !empty($value)) {
                    $has_filters = true;
                    break;
                }
            }

            if (!$has_filters) {
                return false;
            }

            return ($processing_mode === 'include') ? true : false;
        }

        $is_in_filtered_list = false;

        if (!empty($post_types) && in_array($post->post_type, $post_types)) {
            $is_in_filtered_list = true;
        } elseif ($post->post_type === 'page' && in_array($post->ID, $pages)) {
            $is_in_filtered_list = true;
        } elseif ($post->post_type === 'post' && in_array($post->ID, $posts)) {
            $is_in_filtered_list = true;
        } else {
            $cpt_key = 'exclude_cpt_' . $post->post_type;
            if (isset($options[$cpt_key]) && is_array($options[$cpt_key]) && in_array($post->ID, $options[$cpt_key])) {
                $is_in_filtered_list = true;
            }
        }

        $has_filters = !empty($pages) || !empty($posts) || !empty($post_types);
        if (!$has_filters) {
            foreach ($options as $key => $value) {
                if (strpos($key, 'exclude_cpt_') === 0 && is_array($value) && !empty($value)) {
                    $has_filters = true;
                    break;
                }
            }
        }

        if (!$has_filters) {
            return false;
        }

        if ($processing_mode === 'include') {
            return !$is_in_filtered_list;
        } else {
            return $is_in_filtered_list;
        }
    }

    /**
     * Check if Manual processing should be excluded for the current post type
     * OPTION 3 implementation - uses same post detection as shouldExcludePage()
     *
     * @return bool True if Manual processing should be skipped, false otherwise
     */
    private function shouldExcludeManualForPostType()
    {
        $manual_options = get_option('metaslider_lightbox_manual_options', array());
        $excluded_post_types = isset($manual_options['manual_exclude_post_types']) && is_array($manual_options['manual_exclude_post_types'])
            ? $manual_options['manual_exclude_post_types']
            : array();

        if (empty($excluded_post_types)) {
            return false;
        }

        $current_post_type = null;

        global $post;
        if ($post && isset($post->post_type)) {
            $current_post_type = $post->post_type;
        }

        if (!$current_post_type && is_singular()) {
            $queried_object = get_queried_object();
            if ($queried_object && isset($queried_object->post_type)) {
                $current_post_type = $queried_object->post_type;
            }
        }

        if (!$current_post_type) {
            $post_id = get_the_ID();
            if ($post_id) {
                $current_post_type = get_post_type($post_id);
            }
        }

        if (!$current_post_type) {
            return false;
        }

        return in_array($current_post_type, $excluded_post_types);
    }

    /**
     * Filter images based on CSS selectors
     *
     * Note: CSS selectors ALWAYS work as exclusion, regardless of processing mode.
     * This makes sense because CSS selectors are conceptually about filtering OUT
     * unwanted elements (ads, thumbnails, etc.), even in inclusion mode.
     */
    private function removeExcludedElements($content)
    {
        static $cached_selectors = null;

        if ($cached_selectors === null) {
            $options = $this->getCachedGeneralOptions();
            $css_selectors = isset($options['exclude_css_selectors']) ? trim($options['exclude_css_selectors']) : '';
            $cached_selectors = empty($css_selectors) ? array() : array_filter(array_map('trim', explode("\n", $css_selectors)));
        }

        if (empty($cached_selectors)) {
            return $content;
        }

        foreach ($cached_selectors as $selector) {
            if (empty($selector)) {
                continue;
            }

            $content = $this->removeElementsBySelector($content, $selector);
        }

        return $content;
    }

    private function removeElementsBySelector($content, $selector)
    {
        $selector = trim($selector);
        
        if (strpos($selector, '.') === 0) {
            $class_name = trim(str_replace('.', '', $selector));
            return $this->removeImagesByClass($content, $class_name);
        } elseif (strpos($selector, '#') === 0) {
            $id_name = trim(str_replace('#', '', $selector));
            return $this->removeImagesById($content, $id_name);
        } elseif (strpos($selector, ' ') !== false) {
            $parts = explode(' ', $selector);
            if (count($parts) === 2 && trim($parts[1]) === 'img') {
                $parent_selector = trim($parts[0]);
                return $this->removeImagesByParentSelector($content, $parent_selector);
            }
        }
        
        return $content;
    }

    private function removeImagesByClass($content, $class_name)
    {
        $escaped_class = preg_quote($class_name, '/');
        
        $combined_pattern = '/(?:<img[^>]*class="[^"]*' . $escaped_class . '[^"]*"[^>]*>|<(figure|div)[^>]*class="[^"]*' . $escaped_class . '[^"]*"[^>]*>.*?<\/\1>)/is';
        
        $content = preg_replace_callback($combined_pattern, function($matches) {
            return $this->commentOutImages($matches[0]);
        }, $content);
        
        return $content;
    }

    private function removeImagesById($content, $id_name)
    {
        $escaped_id = preg_quote($id_name, '/');
        
        $combined_pattern = '/(?:<img[^>]*id="' . $escaped_id . '"[^>]*>|<(figure|div)[^>]*id="' . $escaped_id . '"[^>]*>.*?<\/\1>)/is';
        
        $content = preg_replace_callback($combined_pattern, function($matches) {
            return $this->commentOutImages($matches[0]);
        }, $content);
        
        return $content;
    }

    private function removeImagesByParentSelector($content, $parent_selector)
    {
        if (strpos($parent_selector, '.') === 0) {
            $class_name = trim(str_replace('.', '', $parent_selector));
            $escaped_class = preg_quote($class_name, '/');
            
            $patterns = array(
                '/<figure[^>]*class="[^"]*' . $escaped_class . '[^"]*"[^>]*>.*?<img[^>]*>.*?<\/figure>/is',
                '/<div[^>]*class="[^"]*' . $escaped_class . '[^"]*"[^>]*>.*?<img[^>]*>.*?<\/div>/is',
            );
            
            foreach ($patterns as $pattern) {
                $content = preg_replace_callback($pattern, function($matches) {
                    return $this->commentOutImages($matches[0]);
                }, $content);
            }
        } elseif (strpos($parent_selector, '#') === 0) {
            $id_name = trim(str_replace('#', '', $parent_selector));
            $escaped_id = preg_quote($id_name, '/');
            
            $patterns = array(
                '/<figure[^>]*id="' . $escaped_id . '"[^>]*>.*?<img[^>]*>.*?<\/figure>/is',
                '/<div[^>]*id="' . $escaped_id . '"[^>]*>.*?<img[^>]*>.*?<\/div>/is',
            );
            
            foreach ($patterns as $pattern) {
                $content = preg_replace_callback($pattern, function($matches) {
                    return $this->commentOutImages($matches[0]);
                }, $content);
            }
        }
        
        return $content;
    }

    /**
     * Keep only elements that match the given selectors (inclusion mode)
     */
    private function keepOnlyMatchingElements($content, $selectors)
    {
        $matching_elements = array();

        foreach ($selectors as $selector) {
            if (empty($selector)) {
                continue;
            }

            $matching_elements = array_merge(
                $matching_elements,
                $this->findElementsBySelector($content, $selector)
            );
        }

        if (empty($matching_elements)) {
            return preg_replace('/<img\b([^>]*)>/i', '<img$1 data-ml-exclude="true">', $content);
        }

        $content = preg_replace('/<img\b([^>]*)>/i', '<img$1 data-ml-exclude="true">', $content);

        foreach ($matching_elements as $element) {
            $excluded_element = str_replace('<img', '<img data-ml-exclude="true"', $element);
            $included_element = str_replace(' data-ml-exclude="true"', '', $excluded_element);
            $content = str_replace($excluded_element, $included_element, $content);
        }

        return $content;
    }

    /**
     * Find elements that match a given selector
     */
    private function findElementsBySelector($content, $selector)
    {
        $selector = trim($selector);
        $elements = array();

        if (strpos($selector, '.') === 0) {
            $class_name = trim(str_replace('.', '', $selector));
            $elements = array_merge($elements, $this->findImagesByClass($content, $class_name));
        } elseif (strpos($selector, '#') === 0) {
            $id_name = trim(str_replace('#', '', $selector));
            $elements = array_merge($elements, $this->findImagesById($content, $id_name));
        } elseif (strpos($selector, ' ') !== false) {
            $parts = explode(' ', $selector);
            if (count($parts) === 2 && trim($parts[1]) === 'img') {
                $parent_selector = trim($parts[0]);
                $elements = array_merge($elements, $this->findImagesByParentSelector($content, $parent_selector));
            }
        }

        return $elements;
    }

    /**
     * Find images by class name
     */
    private function findImagesByClass($content, $class_name)
    {
        $escaped_class = preg_quote($class_name, '/');
        $elements = array();

        preg_match_all('/<img[^>]*class="[^"]*' . $escaped_class . '[^"]*"[^>]*>/i', $content, $matches);
        $elements = array_merge($elements, $matches[0]);

        preg_match_all('/<(figure|div)[^>]*class="[^"]*' . $escaped_class . '[^"]*"[^>]*>.*?<\/\1>/is', $content, $matches);
        foreach ($matches[0] as $container) {
            preg_match_all('/<img[^>]*>/i', $container, $img_matches);
            $elements = array_merge($elements, $img_matches[0]);
        }

        return $elements;
    }

    /**
     * Find images by ID
     */
    private function findImagesById($content, $id_name)
    {
        $escaped_id = preg_quote($id_name, '/');
        $elements = array();

        preg_match_all('/<img[^>]*id="' . $escaped_id . '"[^>]*>/i', $content, $matches);
        $elements = array_merge($elements, $matches[0]);

        preg_match_all('/<(figure|div)[^>]*id="' . $escaped_id . '"[^>]*>.*?<\/\1>/is', $content, $matches);
        foreach ($matches[0] as $container) {
            preg_match_all('/<img[^>]*>/i', $container, $img_matches);
            $elements = array_merge($elements, $img_matches[0]);
        }

        return $elements;
    }

    /**
     * Find images by parent selector
     */
    private function findImagesByParentSelector($content, $parent_selector)
    {
        $elements = array();

        if (strpos($parent_selector, '.') === 0) {
            $class_name = trim(str_replace('.', '', $parent_selector));
            $escaped_class = preg_quote($class_name, '/');

            $patterns = array(
                '/<figure[^>]*class="[^"]*' . $escaped_class . '[^"]*"[^>]*>.*?<\/figure>/is',
                '/<div[^>]*class="[^"]*' . $escaped_class . '[^"]*"[^>]*>.*?<\/div>/is',
            );

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $content, $matches);
                foreach ($matches[0] as $container) {
                    preg_match_all('/<img[^>]*>/i', $container, $img_matches);
                    $elements = array_merge($elements, $img_matches[0]);
                }
            }
        } elseif (strpos($parent_selector, '#') === 0) {
            $id_name = trim(str_replace('#', '', $parent_selector));
            $escaped_id = preg_quote($id_name, '/');

            $patterns = array(
                '/<figure[^>]*id="' . $escaped_id . '"[^>]*>.*?<\/figure>/is',
                '/<div[^>]*id="' . $escaped_id . '"[^>]*>.*?<\/div>/is',
            );

            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $content, $matches);
                foreach ($matches[0] as $container) {
                    preg_match_all('/<img[^>]*>/i', $container, $img_matches);
                    $elements = array_merge($elements, $img_matches[0]);
                }
            }
        }

        return $elements;
    }

    private function commentOutImages($html)
    {
        return preg_replace('/<img\b([^>]*)>/i', '<img$1 data-ml-exclude="true">', $html);
    }

    private function processManualLightboxOptions($content)
    {
        if ($this->shouldExcludeManualForPostType()) {
            return $content;
        }

        $content = preg_replace_callback(
            '/<(figure|div|span|ul|section)([^>]*class="[^"]*ml-lightbox-enabled[^"]*"[^>]*)>(.*?)<\/\1>/is',
            function($matches) {
                $tag_name = $matches[1];
                $element_attributes = $matches[2];
                $element_content = $matches[3];

                if (strpos($element_attributes, 'ml-gallery-lightbox') !== false) {
                    return $matches[0];
                }

                $image_link_count = preg_match_all('/<a[^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*>/i', $element_content);
                $image_count = preg_match_all('/<img[^>]*src="[^"]*"[^>]*>/i', $element_content);

                if ($image_link_count > 1 || $image_count > 1) {
                    $enhanced_content = preg_replace_callback(
                        '/<a([^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*)>/i',
                        function($link_matches) {
                            $link_attributes = $link_matches[1];

                            if (strpos($link_attributes, 'ml-gallery-lightbox') !== false ||
                                strpos($link_attributes, 'data-src') !== false) {
                                return $link_matches[0];
                            }

                            if (strpos($link_attributes, 'class=') !== false) {
                                $enhanced_attributes = preg_replace(
                                    '/class="([^"]*)"/',
                                    'class="$1 ml-gallery-lightbox"',
                                    $link_attributes
                                );
                            } else {
                                $enhanced_attributes = $link_attributes . ' class="ml-gallery-lightbox"';
                            }

                            return '<a' . $enhanced_attributes . '>';
                        },
                        $element_content
                    );

                    $enhanced_content = preg_replace_callback(
                        '/<img([^>]*src="([^"]*)"[^>]*)>/i',
                        function($img_matches) use ($enhanced_content) {
                            $img_tag = $img_matches[0];
                            $img_src = $img_matches[2];

                            $img_position = strpos($enhanced_content, $img_tag);
                            if ($img_position !== false) {
                                $before_img = substr($enhanced_content, 0, $img_position);
                                $last_a_open = strrpos($before_img, '<a');
                                $last_a_close = strrpos($before_img, '</a>');
                                if ($last_a_open !== false && ($last_a_close === false || $last_a_open > $last_a_close)) {
                                    return $img_tag;
                                }
                            }

                            $full_url = $this->getFullSizeImageUrl($img_src, $img_tag);

                            return '<a href="' . esc_attr($full_url) . '" class="ml-gallery-lightbox" data-src="' . esc_attr($full_url) . '" data-thumb="' . esc_attr($img_src) . '">' . $img_tag . '</a>';
                        },
                        $enhanced_content
                    );

                    return '<' . $tag_name . $element_attributes . '>' . $enhanced_content . '</' . $tag_name . '>';
                }

                if (preg_match('/<img[^>]*src="([^"]*)"[^>]*>/i', $element_content, $img_matches)) {
                    $img_src = $img_matches[1];
                    $img_tag = $img_matches[0];
                    $full_url = $this->getFullSizeImageUrl($img_src, $img_tag);

                    $element_content = preg_replace('/(<img[^>]*class="[^"]*)\bml-lightbox-enabled\b([^"]*"[^>]*>)/i', '$1$2', $element_content);

                    $enhanced_attributes = $element_attributes . ' data-src="' . esc_attr($full_url) . '" data-thumb="' . esc_attr($img_src) . '"';
                    return '<' . $tag_name . $enhanced_attributes . '>' . $element_content . '</' . $tag_name . '>';
                }

                return $matches[0];
            },
            $content
        );

        $content = preg_replace_callback(
            '/<(figure|div|ul|section)([^>]*data-lg[^>]*)>(.*?)<\/\1>/is',
            function($matches) {
                $tag_name = $matches[1];
                $element_attributes = $matches[2];
                $element_content = $matches[3];

                if (strpos($element_attributes, 'ml-gallery-lightbox') !== false) {
                    return $matches[0];
                }

                $enhanced_content = preg_replace_callback(
                    '/<a([^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*)>/i',
                    function($link_matches) {
                        $link_attributes = $link_matches[1];

                        if (strpos($link_attributes, 'ml-gallery-lightbox') !== false ||
                            strpos($link_attributes, 'data-src') !== false) {
                            return $link_matches[0];
                        }

                        if (strpos($link_attributes, 'class=') !== false) {
                            $enhanced_attributes = preg_replace(
                                '/class="([^"]*)"/',
                                'class="$1 ml-gallery-lightbox"',
                                $link_attributes
                            );
                        } else {
                            $enhanced_attributes = $link_attributes . ' class="ml-gallery-lightbox"';
                        }

                        return '<a' . $enhanced_attributes . '>';
                    },
                    $element_content
                );

                return '<' . $tag_name . $element_attributes . '>' . $enhanced_content . '</' . $tag_name . '>';
            },
            $content
        );

        $content = preg_replace_callback(
            '/<img[^>]*>/i',
            function($matches) use ($content) {
                $img_tag = $matches[0];

                if (strpos($img_tag, 'ml-lightbox-enabled') === false) {
                    return $img_tag;
                }

                if (strpos($img_tag, 'data-src') !== false) {
                    return $img_tag;
                }

                $img_position = strpos($content, $img_tag);
                if ($img_position !== false) {
                    $before_img = substr($content, 0, $img_position);

                    if (preg_match('/<(figure|div|span)[^>]*class="[^"]*ml-lightbox-enabled[^"]*"[^>]*>(?!.*<\/\1>)$/s', $before_img)) {
                        return $img_tag;
                    }
                }

                if (preg_match('/src="([^"]*)"/', $img_tag, $src_matches)) {
                    $img_src = $src_matches[1];
                    $full_url = $this->getFullSizeImageUrl($img_src, $img_tag);

                    return '<a href="' . esc_url($full_url) . '" data-src="' . esc_url($full_url) . '" data-thumb="' . esc_url($img_src) . '" class="ml-img-lightbox">' . $img_tag . '</a>';
                }

                return $img_tag;
            },
            $content
        );

        $content = preg_replace_callback(
            '/<a[^>]*href="([^"]*\.(jpg|jpeg|png|gif|webp|svg))"[^>]*>(.*?)<\/a>/is',
            function($matches) use ($content) {
                $href = $matches[1];
                $link_content = $matches[3];

                if (strpos($link_content, '<img') === false) {
                    return $matches[0];
                }

                if (strpos($matches[0], 'data-src') !== false ||
                    strpos($matches[0], 'ml-lightbox-enabled') !== false ||
                    strpos($link_content, 'ml-lightbox-enabled') !== false) {
                    return $matches[0];
                }

                $link_position = strpos($content, $matches[0]);
                if ($link_position !== false) {
                    $before_link = substr($content, 0, $link_position);
                    $after_link = substr($content, $link_position + strlen($matches[0]));

                    if (preg_match('/<figure[^>]*class="[^"]*wp-block-gallery[^"]*"[^>]*>/s', $before_link) &&
                        preg_match('/<\/figure>/s', $after_link)) {
                        return $matches[0];
                    }

                    if (preg_match('/<div[^>]*class="[^"]*filmstrip[^"]*"[^>]*>/s', $before_link) ||
                        preg_match('/<div[^>]*id="[^"]*filmstrip[^"]*"[^>]*>/s', $before_link) ||
                        preg_match('/<li[^>]*class="[^"]*ms-thumb[^"]*"[^>]*>/s', $before_link)) {
                        return $matches[0];
                    }
                }

                $options = $this->getCachedGeneralOptions();

                if ((strpos($link_content, 'data-wp-on') !== false && strpos($link_content, 'showLightbox') !== false) ||
                    strpos($link_content, 'wp-lightbox-container') !== false) {
                    $override_enabled = isset($options['override_enlarge_on_click']) ? $options['override_enlarge_on_click'] : true;

                    return $matches[0];
                } else {
                    $override_link_enabled = isset($options['override_link_to_image_file']) ? $options['override_link_to_image_file'] : true;

                    if ($override_link_enabled === false || $override_link_enabled === '0' || $override_link_enabled === 0) {
                        return $matches[0];
                    }
                }

                if (preg_match('/src="([^"]*)"/', $link_content, $src_matches)) {
                    $img_src = $src_matches[1];

                    $metaslider_options = $this->getCachedMetaSliderOptions();
                    $showButton = isset($metaslider_options['show_lightbox_button']) ? $metaslider_options['show_lightbox_button'] : true;

                    if ($showButton) {
                        $enhanced_content = preg_replace('/(<img[^>]*class="[^"]*)("[^>]*>)/', '$1 ml-lightbox-enabled$2', $link_content);
                        if ($enhanced_content === $link_content) {
                            $enhanced_content = preg_replace('/(<img[^>]*)(>)/', '$1 class="ml-lightbox-enabled"$2', $link_content);
                        }
                        return $enhanced_content;
                    } else {
                        $full_url = $this->getFullSizeImageUrl($href);
                        return '<a href="' . esc_url($full_url) . '" data-src="' . esc_url($full_url) . '" data-thumb="' . esc_url($img_src) . '" class="ml-lightbox-enabled">' . $link_content . '</a>';
                    }
                }

                return $matches[0];
            },
            $content
        );

        $content = preg_replace_callback(
            '/<(figure|div|span|ul|section)([^>]*class="[^"]*ml-lightbox-enabled[^"]*"[^>]*)>(.*?)<\/\1>/is',
            function($matches) {
                $element_content = $matches[3];

                $lightbox_link_count = substr_count($element_content, 'ml-lightbox-enabled');

                if ($lightbox_link_count > 1) {
                    $enhanced_content = str_replace('class="ml-lightbox-enabled"', 'class="ml-lightbox-enabled ml-gallery-lightbox"', $element_content);
                    $enhanced_content = preg_replace('/class="([^"]*ml-lightbox-enabled[^"]*)"/', 'class="$1 ml-gallery-lightbox"', $enhanced_content);

                    return '<' . $matches[1] . $matches[2] . '>' . $enhanced_content . '</' . $matches[1] . '>';
                }

                return $matches[0];
            },
            $content
        );

        return $content;
    }

    /**
     * Get the full-size image URL from a potentially resized image URL
     *
     * @param string $image_url The image URL (may be resized like image-300x200.jpg)
     * @param string $img_tag Optional img tag HTML to extract attachment ID from classes
     * @return string The full-size image URL or original URL if not found
     */
    private function getFullSizeImageUrl($image_url, $img_tag = '')
    {
        $attachment_id = 0;
        if (!empty($img_tag) && preg_match('/wp-image-(\d+)/i', $img_tag, $matches)) {
            $attachment_id = absint($matches[1]);
        }

        if (!$attachment_id) {
            $stripped_url = preg_replace('/-\d+x\d+(\.(jpg|jpeg|png|gif|webp|svg))$/i', '$1', $image_url);

            if ($stripped_url !== $image_url) {
                $attachment_id = attachment_url_to_postid($stripped_url);
            }

            if (!$attachment_id) {
                $attachment_id = attachment_url_to_postid($image_url);
            }
        }

        if ($attachment_id) {
            $full_size_url = wp_get_attachment_url($attachment_id);
            if ($full_size_url) {
                return $full_size_url;
            }
        }

        $stripped_url = preg_replace('/-\d+x\d+(\.(jpg|jpeg|png|gif|webp|svg))$/i', '$1', $image_url);
        return $stripped_url;
    }

    private function enhanceContentImages($content)
    {
        if ($this->shouldExcludePage()) {
            return $content;
        }

        $content = $this->processManualLightboxOptions($content);
        
        $content = $this->removeExcludedElements($content);
        
        $protected_content = $content;
        
        $gallery_patterns = array(
            '/(\[gallery[^\]]*\])/s',
            '/(<div[^>]*class[^>]*gallery[^>]*>.*?<\/div>)/s',
            '/(\[ml-slider[^\]]*\])/s',
            '/(\[metaslider[^\]]*\])/s',
            '/(<div[^>]*class[^>]*metaslider[^>]*>.*?<\/div>)/s',
        );

        $placeholders = array();
        $placeholder_index = 0;

        foreach ($gallery_patterns as $pattern) {
            $protected_content = preg_replace_callback($pattern, function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '<!--GALLERY_PLACEHOLDER_' . $placeholder_index . '-->';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            }, $protected_content);
        }

        $options = $this->getCachedGeneralOptions();
        $enable_on_content = isset($options['enable_on_content']) ? $options['enable_on_content'] : false;
        if (!$enable_on_content) {
            foreach ($placeholders as $placeholder => $original) {
                $protected_content = str_replace($placeholder, $original, $protected_content);
            }
            return $protected_content;
        }

        $pattern = '/<img[^>]*src=["\']([^"\']*\.(jpg|jpeg|png|gif|webp|svg))["\'][^>]*>/i';

        $protected_content = preg_replace_callback($pattern, function($matches) use ($protected_content) {
            $img_tag = $matches[0];
            $img_src = $matches[1];

            if (strpos($img_tag, 'data-ml-exclude="true"') !== false) {
                return $img_tag;
            }

            if (strpos($img_tag, 'ml-lightbox-enabled') !== false) {
                return $img_tag;
            }

            if (strpos($img_tag, 'wp-post-image') !== false) {
                return $img_tag;
            }

            $ui_classes = array('custom-logo', 'site-logo', 'logo', 'avatar', 'icon', 'emoji', 'thumbnail', 'thumb');
            foreach ($ui_classes as $ui_class) {
                if (preg_match('/class=["\'][^"\']*\b' . preg_quote($ui_class, '/') . '\b[^"\']*["\']/', $img_tag)) {
                    return $img_tag;
                }
            }

            $options = $this->getCachedGeneralOptions();
            $min_width = isset($options['minimum_image_width']) ? absint($options['minimum_image_width']) : 200;
            $min_height = isset($options['minimum_image_height']) ? absint($options['minimum_image_height']) : 200;

            $img_width = 0;
            $img_height = 0;

            if (preg_match('/width=["\']?(\d+)["\']?/i', $img_tag, $width_match)) {
                $img_width = absint($width_match[1]);
            }

            if (preg_match('/height=["\']?(\d+)["\']?/i', $img_tag, $height_match)) {
                $img_height = absint($height_match[1]);
            }

            if (($img_width === 0 || $img_height === 0) && preg_match('/style=["\']([^"\']*)["\']/', $img_tag, $style_match)) {
                $style = $style_match[1];
                if ($img_width === 0 && preg_match('/width:\s*(\d+)px/i', $style, $style_width_match)) {
                    $img_width = absint($style_width_match[1]);
                }
                if ($img_height === 0 && preg_match('/height:\s*(\d+)px/i', $style, $style_height_match)) {
                    $img_height = absint($style_height_match[1]);
                }
            }

            if ($min_width > 0 && $img_width > 0 && $img_width < $min_width) {
                return $img_tag;
            }
            if ($min_height > 0 && $img_height > 0 && $img_height < $min_height) {
                return $img_tag;
            }

            $img_position = strpos($protected_content, $img_tag);
            if ($img_position !== false) {
                $before_img = substr($protected_content, 0, $img_position);
                $after_img = substr($protected_content, $img_position + strlen($img_tag));

                if (preg_match('/<figure[^>]*class="[^"]*wp-block-gallery[^"]*"[^>]*>/s', $before_img) &&
                    preg_match('/<\/figure>/s', $after_img)) {
                    return $img_tag;
                }

                if (preg_match('/<figure[^>]*class="[^"]*wp-lightbox-container[^"]*"[^>]*>/s', $before_img) &&
                    preg_match('/<\/figure>/s', $after_img)) {
                    return $img_tag;
                }

                $in_filmstrip = false;
                if (preg_match('/<div[^>]*class="[^"]*filmstrip[^"]*"[^>]*>/s', $before_img)) {
                    if (preg_match('/<\/div>/s', $after_img)) {
                        $in_filmstrip = true;
                    }
                }
                if (!$in_filmstrip && preg_match('/<div[^>]*id="[^"]*filmstrip[^"]*"[^>]*>/s', $before_img)) {
                    if (preg_match('/<\/div>/s', $after_img)) {
                        $in_filmstrip = true;
                    }
                }
                if (!$in_filmstrip && preg_match('/<li[^>]*class="[^"]*ms-thumb[^"]*"[^>]*>/s', $before_img)) {
                    if (preg_match('/<\/li>/s', $after_img)) {
                        $in_filmstrip = true;
                    }
                }

                if ($in_filmstrip) {
                    return $img_tag;
                }

                if (preg_match('/ml-lightbox-enabled[^>]*>(?!.*<\/[^>]*>)[^<]*$/s', $before_img)) {
                    return $img_tag;
                }

                if (preg_match('/(ml-lightbox-wrapper|ml-lightbox-enabled|data-src=)[^>]*>(?!.*<\/[^>]*>)[^<]*$/s', $before_img)) {
                    return $img_tag;
                }

                if (strpos($img_tag, 'data-wp-on') !== false && strpos($img_tag, 'showLightbox') !== false) {
                    $options = $this->getCachedGeneralOptions();
                    $override_enabled = isset($options['override_enlarge_on_click']) ? $options['override_enlarge_on_click'] : true;

                    if ($override_enabled === false || $override_enabled === '0' || $override_enabled === 0) {
                        return $img_tag;
                    }
                }

                if (preg_match('/<a[^>]*href="[^"]*"[^>]*>(?![^<]*<\/a>)[^<]*$/s', $before_img)) {
                    $options = $this->getCachedGeneralOptions();
                    $override_link_enabled = isset($options['override_link_to_image_file']) ? $options['override_link_to_image_file'] : true;

                    if ($override_link_enabled === false || $override_link_enabled === '0' || $override_link_enabled === 0) {
                        return $img_tag;
                    } else {
                        return $img_tag;
                    }
                }
            }

            $metaslider_options = $this->getCachedMetaSliderOptions();
            $show_button = isset($metaslider_options['show_lightbox_button']) ? $metaslider_options['show_lightbox_button'] : true;

            if ($show_button) {
                return $img_tag;
            }

            $full_size_url = $this->getFullSizeImageUrl($img_src, $img_tag);

            return '<a href="' . esc_url($full_size_url) . '" data-src="' . esc_url($full_size_url) . '" data-thumb="' . esc_url($img_src) . '" class="ml-lightbox-enabled">' . $img_tag . '</a>';
        }, $protected_content);

        $options = $this->getCachedGeneralOptions();
        if (isset($options['enable_on_content']) && $options['enable_on_content']) {
            $protected_content = $this->processContentVideos($protected_content);
        }

        foreach ($placeholders as $placeholder => $original_content) {
            $protected_content = str_replace($placeholder, $original_content, $protected_content);
        }

        return $protected_content;
    }

    private function processContentVideos($content)
    {
        if (strpos($content, 'youtube.com/embed/') === false && strpos($content, 'player.vimeo.com/video/') === false) {
            return $content;
        }

        if (strpos($content, 'ml-lightbox-enabled') !== false) {
            return $content;
        }

        $youtube_embed_pattern = '/<figure([^>]*class="[^"]*wp-block-embed[^"]*wp-block-embed-youtube[^"]*"[^>]*)>(.*?<div[^>]*class="[^"]*wp-block-embed__wrapper[^"]*"[^>]*>(.*?<iframe[^>]*src=["\']https?:\/\/(?:www\.)?youtube\.com\/embed\/[a-zA-Z0-9_-]{11}[^"\']*["\'][^>]*><\/iframe>.*?)<\/div>.*?)<\/figure>/s';
        $content = preg_replace_callback($youtube_embed_pattern, function($matches) {
            $figure_attrs = $matches[1];
            $figure_content = $matches[2];
            $wrapper_and_iframe = $matches[3];

            if (strpos($figure_content, 'ml-lightbox-enabled') !== false) {
                return $matches[0];
            }

            $enhanced_content = preg_replace(
                '/(<div[^>]*class=")([^"]*wp-block-embed__wrapper[^"]*)(")([^>]*>)/',
                '$1$2 ml-lightbox-enabled$3$4',
                $figure_content
            );

            return '<figure' . $figure_attrs . '>' . $enhanced_content . '</figure>';
        }, $content);

        $vimeo_embed_pattern = '/<figure([^>]*class="[^"]*wp-block-embed[^"]*wp-block-embed-vimeo[^"]*"[^>]*)>(.*?<div[^>]*class="[^"]*wp-block-embed__wrapper[^"]*"[^>]*>(.*?<iframe[^>]*src=["\']https?:\/\/player\.vimeo\.com\/video\/\d+[^"\']*["\'][^>]*><\/iframe>.*?)<\/div>.*?)<\/figure>/s';
        $content = preg_replace_callback($vimeo_embed_pattern, function($matches) {
            $figure_attrs = $matches[1];
            $figure_content = $matches[2];
            $wrapper_and_iframe = $matches[3];

            if (strpos($figure_content, 'ml-lightbox-enabled') !== false) {
                return $matches[0];
            }

            $enhanced_content = preg_replace(
                '/(<div[^>]*class=")([^"]*wp-block-embed__wrapper[^"]*)(")([^>]*>)/',
                '$1$2 ml-lightbox-enabled$3$4',
                $figure_content
            );

            return '<figure' . $figure_attrs . '>' . $enhanced_content . '</figure>';
        }, $content);

        $youtube_iframe_pattern = '/<iframe[^>]*src=["\']https?:\/\/(?:www\.)?youtube\.com\/embed\/[a-zA-Z0-9_-]{11}[^"\']*["\'][^>]*><\/iframe>/i';
        $content = preg_replace_callback($youtube_iframe_pattern, function($matches) use ($content) {
            $iframe = $matches[0];

            $context_before = substr($content, 0, strpos($content, $iframe));
            if (strpos($context_before, 'wp-block-embed__wrapper') !== false &&
                !preg_match('/<\/div>.*$/s', substr($context_before, strrpos($context_before, 'wp-block-embed__wrapper')))) {
                return $iframe;
            }

            if (strpos($iframe, 'ml-lightbox-enabled') !== false) {
                return $iframe;
            }

            return '<div class="ml-lightbox-enabled" style="display: inline-block; position: relative;">' . $iframe . '</div>';
        }, $content);

        $vimeo_iframe_pattern = '/<iframe[^>]*src=["\']https?:\/\/player\.vimeo\.com\/video\/\d+[^"\']*["\'][^>]*><\/iframe>/i';
        $content = preg_replace_callback($vimeo_iframe_pattern, function($matches) use ($content) {
            $iframe = $matches[0];

            $context_before = substr($content, 0, strpos($content, $iframe));
            if (strpos($context_before, 'wp-block-embed__wrapper') !== false &&
                !preg_match('/<\/div>.*$/s', substr($context_before, strrpos($context_before, 'wp-block-embed__wrapper')))) {
                return $iframe;
            }

            if (strpos($iframe, 'ml-lightbox-enabled') !== false) {
                return $iframe;
            }

            return '<div class="ml-lightbox-enabled" style="display: inline-block; position: relative;">' . $iframe . '</div>';
        }, $content);

        return $content;
    }

    private function enhanceContentVideos($content)
    {
        
        if (strpos($content, 'ml-content-video-lightbox') !== false || 
            strpos($content, 'ml-widget-video-lightbox') !== false) {
            return $content;
        }
        
        $protected_content = $content;
        
        $gallery_patterns = array(
            '/(\[gallery[^\]]*\])/s',
            '/(<div[^>]*class[^>]*gallery[^>]*>.*?<\/div>)/s',
            '/(\[ml-slider[^\]]*\])/s',
            '/(\[metaslider[^\]]*\])/s',
            '/(<div[^>]*class[^>]*metaslider[^>]*>.*?<\/div>)/s',
        );

        $placeholders = array();
        $placeholder_index = 0;

        foreach ($gallery_patterns as $pattern) {
            $protected_content = preg_replace_callback($pattern, function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '<!--VIDEO_GALLERY_PLACEHOLDER_' . $placeholder_index . '-->';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            }, $protected_content);
        }

        $youtube_block_pattern = '/<figure[^>]*class[^>]*wp-block-embed-youtube[^>]*>.*?<\/figure>/is';
        $protected_content = preg_replace_callback($youtube_block_pattern, function($matches) {
            $block_html = $matches[0];
            
            if (preg_match('/youtube\.com\/embed\/([a-zA-Z0-9_-]{11})/', $block_html, $id_matches)) {
                $youtube_id = $id_matches[1];
                $youtube_url = 'https://www.youtube.com/watch?v=' . $youtube_id;
                $thumbnail_url = 'https://img.youtube.com/vi/' . $youtube_id . '/maxresdefault.jpg';
                
                return '<div class="wp-block-embed is-type-video is-provider-youtube">' .
                       '<a href="' . esc_url($youtube_url) . '" data-src="" data-thumb="' . esc_url($thumbnail_url) . '" data-video=\'{"source": [{"src":"' . esc_url($youtube_url) . '", "type":"video/youtube"}], "attributes": {"preload": false, "controls": true}}\' class="ml-content-video-lightbox" style="position: relative; display: block;">' . 
                       '<img src="' . esc_url($thumbnail_url) . '" alt="YouTube Video" class="ml-video-poster" style="max-width: 100%; height: auto;" />' .
                       '<span class="ml-video-play-button" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 60px; text-shadow: 0 0 10px rgba(0,0,0,0.5); pointer-events: none;">▶</span>' .
                       '</a></div>';
            }
            
            return $matches[0];
        }, $protected_content);

        $youtube_iframe_pattern = '/<iframe[^>]*youtube\.com\/embed\/([a-zA-Z0-9_-]{11})[^>]*><\/iframe>/i';
        $protected_content = preg_replace_callback($youtube_iframe_pattern, function($matches) {
            $youtube_id = $matches[1];
            $youtube_url = 'https://www.youtube.com/watch?v=' . $youtube_id;
            $thumbnail_url = 'https://img.youtube.com/vi/' . $youtube_id . '/maxresdefault.jpg';
            
            return '<a href="' . esc_url($youtube_url) . '" data-src="" data-thumb="' . esc_url($thumbnail_url) . '" data-video=\'{"source": [{"src":"' . esc_url($youtube_url) . '", "type":"video/youtube"}], "attributes": {"preload": false, "controls": true}}\' class="ml-content-video-lightbox" style="position: relative; display: block;">' . 
                   '<img src="' . esc_url($thumbnail_url) . '" alt="YouTube Video" class="ml-video-poster" style="max-width: 100%; height: auto;" />' .
                   '<span class="ml-video-play-button" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 60px; text-shadow: 0 0 10px rgba(0,0,0,0.5); pointer-events: none;">▶</span>' .
                   '</a>';
        }, $protected_content);
        $vimeo_block_pattern = '/<figure[^>]*class[^>]*wp-block-embed-vimeo[^>]*>.*?<\/figure>/is';
        $protected_content = preg_replace_callback($vimeo_block_pattern, function($matches) {
            $block_html = $matches[0];
            
            if (preg_match('/player\.vimeo\.com\/video\/(\d+)/', $block_html, $id_matches)) {
                $vimeo_id = $id_matches[1];
                $vimeo_url = 'https://vimeo.com/' . $vimeo_id;
                $thumbnail_url = 'https://vumbnail.com/' . $vimeo_id . '.jpg';
                
                return '<div class="wp-block-embed is-type-video is-provider-vimeo">' .
                       '<a href="' . esc_url($vimeo_url) . '" data-src="" data-thumb="' . esc_url($thumbnail_url) . '" data-video=\'{"source": [{"src":"' . esc_url($vimeo_url) . '", "type":"video/vimeo"}], "attributes": {"preload": false, "controls": true}}\' class="ml-content-video-lightbox" style="position: relative; display: block;">' .
                       '<img src="' . esc_url($thumbnail_url) . '" alt="Vimeo Video" class="ml-video-poster" style="max-width: 100%; height: auto;" />' .
                       '<span class="ml-video-play-button" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 60px; text-shadow: 0 0 10px rgba(0,0,0,0.5); pointer-events: none;">▶</span>' .
                       '</a></div>';
            }
            
            return $matches[0];
        }, $protected_content);

        $vimeo_iframe_pattern = '/<iframe[^>]*player\.vimeo\.com\/video\/(\d+)[^>]*><\/iframe>/i';
        $protected_content = preg_replace_callback($vimeo_iframe_pattern, function($matches) {
            $vimeo_id = $matches[1];
            $vimeo_url = 'https://vimeo.com/' . $vimeo_id;
            $thumbnail_url = 'https://vumbnail.com/' . $vimeo_id . '.jpg';
            
            return '<a href="' . esc_url($vimeo_url) . '" data-src="" data-thumb="' . esc_url($thumbnail_url) . '" data-video=\'{"source": [{"src":"' . esc_url($vimeo_url) . '", "type":"video/vimeo"}], "attributes": {"preload": false, "controls": true}}\' class="ml-content-video-lightbox" style="position: relative; display: block;">' .
                   '<img src="' . esc_url($thumbnail_url) . '" alt="Vimeo Video" class="ml-video-poster" style="max-width: 100%; height: auto;" />' .
                   '<span class="ml-video-play-button" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; font-size: 60px; text-shadow: 0 0 10px rgba(0,0,0,0.5); pointer-events: none;">▶</span>' .
                   '</a>';
        }, $protected_content);
        foreach ($placeholders as $placeholder => $original_content) {
            $protected_content = str_replace($placeholder, $original_content, $protected_content);
        }

        return $protected_content;
    }

    public function enhanceWordPressGallery($output, $atts, $instance)
    {
        static $processing = false;
        if ($processing) {
            return $output;
        }

        if (!empty($output)) {
            return $output;
        }

        if ($this->shouldExcludePage()) {
            $processing = true;
            $result = gallery_shortcode($atts);
            $processing = false;
            return $result;
        }

        $processing = true;
        $output = gallery_shortcode($atts);
        $processing = false;

        $output = str_replace('class="gallery-item"', 'class="gallery-item ml-gallery-item"', $output);
        $output = preg_replace('/(<a[^>]*?)>/', '$1 class="ml-gallery-lightbox">', $output);

        return $output;
    }

    public function enhanceGutenbergGalleryBlock($block_content, $block)
    {
        if ($block['blockName'] !== 'core/gallery') {
            return $block_content;
        }

        if ($this->shouldExcludePage()) {
            $block_content = preg_replace(
                '/(<figure[^>]*class=["\'][^"\']*wp-block-gallery[^"\']*["\'][^>]*)>/',
                '$1 data-ml-exclude-gallery="true">',
                $block_content
            );
            return $block_content;
        }

        $enhanced_content = preg_replace('/(<a[^>]*class=["\'][^"\']*)(["\']\s[^>]*>)/', '$1 ml-gallery-lightbox$2', $block_content);

        if ($enhanced_content === $block_content) {
            $enhanced_content = preg_replace('/(<a[^>]*?)(\s*>)/', '$1 class="ml-gallery-lightbox"$2', $block_content);
        }

        return $enhanced_content;
    }

    public function enhanceVideoBlock($block_content, $block)
    {
        if (!in_array($block['blockName'], array('core/embed', 'core/video'))) {
            return $block_content;
        }

        $options = $this->getCachedGeneralOptions();
        $enable_videos = isset($options['enable_videos']) ? $options['enable_videos'] : true;
        if (!$enable_videos) {
            return $block_content;
        }

        if ($this->shouldExcludePage()) {
            return $block_content;
        }

        $options = $this->getCachedGeneralOptions();
        $css_selectors = isset($options['exclude_css_selectors']) ? trim($options['exclude_css_selectors']) : '';
        if (!empty($css_selectors)) {
            $selectors = array_filter(array_map('trim', explode("\n", $css_selectors)));
            foreach ($selectors as $selector) {
                if (empty($selector)) continue;

                if (strpos($selector, '.') === 0) {
                    $class_name = trim(str_replace('.', '', $selector));
                    if (preg_match('/class="[^"]*' . preg_quote($class_name, '/') . '[^"]*"/', $block_content)) {
                        return $block_content;
                    }
                }
            }
        }

        if (strpos($block_content, 'ml-video-overlay') !== false || strpos($block_content, 'ml-lightbox-enabled') !== false) {
            return $block_content;
        }

        if ($block['blockName'] === 'core/embed') {
            return $this->addLightboxClassToVideoBlock($block_content, 'wp-block-embed');
        }

        if ($block['blockName'] === 'core/video') {
            return $this->addLightboxClassToVideoBlock($block_content, 'wp-block-video');
        }

        return $block_content;
    }

    public function manualOptionsCallback()
    {
        echo '<p>' . __('Configure how the lightbox interacts with WordPress built-in features.', 'ml-slider-lightbox') . '</p>';
    }

    public function overrideEnlargeOnClickCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $manual_options = get_option('metaslider_lightbox_manual_options', array());
        $enabled = isset($manual_options['override_enlarge_on_click']) ? $manual_options['override_enlarge_on_click'] : true;

        $this->renderToggleSwitch(
            'metaslider_lightbox_manual_options[override_enlarge_on_click]',
            $enabled,
            __('Override WordPress "Enlarge on Click" Setting', 'ml-slider-lightbox'),
            __('When enabled, disables WordPress built-in gallery window and uses MetaSlider Gallery for images set to "Enlarge on Click". When disabled, respects WordPress setting and skips images set to "Enlarge on Click".', 'ml-slider-lightbox')
        );
    }

    public function overrideLinkToImageFileCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $manual_options = get_option('metaslider_lightbox_manual_options', array());
        $enabled = isset($manual_options['override_link_to_image_file']) ? $manual_options['override_link_to_image_file'] : true;

        $this->renderToggleSwitch(
            'metaslider_lightbox_manual_options[override_link_to_image_file]',
            $enabled,
            __('Override "Link to Image File" Setting', 'ml-slider-lightbox'),
            __('When enabled, uses MetaSlider Gallery for images linked to their full-size image file (commonly set via "Link to Image File" in the block editor). When disabled, respects the original link behavior.', 'ml-slider-lightbox')
        );
    }

    /**
     * OPTION 3: Multi-select dropdown to exclude specific post types from Manual processing
     */
    public function manualExcludePostTypesCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $manual_options = get_option('metaslider_lightbox_manual_options', array());
        $selected_post_types = isset($manual_options['manual_exclude_post_types']) && is_array($manual_options['manual_exclude_post_types'])
            ? $manual_options['manual_exclude_post_types']
            : array();

        $post_types = get_post_types(array(
            'show_ui' => true
        ), 'objects');

        $internal_types = array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'ml_gallery');
        foreach ($internal_types as $internal_type) {
            unset($post_types[$internal_type]);
        }

        ?>
        <div class="ml-settings-field">
            <div class="ml-settings-field-content">
                <h3 class="ml-settings-field-title">
                    <?php echo esc_html__('Exclude Post Types from Manual Processing', 'ml-slider-lightbox'); ?>
                </h3>
                <div class="ml-settings-field-description">
                    <?php echo esc_html__('Select post types where Manual options should NOT work. For example, select "Products (product)" to disable the gallery on WooCommerce product pages while keeping it active elsewhere.', 'ml-slider-lightbox'); ?>
                </div>
            </div>
            <div class="ml-settings-field-control">
                <select id="manual-exclude-post-types" name="metaslider_lightbox_manual_options[manual_exclude_post_types][]" multiple class="ml-select2-manual-post-types">
                    <?php
                    if (!empty($post_types)) {
                        foreach ($post_types as $post_type) {
                            $selected = in_array($post_type->name, $selected_post_types) ? 'selected' : '';
                            echo '<option value="' . esc_attr($post_type->name) . '" ' . $selected . '>';
                            echo esc_html($post_type->label . ' (' . $post_type->name . ')');
                            echo '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
        </div>
        <?php
    }

    public function renderManualOptionsHowTo()
    {
        echo '<details class="ml-how-to-toggle">';
        echo '<summary><strong>' . __('How to use: WordPress "Enlarge on Click"', 'ml-slider-lightbox') . '</strong></summary>';
        echo '<div class="ml-how-to-content">';
        echo '<p>' . __('WordPress has a built-in "Enlarge on Click" feature for images. Here\'s how to enable it:', 'ml-slider-lightbox') . '</p>';
        echo '<ol>';
        echo '<li>' . __('Select an image in the block editor.', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('In the block toolbar, click on the Link icon.', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('Select "Enlarge on click".', 'ml-slider-lightbox') . '</li>';
        echo '</ol>';
        echo '</div>';
        echo '</details>';

        echo '<details class="ml-how-to-toggle">';
        echo '<summary><strong>' . __('How to use: "Link to Image File"', 'ml-slider-lightbox') . '</strong></summary>';
        echo '<div class="ml-how-to-content">';
        echo '<p>' . __('You can link images directly to their full-size media file. Here\'s how:', 'ml-slider-lightbox') . '</p>';
        echo '<p>' . __('For the Block Editor:', 'ml-slider-lightbox') . '</p>';
        echo '<ol>';
        echo '<li>' . __('Select an image in the block editor.', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('In the block toolbar, click on the Link icon.', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('Choose "Link to image file".', 'ml-slider-lightbox') . '</li>';
        echo '</ol>';
        echo '<p>' . __('For the Classic Editor:', 'ml-slider-lightbox') . '</p>';
        echo '<ol>';
        echo '<li>' . __('Select an image.', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('In the image toolbar, click on the Edit icon.', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('Choose "Media File" on the "Link To" dropdown.', 'ml-slider-lightbox') . '</li>';
        echo '</ol>';
        echo '</div>';
        echo '</details>';

        echo '<details class="ml-how-to-toggle">';
        echo '<summary><strong>' . __('How to use: CSS Class Method (Advanced)', 'ml-slider-lightbox') . '</strong></summary>';
        echo '<div class="ml-how-to-content">';
        echo '<p>' . __('For developers: Add the <code>ml-lightbox-enabled</code> class to enable MetaSlider Gallery on any element containing images.', 'ml-slider-lightbox') . '</p>';
        echo '<h4>' . __('Method 1: Block Editor Additional CSS Classes', 'ml-slider-lightbox') . '</h4>';
        echo '<ol>';
        echo '<li>' . __('Select an image or gallery block.', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('In the block settings sidebar, scroll to "Advanced".', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('Add <code>ml-lightbox-enabled</code> to "Additional CSS class(es)".', 'ml-slider-lightbox') . '</li>';
        echo '</ol>';
        echo '<h4>' . __('Method 2: HTML/Code', 'ml-slider-lightbox') . '</h4>';
        echo '<p>' . __('For custom HTML or theme developers, add the class directly:', 'ml-slider-lightbox') . '</p>';
        echo '<pre><code>&lt;div class="ml-lightbox-enabled"&gt;<br>  &lt;img src="image.jpg" alt="Image" /&gt;<br>&lt;/div&gt;</code></pre>';
        echo '</div>';
        echo '</details>';
    }

    private function addLightboxClassToVideoBlock($content, $block_class)
    {
        $enhanced_content = preg_replace(
            '/(<figure[^>]*class=")([^"]*' . preg_quote($block_class, '/') . '[^"]*)(")/',
            '$1$2 ml-lightbox-enabled$3',
            $content
        );

        return $enhanced_content;
    }

    private function addVideoOverlayToBlock($content, $video_url, $provider)
    {
        $poster_url = $this->getVideoThumbnail($video_url, $provider);
        
        $overlay = sprintf(
            '<a href="#" class="ml-video-overlay" data-src="%s" data-poster="%s" style="position: absolute; top: 0; left: 0; width: 100%%; height: 100%%; background: rgba(0,0,0,0.05); z-index: 2; cursor: pointer; display: block;"></a>',
            esc_url($video_url),
            esc_url($poster_url)
        );
        
        $enhanced_content = preg_replace(
            '/(<figure[^>]*class=")([^"]*wp-block-embed[^"]*)(")([^>]*)(>.*?)(<\/figure>)/s',
            '$1$2 ml-lightbox-enabled$3$4 style="position: relative;"$5' . $overlay . '$6',
            $content
        );
        
        return $enhanced_content;
    }

    private function addHTML5VideoOverlayToBlock($content, $video_url)
    {
        $poster_url = '';
        if (preg_match('/<video[^>]*poster=["\']([^"\']+)["\'][^>]*>/', $content, $poster_matches)) {
            $poster_url = $poster_matches[1];
        }

        $video_data = array(
            'source' => array(
                array(
                    'src' => $video_url,
                    'type' => $this->getVideoMimeType($video_url)
                )
            ),
            'attributes' => array(
                'preload' => false,
                'controls' => true
            )
        );

        $overlay = sprintf(
            '<a href="#" class="ml-video-overlay" data-src="" data-video=\'%s\' data-thumb="%s" style="position: absolute; top: 0; left: 0; width: 100%%; height: 100%%; background: rgba(0,0,0,0.05); z-index: 2; cursor: pointer; display: block;"></a>',
            esc_attr(wp_json_encode($video_data)),
            esc_url($poster_url)
        );

        $enhanced_content = preg_replace(
            '/(<figure[^>]*class=")([^"]*wp-block-video[^"]*)(")([^>]*)(>.*?)(<\/figure>)/s',
            '$1$2 ml-lightbox-enabled$3$4 style="position: relative;"$5' . $overlay . '$6',
            $content
        );

        return $enhanced_content;
    }

    private function getVideoMimeType($video_url)
    {
        $extension = strtolower(pathinfo(parse_url($video_url, PHP_URL_PATH), PATHINFO_EXTENSION));

        switch ($extension) {
            case 'mp4':
                return 'video/mp4';
            case 'webm':
                return 'video/webm';
            case 'ogg':
            case 'ogv':
                return 'video/ogg';
            case 'avi':
                return 'video/x-msvideo';
            case 'mov':
                return 'video/quicktime';
            default:
                return 'video/mp4';
        }
    }

    private function addHTML5VideoButtonToBlock($content, $video_url)
    {
        $poster_url = '';
        if (preg_match('/<video[^>]*poster=["\']([^"\']+)["\'][^>]*>/', $content, $poster_matches)) {
            $poster_url = $poster_matches[1];
        }

        $video_data = array(
            'source' => array(
                array(
                    'src' => $video_url,
                    'type' => $this->getVideoMimeType($video_url)
                )
            ),
            'attributes' => array(
                'preload' => false,
                'controls' => true
            )
        );

        $options     = $this->getCachedGeneralOptions();
        $button_text = isset($options['button_text']) ? $options['button_text'] : __('Open in Gallery', 'ml-slider-lightbox');

        $button = sprintf(
            '<a href="#" class="ml-lightbox-button" data-src="" data-video=\'%s\' data-thumb="%s">%s</a>',
            esc_attr(wp_json_encode($video_data)),
            esc_url($poster_url),
            esc_html($button_text)
        );

        $enhanced_content = preg_replace(
            '/(<figure[^>]*class=")([^"]*wp-block-video[^"]*)(")([^>]*)(>.*?)(<\/figure>)/s',
            '$1$2 ml-lightbox-enabled$3$4 style="position: relative;"$5' . $button . '$6',
            $content
        );

        return $enhanced_content;
    }

    private function addEmbedVideoButtonToBlock($content, $video_url, $provider)
    {
        $poster_url = $this->getVideoThumbnail($video_url, $provider);

        $options     = $this->getCachedGeneralOptions();
        $button_text = isset($options['button_text']) ? $options['button_text'] : __('Open in Gallery', 'ml-slider-lightbox');

        $button = sprintf(
            '<a href="#" class="ml-lightbox-button" data-src="%s" data-poster="%s">%s</a>',
            esc_url($video_url),
            esc_url($poster_url),
            esc_html($button_text)
        );

        $enhanced_content = preg_replace(
            '/(<figure[^>]*class=")([^"]*wp-block-embed[^"]*)(")([^>]*)(>.*?)(<\/figure>)/s',
            '$1$2 ml-lightbox-enabled$3$4 style="position: relative;"$5' . $button . '$6',
            $content
        );

        return $enhanced_content;
    }

    private function getVideoThumbnail($video_url, $provider)
    {
        if ($provider === 'youtube') {
            if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $video_url, $matches)) {
                $video_id = $matches[1];
                return "https://img.youtube.com/vi/$video_id/maxresdefault.jpg";
            }
        } elseif ($provider === 'vimeo') {
            if (preg_match('/vimeo\.com\/(\d+)/i', $video_url, $matches)) {
                $video_id = $matches[1];
                return "https://vumbnail.com/$video_id.jpg";
            }
        }

        return '';
    }

    public function enhanceFeaturedImage($html, $post_id, $post_thumbnail_id, $size, $attr)
    {
        if (empty($html) || is_admin()) {
            return $html;
        }

        if ($this->shouldExcludePage()) {
            return $html;
        }

        
        $full_size_url = wp_get_attachment_image_url($post_thumbnail_id, 'full');
        if (!$full_size_url) {
            return $html;
        }
        
        $post_url = get_permalink($post_id);

        if (preg_match('/<a[^>]*>.*?<\/a>/s', $html)) {
            $enhanced_html = preg_replace(
                '/(<a[^>]*?)(\s*>)/',
                '$1 data-src="' . esc_url($full_size_url) . '" data-thumb="' . esc_url($full_size_url) . '" class="ml-featured-lightbox"$2',
                $html
            );

            if (preg_match('/class=["\'][^"\']*["\']/', $html)) {
                $enhanced_html = preg_replace(
                    '/(<a[^>]*class=["\'][^"\']*)(["\']\s[^>]*>)/',
                    '$1 ml-featured-lightbox$2',
                    $html
                );
                $enhanced_html = preg_replace(
                    '/(<a[^>]*?)(\s[^>]*>)/',
                    '$1 data-src="' . esc_url($full_size_url) . '" data-thumb="' . esc_url($full_size_url) . '"$2',
                    $enhanced_html
                );
            }

            return $enhanced_html;
        } else {
            return '<a href="' . esc_url($post_url) . '" data-src="' . esc_url($full_size_url) . '" data-thumb="' . esc_url($full_size_url) . '" class="ml-featured-lightbox">' . $html . '</a>';
        }
    }

    public function enhanceFeaturedImageBlock($block_content, $parsed_block)
    {
        if ($parsed_block['blockName'] !== 'core/post-featured-image') {
            return $block_content;
        }

        if (empty($block_content) || is_admin() || $this->shouldExcludePage()) {
            return $block_content;
        }

        // Only process if our lightbox <a> is present (added by post_thumbnail_html hook)
        if (strpos($block_content, 'ml-featured-lightbox') === false) {
            return $block_content;
        }

        // Extract img URL from our lightbox <a>'s data-src attribute
        if (!preg_match('/data-src="([^"]+)"[^>]*class="[^"]*ml-featured-lightbox/', $block_content, $m) &&
            !preg_match('/class="[^"]*ml-featured-lightbox[^"]*"[^>]*data-src="([^"]+)"/', $block_content, $m)) {
            return $block_content;
        }
        $img_url = $m[1];

        // Detect nesting: if the first <a> in the block IS our lightbox <a>, there is no outer wrapper
        if (!preg_match('/<a\b[^>]*>/', $block_content, $first_a_match)) {
            return $block_content;
        }
        if (strpos($first_a_match[0], 'ml-featured-lightbox') !== false) {
            // First <a> is already our lightbox link — no nesting, nothing to fix
            return $block_content;
        }

        // Fix: remove inner lightbox <a> wrapper, keeping its children
        $block_content = preg_replace(
            '/<a\b[^>]+class="[^"]*ml-featured-lightbox[^"]*"[^>]*>(.*?)<\/a>/s',
            '$1',
            $block_content
        );

        // Add lightbox attributes to the outer post-link <a> (first <a> in block)
        $escaped_url   = esc_url($img_url);
        $block_content = preg_replace_callback(
            '/(<a\b)([^>]*)(>)/',
            function ($matches) use ($escaped_url) {
                $attrs = $matches[2];
                $attrs .= ' data-src="' . $escaped_url . '" data-thumb="' . $escaped_url . '"';
                if (preg_match('/\bclass="/', $attrs)) {
                    $attrs = preg_replace('/class="([^"]*)"/', 'class="$1 ml-featured-lightbox"', $attrs, 1);
                } else {
                    $attrs .= ' class="ml-featured-lightbox"';
                }
                return $matches[1] . $attrs . $matches[3];
            },
            $block_content,
            1
        );

        return $block_content;
    }

    private function hasContentDetectionEnabled()
    {
        $options = $this->getCachedGeneralOptions();

        return (isset($options['enable_on_content']) && $options['enable_on_content']) ||
               (isset($options['enable_on_widgets']) && $options['enable_on_widgets']) ||
               (isset($options['enable_galleries']) && $options['enable_galleries']) ||
               (isset($options['enable_featured_images']) && $options['enable_featured_images']);
    }

    private function hasManualLightboxProcessing()
    {
        global $post;

        if (!$post || !$post->post_content) {
            return false;
        }

        $content = $post->post_content;

        if (strpos($content, 'wp-lightbox-container') !== false &&
            (strpos($content, 'data-wp-on') !== false || strpos($content, 'data-wp-interactive') !== false)) {
            return true;
        }

        if (strpos($content, 'ml-lightbox-enabled') !== false) {
            return true;
        }

        if (preg_match('/<a[^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*>.*?<img[^>]*>.*?<\/a>/is', $content)) {
            return true;
        }

        return false;
    }

    private function pageNeedsVideoAssets()
    {
        global $post;

        if ($this->hasLightboxEnabledSliders()) {
            return true;
        }

        if (!$post || !$post->post_content) {
            return false;
        }

        $content = $post->post_content;

        if (preg_match('/youtube\.com|youtu\.be|vimeo\.com/i', $content)) {
            return true;
        }

        if (preg_match('/<a[^>]*href="[^"]*\.(mp4|webm|ogv)"[^>]*>/i', $content)) {
            return true;
        }

        if (strpos($content, '<video') !== false) {
            return true;
        }

        return false;
    }

    private function pageNeedsThumbnailAssets()
    {
        global $post;
        $content = $post && $post->post_content ? $post->post_content : '';

        // ml_gallery has per-gallery thumbnail settings independent of the global toggle,
        // so always load thumbnail assets when the shortcode is present on the page.
        if ($content && strpos($content, '[ml_gallery') !== false) {
            return true;
        }

        $options = $this->getCachedMetaSliderOptions();

        if (!isset($options['show_thumbnails']) || !$options['show_thumbnails']) {
            return false;
        }

        if ($this->hasLightboxEnabledSliders()) {
            return true;
        }

        if (!$content) {
            return false;
        }

        if (strpos($content, '[gallery') !== false ||
            strpos($content, 'wp-block-gallery') !== false ||
            preg_match('/<a[^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*>.*?<img[^>]*>.*?<\/a>/is', $content)) {
            return true;
        }

        return false;
    }

    public function disableWordpressLightboxJs()
    {
        ?>
        <script type="text/javascript">
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                var containers = document.querySelectorAll('.wp-lightbox-container');

                for (var i = 0; i < containers.length; i++) {
                    var container = containers[i];

                    var wpElements = container.querySelectorAll('[data-wp-on], [data-wp-interactive], [data-wp-init], [data-wp-context], img, button');

                    for (var j = 0; j < wpElements.length; j++) {
                        var el = wpElements[j];
                        var attributes = el.attributes;

                        for (var k = attributes.length - 1; k >= 0; k--) {
                            var attr = attributes[k];
                            if (attr.name.indexOf('data-wp-') === 0) {
                                el.removeAttribute(attr.name);
                            }
                        }
                    }

                    var buttons = container.querySelectorAll('button');
                    for (var j = 0; j < buttons.length; j++) {
                        buttons[j].remove();
                    }
                }

                var allWpImages = document.querySelectorAll('img[data-wp-on], img[data-wp-interactive], img[data-wp-init]');
                for (var i = 0; i < allWpImages.length; i++) {
                    var img = allWpImages[i];
                    var attributes = img.attributes;

                    for (var j = attributes.length - 1; j >= 0; j--) {
                        var attr = attributes[j];
                        if (attr.name.indexOf('data-wp-') === 0) {
                            img.removeAttribute(attr.name);
                        }
                    }
                }

                var standaloneButtons = document.querySelectorAll('button.lightbox-trigger, button[data-wp-on], button[data-wp-interactive]');
                for (var i = 0; i < standaloneButtons.length; i++) {
                    standaloneButtons[i].remove();
                }
            });

            document.addEventListener('click', function(e) {
                var target = e.target;

                if (target.hasAttribute('data-wp-on') ||
                    target.hasAttribute('data-wp-interactive') ||
                    target.classList.contains('lightbox-trigger') ||
                    target.hasAttribute('data-wp-init')) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return false;
                }

                if (target.tagName === 'FIGURE' && target.classList.contains('wp-lightbox-container')) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return false;
                }

                if (target.tagName === 'A' && target.querySelector('img[data-wp-on], img[data-wp-interactive]')) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return false;
                }
            }, true);
        })();
        </script>
        <?php
    }

    /**
     * Store MetaSlider Gallery version and path in the database
     * 
     * @since 2.21
     * 
     * @return void
     */
    public function store_plugin_data()
    {
        if ( apply_filters( 'metaslider_skip_store_plugin_data', false ) === true ) {
            return false;
        }

        $stored_version = get_option( 'metaslider_lightbox_plugin_version' );
        $stored_path    = get_option( 'metaslider_lightbox_plugin_path' );
        $current_path   = basename( dirname( __FILE__ ) ) . '/ml-slider-lightbox.php';

        if ( $stored_version !== $this->version || $stored_path !== $current_path ) {
            update_option( 'metaslider_lightbox_plugin_version', $this->version );
            update_option( 'metaslider_lightbox_plugin_path', $current_path );
        }
    }
}
