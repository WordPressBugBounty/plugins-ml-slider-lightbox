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
    public $version = '2.10.0';
    protected static $instance = null;
    private $supported_plugins = array();

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
    }

    public function __construct()
    {
        // Initialize WordPress lightbox override if enabled
        add_action('init', array($this, 'initializeWordPressLightboxOverride'));

        // Check for settings migration on admin init
        add_action('admin_init', array($this, 'migrateSettingsIfNeeded'));
    }

    /**
     * Initialize WordPress lightbox override functionality
     */
    public function initializeWordPressLightboxOverride()
    {
        $options = $this->getCachedGeneralOptions();
        $override_enabled = isset($options['override_enlarge_on_click']) ? $options['override_enlarge_on_click'] : false;

        if ($override_enabled) {
            // Disable WordPress 6.4+ built-in lightbox
            add_filter('wp_theme_json_data_theme', array($this, 'disableWordPressLightbox'));
            add_filter('wp_theme_json_data_user', array($this, 'disableWordPressLightbox'));

            // For older WordPress versions, disable via theme support
            add_action('after_setup_theme', array($this, 'removeWordPressLightboxSupport'), 20);

            // Output JavaScript to disable WordPress lightbox on frontend
            add_action('wp_head', array($this, 'disableWordpressLightboxJs'), 5);
        }
    }

    /**
     * Disable WordPress built-in lightbox via theme JSON
     */
    public function disableWordPressLightbox($theme_json)
    {
        $new_data = $theme_json->get_data();
        $new_data['settings']['lightbox']['enabled'] = false;

        return $theme_json->update_with($new_data);
    }

    /**
     * Remove WordPress lightbox theme support for older versions
     */
    public function removeWordPressLightboxSupport()
    {
        remove_theme_support('lightbox');
    }

    /**
     * Migrate old settings structure to new separated structure
     * This ensures backwards compatibility for existing users
     */
    public function migrateSettingsIfNeeded()
    {
        // Check if migration has already been done
        $migration_done = get_option('metaslider_lightbox_migration_done', false);
        if ($migration_done) {
            return;
        }

        // Get old settings
        $old_settings = get_option('metaslider_lightbox_general_options', array());
        if (empty($old_settings)) {
            // No old settings to migrate, mark as done
            update_option('metaslider_lightbox_migration_done', true);
            return;
        }

        // Migrate Content settings
        $content_settings = array();
        $content_fields = ['enable_on_content', 'enable_on_widgets', 'enable_galleries', 'enable_featured_images', 'enable_videos'];
        foreach ($content_fields as $field) {
            if (isset($old_settings[$field])) {
                $content_settings[$field] = $old_settings[$field];
            }
        }

        // Also migrate exclusion settings to content
        $exclusion_fields = ['exclude_pages', 'exclude_posts', 'exclude_post_types', 'exclude_css_selectors'];
        foreach ($exclusion_fields as $field) {
            if (isset($old_settings[$field])) {
                $content_settings[$field] = $old_settings[$field];
            }
        }

        if (!empty($content_settings)) {
            update_option('metaslider_lightbox_content_options', $content_settings);
        }

        // Migrate Manual Options settings
        $manual_settings = array();
        if (isset($old_settings['override_enlarge_on_click'])) {
            $manual_settings['override_enlarge_on_click'] = $old_settings['override_enlarge_on_click'];
        }

        if (!empty($manual_settings)) {
            update_option('metaslider_lightbox_manual_options', $manual_settings);
        }

        // Migrate Appearance settings (if any exist in future)
        $appearance_settings = array();
        // Add appearance fields here when they exist

        if (!empty($appearance_settings)) {
            update_option('metaslider_lightbox_appearance_options', $appearance_settings);
        }

        // Mark migration as complete
        update_option('metaslider_lightbox_migration_done', true);

        // Optionally keep the old settings for a while in case of rollback needs
        // Don't delete $old_settings immediately
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

            // Get settings from new separated structure
            $content_options = get_option('metaslider_lightbox_content_options', array());
            $manual_options = get_option('metaslider_lightbox_manual_options', array());
            $appearance_options = get_option('metaslider_lightbox_appearance_options', array());

            $default_options = array(
                'enable_on_content' => false,
                'enable_on_widgets' => false,
                'enable_galleries' => false,
                'enable_featured_images' => false,
                'enable_videos' => false,
                'override_enlarge_on_click' => true,
                'override_link_to_image_file' => true,
                'exclude_pages' => array(),
                'exclude_posts' => array(),
                'exclude_post_types' => array(),
                'exclude_css_selectors' => '',
            );

            // Merge all settings together for backwards compatibility
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
                'icon_hover_color' => '#e0e0e0',
                'background_opacity' => '0.9',
                'enable_on_content' => false,
                'enable_on_widgets' => false,
                'enable_galleries' => false,
                'enable_featured_images' => false,
                'enable_videos' => false,
                'override_enlarge_on_click' => true,
                'override_link_to_image_file' => true,
                'exclude_pages' => array(),
                'exclude_posts' => array(),
                'exclude_post_types' => array(),
                'exclude_css_selectors' => '',
            );
            add_option('metaslider_lightbox_general_options', $default_general_options);
            self::$cached_options['general'] = $default_general_options;
        }

        $metaslider_options = get_option('ml_lightbox_options', false);
        if ($metaslider_options === false) {
            $default_metaslider_options = array(
                'show_arrows' => true,
                'show_thumbnails' => false,
                'show_lightbox_button' => false,
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
        add_shortcode('ml_gallery', array($this, 'galleryShortcode'));
        
        $this->setupContentDetection();

        $this->setupEnlargeOnClickOverride();
    }

    public function setupMetasliderIntegration()
    {
        if (!$this->isMetasliderActive()) {
            return;
        }

        if (is_admin()) {
            add_filter('metaslider_advanced_settings', array($this, 'addSettings'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
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
                    'metaslider_image_attributes' => 15, // Target the img tag directly
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
            'MetaSlider Lightbox' => array(
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

        if (filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
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
            $showButton = isset($options['show_lightbox_button']) ? $options['show_lightbox_button'] : false;

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
                                    $attributes[$key] = '1200-800'; // Fallback dimensions
                                }
                            }
                        }
                    }
                }
            }
            // Built-in lightbox now handled entirely by JavaScript
            // No PHP attributes needed when using our unified JavaScript approach
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
                    'MetaSlider Lightbox requires MetaSlider and at least one other supported lightbox plugin to be installed and activated.',
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
                    'There is more than one lightbox plugin activated. This may cause conflicts with MetaSlider Lightbox',
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
                'Name' => 'MetaSlider Lightbox',
                'Version' => $this->version
            );
            $lightbox_name = 'MetaSlider Lightbox';
            $lightbox_settings_url = 'admin.php?page=metaslider-lightbox';
        }

        if (isset($slider->id)) {
            $settings = get_post_meta($slider->id, 'ml-slider_settings', true);
            $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

            $link = ! empty($lightbox_settings_url) ? sprintf(
                "<br><a href='%s' target='_blank'>%s</a>",
                admin_url($lightbox_settings_url),
                __("Edit settings", "ml-slider-lightbox")
            ) : '';

            $msl_lightbox = array(
                'lightbox' => array(
                    'priority' => 165,
                    'type' => 'checkbox',
                    'label' => __('Open in lightbox?', 'ml-slider-lightbox'),
                    'after' => $link,
                    'class' => 'coin flex responsive nivo',
                    'checked' => $this->isLightboxEnabled($enabled) ? 'checked' : '',
                    'helptext' => sprintf(
                        _x("All slides will open in a lightbox, using %s", "Name of a plugin", "ml-slider-lightbox"),
                        $lightbox_name
                    )
                ),
            );
            $aFields = array_merge($aFields, $msl_lightbox);
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
        return ($enabled === 'true');
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
                return true; // Always use built-in
            case 'third_party':
                return false; // Never use built-in
            case 'auto':
            default:
                break;
        }

        foreach ($this->supported_plugins as $name => $plugin) {
            if (isset($plugin['built_in']) && $plugin['built_in']) {
                continue; // Skip built-in entry
            }

            $path = $plugin['location'];
            if (! function_exists('get_plugins')) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }

            if (is_plugin_active($path)) {
                return false; // Third-party lightbox found, don't use built-in
            }

            foreach (get_plugins() as $plugin_path => $plugin_data) {
                if ($name === $plugin_data['Name'] && is_plugin_active($plugin_path)) {
                    return false; // Third-party lightbox found, don't use built-in
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
            self::$cached_lightbox_enabled = true; // Conservative approach for listing pages
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
        if (isset($options['load_assets_globally']) && $options['load_assets_globally']) {
            $should_load_assets = true;
        }
        else if (!$thirdPartyActive && ($this->hasContentDetectionEnabled() || $this->hasManualLightboxProcessing())) {
            $should_load_assets = true;
        }
        // Always load assets for manual lightbox processing (WordPress enlarge on click, etc.) when MetaSlider is disabled
        else if (!$metasliderActive && !$thirdPartyActive) {
            $should_load_assets = true;
        }
        else if ($metasliderActive && !$thirdPartyActive && $this->hasLightboxEnabledSliders()) {
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

        // Core LightGallery CSS (always needed)
        wp_enqueue_style(
            'ml-lightgallery-css',
            'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/css/lightgallery.min.css',
            array(),
            '2.7.1'
        );

        $css_dependencies = array('ml-lightgallery-css');

        // Video CSS (only if needed)
        if ($needs_video) {
            wp_enqueue_style(
                'lightgallery-video-css',
                'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/css/lg-video.css',
                array('ml-lightgallery-css'),
                '2.7.1'
            );
            $css_dependencies[] = 'lightgallery-video-css';
        }

        // Thumbnail CSS (only if needed)
        if ($needs_thumbnails) {
            wp_enqueue_style(
                'lightgallery-thumbnail-css',
                'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/css/lg-thumbnail.css',
                array('ml-lightgallery-css'),
                '2.7.1'
            );
            $css_dependencies[] = 'lightgallery-thumbnail-css';
        }

        // Plugin CSS
        wp_enqueue_style(
            'ml-lightbox-public-css',
            plugin_dir_url(__FILE__) . 'assets/css/ml-lightbox-public.css',
            $css_dependencies,
            $this->version
        );

        $this->addCustomLightboxCss();

        // Core LightGallery JS (always needed)
        wp_enqueue_script(
            'ml-lightgallery-js',
            plugin_dir_url(__FILE__) . 'assets/js/lightgallery.min.js',
            array('jquery'),
            $this->version,
            true
        );

        // Video JS (only if needed)
        if ($needs_video) {
            wp_enqueue_script(
                'lightgallery-video',
                'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/plugins/video/lg-video.min.js',
                array('ml-lightgallery-js'),
                '2.7.1',
                true
            );
        }

        // Thumbnail JS (only if needed)
        if ($needs_thumbnails) {
            wp_enqueue_script(
                'lightgallery-thumbnail',
                'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/plugins/thumbnail/lg-thumbnail.min.js',
                array('ml-lightgallery-js'),
                '2.7.1',
                true
            );
        }

        // Vimeo thumbnail plugin (only if video needed)
        if ($needs_video) {
            wp_enqueue_script(
                'lightgallery-vimeo-thumbnail',
                'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/plugins/vimeoThumbnail/lg-vimeo-thumbnail.min.js',
                array('ml-lightgallery-js'),
                '2.7.1',
                true
            );
        }

        // VideoJS (only if video needed)
        if ($needs_video) {
            wp_enqueue_style(
                'videojs-css',
                'https://vjs.zencdn.net/8.5.2/video-js.css',
                array(),
                '8.5.2'
            );

            wp_enqueue_script(
                'videojs',
                'https://vjs.zencdn.net/8.5.2/video.min.js',
                array(),
                '8.5.2',
                true
            );
        }

        // Build dependencies array dynamically
        $js_dependencies = array('ml-lightgallery-js');
        if ($needs_video) {
            $js_dependencies[] = 'lightgallery-video';
            $js_dependencies[] = 'lightgallery-vimeo-thumbnail';
            $js_dependencies[] = 'videojs';
        }
        if ($needs_thumbnails) {
            $js_dependencies[] = 'lightgallery-thumbnail';
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
                    'lightbox_enabled' => $this->isLightboxEnabled(isset($settings['lightbox']) ? $settings['lightbox'] : null)
                );
            }
        }

        $general_options = $this->getCachedGeneralOptions();
        
        $lightbox_settings = array(
            'slider_settings' => $slider_settings,
            'metaslider_options' => array(
                'show_arrows' => isset($metaslider_options['show_arrows']) ? $metaslider_options['show_arrows'] : true,
                'show_thumbnails' => isset($metaslider_options['show_thumbnails']) ? $metaslider_options['show_thumbnails'] : false,
                'show_lightbox_button' => isset($metaslider_options['show_lightbox_button']) ? $metaslider_options['show_lightbox_button'] : false,
                'show_captions' => isset($metaslider_options['show_captions']) ? $metaslider_options['show_captions'] : true,
            ),
            'enable_on_content' => isset($general_options['enable_on_content']) ? $general_options['enable_on_content'] : false,
            'enable_on_widgets' => isset($general_options['enable_on_widgets']) ? $general_options['enable_on_widgets'] : false,
            'enable_galleries' => isset($general_options['enable_galleries']) ? $general_options['enable_galleries'] : false,
            'enable_featured_images' => isset($general_options['enable_featured_images']) ? $general_options['enable_featured_images'] : false,
            'enable_videos' => isset($general_options['enable_videos']) ? $general_options['enable_videos'] : false,
            'override_enlarge_on_click' => isset($general_options['override_enlarge_on_click']) ? $general_options['override_enlarge_on_click'] : true,
            'override_link_to_image_file' => isset($general_options['override_link_to_image_file']) ? $general_options['override_link_to_image_file'] : true,
            'license_key' => ML_LIGHTGALLERY_LICENSE_KEY
        );
        
        $lightbox_settings = apply_filters('ml_lightbox_settings', $lightbox_settings);
        
        wp_localize_script('ml-lightgallery-clean', 'mlLightboxSettings', $lightbox_settings);
    }

    private function addCustomLightboxCss()
    {
        $options = $this->getPluginOptions();

        $background_color = isset($options['background_color']) ? $options['background_color'] : '#000000';
        $button_color = isset($options['button_color']) ? $options['button_color'] : '#000000';
        $button_text_color = isset($options['button_text_color']) ? $options['button_text_color'] : '#ffffff';
        $button_hover_color = isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0';
        $button_hover_text_color = isset($options['button_hover_text_color']) ? $options['button_hover_text_color'] : '#000000';
        $icon_color = isset($options['icon_color']) ? $options['icon_color'] : '#ffffff';
        $icon_hover_color = isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#e0e0e0';
        $background_opacity = isset($options['background_opacity']) ? $options['background_opacity'] : '0.9';

        $icon_color_encoded = urlencode($icon_color);

        $close_svg = 'data:image/svg+xml;charset=utf-8,' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cline%20x1%3D%2218%22%20y1%3D%226%22%20x2%3D%226%22%20y2%3D%2218%22%3E%3C%2Fline%3E%3Cline%20x1%3D%226%22%20y1%3D%226%22%20x2%3D%2218%22%20y2%3D%2218%22%3E%3C%2Fline%3E%3C%2Fsvg%3E';
        $prev_svg = 'data:image/svg+xml;charset=utf-8,' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%2215%2C18%209%2C12%2015%2C6%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E';
        $next_svg = 'data:image/svg+xml;charset=utf-8,' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%229%2C18%2015%2C12%209%2C6%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E';

        $close_icon_url = 'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cline%20x1%3D%2218%22%20y1%3D%226%22%20x2%3D%226%22%20y2%3D%2218%22%3E%3C%2Fline%3E%3Cline%20x1%3D%226%22%20y1%3D%226%22%20x2%3D%2218%22%20y2%3D%2218%22%3E%3C%2Fline%3E%3C%2Fsvg%3E';
        $prev_icon_url = 'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%2215%2C18%209%2C12%2015%2C6%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E';
        $next_icon_url = 'data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2224%22%20height%3D%2224%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%229%2C18%2015%2C12%209%2C6%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E';

        $custom_css = '
            /* MetaSlider Lightbox Custom Colors */
            :root {
                --ml-lightbox-icon-color: ' . esc_html($icon_color) . ' !important;
                --ml-lightbox-icon-hover-color: ' . esc_html($icon_hover_color) . ' !important;
            }
            
            .lg-backdrop {
                background-color: ' . esc_html($background_color) . ' !important;
                opacity: ' . esc_html($background_opacity) . ' !important;
            }
            
            /* Apply custom background color and opacity to thumbnail area */
            .lg-outer .lg-thumb-outer {
                background-color: ' . esc_html($background_color) . ' !important;
                opacity: ' . esc_html($background_opacity) . ' !important;
            }
            
            .lg-outer .lg-close,
            .lg-outer .lg-prev,
            .lg-outer .lg-next,
            .lg-outer .lg-toolbar .lg-icon,
            .lg-outer .lg-counter {
                background-color: ' . esc_html($button_color) . ' !important;
                color: var(--ml-lightbox-icon-color) !important;
            }
            
            .lg-outer .lg-close:hover,
            .lg-outer .lg-prev:hover,
            .lg-outer .lg-next:hover,
            .lg-outer .lg-toolbar .lg-icon:hover,
            .lg-outer .lg-counter:hover {
                background-color: ' . esc_html($button_hover_color) . ' !important;
                color: ' . esc_html($button_hover_text_color) . ' !important;
            }

            /* Open in Lightbox button styling */
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
            
        ';

        wp_add_inline_style('ml-lightbox-public-css', $custom_css);
    }

    public function enqueueAdminScripts($hook)
    {
        if (strpos($hook, 'metaslider-lightbox') === false) {
            return;
        }

        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('wp-color-picker');
        
        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');

        wp_enqueue_script(
            'ml-lightbox-admin',
            plugin_dir_url(__FILE__) . 'assets/js/ml-lightbox-admin.js',
            array('jquery', 'wp-color-picker', 'select2'),
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
                    'MetaSlider Lightbox requires MetaSlider to be installed and activated.',
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
                    <h1>
                        <?php 
                        if ($this->isProPluginActive()) {
                            _e('MetaSlider Lightbox Pro', 'ml-slider-lightbox');
                        } else {
                            _e('MetaSlider Lightbox', 'ml-slider-lightbox');
                        }
                        ?>
                    </h1>
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
        echo '<span class="ml-toggle-switch" onclick="document.getElementById(\'' . esc_js($unique_id) . '\').click();"></span>';
        echo '</div>';
        echo '</div>';
    }

    public function enqueueAdminAssets($hook)
    {
        if (strpos($hook, 'metaslider-lightbox') === false) {
            return;
        }

        // Enqueue WordPress color picker and Select2 for admin functionality
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('wp-color-picker');

        wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array('jquery'), '4.1.0', true);
        wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), '4.1.0');

        wp_enqueue_style(
            'ml-lightbox-admin-style',
            plugin_dir_url(__FILE__) . 'assets/css/ml-lightbox-admin.css',
            array(),
            $this->version
        );

        wp_enqueue_script(
            'ml-lightbox-admin-script',
            plugin_dir_url(__FILE__) . 'assets/js/ml-lightbox-admin.js',
            array('jquery', 'wp-color-picker', 'select2'),
            $this->version,
            true
        );
    }

    public function addAdminMenu()
    {
        $menu_title = $this->isProPluginActive()
            ? __('Lightbox Pro', 'ml-slider-lightbox')
            : __('Lightbox', 'ml-slider-lightbox');

        $page_title = $this->isProPluginActive()
            ? __('MetaSlider Lightbox Pro', 'ml-slider-lightbox')
            : __('MetaSlider Lightbox', 'ml-slider-lightbox');

        add_menu_page(
            $page_title,
            $menu_title,
            'manage_options',
            'metaslider-lightbox',
            array($this, 'renderMainPage'),
            'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHN2ZyBmaWxsPSIjZmZmIiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4IiB2aWV3Qm94PSIwIDAgMjU1LjggMjU1LjgiIHN0eWxlPSJmaWxsOiNmZmYiIHhtbDpzcGFjZT0icHJlc2VydmUiPjxnPjxwYXRoIGQ9Ik0xMjcuOSwwQzU3LjMsMCwwLDU3LjMsMCwxMjcuOWMwLDcwLjYsNTcuMywxMjcuOSwxMjcuOSwxMjcuOWM3MC42LDAsMTI3LjktNTcuMywxMjcuOS0xMjcuOUMyNTUuOCw1Ny4zLDE5OC41LDAsMTI3LjksMHogTTE2LjQsMTc3LjFsOTIuNS0xMTcuNUwxMjQuMiw3OWwtNzcuMyw5OC4xSDE2LjR6IE0xNzAuNSwxNzcuMWwtMzguOS00OS40bDE1LjUtMTkuNmw1NC40LDY5SDE3MC41eiBNMjA4LjUsMTc3LjFMMTQ2LjksOTkgbC02MS42LDc4LjJoLTMxbDkyLjUtMTE3LjVsOTIuNSwxMTcuNUgyMDguNXoiLz48L2c+PC9zdmc+Cg=='
        );
    }

    public function renderMainPage()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'detection';

        $tabs = array(
            'detection' => '🎯 ' . __('Automatic', 'ml-slider-lightbox'),
            'manual' => '👆 ' . __('Manual', 'ml-slider-lightbox'),
            'appearance' => '🎨 ' . __('Appearance', 'ml-slider-lightbox'),
            'behavior' => '⚙️ ' . __('Behavior', 'ml-slider-lightbox')
        );

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


                    <h2><?php echo esc_html(__('Exclusions', 'ml-slider-lightbox')); ?></h2>
                    <?php $this->contentExclusionsCallback(); ?>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('metaslider_lightbox_content', 'metaslider_lightbox_content_exclusions'); ?>
                    </table>
                    
                    <?php submit_button(); ?>
                </form>
            <?php elseif ($current_tab === 'appearance') : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('metaslider_lightbox_appearance');
                    ?>
                    <h2><?php echo esc_html(__('Background & Overlay', 'ml-slider-lightbox')); ?></h2>
                    <?php $this->appearanceBackgroundCallback(); ?>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('metaslider_lightbox_appearance', 'metaslider_lightbox_appearance_background'); ?>
                    </table>

                    <h2><?php echo esc_html(__('Navigation Icons', 'ml-slider-lightbox')); ?></h2>
                    <?php $this->appearanceIconsCallback(); ?>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('metaslider_lightbox_appearance', 'metaslider_lightbox_appearance_icons'); ?>
                    </table>

                    <h2><?php echo esc_html(__('Buttons', 'ml-slider-lightbox')); ?></h2>
                    <?php $this->appearanceButtonsCallback(); ?>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('metaslider_lightbox_appearance', 'metaslider_lightbox_appearance_buttons'); ?>
                    </table>
                    
                    <?php submit_button(); ?>
                </form>
            <?php elseif ($current_tab === 'behavior') : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('metaslider_lightbox_settings');
                    ?>
                    <h2><?php echo esc_html(__('Navigation & Controls', 'ml-slider-lightbox')); ?></h2>
                    <?php $this->behaviorNavigationCallback(); ?>
                    <table class="form-table" role="presentation">
                        <?php do_settings_fields('metaslider_lightbox_settings', 'metaslider_lightbox_behavior_navigation'); ?>
                    </table>

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
                    <?php endif; ?>
                    
                    <?php submit_button(); ?>
                </form>
            <?php elseif ($current_tab === 'manual') : ?>
                <form method="post" action="options.php">
                    <?php settings_fields('metaslider_lightbox_manual'); ?>
                    <h2><?php echo esc_html(__('Manual Lightbox Options', 'ml-slider-lightbox')); ?></h2>
                    <?php
                    // Only show the manual options section
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
            <?php elseif ($current_tab === 'pro') : ?>
                <div class="pro-upgrade-content">
                    <div class="pro-header">
                        <h2><?php _e('Upgrade to MetaSlider Lightbox Pro', 'ml-slider-lightbox'); ?></h2>
                        <p><?php _e('Unlock advanced lightbox features and take your galleries to the next level!', 'ml-slider-lightbox'); ?></p>
                    </div>
                    
                    <div class="pro-features">
                        <h3><?php _e('Pro Features Include:', 'ml-slider-lightbox'); ?></h3>
                        <div class="features-grid">
                            <div class="feature-item">
                                <span class="feature-icon">📱</span>
                                <h4><?php _e('Thumbnails', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Navigate through images with thumbnail navigation', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">🔍</span>
                                <h4><?php _e('Zoom Images', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Allow users to zoom into image details', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">👌</span>
                                <h4><?php _e('Pinch to Zoom', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Touch-friendly zoom controls for mobile devices', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">🔗</span>
                                <h4><?php _e('Custom URL for Each Gallery', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Share specific gallery images with custom URLs', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">📱</span>
                                <h4><?php _e('Social Media Sharing', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Enable sharing to Facebook, Twitter, and more', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">▶️</span>
                                <h4><?php _e('Slideshow', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Auto-advance through images with slideshow mode', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">🔴</span>
                                <h4><?php _e('Pagers', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Visual page indicators for easy navigation', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">🔄</span>
                                <h4><?php _e('Rotate', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Rotate images within the lightbox', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">🔁</span>
                                <h4><?php _e('Flip', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Flip images horizontally or vertically', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">🖥️</span>
                                <h4><?php _e('Fullscreen', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Immersive fullscreen viewing experience', 'ml-slider-lightbox'); ?></p>
                            </div>
                            <div class="feature-item">
                                <span class="feature-icon">✨</span>
                                <h4><?php _e('And Much More', 'ml-slider-lightbox'); ?></h4>
                                <p><?php _e('Advanced animations, video support, and more!', 'ml-slider-lightbox'); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="pro-cta">
                        <h3><?php _e('Ready to Upgrade?', 'ml-slider-lightbox'); ?></h3>
                        <p><?php _e('Join thousands of satisfied customers who have upgraded to Pro!', 'ml-slider-lightbox'); ?></p>
                        <a href="#" class="button button-primary button-large pro-upgrade-btn" target="_blank">
                            <?php _e('Upgrade to Pro Now', 'ml-slider-lightbox'); ?>
                        </a>
                        <p class="pro-guarantee">
                            <small><?php _e('30-day money-back guarantee • Lifetime updates • Priority support', 'ml-slider-lightbox'); ?></small>
                        </p>
                    </div>
                </div>
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
            'metaslider_lightbox_content_exclusions',
            __('Exclusions', 'ml-slider-lightbox'),
            array($this, 'contentExclusionsCallback'),
            'metaslider_lightbox_content'
        );

        add_settings_field(
            'exclude_pages',
            __('Exclude specific pages', 'ml-slider-lightbox'),
            array($this, 'excludePagesCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'exclude_posts',
            __('Exclude specific posts', 'ml-slider-lightbox'),
            array($this, 'excludePostsCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'exclude_post_types',
            __('Exclude specific post types', 'ml-slider-lightbox'),
            array($this, 'excludePostTypesCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );

        add_settings_field(
            'exclude_css_selectors',
            __('Exclude by CSS selector', 'ml-slider-lightbox'),
            array($this, 'excludeCssSelectorsCallback'),
            'metaslider_lightbox_content',
            'metaslider_lightbox_content_exclusions'
        );
        /**
         * APPEARANCE TAB
         */
        
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
            'icon_color',
            __('Icon Color', 'ml-slider-lightbox'),
            array($this, 'iconColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_icons'
        );

        add_settings_field(
            'icon_hover_color',
            __('Icon Hover Color', 'ml-slider-lightbox'),
            array($this, 'iconHoverColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_icons'
        );
        
        add_settings_field(
            'button_color',
            __('Button Color', 'ml-slider-lightbox'),
            array($this, 'buttonColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_buttons'
        );

        add_settings_field(
            'button_hover_color',
            __('Button Hover Color', 'ml-slider-lightbox'),
            array($this, 'buttonHoverColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_buttons'
        );

        add_settings_field(
            'button_text_color',
            __('Button Text Color', 'ml-slider-lightbox'),
            array($this, 'buttonTextColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_buttons'
        );

        add_settings_field(
            'button_hover_text_color',
            __('Button Hover Text Color', 'ml-slider-lightbox'),
            array($this, 'buttonHoverTextColorCallback'),
            'metaslider_lightbox_appearance',
            'metaslider_lightbox_appearance_buttons'
        );

        /**
         * BEHAVIOR TAB
         */
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
    }

    public function sanitizeContentOptions($input)
    {
        if (!current_user_can('manage_options')) {
            return get_option('metaslider_lightbox_content_options', array());
        }

        if (!is_array($input)) {
            return get_option('metaslider_lightbox_content_options', array());
        }

        $sanitized = array();

        $content_fields = ['enable_on_content', 'enable_on_widgets', 'enable_galleries', 'enable_featured_images', 'enable_videos'];

        foreach ($content_fields as $field) {
            $sanitized[$field] = isset($input[$field]) ? true : false;
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

        if ($input === null) {
            $input = array();
        }

        if (!is_array($input)) {
            return get_option('metaslider_lightbox_manual_options', array());
        }

        $sanitized = array();
        $sanitized['override_enlarge_on_click'] = isset($input['override_enlarge_on_click']) ? true : false;
        $sanitized['override_link_to_image_file'] = isset($input['override_link_to_image_file']) ? true : false;

        $current_options = get_option('metaslider_lightbox_manual_options', array());
        if (isset($current_options['override_link_to_media'])) {
            // Don't include the stale field in sanitized output
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

        if (isset($input['background_opacity'])) {
            $opacity = floatval($input['background_opacity']);
            $sanitized['background_opacity'] = max(0, min(1, $opacity));
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

        return $sanitized;
    }

    public function generalSettingsSectionCallback()
    {
        echo '<p>' . __('Configure general lightbox settings that apply site-wide.', 'ml-slider-lightbox') . '</p>';
    }

    public function metasliderSettingsSectionCallback()
    {
        echo '<p>' . __('Configure settings specific to MetaSlider lightbox functionality.', 'ml-slider-lightbox') . '</p>';
    }

    public function contentWhereCallback()
    {
        echo '<p>' . __('Select where the lightbox will be enabled automatically.', 'ml-slider-lightbox') . '</p>';
    }


    public function contentExclusionsCallback()
    {
        echo '<p>' . __('Specify CSS classes and selectors to exclude from automatic lightbox enhancement.', 'ml-slider-lightbox') . '</p>';
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
            __('When enabled, individual images without links and "Enlarge to Click" set in posts and pages will automatically open in the lightbox.', 'ml-slider-lightbox')
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
            __('When enabled, images and videos in widget areas will automatically open in the lightbox.', 'ml-slider-lightbox')
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
            __('When enabled, images in WordPress [gallery] shortcodes and Gutenberg Gallery blocks will automatically open in the lightbox.', 'ml-slider-lightbox')
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
            __('When enabled, featured images (post thumbnails) will automatically open in the lightbox when clicked.', 'ml-slider-lightbox')
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
            __('When enabled, standalone video blocks (HTML5 videos, YouTube embeds, Vimeo embeds) in post/page content will automatically open in the lightbox.', 'ml-slider-lightbox')
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
        echo '<p class="description">' . __('Background color for the lightbox overlay. Dark colors work best for image viewing.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['button_color']) ? $options['button_color'] : '#000000';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color for "Open in Lightbox" buttons that appear on slides, images and videos.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonTextColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['button_text_color']) ? $options['button_text_color'] : '#ffffff';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_text_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Text color for "Open in Lightbox" buttons that appear on slides, images and videos.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['button_hover_color']) ? $options['button_hover_color'] : '#f0f0f0';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Background color when hovering over "Open in Lightbox" buttons.', 'ml-slider-lightbox') . '</p>';
    }

    public function buttonHoverTextColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['button_hover_text_color']) ? $options['button_hover_text_color'] : '#000000';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[button_hover_text_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Text color when hovering over "Open in Lightbox" buttons.', 'ml-slider-lightbox') . '</p>';
    }

    public function iconColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['icon_color']) ? $options['icon_color'] : '#ffffff';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[icon_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Color for close button, navigation arrows, and other lightbox icons.', 'ml-slider-lightbox') . '</p>';
    }

    public function iconHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $color = isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#e0e0e0';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_appearance_options[icon_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Icon color when hovering over navigation elements. Creates visual feedback for users.', 'ml-slider-lightbox') . '</p>';
    }

    public function backgroundOpacityCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $opacity = isset($options['background_opacity']) ? $options['background_opacity'] : '0.9';

        echo '<input type="range" name="metaslider_lightbox_appearance_options[background_opacity]" min="0" max="1" step="0.1" value="' . esc_attr($opacity) . '" oninput="this.nextElementSibling.value = this.value" />';
        echo '<output>' . esc_html($opacity) . '</output>';
        echo '<p class="description">' . __('Set the opacity of the lightbox background (0 = transparent, 1 = opaque).', 'ml-slider-lightbox') . '</p>';
    }

    public function showArrowsCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['show_arrows']) ? $options['show_arrows'] : true;

        $this->renderToggleSwitch(
            'ml_lightbox_options[show_arrows]',
            $checked,
            __('Show navigation arrows in lightbox', 'ml-slider-lightbox'),
            __('Display left/right arrows for navigating between slides. Also enables keyboard navigation (← → keys, ESC to close).', 'ml-slider-lightbox')
        );
    }
    /**
     * New section callbacks for the improved admin UI
     */

    public function appearanceBackgroundCallback()
    {
        echo '<p>' . __('Customize the lightbox background and overlay appearance.', 'ml-slider-lightbox') . '</p>';
    }

    public function appearanceIconsCallback()
    {
        echo '<p>' . __('Customize navigation icons (close, previous, next arrows) that appear in all lightboxes.', 'ml-slider-lightbox') . '</p>';
    }

    public function appearanceButtonsCallback()
    {
        echo '<p>' . __('Customize the "Open in Lightbox" buttons that appear on slides, images, and videos.', 'ml-slider-lightbox') . '</p>';
    }

    public function behaviorNavigationCallback()
    {
        echo '<p>' . __('Configure navigation and control options that apply to all lightboxes.', 'ml-slider-lightbox') . '</p>';
    }
    public function showThumbnailsCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['show_thumbnails']) ? $options['show_thumbnails'] : false;

        $this->renderToggleSwitch(
            'ml_lightbox_options[show_thumbnails]',
            $checked,
            __('Show thumbnails', 'ml-slider-lightbox'),
            __('Display thumbnail images at the bottom of the lightbox for easy navigation between slides/images/videos.', 'ml-slider-lightbox')
        );
    }

    public function showLightboxButtonCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['show_lightbox_button']) ? $options['show_lightbox_button'] : false;

        $this->renderToggleSwitch(
            'ml_lightbox_options[show_lightbox_button]',
            $checked,
            __('Show "Open in Lightbox" button', 'ml-slider-lightbox'),
            __('When enabled, shows a button to open lightbox. When disabled, clicking the slide/image/video directly opens the lightbox.', 'ml-slider-lightbox')
        );
    }

    public function showCaptionsCallback()
    {
        $options = $this->getCachedMetaSliderOptions();
        $checked = isset($options['show_captions']) ? $options['show_captions'] : true;

        $this->renderToggleSwitch(
            'ml_lightbox_options[show_captions]',
            $checked,
            __('Show captions', 'ml-slider-lightbox'),
            __('When enabled, displays image captions and slide descriptions in the lightbox. When disabled, no captions are shown.', 'ml-slider-lightbox')
        );
    }

    public function behaviorZoomCallback()
    {
        if ($this->isProPluginActive()) {
            echo '<p>' . __('Advanced lightbox features are available through MetaSlider Lightbox Pro.', 'ml-slider-lightbox') . '</p>';
        } else {
            echo '<p>' . __('Get a preview of the advanced features available in MetaSlider Lightbox Pro.', 'ml-slider-lightbox') . '</p>';
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

        echo '<select name="metaslider_lightbox_content_options[exclude_pages][]" multiple class="ml-select2-pages" style="width: 100%;">';
        
        if (!empty($pages)) {
            foreach ($pages as $page) {
                $selected = in_array($page->ID, $selected_pages) ? 'selected' : '';
                echo '<option value="' . esc_attr($page->ID) . '" ' . $selected . '>';
                echo esc_html($page->post_title);
                echo '</option>';
            }
        }
        
        echo '</select>';
        echo '<p class="description">' . __('Select pages where lightbox should be disabled. Shows up to 100 most recent pages. Type to search.', 'ml-slider-lightbox') . '</p>';
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

        echo '<select name="metaslider_lightbox_content_options[exclude_posts][]" multiple class="ml-select2-posts" style="width: 100%;">';
        
        if (!empty($posts)) {
            foreach ($posts as $post) {
                $selected = in_array($post->ID, $selected_posts) ? 'selected' : '';
                echo '<option value="' . esc_attr($post->ID) . '" ' . $selected . '>';
                echo esc_html($post->post_title);
                echo '</option>';
            }
        }
        
        echo '</select>';
        echo '<p class="description">' . __('Select posts where lightbox should be disabled. Shows up to 100 most recent posts. Type to search.', 'ml-slider-lightbox') . '</p>';
    }

    public function excludePostTypesCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $selected_post_types = isset($options['exclude_post_types']) ? $options['exclude_post_types'] : array();

        $post_types = get_post_types(array(
            'public' => true,
            'show_ui' => true
        ), 'objects');

        unset($post_types['attachment']);

        echo '<select name="metaslider_lightbox_content_options[exclude_post_types][]" multiple class="ml-select2-post-types" style="width: 100%;">';

        if (!empty($post_types)) {
            foreach ($post_types as $post_type) {
                $selected = in_array($post_type->name, $selected_post_types) ? 'selected' : '';
                echo '<option value="' . esc_attr($post_type->name) . '" ' . $selected . '>';
                echo esc_html($post_type->label . ' (' . $post_type->name . ')');
                echo '</option>';
            }
        }

        echo '</select>';
        echo '<p class="description">' . __('Select post types where lightbox should be disabled entirely.', 'ml-slider-lightbox') . '</p>';
    }

    public function excludeCssSelectorsCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->getCachedGeneralOptions();
        $selectors = isset($options['exclude_css_selectors']) ? $options['exclude_css_selectors'] : '';

        echo '<textarea name="metaslider_lightbox_content_options[exclude_css_selectors]" rows="4" cols="50" style="width: 100%;">';
        echo esc_textarea($selectors);
        echo '</textarea>';
        echo '<p class="description">' . __('CSS selectors for elements to exclude from lightbox (one per line). Examples:<br>.no-lightbox<br>.custom-gallery img<br>#sidebar .widget-area', 'ml-slider-lightbox') . '</p>';
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
            __('Enable Hash URLs', 'ml-slider-lightbox'),
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
            __('Automatic slideshow progression with customizable timing and pause-on-hover.', 'ml-slider-lightbox'),
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
     * Check if MetaSlider Lightbox Pro plugin is active
     */
    private function isProPluginActive()
    {
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . '/wp-admin/includes/plugin.php');
        }
        return is_plugin_active('metaslider-lightbox-pro/metaslider-lightbox-pro.php');
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
            'icon_hover_color' => '#e0e0e0',
            'background_opacity' => '0.9',
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
        $showButton = isset($options['show_lightbox_button']) ? $options['show_lightbox_button'] : false;
        
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
        
        if ($thirdPartyActive || $showButton) {
            return $attributes;
        }
        
        if (empty($attributes['href'])) {
            $attributes['href'] = $media_url;
        }

        $attributes['data-src'] = $media_url;
        
        $showArrows = isset($options['show_arrows']) ? $options['show_arrows'] : true;
        $showThumbnails = isset($options['show_thumbnails']) ? $options['show_thumbnails'] : false;
        
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
        $show_thumbnails = isset($metaslider_options['show_thumbnails']) ? $metaslider_options['show_thumbnails'] : false;

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
        }

        if (isset($options['enable_videos']) && $options['enable_videos']) {
            add_filter('render_block', array($this, 'enhanceVideoBlock'), 10, 2);
        }
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

        if ($this->shouldExcludePage()) {
            return $content;
        }

        $content = $this->removeExcludedElements($content);
        
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
            
            return '<a href="' . esc_url($img_src) . '" data-src="' . esc_url($img_src) . '" data-thumb="' . esc_url($img_src) . '" class="ml-widget-lightbox">' . $img_tag . '</a>';
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
        $excluded_pages = isset($options['exclude_pages']) ? $options['exclude_pages'] : array();
        $excluded_posts = isset($options['exclude_posts']) ? $options['exclude_posts'] : array();
        $excluded_post_types = isset($options['exclude_post_types']) ? $options['exclude_post_types'] : array();

        global $post;
        if ($post) {
            if (!empty($excluded_post_types) && in_array($post->post_type, $excluded_post_types)) {
                return true;
            }

            if ($post->post_type === 'page' && in_array($post->ID, $excluded_pages)) {
                return true;
            }

            if ($post->post_type === 'post' && in_array($post->ID, $excluded_posts)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove images that match excluded CSS selectors
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

    private function commentOutImages($html)
    {
        return preg_replace('/<img\b([^>]*)>/i', '<img$1 data-ml-exclude="true">', $html);
    }

    private function processManualLightboxOptions($content)
    {
        // 1a. Handle class-based lightbox on container elements (.ml-lightbox-enabled) - highest priority
        $content = preg_replace_callback(
            '/<(figure|div|span|ul|section)([^>]*class="[^"]*ml-lightbox-enabled[^"]*"[^>]*)>(.*?)<\/\1>/is',
            function($matches) {
                $tag_name = $matches[1];
                $element_attributes = $matches[2];
                $element_content = $matches[3];

                // Skip if already processed
                if (strpos($element_attributes, 'ml-gallery-lightbox') !== false) {
                    return $matches[0];
                }

                // Count how many images/links are inside this container
                $image_link_count = preg_match_all('/<a[^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*>/i', $element_content);
                $image_count = preg_match_all('/<img[^>]*src="[^"]*"[^>]*>/i', $element_content);

                // If multiple images or image links, treat as gallery
                if ($image_link_count > 1 || $image_count > 1) {
                    // First, process existing image links
                    $enhanced_content = preg_replace_callback(
                        '/<a([^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*)>/i',
                        function($link_matches) {
                            $link_attributes = $link_matches[1];

                            // Skip if already has lightbox class
                            if (strpos($link_attributes, 'ml-gallery-lightbox') !== false ||
                                strpos($link_attributes, 'data-src') !== false) {
                                return $link_matches[0];
                            }

                            // Add ml-gallery-lightbox class
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

                    // Second, wrap standalone images (not already in links) with <a> tags
                    $enhanced_content = preg_replace_callback(
                        '/<img([^>]*src="([^"]*)"[^>]*)>/i',
                        function($img_matches) use ($enhanced_content) {
                            $img_tag = $img_matches[0];
                            $img_src = $img_matches[2];

                            // Skip if this image is already inside an <a> tag
                            $img_position = strpos($enhanced_content, $img_tag);
                            if ($img_position !== false) {
                                $before_img = substr($enhanced_content, 0, $img_position);
                                // Check if there's an unclosed <a> tag before this image
                                $last_a_open = strrpos($before_img, '<a');
                                $last_a_close = strrpos($before_img, '</a>');
                                if ($last_a_open !== false && ($last_a_close === false || $last_a_open > $last_a_close)) {
                                    // Image is inside an <a> tag, skip wrapping
                                    return $img_tag;
                                }
                            }

                            // Generate full-size URL
                            $full_url = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $img_src);

                            // Wrap the image with a lightbox link
                            return '<a href="' . esc_attr($full_url) . '" class="ml-gallery-lightbox" data-src="' . esc_attr($full_url) . '" data-thumb="' . esc_attr($img_src) . '">' . $img_tag . '</a>';
                        },
                        $enhanced_content
                    );

                    return '<' . $tag_name . $element_attributes . '>' . $enhanced_content . '</' . $tag_name . '>';
                }

                // Single image: use original single-image logic
                if (preg_match('/<img[^>]*src="([^"]*)"[^>]*>/i', $element_content, $img_matches)) {
                    $img_src = $img_matches[1];
                    $full_url = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $img_src);

                    // Remove .ml-lightbox-enabled from any child img elements to prevent double processing
                    $element_content = preg_replace('/(<img[^>]*class="[^"]*)\bml-lightbox-enabled\b([^"]*"[^>]*>)/i', '$1$2', $element_content);

                    // Add lightbox attributes to the container element
                    $enhanced_attributes = $element_attributes . ' data-src="' . esc_attr($full_url) . '" data-thumb="' . esc_attr($img_src) . '"';
                    return '<' . $tag_name . $enhanced_attributes . '>' . $element_content . '</' . $tag_name . '>';
                }

                return $matches[0];
            },
            $content
        );

        // 1b. Handle data-lg attribute on gallery containers - standard LightGallery approach
        $content = preg_replace_callback(
            '/<(figure|div|ul|section)([^>]*data-lg[^>]*)>(.*?)<\/\1>/is',
            function($matches) {
                $tag_name = $matches[1];
                $element_attributes = $matches[2];
                $element_content = $matches[3];

                // Skip if already processed
                if (strpos($element_attributes, 'ml-gallery-lightbox') !== false) {
                    return $matches[0];
                }

                // Add ml-gallery-lightbox class to all image links within this container
                $enhanced_content = preg_replace_callback(
                    '/<a([^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*)>/i',
                    function($link_matches) {
                        $link_attributes = $link_matches[1];

                        // Skip if already has lightbox class
                        if (strpos($link_attributes, 'ml-gallery-lightbox') !== false ||
                            strpos($link_attributes, 'data-src') !== false) {
                            return $link_matches[0];
                        }

                        // Add ml-gallery-lightbox class
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

        // 1c. Handle class-based lightbox directly on img elements (.ml-lightbox-enabled) - flexible attribute order
        $content = preg_replace_callback(
            '/<img[^>]*>/i',
            function($matches) use ($content) {
                $img_tag = $matches[0];

                // Check if this img has .ml-lightbox-enabled class (anywhere in the attributes)
                if (strpos($img_tag, 'ml-lightbox-enabled') === false) {
                    return $img_tag;
                }

                // Skip if already processed
                if (strpos($img_tag, 'data-src') !== false) {
                    return $img_tag;
                }

                // Check if this img is inside a container that already has .ml-lightbox-enabled class
                $img_position = strpos($content, $img_tag);
                if ($img_position !== false) {
                    $before_img = substr($content, 0, $img_position);

                    // Look for opening container tag with .ml-lightbox-enabled before this img
                    if (preg_match('/<(figure|div|span)[^>]*class="[^"]*ml-lightbox-enabled[^"]*"[^>]*>(?!.*<\/\1>)$/s', $before_img)) {
                        // This img is inside a container with .ml-lightbox-enabled, skip individual processing
                        return $img_tag;
                    }
                }

                // Extract src attribute (flexible position)
                if (preg_match('/src="([^"]*)"/', $img_tag, $src_matches)) {
                    $img_src = $src_matches[1];
                    $full_url = preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $img_src);

                    // Wrap the img in a lightbox link
                    return '<a href="' . esc_url($full_url) . '" data-src="' . esc_url($full_url) . '" data-thumb="' . esc_url($img_src) . '" class="ml-img-lightbox">' . $img_tag . '</a>';
                }

                return $img_tag;
            },
            $content
        );

        // 2. Handle individual linked images (Link to Media File) - second priority
        $content = preg_replace_callback(
            '/<a[^>]*href="([^"]*\.(jpg|jpeg|png|gif|webp|svg))"[^>]*>(.*?)<\/a>/is',
            function($matches) use ($content) {
                $href = $matches[1];
                $link_content = $matches[3];

                // Only process if it contains an image
                if (strpos($link_content, '<img') === false) {
                    return $matches[0];
                }

                // Skip if already processed or has class-based processing
                if (strpos($matches[0], 'data-src') !== false ||
                    strpos($matches[0], 'ml-lightbox-enabled') !== false ||
                    strpos($link_content, 'ml-lightbox-enabled') !== false) {
                    return $matches[0];
                }

                // Skip if this link is inside a wp-block-gallery - let gallery processing handle it
                $link_position = strpos($content, $matches[0]);
                if ($link_position !== false) {
                    $before_link = substr($content, 0, $link_position);
                    $after_link = substr($content, $link_position + strlen($matches[0]));

                    if (preg_match('/<figure[^>]*class="[^"]*wp-block-gallery[^"]*"[^>]*>/s', $before_link) &&
                        preg_match('/<\/figure>/s', $after_link)) {
                        return $matches[0]; // Skip processing - let gallery handle it
                    }
                }

                // Cache options once for both checks
                $options = $this->getCachedGeneralOptions();

                // Check if this is a WordPress "Enlarge on Click" image and override is disabled
                if (strpos($link_content, 'data-wp-on-async--click="actions.showLightbox"') !== false) {
                    $override_enabled = isset($options['override_enlarge_on_click']) ? $options['override_enlarge_on_click'] : true;

                    // Handle both boolean false and string '0' (WordPress admin forms can send either)
                    if ($override_enabled === false || $override_enabled === '0' || $override_enabled === 0) {
                        // Skip processing - let WordPress handle it
                        return $matches[0];
                    }
                } else {
                    // Check if this is a regular "Link to Media File" image and override is disabled
                    $override_link_enabled = isset($options['override_link_to_image_file']) ? $options['override_link_to_image_file'] : true;

                    // Handle both boolean false and string '0' (WordPress admin forms can send either)
                    if ($override_link_enabled === false || $override_link_enabled === '0' || $override_link_enabled === 0) {
                        // Skip processing - let original link behavior remain
                        return $matches[0];
                    }
                }

                // Extract img src for thumbnail
                if (preg_match('/src="([^"]*)"/', $link_content, $src_matches)) {
                    $img_src = $src_matches[1];

                    // Check button mode setting
                    $metaslider_options = $this->getCachedMetaSliderOptions();
                    $showButton = isset($metaslider_options['show_lightbox_button']) ? $metaslider_options['show_lightbox_button'] : false;

                    if ($showButton) {
                        // Button mode: Add class to image, let JavaScript create button
                        $enhanced_content = preg_replace('/(<img[^>]*class="[^"]*)("[^>]*>)/', '$1 ml-lightbox-enabled$2', $link_content);
                        if ($enhanced_content === $link_content) {
                            // No class attribute found, add one
                            $enhanced_content = preg_replace('/(<img[^>]*)(>)/', '$1 class="ml-lightbox-enabled"$2', $link_content);
                        }
                        return $enhanced_content;
                    } else {
                        // Direct click mode: Create wrapper link
                        return '<a href="' . esc_url($href) . '" data-src="' . esc_url($href) . '" data-thumb="' . esc_url($img_src) . '" class="ml-lightbox-enabled">' . $link_content . '</a>';
                    }
                }

                return $matches[0];
            },
            $content
        );

        // Final pass: ensure gallery containers with multiple ml-lightbox-enabled links get ml-gallery-lightbox class
        $content = preg_replace_callback(
            '/<(figure|div|span|ul|section)([^>]*class="[^"]*ml-lightbox-enabled[^"]*"[^>]*)>(.*?)<\/\1>/is',
            function($matches) {
                $element_content = $matches[3];

                // Count ml-lightbox-enabled links in this container
                $lightbox_link_count = substr_count($element_content, 'ml-lightbox-enabled');

                if ($lightbox_link_count > 1) {
                    // Add ml-gallery-lightbox for gallery behavior - links already have ml-lightbox-enabled
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

    private function enhanceContentImages($content)
    {
        // ALWAYS process manual options first (WordPress enlarge, class-based, linked images)
        // Manual options should work even on excluded pages
        $content = $this->processManualLightboxOptions($content);

        // Check exclusion only for automatic processing
        if ($this->shouldExcludePage()) {
            return $content;
        }
        
        $content = $this->removeExcludedElements($content);
        
        $protected_content = $content;
        
        $gallery_patterns = array(
            '/(\[gallery[^\]]*\])/s',
            '/(<div[^>]*class[^>]*gallery[^>]*>.*?<\/div>)/s',
            '/(\[ml-slider[^\]]*\])/s',  // MetaSlider shortcodes
            '/(\[metaslider[^\]]*\])/s', // Alternative MetaSlider shortcodes
            '/(<div[^>]*class[^>]*metaslider[^>]*>.*?<\/div>)/s', // MetaSlider containers
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

        // Check if standalone image processing is enabled
        $options = $this->getCachedGeneralOptions();
        $enable_on_content = isset($options['enable_on_content']) ? $options['enable_on_content'] : false;
        if (!$enable_on_content) {
            // Skip standalone image processing but still restore placeholders
            foreach ($placeholders as $placeholder => $original) {
                $protected_content = str_replace($placeholder, $original, $protected_content);
            }
            return $protected_content;
        }

        // Process images first
        $pattern = '/<img[^>]*src=["\']([^"\']*\.(jpg|jpeg|png|gif|webp|svg))["\'][^>]*>/i';

        $protected_content = preg_replace_callback($pattern, function($matches) use ($protected_content) {
            $img_tag = $matches[0];
            $img_src = $matches[1];

            // Skip if image already has data-ml-exclude attribute
            if (strpos($img_tag, 'data-ml-exclude="true"') !== false) {
                return $img_tag;
            }

            // Skip if image has ml-lightbox-enabled class (manual processing should handle it)
            if (strpos($img_tag, 'ml-lightbox-enabled') !== false) {
                return $img_tag;
            }

            // Skip if image is already inside a lightbox wrapper or link
            $img_position = strpos($protected_content, $img_tag);
            if ($img_position !== false) {
                $before_img = substr($protected_content, 0, $img_position);
                $after_img = substr($protected_content, $img_position + strlen($img_tag));

                // Always check if inside wp-block-gallery - gallery processing should handle these
                if (preg_match('/<figure[^>]*class="[^"]*wp-block-gallery[^"]*"[^>]*>/s', $before_img) &&
                    preg_match('/<\/figure>/s', $after_img)) {
                    return $img_tag; // Skip processing images inside gallery blocks - let gallery processing handle them
                }

                // Check if we're inside an unclosed ml-lightbox-enabled element
                if (preg_match('/ml-lightbox-enabled[^>]*>(?!.*<\/[^>]*>)[^<]*$/s', $before_img)) {
                    return $img_tag;
                }

                // Check if we're inside an existing lightbox wrapper or link
                if (preg_match('/(ml-lightbox-wrapper|ml-lightbox-enabled|data-src=)[^>]*>(?!.*<\/[^>]*>)[^<]*$/s', $before_img)) {
                    return $img_tag;
                }

                // Check if this is a WordPress "Enlarge on Click" image and override is disabled
                if (strpos($img_tag, 'data-wp-on-async--click="actions.showLightbox"') !== false) {
                    $options = $this->getCachedGeneralOptions();
                    $override_enabled = isset($options['override_enlarge_on_click']) ? $options['override_enlarge_on_click'] : true;

                    // Handle both boolean false and string '0' (WordPress admin forms can send either)
                    if ($override_enabled === false || $override_enabled === '0' || $override_enabled === 0) {
                        // Skip processing - let WordPress handle it
                        return $img_tag;
                    }
                }

                // Check if this image is inside any link (could be "Link to Media File") and override is disabled
                if (preg_match('/<a[^>]*href="[^"]*"[^>]*>(?![^<]*<\/a>)[^<]*$/s', $before_img)) {
                    // For images inside links, check the override_link_to_image_file setting
                    $options = $this->getCachedGeneralOptions();
                    $override_link_enabled = isset($options['override_link_to_image_file']) ? $options['override_link_to_image_file'] : true;

                    // Handle both boolean false and string '0' (WordPress admin forms can send either)
                    if ($override_link_enabled === false || $override_link_enabled === '0' || $override_link_enabled === 0) {
                        // Skip processing - let original link behavior remain
                        return $img_tag;
                    }
                }
            }

            return '<a href="' . esc_url($img_src) . '" data-src="' . esc_url($img_src) . '" data-thumb="' . esc_url($img_src) . '" class="ml-lightbox-enabled">' . $img_tag . '</a>';
        }, $protected_content);

        // Process videos (YouTube/Vimeo iframes) - only if content processing is enabled
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
        // Skip if no video iframes found
        if (strpos($content, 'youtube.com/embed/') === false && strpos($content, 'player.vimeo.com/video/') === false) {
            return $content;
        }

        // Skip if already processed by widget processing or has ml-lightbox-enabled class
        if (strpos($content, 'ml-lightbox-enabled') !== false) {
            return $content;
        }

        // WordPress YouTube embed pattern - add ml-lightbox-enabled class to wp-block-embed__wrapper
        $youtube_embed_pattern = '/<figure([^>]*class="[^"]*wp-block-embed[^"]*wp-block-embed-youtube[^"]*"[^>]*)>(.*?<div[^>]*class="[^"]*wp-block-embed__wrapper[^"]*"[^>]*>(.*?<iframe[^>]*src=["\']https?:\/\/(?:www\.)?youtube\.com\/embed\/[a-zA-Z0-9_-]{11}[^"\']*["\'][^>]*><\/iframe>.*?)<\/div>.*?)<\/figure>/s';
        $content = preg_replace_callback($youtube_embed_pattern, function($matches) {
            $figure_attrs = $matches[1];
            $figure_content = $matches[2];
            $wrapper_and_iframe = $matches[3];

            // Skip if already has ml-lightbox-enabled
            if (strpos($figure_content, 'ml-lightbox-enabled') !== false) {
                return $matches[0];
            }

            // Add ml-lightbox-enabled class to the wp-block-embed__wrapper div
            $enhanced_content = preg_replace(
                '/(<div[^>]*class=")([^"]*wp-block-embed__wrapper[^"]*)(")([^>]*>)/',
                '$1$2 ml-lightbox-enabled$3$4',
                $figure_content
            );

            return '<figure' . $figure_attrs . '>' . $enhanced_content . '</figure>';
        }, $content);

        // WordPress Vimeo embed pattern - add ml-lightbox-enabled class to wp-block-embed__wrapper
        $vimeo_embed_pattern = '/<figure([^>]*class="[^"]*wp-block-embed[^"]*wp-block-embed-vimeo[^"]*"[^>]*)>(.*?<div[^>]*class="[^"]*wp-block-embed__wrapper[^"]*"[^>]*>(.*?<iframe[^>]*src=["\']https?:\/\/player\.vimeo\.com\/video\/\d+[^"\']*["\'][^>]*><\/iframe>.*?)<\/div>.*?)<\/figure>/s';
        $content = preg_replace_callback($vimeo_embed_pattern, function($matches) {
            $figure_attrs = $matches[1];
            $figure_content = $matches[2];
            $wrapper_and_iframe = $matches[3];

            // Skip if already has ml-lightbox-enabled
            if (strpos($figure_content, 'ml-lightbox-enabled') !== false) {
                return $matches[0];
            }

            // Add ml-lightbox-enabled class to the wp-block-embed__wrapper div
            $enhanced_content = preg_replace(
                '/(<div[^>]*class=")([^"]*wp-block-embed__wrapper[^"]*)(")([^>]*>)/',
                '$1$2 ml-lightbox-enabled$3$4',
                $figure_content
            );

            return '<figure' . $figure_attrs . '>' . $enhanced_content . '</figure>';
        }, $content);

        // Fallback: For standalone YouTube/Vimeo iframes not in WordPress blocks
        $youtube_iframe_pattern = '/<iframe[^>]*src=["\']https?:\/\/(?:www\.)?youtube\.com\/embed\/[a-zA-Z0-9_-]{11}[^"\']*["\'][^>]*><\/iframe>/i';
        $content = preg_replace_callback($youtube_iframe_pattern, function($matches) use ($content) {
            $iframe = $matches[0];

            // Skip if this iframe is inside a WordPress embed block (already processed above)
            $context_before = substr($content, 0, strpos($content, $iframe));
            if (strpos($context_before, 'wp-block-embed__wrapper') !== false &&
                !preg_match('/<\/div>.*$/s', substr($context_before, strrpos($context_before, 'wp-block-embed__wrapper')))) {
                return $iframe;
            }

            // Skip if iframe already has ml-lightbox-enabled class
            if (strpos($iframe, 'ml-lightbox-enabled') !== false) {
                return $iframe;
            }

            // Wrap standalone iframe in a container with ml-lightbox-enabled class
            return '<div class="ml-lightbox-enabled" style="display: inline-block; position: relative;">' . $iframe . '</div>';
        }, $content);

        $vimeo_iframe_pattern = '/<iframe[^>]*src=["\']https?:\/\/player\.vimeo\.com\/video\/\d+[^"\']*["\'][^>]*><\/iframe>/i';
        $content = preg_replace_callback($vimeo_iframe_pattern, function($matches) use ($content) {
            $iframe = $matches[0];

            // Skip if this iframe is inside a WordPress embed block (already processed above)
            $context_before = substr($content, 0, strpos($content, $iframe));
            if (strpos($context_before, 'wp-block-embed__wrapper') !== false &&
                !preg_match('/<\/div>.*$/s', substr($context_before, strrpos($context_before, 'wp-block-embed__wrapper')))) {
                return $iframe;
            }

            // Skip if iframe already has ml-lightbox-enabled class
            if (strpos($iframe, 'ml-lightbox-enabled') !== false) {
                return $iframe;
            }

            // Wrap standalone iframe in a container with ml-lightbox-enabled class
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
            '/(\[ml-slider[^\]]*\])/s',  // MetaSlider shortcodes
            '/(\[metaslider[^\]]*\])/s', // Alternative MetaSlider shortcodes
            '/(<div[^>]*class[^>]*metaslider[^>]*>.*?<\/div>)/s', // MetaSlider containers
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
            
            return $matches[0]; // Return unchanged if no ID found
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
            
            return $matches[0]; // Return unchanged if no ID found
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
        if (!empty($output)) {
            return $output;
        }
        
        
        $output = gallery_shortcode($atts);
        
        $output = str_replace('class="gallery-item"', 'class="gallery-item ml-gallery-item"', $output);
        $output = preg_replace('/(<a[^>]*?)>/', '$1 class="ml-gallery-lightbox">', $output);
        
        return $output;
    }

    public function enhanceGutenbergGalleryBlock($block_content, $block)
    {
        if ($block['blockName'] !== 'core/gallery') {
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
        // Handle both core/embed (YouTube/Vimeo) and core/video (HTML5) blocks
        if (!in_array($block['blockName'], array('core/embed', 'core/video'))) {
            return $block_content;
        }

        // Check if video processing is enabled
        $options = $this->getCachedGeneralOptions();
        $enable_videos = isset($options['enable_videos']) ? $options['enable_videos'] : true;
        if (!$enable_videos) {
            return $block_content;
        }

        if ($this->shouldExcludePage()) {
            return $block_content;
        }

        // Check CSS selector exclusions
        $options = $this->getCachedGeneralOptions();
        $css_selectors = isset($options['exclude_css_selectors']) ? trim($options['exclude_css_selectors']) : '';
        if (!empty($css_selectors)) {
            $selectors = array_filter(array_map('trim', explode("\n", $css_selectors)));
            foreach ($selectors as $selector) {
                if (empty($selector)) continue;

                // Check if this video block matches any exclusion selector
                if (strpos($selector, '.') === 0) {
                    $class_name = trim(str_replace('.', '', $selector));
                    if (preg_match('/class="[^"]*' . preg_quote($class_name, '/') . '[^"]*"/', $block_content)) {
                        return $block_content; // Skip processing this video
                    }
                }
            }
        }

        // Prevent duplicate processing
        if (strpos($block_content, 'ml-video-overlay') !== false || strpos($block_content, 'ml-lightbox-enabled') !== false) {
            return $block_content;
        }

        // Handle core/embed blocks (YouTube/Vimeo embeds)
        if ($block['blockName'] === 'core/embed') {
            return $this->addLightboxClassToVideoBlock($block_content, 'wp-block-embed');
        }

        // Handle core/video blocks (HTML5 videos)
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
            __('Override WordPress "Enlarge on Click" setting', 'ml-slider-lightbox'),
            __('When enabled, disables WordPress built-in lightbox and uses our lightbox for images set to "Enlarge on Click". When disabled, respects WordPress setting and skips images set to "Enlarge on Click".', 'ml-slider-lightbox')
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
            __('Override "Link to Image File" setting', 'ml-slider-lightbox'),
            __('When enabled, uses our lightbox for images linked to their full-size image file (commonly set via "Link to Image File" in the block editor). When disabled, respects the original link behavior.', 'ml-slider-lightbox')
        );
    }

    public function renderManualOptionsHowTo()
    {
        // How-to: Enlarge on Click
        echo '<details class="ml-how-to-toggle">';
        echo '<summary><strong>' . __('How to use: WordPress "Enlarge on Click"', 'ml-slider-lightbox') . '</strong></summary>';
        echo '<div class="ml-how-to-content">';
        echo '<p>' . __('WordPress has a built-in "Enlarge on Click" feature for images. Here\'s how to enable it:', 'ml-slider-lightbox') . '</p>';
        echo '<ol>';
        echo '<li>' . __('Select an image in the block editor', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('In the block settings sidebar, look for "Link settings"', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('Toggle on "Enlarge on click"', 'ml-slider-lightbox') . '</li>';
        echo '</ol>';
        echo '<img src="' . plugins_url('assets/images/enlarge-on-click.png', __FILE__) . '" alt="' . esc_attr__('WordPress Enlarge on Click setting', 'ml-slider-lightbox') . '" />';
        echo '</div>';
        echo '</details>';

        // How-to: Link to Media File
        echo '<details class="ml-how-to-toggle">';
        echo '<summary><strong>' . __('How to use: "Link to Image File"', 'ml-slider-lightbox') . '</strong></summary>';
        echo '<div class="ml-how-to-content">';
        echo '<p>' . __('You can link images directly to their full-size media file. Here\'s how:', 'ml-slider-lightbox') . '</p>';
        echo '<ol>';
        echo '<li>' . __('Select an image in the block editor', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('In the block settings sidebar, find "Link" settings', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('Choose "Link to: Media File"', 'ml-slider-lightbox') . '</li>';
        echo '</ol>';
        echo '<img src="' . plugins_url('assets/images/link-to-image.png', __FILE__) . '" alt="' . esc_attr__('WordPress Link to Image File setting', 'ml-slider-lightbox') . '" />';
        echo '</div>';
        echo '</details>';

        // How-to: CSS Class Method
        echo '<details class="ml-how-to-toggle">';
        echo '<summary><strong>' . __('How to use: CSS Class Method (Advanced)', 'ml-slider-lightbox') . '</strong></summary>';
        echo '<div class="ml-how-to-content">';
        echo '<p>' . __('For developers: Add the <code>ml-lightbox-enabled</code> class to enable lightbox on any element containing images.', 'ml-slider-lightbox') . '</p>';
        echo '<h4>' . __('Method 1: Block Editor Additional CSS Classes', 'ml-slider-lightbox') . '</h4>';
        echo '<ol>';
        echo '<li>' . __('Select an image or gallery block', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('In the block settings sidebar, scroll to "Advanced"', 'ml-slider-lightbox') . '</li>';
        echo '<li>' . __('Add <code>ml-lightbox-enabled</code> to "Additional CSS class(es)"', 'ml-slider-lightbox') . '</li>';
        echo '</ol>';
        echo '<img src="' . plugins_url('assets/images/class-enabled.png', __FILE__) . '" alt="' . esc_attr__('WordPress Additional CSS Classes setting', 'ml-slider-lightbox') . '" />';
        echo '<h4>' . __('Method 2: HTML/Code', 'ml-slider-lightbox') . '</h4>';
        echo '<p>' . __('For custom HTML or theme developers, add the class directly:', 'ml-slider-lightbox') . '</p>';
        echo '<pre><code>&lt;div class="ml-lightbox-enabled"&gt;<br>  &lt;img src="image.jpg" alt="Image" /&gt;<br>&lt;/div&gt;</code></pre>';
        echo '</div>';
        echo '</details>';
    }

    private function addLightboxClassToVideoBlock($content, $block_class)
    {
        // Simply add ml-lightbox-enabled class to the video block
        // JavaScript will handle creating buttons or overlays
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
            esc_url($video_url), // Use data-src for videos per LightGallery docs
            esc_url($poster_url) // Use data-poster for video thumbnail  
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
        // Extract poster attribute if available
        $poster_url = '';
        if (preg_match('/<video[^>]*poster=["\']([^"\']+)["\'][^>]*>/', $content, $poster_matches)) {
            $poster_url = $poster_matches[1];
        }

        // Create overlay for HTML5 video with data-video format for LightGallery
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

        // Add overlay to wp-block-video figure and make it relatively positioned with ml-lightbox-enabled class
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
                return 'video/mp4'; // Default fallback
        }
    }

    private function addHTML5VideoButtonToBlock($content, $video_url)
    {
        // Extract poster attribute if available
        $poster_url = '';
        if (preg_match('/<video[^>]*poster=["\']([^"\']+)["\'][^>]*>/', $content, $poster_matches)) {
            $poster_url = $poster_matches[1];
        }

        // Create video data for LightGallery
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

        // Create lightbox button (styling handled by CSS)
        $button = sprintf(
            '<a href="#" class="ml-lightbox-button" data-src="" data-video=\'%s\' data-thumb="%s">Open in Lightbox</a>',
            esc_attr(wp_json_encode($video_data)),
            esc_url($poster_url)
        );

        // Add button to wp-block-video figure and make it relatively positioned with ml-lightbox-enabled class
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

        // Create lightbox button (styling handled by CSS)
        $button = sprintf(
            '<a href="#" class="ml-lightbox-button" data-src="%s" data-poster="%s">Open in Lightbox</a>',
            esc_url($video_url),
            esc_url($poster_url)
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

        // Check exclusion settings
        if ($this->shouldExcludePage()) {
            return $html;
        }

        
        $full_size_url = wp_get_attachment_image_url($post_thumbnail_id, 'full');
        if (!$full_size_url) {
            return $html;
        }
        
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
            return '<a href="' . esc_url($full_size_url) . '" data-src="' . esc_url($full_size_url) . '" data-thumb="' . esc_url($full_size_url) . '" class="ml-featured-lightbox">' . $html . '</a>';
        }
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

        // Check for WordPress "Enlarge on Click" images
        if (strpos($content, 'wp-lightbox-container') !== false &&
            strpos($content, 'data-wp-on-async--click="actions.showLightbox"') !== false) {
            return true;
        }

        // Check for class-based lightbox (.ml-lightbox-enabled) on containers or img elements
        if (strpos($content, 'ml-lightbox-enabled') !== false) {
            return true;
        }

        // Check for linked images (Link to Media File)
        if (preg_match('/<a[^>]*href="[^"]*\.(jpg|jpeg|png|gif|webp|svg)"[^>]*>.*?<img[^>]*>.*?<\/a>/is', $content)) {
            return true;
        }

        return false;
    }

    private function pageNeedsVideoAssets()
    {
        global $post;

        // Always load for MetaSlider content that might have videos
        if ($this->hasLightboxEnabledSliders()) {
            return true;
        }

        if (!$post || !$post->post_content) {
            return false;
        }

        $content = $post->post_content;

        // Check for YouTube/Vimeo embeds in content
        if (preg_match('/youtube\.com|youtu\.be|vimeo\.com/i', $content)) {
            return true;
        }

        // Check for video file links
        if (preg_match('/<a[^>]*href="[^"]*\.(mp4|webm|ogv)"[^>]*>/i', $content)) {
            return true;
        }

        // Check for HTML5 video elements
        if (strpos($content, '<video') !== false) {
            return true;
        }

        return false;
    }

    private function pageNeedsThumbnailAssets()
    {
        $options = $this->getCachedMetaSliderOptions();

        // Check if thumbnails are disabled globally
        if (!isset($options['show_thumbnails']) || !$options['show_thumbnails']) {
            return false;
        }

        // Always load for MetaSlider content if thumbnails are enabled
        if ($this->hasLightboxEnabledSliders()) {
            return true;
        }

        global $post;
        if (!$post || !$post->post_content) {
            return false;
        }

        $content = $post->post_content;

        // Check for galleries that would use thumbnails
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
                var images = document.querySelectorAll('img[data-wp-on-async--click]');
                for (var i = 0; i < images.length; i++) {
                    var img = images[i];
                    var attributes = img.attributes;
                    for (var j = attributes.length - 1; j >= 0; j--) {
                        var attr = attributes[j];
                        if (attr.name.indexOf('data-wp-') === 0) {
                            img.removeAttribute(attr.name);
                        }
                    }
                }
            });
            
            document.addEventListener('click', function(e) {
                var target = e.target;
                
                if (target.tagName === 'IMG' && target.hasAttribute('data-wp-on-async--click')) {
                    e.preventDefault();
                    e.stopPropagation();
                    e.stopImmediatePropagation();
                    return false;
                }
                
                if (target.tagName === 'A' && target.querySelector('img[data-wp-on-async--click]')) {
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
}
