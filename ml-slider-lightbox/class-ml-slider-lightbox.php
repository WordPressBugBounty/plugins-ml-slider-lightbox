<?php

namespace MetaSlider\Lightbox;

if (! defined('ABSPATH')) {
    die('No direct access.');
}

// LightGallery Enterprise License Key
if (!defined('ML_LIGHTGALLERY_LICENSE_KEY')) {
    define('ML_LIGHTGALLERY_LICENSE_KEY', 'E8BD65E9-797F-4DB9-B91D-7D1ECDCA7252');
}

/**
 * Register the plugin.
 */
class MetaSliderLightboxPlugin
{
    /**
     * Lightbox version
     *
     * @var string
     */
    public $version = '2.0.0';

    /**
     * Instance object
     *
     * @var object
     * @see get_instance()
     */
    protected static $instance = null;

    /**
     * An array of supported plugins
     *
     * @var array
     * @see get_supported_plugins()
     */
    private $supported_plugins = array();


    /**
     * Used to access the instance
     */
    public static function getInstance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Used to set up the plugin
     */
    public function setup()
    {
        $this->supported_plugins = $this->getSupportedPlugins();
        $this->initializeDefaultOptions();
        $this->addSettings();
        $this->setupCoreFeatures();
        $this->setupMetasliderIntegration();
        $this->setupAdminMenu();
    }

    /**
     * Constructor. Intentionally left empty and public.
     */
    public function __construct()
    {
    }

    /**
     * Initialize default options in database if they don't exist
     */
    private function initializeDefaultOptions()
    {
        // Check if general options exist, if not create them with explicit false values
        $general_options = get_option('metaslider_lightbox_general_options', false);
        if ($general_options === false) {
            $default_general_options = array(
                'detect_all_images' => false,
                'detect_all_galleries' => false,
                'detect_all_videos' => false,
                'background_color' => '#000000',
                'button_color' => '#ffffff',
                'icon_color' => '#000000',
                'icon_hover_color' => '#333333',
                'background_opacity' => '0.9',
            );
            add_option('metaslider_lightbox_general_options', $default_general_options);
        }

        // Check if MetaSlider options exist, if not create them
        $metaslider_options = get_option('metaslider_lightbox_metaslider_options', false);
        if ($metaslider_options === false) {
            $default_metaslider_options = array(
                'show_arrows' => true,
                'show_thumbnails' => false,
            );
            add_option('metaslider_lightbox_metaslider_options', $default_metaslider_options);
        }
    }

    /**
     * Set up core standalone features
     */
    public function setupCoreFeatures()
    {
        add_action('wp_enqueue_scripts', array($this, 'enqueueFrontendAssets'));
        add_shortcode('ml_lightbox', array($this, 'lightboxShortcode'));
        add_shortcode('ml_gallery', array($this, 'galleryShortcode'));
        add_filter('the_content', array($this, 'autoDetectGalleries'), 20);
        add_filter('post_gallery', array($this, 'enhanceWordpressGallery'), 10, 3);
        add_filter('render_block', array($this, 'enhanceGutenbergBlocks'), 10, 2);

        // Override WordPress "Enlarge on click" when detect_all_images is enabled
        $this->setupEnlargeOnClickOverride();
    }

    /**
     * Set up MetaSlider integration (only if MetaSlider is active)
     */
    public function setupMetasliderIntegration()
    {
        // Only load MetaSlider integration if the plugin is active
        if (!$this->isMetasliderActive()) {
            return;
        }

        if (is_admin()) {
            add_filter('metaslider_advanced_settings', array($this, 'addSettings'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        }

        add_filter('metaslider_flex_slider_anchor_attributes', array($this, 'addAttributes'), 10, 3);
        add_filter('metaslider_nivo_slider_anchor_attributes', array($this, 'addAttributes'), 10, 3);
        add_filter('metaslider_responsive_slider_anchor_attributes', array($this, 'addAttributes'), 10, 3);
        add_filter('metaslider_coin_slider_anchor_attributes', array($this, 'addAttributes'), 10, 3);
        add_filter('metaslider_css_classes', array($this, 'addClassnames'), 10, 3);

        // Add slide type handlers for different MetaSlider slide types
        $this->registerSlideTypeHandlers();

        // External images and video slides (Vimeo, YouTube, External Video, Custom HTML, Image Folder) are handled by JavaScript using their respective classes
    }

    /**
     * Register slide type handlers for different MetaSlider slide types
     */
    private function registerSlideTypeHandlers()
    {
        // Get all supported slide types
        $slide_types = $this->getSupportedSlideTypes();

        foreach ($slide_types as $slide_type => $config) {
            // Register filters for each slide type
            foreach ($config['filters'] as $filter_name => $priority) {
                add_filter($filter_name, array($this, 'handleSlideLightbox'), $priority, 3);
            }
        }

        // Keep the filter registration for future extensibility
        // (Currently using content filtering instead as the attribute filters don't exist)
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
                    // Vimeo slides are handled by JavaScript content processing
                ),
                'handler' => 'handleVimeoVideoSlide',
                'has_anchor' => false,
            ),
            'youtube' => array(
                'name' => 'YouTube Video Slide',
                'description' => 'YouTube video slides with lightbox button',
                'filters' => array(
                    // YouTube slides are handled by JavaScript content processing
                ),
                'handler' => 'handleYoutubeVideoSlide',
                'has_anchor' => false,
            ),
            'external_video' => array(
                'name' => 'External Video Slide',
                'description' => 'External video slides with lightbox button',
                'filters' => array(
                    // External video slides are handled by JavaScript content processing
                ),
                'handler' => 'handleExternalVideoSlide',
                'has_anchor' => false,
            ),
            'custom_html' => array(
                'name' => 'Custom HTML Slide',
                'description' => 'Custom HTML slides with lightbox button',
                'filters' => array(
                    // Custom HTML slides are handled by JavaScript content processing
                ),
                'handler' => 'handleCustomHtmlSlide',
                'has_anchor' => false,
            ),
            'image_folder' => array(
                'name' => 'Image Folder Slide',
                'description' => 'Image folder slides with gallery lightbox',
                'filters' => array(
                    // Image folder slides are handled by JavaScript content processing
                ),
                'handler' => 'handleImageFolderSlide',
                'has_anchor' => false,
            ),
            'postfeed' => array(
                'name' => 'PostFeed Slide',
                'description' => 'PostFeed slides with lightbox support',
                'filters' => array(
                    // PostFeed slides are handled by JavaScript content processing
                ),
                'handler' => 'handlePostfeedSlide',
                'has_anchor' => false,
            ),
            'layer' => array(
                'name' => 'Layer Slide',
                'description' => 'Layer slides with background image lightbox',
                'filters' => array(
                    // Layer slides are handled by JavaScript content processing
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
    public function handleSlideLightbox($attributes, $slide, $slider_id)
    {
        // Check if lightbox is enabled for this slider
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if (!$this->isLightboxEnabled($enabled)) {
            return $attributes;
        }

        // Determine slide type
        $slide_type = $this->determineSlideType($slide, $attributes);
        $slide_types = $this->getSupportedSlideTypes();

        // Call specific handler if it exists
        if (isset($slide_types[$slide_type]) && method_exists($this, $slide_types[$slide_type]['handler'])) {
            $handler_method = $slide_types[$slide_type]['handler'];
            return $this->$handler_method($attributes, $slide, $slider_id);
        }

        // Fallback to generic handler
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
        // Check for explicit slide type
        if (isset($slide['type'])) {
            return $slide['type'];
        }

        // Check for slide classes in attributes
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

        // Check for video URL
        if (isset($slide['url']) && $this->isVideoUrl($slide['url'])) {
            return 'video';
        }

        // Check for layer slide
        if (isset($slide['layer_content'])) {
            return 'layer';
        }

        // Default to image slide
        return 'image';
    }

    /**
     * Check if MetaSlider is active
     */
    private function isMetasliderActive()
    {
        if (!function_exists('is_plugin_active')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        return is_plugin_active('ml-slider/ml-slider.php') || is_plugin_active('ml-slider-pro/ml-slider-pro.php');
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
                    'data-slb-group' => '$slider_id',
                    'data-slb-active' => '1',
                    'data-slb-internal' => '0',
                    'data-slb-caption' => ':caption'
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
    public function addAttributes($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;
        $thumbnail_id = ('attachment' === get_post_type($slide['id'])) ? $slide['id'] : get_post_thumbnail_id(
            $slide['id']
        );

        // Always skip if lightbox is not enabled
        if (!$this->isLightboxEnabled($enabled)) {
            return $attributes;
        }


        // Skip adding attributes for slide types that use button-only approach
        // Direct check: if this is not an attachment, it's likely a post feed slide
        if (isset($slide['id'])) {
            $post_type = get_post_type($slide['id']);
            if ($post_type && $post_type !== 'attachment') {
                // This is a post, not an image attachment - skip lightbox attributes
                return $attributes;
            }
        }

        // Additional check: if href points to a post URL (not an image), skip
        if (isset($attributes['href']) && !preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $attributes['href'])) {
            return $attributes;
        }

        // Additional check for MetaSlider Pro slide types
        if (isset($slide['id'])) {
            $meta_type = get_post_meta($slide['id'], 'ml-slider_type', true);
            if ($meta_type && in_array($meta_type, ['external', 'folder', 'vimeo', 'youtube', 'local-video', 'external-video', 'custom-html', 'postfeed', 'layer'])) {
                return $attributes;
            }
        }

        // Check slide properties for type
        if (isset($slide['type']) && in_array($slide['type'], ['external', 'folder', 'vimeo', 'youtube', 'local-video', 'external-video', 'custom-html', 'postfeed', 'layer'])) {
            return $attributes;
        }

        // If we get here, proceed with adding lightbox attributes for regular image slides
        // Link to the full size image if nothing is set.
        if (empty($attributes['href'])) {
            $attributes['href'] = wp_get_attachment_url($thumbnail_id);
        }

        // Add MetaSlider-specific lightbox settings as data attributes
        $metaslider_options = get_option('metaslider_lightbox_metaslider_options', array());
        $show_arrows = isset($metaslider_options['show_arrows']) ? $metaslider_options['show_arrows'] : true;
        $show_thumbnails = isset($metaslider_options['show_thumbnails']) ? $metaslider_options['show_thumbnails'] : false;

        $attributes['data-lightbox-arrows'] = $show_arrows ? 'true' : 'false';
        $attributes['data-lightbox-thumbnails'] = $show_thumbnails ? 'true' : 'false';

        foreach ($this->supported_plugins as $name => $plugin) {
            if ($this->checkIfPluginIsActive($name, $plugin['location'])) {
                $attributes['rel'] = (isset($plugin['rel'])) ? $plugin['rel'] . "[{$slider_id}]" : '';

                // Cycle through the list of attributes
                if (isset($plugin['attributes'])) {
                    foreach ($plugin['attributes'] as $key => $value) {
                        $attributes[$key] = ('$' === $value[0]) ?
                            ${ltrim($value, '$')} : $value;

                        // Custom keywords to return specific info
                        if (':caption' === $value) {
                            $attributes[$key] = isset($slide['caption']) ? $slide['caption'] : '';
                        }
                        if (':url' === $value) {
                            $attributes[$key] = $attributes['href'];
                        }
                        if (':dimensions' === $value) {
                            // Get image dimensions for LightGallery from the actual full-size image
                            $full_size_url = $attributes['href'];
                            $attachment_id = attachment_url_to_postid($full_size_url);

                            // If we can't get the attachment ID from URL, use the thumbnail ID
                            if (!$attachment_id) {
                                $attachment_id = $thumbnail_id;
                            }

                            $image_meta = wp_get_attachment_metadata($attachment_id);
                            if ($image_meta && isset($image_meta['width']) && isset($image_meta['height'])) {
                                $attributes[$key] = $image_meta['width'] . '-' . $image_meta['height'];
                            } else {
                                // Try to get dimensions from the full-size image directly
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
                break;
            }
        }

        return $attributes;
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
    public function addClassnames($class, $slider_id, $settings)
    {
        // Add the class for this plugin
        $class .= ' ml-slider-lightbox-' . sanitize_title($this->version);

        // The slideshow is unchecked or no ligthbox found
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if (!$this->isLightboxEnabled($enabled)) {
            return $class . ' lightbox-disabled';
        }

        foreach ($this->supported_plugins as $name => $plugin) {
            if ($path = $this->checkIfPluginIsActive($name, $plugin['location'])) {
                // Handle built-in lightbox separately
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
                    // Add a class required by a specific plugin
                    $class .= ' ' . $plugin['body_class'];
                }
                break;
            }
        }

        // No lightbox found
        if (! isset($active_lightbox_data['Version'])) {
            // Check if we should use built-in lightbox
            if ($this->shouldUseBuiltInLightbox()) {
                return $class . ' ml-builtin-lightbox';
            }
            return $class . ' no-active-lightbox';
        }

        // Return the name of the active lightbox with it's version number
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
        // Handle built-in lightbox
        if ('built-in' === $path) {
            return $this->shouldUseBuiltInLightbox() ? 'built-in' : false;
        }

        if (! function_exists('get_plugins')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        if (is_plugin_active($path)) {
            return $path;
        }

        // In case the directory structure has changed, look for the name too
        foreach (get_plugins() as $plugin_path => $plugin_data) {
            if ($name === $plugin_data['Name'] && is_plugin_active($plugin_path)) {
                return $plugin_path;
            }
        }
        return false;
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

        // Everything looks good
        if ((1 === $active_plugin_count || $has_built_in) && $has_active_lightbox && $this->checkIfPluginIsActive('MetaSlider')) {
            return true;
        }

        // MetaSlider isn't installed
        if (! $this->checkIfPluginIsActive('MetaSlider')) {
            add_action('admin_notices', array($this, 'showMetasliderDependencyWarning'));
            add_action('metaslider_admin_notices', array($this, 'showMetasliderDependencyWarning'));
            return false;
        }

        // No lightbox found, but we have built-in option
        if (! $has_active_lightbox && ! $has_built_in) {
            // Built-in lightbox will be used automatically
            return true;
        }

        // Too many plugins
        if ($active_plugin_count > 1) {
            // Notices in admin pages except in MetaSlider admin pages - See MetaSliderPlugin->filter_admin_notices() from base plugin
            add_action('admin_notices', array($this, 'showMultipleLightboxWarning'), 10, 3);
            // @since 1.13.2 - Notices in MetaSlider admin pages
            add_action('metaslider_admin_notices', array($this, 'showMultipleLightboxWarning'), 10, 3);
            return false;
        }
    }

    /**
     * The warning message that is displayed if MetaSlider or Simple lightbox isn't activated
     */
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

    /**
     * The warning message that is displayed if more than one lightbox is activated
     */
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
                    // Built-in lightbox
                    $active_lightbox_data = array(
                        'Name' => $name,
                        'Version' => $this->version
                    );
                    $lightbox_name = $name;
                } else {
                    // Third-party lightbox
                    if ($path && $path !== 'built-in' && file_exists(WP_PLUGIN_DIR . '/' . $path)) {
                        $active_lightbox_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $path);
                        $lightbox_name = $active_lightbox_data['Name'];
                    }
                }
                $lightbox_settings_url = $plugin['settings_url'];
                break;
            }
        }

        // If no third-party lightbox found, use built-in
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

        // Check global lightbox mode setting
        switch ($options['lightbox_mode']) {
            case 'builtin':
                return true; // Always use built-in
            case 'third_party':
                return false; // Never use built-in
            case 'auto':
            default:
                // Auto-detect mode - check for third-party lightboxes
                break;
        }

        // Check if any third-party lightbox is active (avoid recursion)
        foreach ($this->supported_plugins as $name => $plugin) {
            if (isset($plugin['built_in']) && $plugin['built_in']) {
                continue; // Skip built-in entry
            }

            // Direct check without using check_if_plugin_is_active to avoid recursion
            $path = $plugin['location'];
            if (! function_exists('get_plugins')) {
                include_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }

            if (is_plugin_active($path)) {
                return false; // Third-party lightbox found, don't use built-in
            }

            // In case the directory structure has changed, look for the name too
            foreach (get_plugins() as $plugin_path => $plugin_data) {
                if ($name === $plugin_data['Name'] && is_plugin_active($plugin_path)) {
                    return false; // Third-party lightbox found, don't use built-in
                }
            }
        }

        // No third-party lightbox found, use built-in
        return true;
    }

    /**
     * Enqueue frontend assets when needed
     */
    public function enqueueFrontendAssets()
    {
        // Only enqueue on pages that might have MetaSlider
        if (is_admin()) {
            return;
        }

        $options = $this->getPluginOptions();

        // Check if assets should be loaded globally
        if ($options['load_assets_globally']) {
            $this->enqueueLightgalleryAssets();
            return;
        }

        // Always load assets if standalone gallery detection is enabled
        if ($options['detect_all_galleries'] || $options['detect_all_images']) {
            $this->enqueueLightgalleryAssets();
            return;
        }

        // Check if built-in lightbox should be used (for MetaSlider integration)
        if ($this->shouldUseBuiltInLightbox()) {
            $this->enqueueLightgalleryAssets();
        }
    }

    /**
     * Enqueue LightGallery assets
     */
    private function enqueueLightgalleryAssets()
    {

        wp_enqueue_style(
            'ml-lightgallery-css',
            'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/css/lightgallery.min.css',
            array(),
            '2.7.1'
        );

        wp_enqueue_style(
            'lightgallery-video-css',
            'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/css/lg-video.css',
            array('ml-lightgallery-css'),
            '2.7.1'
        );

        wp_enqueue_style(
            'lightgallery-thumbnail-css',
            'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/css/lg-thumbnail.css',
            array('ml-lightgallery-css'),
            '2.7.1'
        );

        wp_enqueue_style(
            'ml-lightbox-public-css',
            plugin_dir_url(__FILE__) . 'assets/css/ml-lightbox-public.css',
            array('ml-lightgallery-css', 'lightgallery-video-css', 'lightgallery-thumbnail-css'),
            $this->version
        );

        // Add custom CSS for user-defined colors and opacity
        $this->addCustomLightboxCss();

        wp_enqueue_script(
            'ml-lightgallery-js',
            plugin_dir_url(__FILE__) . 'assets/js/lightgallery.min.js',
            array('jquery'),
            $this->version,
            true
        );

        wp_enqueue_script(
            'lightgallery-video',
            'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/plugins/video/lg-video.min.js',
            array('ml-lightgallery-js'),
            '2.7.1',
            true
        );

        wp_enqueue_script(
            'lightgallery-thumbnail',
            'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/plugins/thumbnail/lg-thumbnail.min.js',
            array('ml-lightgallery-js'),
            '2.7.1',
            true
        );

        wp_enqueue_script(
            'lightgallery-vimeo-thumbnail',
            'https://cdn.jsdelivr.net/npm/lightgallery@2.7.1/plugins/vimeoThumbnail/lg-vimeo-thumbnail.min.js',
            array('ml-lightgallery-js'),
            '2.7.1',
            true
        );

        // Load Video.js from official CDN
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

        wp_enqueue_script(
            'ml-lightgallery-init',
            plugin_dir_url(__FILE__) . 'assets/js/ml-lightgallery-init.js',
            array('ml-lightgallery-js', 'lightgallery-video', 'lightgallery-thumbnail', 'lightgallery-vimeo-thumbnail', 'videojs'),
            $this->version,
            true
        );

        // Pass settings to JavaScript
        $options = $this->getPluginOptions();
        $metaslider_options = get_option('metaslider_lightbox_metaslider_options', array());

        // Get all MetaSlider lightbox settings
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

        wp_localize_script('ml-lightgallery-init', 'mlLightboxSettings', array(
            'detect_all_images' => isset($options['detect_all_images']) ? $options['detect_all_images'] : false,
            'detect_all_galleries' => isset($options['detect_all_galleries']) ? $options['detect_all_galleries'] : false,
            'detect_all_videos' => isset($options['detect_all_videos']) ? $options['detect_all_videos'] : false,
            'slider_settings' => $slider_settings,
            'metaslider_options' => array(
                'show_arrows' => isset($metaslider_options['show_arrows']) ? $metaslider_options['show_arrows'] : true,
                'show_thumbnails' => isset($metaslider_options['show_thumbnails']) ? $metaslider_options['show_thumbnails'] : false,
            ),
            'license_key' => ML_LIGHTGALLERY_LICENSE_KEY
        ));
    }

    /**
     * Add custom CSS for user-defined colors and opacity
     */
    private function addCustomLightboxCss()
    {
        $options = $this->getPluginOptions();

        // Get colors and opacity
        $background_color = isset($options['background_color']) ? $options['background_color'] : '#000000';
        $button_color = isset($options['button_color']) ? $options['button_color'] : '#ffffff';
        $icon_color = isset($options['icon_color']) ? $options['icon_color'] : '#ffffff';
        $icon_hover_color = isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '#333333';
        $background_opacity = isset($options['background_opacity']) ? $options['background_opacity'] : '0.9';

        // URL encode the icon color for SVG
        $icon_color_encoded = urlencode($icon_color);

        // Build SVG data URLs with proper encoding
        $close_svg = 'data:image/svg+xml;charset=utf-8,' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cline%20x1%3D%2218%22%20y1%3D%226%22%20x2%3D%226%22%20y2%3D%2218%22%3E%3C%2Fline%3E%3Cline%20x1%3D%226%22%20y1%3D%226%22%20x2%3D%2218%22%20y2%3D%2218%22%3E%3C%2Fline%3E%3C%2Fsvg%3E';
        $prev_svg = 'data:image/svg+xml;charset=utf-8,' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%2215%2C18%209%2C12%2015%2C6%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E';
        $next_svg = 'data:image/svg+xml;charset=utf-8,' . $icon_color_encoded . '%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpolyline%20points%3D%229%2C18%2015%2C12%209%2C6%22%3E%3C%2Fpolyline%3E%3C%2Fsvg%3E';

        // Create properly encoded SVG URLs
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
            .lg-outer .lg-next {
                background-color: ' . esc_html($button_color) . ' !important;
                color: var(--ml-lightbox-icon-color) !important;
            }
            
            .lg-outer .lg-close:hover,
            .lg-outer .lg-prev:hover,
            .lg-outer .lg-next:hover {
                color: var(--ml-lightbox-icon-hover-color) !important;
            }
            
            /* Prevent layout shifts during loading */
            .lg-outer .lg-item {
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .lg-outer .lg-item img {
                max-width: 100%;
                max-height: calc(100vh - 120px);
                width: auto;
                height: auto;
                object-fit: contain;
                object-position: center;
            }
            
            /* Adjust image height when thumbnails are visible */
            .lg-outer.lg-thumbnail .lg-item img {
                max-height: calc(100vh - 160px);
            }
            
            /* Smooth fade transitions */
            .lg-outer .lg-item {
                transition: opacity 0.3s ease-in-out;
            }
        ';

        wp_add_inline_style('ml-lightbox-public-css', $custom_css);
    }

    /**
     * Enqueue admin scripts for color picker
     */
    public function enqueueAdminScripts($hook)
    {
        // Only load on our plugin settings page
        if (strpos($hook, 'metaslider-lightbox') === false) {
            return;
        }

        // Enqueue WordPress color picker
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('wp-color-picker');

        // Enqueue custom admin script
        wp_enqueue_script(
            'ml-lightbox-admin',
            plugin_dir_url(__FILE__) . 'assets/js/ml-lightbox-admin.js',
            array('jquery', 'wp-color-picker'),
            $this->version,
            true
        );
    }

    /**
     * Show MetaSlider dependency warning
     */
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

    /**
     * Setup admin menu
     */
    public function setupAdminMenu()
    {
        if (is_admin()) {
            // Always show admin menu - plugin works independently
            add_action('admin_menu', array($this, 'addAdminMenu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueueAdminAssets'));
            add_action('admin_init', array($this, 'registerSettings'));
        }
    }

    /**
     * Setup WordPress "Enlarge on click" override (only if detect_all_images is enabled)
     */
    private function setupEnlargeOnClickOverride()
    {
        // Get plugin options
        $options = $this->getPluginOptions();

        // Only override "Enlarge on click" if "Detect All Images on Site" is enabled
        if (empty($options['detect_all_images']) || $options['detect_all_images'] !== true) {
            return;
        }

        // Override WordPress attachment links to use our lightbox instead of default behavior
        add_filter('wp_get_attachment_link', array($this, 'overrideEnlargeOnClick'), 10, 6);

        // Disable WordPress lightbox completely
        add_filter('wp_lightbox_enabled', '__return_false');

        // Add JavaScript to prevent WordPress lightbox
        add_action('wp_footer', array($this, 'disableWordpressLightboxJs'), 5);
    }

    /**
     * Add admin menu page
     */
    public function addAdminMenu()
    {
        // Single menu page with tabs
        add_menu_page(
            __('MetaSlider Lightbox', 'ml-slider-lightbox'),
            __('Lightbox', 'ml-slider-lightbox'),
            'manage_options',
            'metaslider-lightbox',
            array($this, 'renderMainPage'),
            'data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz4KPHN2ZyBmaWxsPSIjZmZmIiB2ZXJzaW9uPSIxLjEiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyIgeG1sbnM6eGxpbms9Imh0dHA6Ly93d3cudzMub3JnLzE5OTkveGxpbmsiIHg9IjBweCIgeT0iMHB4IiB2aWV3Qm94PSIwIDAgMjU1LjggMjU1LjgiIHN0eWxlPSJmaWxsOiNmZmYiIHhtbDpzcGFjZT0icHJlc2VydmUiPjxnPjxwYXRoIGQ9Ik0xMjcuOSwwQzU3LjMsMCwwLDU3LjMsMCwxMjcuOWMwLDcwLjYsNTcuMywxMjcuOSwxMjcuOSwxMjcuOWM3MC42LDAsMTI3LjktNTcuMywxMjcuOS0xMjcuOUMyNTUuOCw1Ny4zLDE5OC41LDAsMTI3LjksMHogTTE2LjQsMTc3LjFsOTIuNS0xMTcuNUwxMjQuMiw3OWwtNzcuMyw5OC4xSDE2LjR6IE0xNzAuNSwxNzcuMWwtMzguOS00OS40bDE1LjUtMTkuNmw1NC40LDY5SDE3MC41eiBNMjA4LjUsMTc3LjFMMTQ2LjksOTkgbC02MS42LDc4LjJoLTMxbDkyLjUtMTE3LjVsOTIuNSwxMTcuNUgyMDguNXoiLz48L2c+PC9zdmc+Cg=='
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAdminAssets($hook)
    {
        if (strpos($hook, 'metaslider-lightbox') === false) {
            return;
        }

        // Enqueue WordPress color picker
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_style('wp-color-picker');

        // Enqueue admin CSS
        wp_enqueue_style(
            'ml-lightbox-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/ml-lightbox-admin.css',
            array(),
            $this->version
        );

        // Enqueue admin JavaScript with color picker dependency
        wp_enqueue_script(
            'ml-lightbox-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/ml-lightbox-admin.js',
            array('jquery', 'wp-color-picker'),
            $this->version,
            true
        );
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
                        <?php _e('MetaSlider Lightbox', 'ml-slider-lightbox'); ?>
                    </h1>
                </div>
            </div>
            
            <div class="ml-lightbox-content">
        <?php
    }

    /**
     * Render custom admin page footer
     */
    private function renderAdminFooter()
    {
        ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the main admin page with tabs
     */
    public function renderMainPage()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        // Get current tab with proper sanitization
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

        // If MetaSlider tab is requested but MetaSlider isn't active, redirect to general tab
        if ($current_tab === 'metaslider' && !$this->isMetasliderActive()) {
            $current_tab = 'general';
        }

        // Define tabs - conditionally include MetaSlider tab
        $tabs = array(
            'general' => __('General', 'ml-slider-lightbox')
        );

        // Only add MetaSlider tab if MetaSlider is active
        if ($this->isMetasliderActive()) {
            $tabs['metaslider'] = __('MetaSlider', 'ml-slider-lightbox');
        }

        // Add Pro tab
        //$tabs['pro'] = __('Upgrade to Pro', 'ml-slider-lightbox');

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
            // Display success/error messages
            if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true') {
                echo '<div class="notice notice-success is-dismissible ml-lightbox-notice-success">';
                echo '<p>' . __('Settings saved successfully!', 'ml-slider-lightbox') . '</p>';
                echo '</div>';
            }
            ?>
            
            <?php if ($current_tab === 'general') : ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields('metaslider_lightbox_general_settings');
                    do_settings_sections('metaslider_lightbox_general_settings');
                    submit_button();
                    ?>
                </form>
            <?php elseif ($current_tab === 'metaslider') : ?>
                <?php if ($this->isMetasliderActive()) : ?>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('metaslider_lightbox_metaslider_settings');
                        do_settings_sections('metaslider_lightbox_metaslider_settings');
                        submit_button();
                        ?>
                    </form>
                <?php else : ?>
                    <div class="notice notice-warning">
                        <p>
                            <strong><?php _e('MetaSlider Required', 'ml-slider-lightbox'); ?></strong><br>
                            <?php _e('The MetaSlider plugin must be installed and activated to access these settings.', 'ml-slider-lightbox'); ?>
                            <br><br>
                            <a href="<?php echo admin_url('plugin-install.php?s=metaslider&tab=search&type=term'); ?>" class="button button-primary">
                                <?php _e('Install MetaSlider', 'ml-slider-lightbox'); ?>
                            </a>
                        </p>
                    </div>
                <?php endif; ?>
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

    /**
     * Register settings
     */
    public function registerSettings()
    {
        // Register general settings (always available)
        register_setting('metaslider_lightbox_general_settings', 'metaslider_lightbox_general_options', array($this, 'sanitizeGeneralOptions'));

        // Register MetaSlider settings (only if MetaSlider is active)
        if ($this->isMetasliderActive()) {
            register_setting('metaslider_lightbox_metaslider_settings', 'metaslider_lightbox_metaslider_options', array($this, 'sanitizeMetasliderOptions'));
        }

        // General Settings Section
        add_settings_section(
            'metaslider_lightbox_general',
            __('General Settings', 'ml-slider-lightbox'),
            array($this, 'generalSettingsSectionCallback'),
            'metaslider_lightbox_general_settings'
        );

        add_settings_field(
            'detect_all_images',
            __('Lightbox for Images', 'ml-slider-lightbox'),
            array($this, 'detectAllImagesCallback'),
            'metaslider_lightbox_general_settings',
            'metaslider_lightbox_general'
        );

        add_settings_field(
            'detect_all_galleries',
            __('Lightbox for Image Galleries', 'ml-slider-lightbox'),
            array($this, 'detectAllGalleriesCallback'),
            'metaslider_lightbox_general_settings',
            'metaslider_lightbox_general'
        );

        add_settings_field(
            'detect_all_videos',
            __('Lightbox for Videos', 'ml-slider-lightbox'),
            array($this, 'detectAllVideosCallback'),
            'metaslider_lightbox_general_settings',
            'metaslider_lightbox_general'
        );


        add_settings_field(
            'background_color',
            __('Background Color', 'ml-slider-lightbox'),
            array($this, 'backgroundColorCallback'),
            'metaslider_lightbox_general_settings',
            'metaslider_lightbox_general'
        );

        add_settings_field(
            'button_color',
            __('Button Color', 'ml-slider-lightbox'),
            array($this, 'buttonColorCallback'),
            'metaslider_lightbox_general_settings',
            'metaslider_lightbox_general'
        );

        add_settings_field(
            'icon_color',
            __('Icon Color', 'ml-slider-lightbox'),
            array($this, 'iconColorCallback'),
            'metaslider_lightbox_general_settings',
            'metaslider_lightbox_general'
        );

        add_settings_field(
            'icon_hover_color',
            __('Icon Hover Color', 'ml-slider-lightbox'),
            array($this, 'iconHoverColorCallback'),
            'metaslider_lightbox_general_settings',
            'metaslider_lightbox_general'
        );

        add_settings_field(
            'background_opacity',
            __('Background Opacity', 'ml-slider-lightbox'),
            array($this, 'backgroundOpacityCallback'),
            'metaslider_lightbox_general_settings',
            'metaslider_lightbox_general'
        );

        // MetaSlider Settings Section (only if MetaSlider is active)
        if ($this->isMetasliderActive()) {
            add_settings_section(
                'metaslider_lightbox_metaslider',
                __('MetaSlider Settings', 'ml-slider-lightbox'),
                array($this, 'metasliderSettingsSectionCallback'),
                'metaslider_lightbox_metaslider_settings'
            );

            add_settings_field(
                'show_arrows',
                __('Show Navigation Arrows', 'ml-slider-lightbox'),
                array($this, 'showArrowsCallback'),
                'metaslider_lightbox_metaslider_settings',
                'metaslider_lightbox_metaslider'
            );

            add_settings_field(
                'show_thumbnails',
                __('Show Thumbnails', 'ml-slider-lightbox'),
                array($this, 'showThumbnailsCallback'),
                'metaslider_lightbox_metaslider_settings',
                'metaslider_lightbox_metaslider'
            );
        }
    }

    /**
     * Sanitize general options
     */
    public function sanitizeGeneralOptions($input)
    {
        if (!current_user_can('manage_options')) {
            return get_option('metaslider_lightbox_general_options', array());
        }

        // Verify nonce for CSRF protection
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'metaslider_lightbox_general_settings-options')) {
            return get_option('metaslider_lightbox_general_options', array());
        }

        // Get existing options to preserve values not in this form submission
        $existing_options = get_option('metaslider_lightbox_general_options', array());
        $sanitized = $existing_options; // Start with existing options


        // Handle checkboxes - explicitly set to true/false based on form submission
        $sanitized['detect_all_images'] = isset($input['detect_all_images']) ? true : false;
        $sanitized['detect_all_galleries'] = isset($input['detect_all_galleries']) ? true : false;
        $sanitized['detect_all_videos'] = isset($input['detect_all_videos']) ? true : false;

        // Handle color inputs
        if (isset($input['background_color'])) {
            $sanitized['background_color'] = sanitize_hex_color($input['background_color']);
        }

        if (isset($input['button_color'])) {
            $sanitized['button_color'] = sanitize_hex_color($input['button_color']);
        }

        if (isset($input['icon_color'])) {
            $sanitized['icon_color'] = sanitize_hex_color($input['icon_color']);
        }

        if (isset($input['icon_hover_color'])) {
            $sanitized['icon_hover_color'] = sanitize_hex_color($input['icon_hover_color']);
        }

        // Handle opacity (must be between 0 and 1)
        if (isset($input['background_opacity'])) {
            $opacity = floatval($input['background_opacity']);
            $sanitized['background_opacity'] = max(0, min(1, $opacity));
        }

        return $sanitized;
    }

    /**
     * Sanitize MetaSlider options
     */
    public function sanitizeMetasliderOptions($input)
    {
        if (!current_user_can('manage_options')) {
            return get_option('metaslider_lightbox_metaslider_options', array());
        }

        // Verify nonce for CSRF protection
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'metaslider_lightbox_metaslider_settings-options')) {
            return get_option('metaslider_lightbox_metaslider_options', array());
        }

        $sanitized = array();

        // Handle checkboxes - if not set, they should be false
        $sanitized['show_arrows'] = isset($input['show_arrows']) ? true : false;
        $sanitized['show_thumbnails'] = isset($input['show_thumbnails']) ? true : false;

        return $sanitized;
    }

    /**
     * General settings section callback
     */
    public function generalSettingsSectionCallback()
    {
        echo '<p>' . __('Configure general lightbox settings that apply site-wide.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * MetaSlider settings section callback
     */
    public function metasliderSettingsSectionCallback()
    {
        echo '<p>' . __('Configure settings specific to MetaSlider lightbox functionality.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Detect all images callback
     */
    public function detectAllImagesCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('metaslider_lightbox_general_options', array());
        $checked = isset($options['detect_all_images']) ? $options['detect_all_images'] : false;

        echo '<label>';
        echo '<input type="checkbox" name="metaslider_lightbox_general_options[detect_all_images]" value="1"' . checked($checked, true, false) . ' />';
        echo ' ' . __('Automatically add the lightbox to all images in post content.', 'ml-slider-lightbox');
        echo '</label>';
    }

    /**
     * Detect all galleries callback
     */
    public function detectAllGalleriesCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('metaslider_lightbox_general_options', array());
        $checked = isset($options['detect_all_galleries']) ? $options['detect_all_galleries'] : false;

        echo '<label>';
        echo '<input type="checkbox" name="metaslider_lightbox_general_options[detect_all_galleries]" value="1"' . checked($checked, true, false) . ' />';
        echo ' ' . __('Automatically add the lightbox to all image galleries in post content.', 'ml-slider-lightbox');
        echo '</label>';
    }

    /**
     * Detect all videos callback
     */
    public function detectAllVideosCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('metaslider_lightbox_general_options', array());
        $checked = isset($options['detect_all_videos']) ? $options['detect_all_videos'] : false;

        echo '<label>';
        echo '<input type="checkbox" name="metaslider_lightbox_general_options[detect_all_videos]" value="1"' . checked($checked, true, false) . ' />';
        echo ' ' . __('Automatically add the lightbox to all YouTube, Vimeo, and other video embeds.', 'ml-slider-lightbox');
        echo '</label>';
    }


    /**
     * Background color callback
     */
    public function backgroundColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('metaslider_lightbox_general_options', array());
        $color = isset($options['background_color']) ? $options['background_color'] : '';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_general_options[background_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Choose the background color for the lightbox overlay.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Button color callback
     */
    public function buttonColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('metaslider_lightbox_general_options', array());
        $color = isset($options['button_color']) ? $options['button_color'] : '';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_general_options[button_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Choose the color for lightbox buttons (close, arrows, etc.).', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Icon color callback
     */
    public function iconColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('metaslider_lightbox_general_options', array());
        $color = isset($options['icon_color']) ? $options['icon_color'] : '';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_general_options[icon_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Choose the color for lightbox icons (close, arrows, etc.).', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Icon hover color callback
     */
    public function iconHoverColorCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('metaslider_lightbox_general_options', array());
        $color = isset($options['icon_hover_color']) ? $options['icon_hover_color'] : '';

        echo '<input type="text" class="ml-color-picker" name="metaslider_lightbox_general_options[icon_hover_color]" value="' . esc_attr($color) . '" />';
        echo '<p class="description">' . __('Choose the color for lightbox icons when hovered.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Background opacity callback
     */
    public function backgroundOpacityCallback()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = get_option('metaslider_lightbox_general_options', array());
        $opacity = isset($options['background_opacity']) ? $options['background_opacity'] : '0.9';

        echo '<input type="range" name="metaslider_lightbox_general_options[background_opacity]" min="0" max="1" step="0.1" value="' . esc_attr($opacity) . '" oninput="this.nextElementSibling.value = this.value" />';
        echo '<output>' . esc_html($opacity) . '</output>';
        echo '<p class="description">' . __('Set the opacity of the lightbox background (0 = transparent, 1 = opaque).', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Show arrows callback
     */
    public function showArrowsCallback()
    {
        $options = get_option('metaslider_lightbox_metaslider_options', array());
        $checked = isset($options['show_arrows']) ? $options['show_arrows'] : true;

        echo '<label>';
        echo '<input type="checkbox" name="metaslider_lightbox_metaslider_options[show_arrows]" value="1"' . checked($checked, true, false) . ' />';
        echo ' ' . __('Show navigation arrows in MetaSlider lightbox', 'ml-slider-lightbox');
        echo '</label>';
        echo '<p class="description">' . __('Display left and right arrows for navigating between slides in the lightbox.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Show thumbnails callback
     */
    public function showThumbnailsCallback()
    {
        $options = get_option('metaslider_lightbox_metaslider_options', array());
        $checked = isset($options['show_thumbnails']) ? $options['show_thumbnails'] : false;

        echo '<label>';
        echo '<input type="checkbox" name="metaslider_lightbox_metaslider_options[show_thumbnails]" value="1"' . checked($checked, true, false) . ' />';
        echo ' ' . __('Show thumbnail navigation in MetaSlider lightbox', 'ml-slider-lightbox');
        echo '</label>';
        echo '<p class="description">' . __('Display thumbnail images at the bottom of the lightbox for easy navigation.', 'ml-slider-lightbox') . '</p>';
    }

    /**
     * Get plugin options with defaults
     *
     * @return array
     */
    private function getPluginOptions()
    {
        $defaults = array(
            'lightbox_mode' => 'auto',
            'default_enabled' => false,
            'load_assets_globally' => false,
            'detect_all_images' => false,
            'detect_all_galleries' => false,
            'detect_all_videos' => false,
            'background_color' => '#000000',
            'button_color' => '#ffffff',
            'icon_color' => '#000000',
            'icon_hover_color' => '#333333',
            'background_opacity' => '0.9',
        );

        // Get saved options from database
        $general_options = get_option('metaslider_lightbox_general_options', array());
        $metaslider_options = get_option('metaslider_lightbox_metaslider_options', array());

        // Merge all options
        $saved_options = array_merge($general_options, $metaslider_options);

        // Return merged options with defaults
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
    public function handleVideoSlides($attributes, $slide, $slider_id)
    {
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if ($this->isLightboxEnabled($enabled)) {
            // Check if this is a video slide
            $video_url = isset($slide['url']) ? $slide['url'] : '';

            if (!empty($video_url) && $this->isVideoUrl($video_url)) {
                // Add video lightbox attributes
                $attributes['data-video-url'] = $video_url;
                $attributes['data-slider-id'] = $slider_id;
                $attributes['class'] = (isset($attributes['class']) ? $attributes['class'] . ' ' : '') . 'ml-video-slide';
            }
        }

        return $attributes;
    }

    /**
     * Handle Vimeo video slides - delegates to main video handler
     */
    public function handleVimeoVideoSlide($attributes, $slide, $slider_id)
    {
        return $this->handleVideoSlides($attributes, $slide, $slider_id);
    }

    /**
     * Handle YouTube video slides - delegates to main video handler
     */
    public function handleYoutubeVideoSlide($attributes, $slide, $slider_id)
    {
        return $this->handleVideoSlides($attributes, $slide, $slider_id);
    }

    /**
     * Handle external video slides - delegates to main video handler
     */
    public function handleExternalVideoSlide($attributes, $slide, $slider_id)
    {
        return $this->handleVideoSlides($attributes, $slide, $slider_id);
    }

    /**
     * Handle custom HTML slides
     */
    public function handleCustomHtmlSlide($attributes, $slide, $slider_id)
    {
        // Since we now use the button approach in JavaScript, don't add any attributes
        return $attributes;
    }

    /**
     * Handle image folder slides
     */
    public function handleImageFolderSlide($attributes, $slide, $slider_id)
    {
        // Since we now use the button approach in JavaScript, don't add any attributes
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
        // Since we now use the button approach in JavaScript, don't add any attributes
        // This prevents PHP from creating links that would conflict with our buttons
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
        // Since we now use the button approach in JavaScript, don't add any attributes
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
        // Since we now use the button approach in JavaScript, don't add any attributes
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
        // Since we now use the button approach in JavaScript, don't add any attributes
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
        // Check if lightbox is enabled for this slider
        $settings = get_post_meta($slider_id, 'ml-slider_settings', true);
        $enabled = isset($settings['lightbox']) ? $settings['lightbox'] : null;

        if (!$this->isLightboxEnabled($enabled)) {
            return $attributes; // Don't add lightbox attributes if disabled
        }

        // Add MetaSlider-specific lightbox settings as data attributes
        $metaslider_options = get_option('metaslider_lightbox_metaslider_options', array());
        $show_arrows = isset($metaslider_options['show_arrows']) ? $metaslider_options['show_arrows'] : true;
        $show_thumbnails = isset($metaslider_options['show_thumbnails']) ? $metaslider_options['show_thumbnails'] : false;

        // Add lightbox data attributes
        $attributes['data-lightbox-arrows'] = $show_arrows ? 'true' : 'false';
        $attributes['data-lightbox-thumbnails'] = $show_thumbnails ? 'true' : 'false';
        $attributes['data-slide-type'] = $slide_type;

        // Add class for lightbox identification
        $attributes['class'] = (isset($attributes['class']) ? $attributes['class'] . ' ' : '') . 'ml-lightbox-slide';

        return $attributes;
    }


    /**
     * Check if URL is a video URL
     */
    private function isVideoUrl($url)
    {
        if (empty($url)) {
            return false;
        }

        // Check for YouTube URLs
        if (preg_match('/^.*(youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/i', $url)) {
            return true;
        }

        // Check for Vimeo URLs
        if (preg_match('/^.*(vimeo\.com\/)((channels\/[A-z]+\/)|(groups\/[A-z]+\/videos\/)|(staff\/picks\/)|(videos\/)|)([0-9]+)/i', $url)) {
            return true;
        }

        // Check for direct video file extensions
        $video_extensions = ['mp4', 'webm', 'ogg', 'mov', 'avi', 'wmv', 'flv', 'm4v'];
        $extension = strtolower(pathinfo($url, PATHINFO_EXTENSION));
        return in_array($extension, $video_extensions);
    }

    /**
     * Shortcode support for lightbox galleries
     */
    public function lightboxShortcode($atts)
    {
        // Implementation for [ml_lightbox] shortcode
        return '';
    }

    /**
     * Gallery shortcode support
     */
    public function galleryShortcode($atts)
    {
        // Implementation for [ml_gallery] shortcode
        return '';
    }

    /**
     * Auto-detect galleries in content
     */
    public function autoDetectGalleries($content)
    {
        // Get plugin settings
        $options = $this->getPluginOptions();
        $detect_all_galleries = $options['detect_all_galleries'];
        $detect_all_images = $options['detect_all_images'];

        // Skip if neither detection is enabled
        if (!$detect_all_galleries && !$detect_all_images) {
            return $content;
        }

        // Don't run in admin area
        if (is_admin()) {
            return $content;
        }

        // Process content for galleries
        if ($detect_all_galleries) {
            $content = $this->enhanceContentGalleries($content);
        }

        // Process content for individual images
        if ($detect_all_images) {
            $content = $this->enhanceContentImages($content);
        }

        return $content;
    }

    /**
     * Enhance content galleries with lightbox
     */
    private function enhanceContentGalleries($content)
    {
        // Replace gallery shortcodes with enhanced versions
        $content = preg_replace_callback('/\[gallery[^\]]*\]/', array($this, 'enhanceGalleryShortcode'), $content);

        // Enhance any existing gallery HTML structures
        $content = $this->enhanceExistingGalleries($content);

        return $content;
    }

    /**
     * Enhance content images with lightbox (excluding images inside galleries and MetaSlider)
     */
    private function enhanceContentImages($content)
    {
        // First, protect gallery content and MetaSlider content from being processed
        $protected_content = $content;

        // Pattern to match gallery containers and MetaSlider containers
        $gallery_patterns = array(
            '/(\[gallery[^\]]*\].*?\[\/gallery\])/s',
            '/(<figure[^>]*class[^>]*wp-block-gallery[^>]*>.*?<\/figure>)/s',
            '/(<div[^>]*class[^>]*gallery[^>]*>.*?<\/div>)/s',
            '/(\[ml-slider[^\]]*\])/s',  // MetaSlider shortcodes
            '/(\[metaslider[^\]]*\])/s', // Alternative MetaSlider shortcodes
            '/(<div[^>]*class[^>]*metaslider[^>]*>.*?<\/div>)/s', // MetaSlider containers
            '/(<div[^>]*id[^>]*metaslider_[0-9]+[^>]*>.*?<\/div>)/s', // MetaSlider ID containers
            '/(<div[^>]*class[^>]*youtube[^>]*>.*?<\/div>)/s', // YouTube containers
            '/(<div[^>]*class[^>]*vimeo[^>]*>.*?<\/div>)/s', // Vimeo containers
            '/(<div[^>]*class[^>]*local-video[^>]*>.*?<\/div>)/s', // Local video containers
            '/(<div[^>]*class[^>]*external-video[^>]*>.*?<\/div>)/s' // External video containers
        );

        $placeholders = array();
        $placeholder_index = 0;

        // Replace gallery content with placeholders
        foreach ($gallery_patterns as $pattern) {
            $protected_content = preg_replace_callback($pattern, function ($matches) use (&$placeholders, &$placeholder_index) {
                $placeholder = '<!--GALLERY_PLACEHOLDER_' . $placeholder_index . '-->';
                $placeholders[$placeholder] = $matches[0];
                $placeholder_index++;
                return $placeholder;
            }, $protected_content);
        }

        // Pattern to match linked images (only in non-gallery content)
        $pattern = '/<a([^>]*?)href=["\']([^"\']*\.(jpg|jpeg|png|gif|webp|svg))["\']([^>]*?)>\s*<img([^>]*?)>\s*<\/a>/i';

        // Replace linked images with lightbox-enabled versions
        $protected_content = preg_replace_callback($pattern, array($this, 'enhanceStandaloneImageLink'), $protected_content);

        // Now handle unlinked images - wrap them in lightbox links
        $protected_content = $this->wrapUnlinkedImagesWithLightbox($protected_content);

        // Restore gallery content
        foreach ($placeholders as $placeholder => $original_content) {
            $protected_content = str_replace($placeholder, $original_content, $protected_content);
        }

        return $protected_content;
    }

    /**
     * Enhanced gallery shortcode callback
     */
    private function enhanceGalleryShortcode($matches)
    {
        $shortcode = $matches[0];

        // Get the gallery shortcode attributes
        $pattern = '/\[gallery([^\]]*)\]/';
        preg_match($pattern, $shortcode, $attr_matches);

        if (!empty($attr_matches[1])) {
            $attributes = shortcode_parse_atts($attr_matches[1]);
        } else {
            $attributes = array();
        }

        // Add lightbox class to gallery
        if (isset($attributes['class'])) {
            $attributes['class'] .= ' ml-auto-lightbox';
        }

        // Reconstruct the shortcode
        $new_shortcode = '[gallery';
        foreach ($attributes as $key => $value) {
            $new_shortcode .= ' ' . $key . '="' . esc_attr($value) . '"';
        }
        $new_shortcode .= ']';

        return $new_shortcode;
    }

    /**
     * Enhance standalone image link (only if standalone image detection is enabled)
     */
    private function enhanceStandaloneImageLink($matches)
    {
        $options = $this->getPluginOptions();

        // Only process if standalone image detection is enabled
        if (!$options['detect_all_images']) {
            return $matches[0];
        }

        // Skip if this appears to be MetaSlider content
        $full_match = $matches[0];
        if (
            preg_match('/class[^>]*ms-(external|folder|vimeo|youtube|local-video|external-video|custom-html|postfeed|layer)/i', $full_match) ||
            preg_match('/class[^>]*msDefaultImage/i', $full_match) ||
            preg_match('/class[^>]*(youtube|vimeo|local-video|external-video)/i', $full_match)
        ) {
            return $matches[0];
        }

        return $this->enhanceImageLink($matches);
    }

    /**
     * Enhance gallery image link (only if gallery detection is enabled)
     */
    private function enhanceGalleryImageLink($matches)
    {
        $options = $this->getPluginOptions();

        // Only process if gallery detection is enabled
        if (!$options['detect_all_galleries']) {
            return $matches[0];
        }

        return $this->enhanceImageLink($matches);
    }

    /**
     * Common image link enhancement logic
     */
    private function enhanceImageLink($matches)
    {
        $full_match = $matches[0];
        $before_href = $matches[1];
        $image_url = $matches[2];
        $after_href = $matches[4];
        $img_attributes = $matches[5];

        // Check if already has lightbox attributes
        if (strpos($full_match, 'data-src') !== false || strpos($full_match, 'data-lightbox') !== false) {
            return $full_match;
        }

        // Extract image alt text for caption
        $alt_text = '';
        if (preg_match('/alt=["\']([^"\']*)["\']/', $img_attributes, $alt_matches)) {
            $alt_text = $alt_matches[1];
        }

        // Build lightbox attributes
        $lightbox_attrs = ' data-src="' . esc_url($image_url) . '"';
        $lightbox_attrs .= ' class="ml-auto-lightbox"';

        if ($alt_text) {
            $lightbox_attrs .= ' data-sub-html="<p>' . esc_html($alt_text) . '</p>"';
        }

        // Reconstruct the link with lightbox attributes
        $enhanced_link = '<a' . $before_href . 'href="' . esc_url($image_url) . '"' . $lightbox_attrs . $after_href . '>';
        $enhanced_link .= '<img' . $img_attributes . '>';
        $enhanced_link .= '</a>';

        return $enhanced_link;
    }

    /**
     * Enhance Gutenberg gallery blocks
     */
    private function enhanceGutenbergGalleries($content)
    {
        // Pattern to match Gutenberg gallery blocks
        $pattern = '/<!-- wp:gallery.*?-->.*?<!-- \/wp:gallery -->/s';

        // Replace gallery blocks with enhanced versions
        $content = preg_replace_callback($pattern, array($this, 'enhanceGutenbergGalleryBlock'), $content);

        return $content;
    }

    /**
     * Enhanced Gutenberg gallery block callback
     */
    private function enhanceGutenbergGalleryBlock($matches)
    {
        $block_content = $matches[0];

        // Add lightbox class to gallery wrapper
        $block_content = preg_replace(
            '/<figure class="wp-block-gallery([^"]*)"/',
            '<figure class="wp-block-gallery$1 ml-auto-lightbox"',
            $block_content
        );

        // Enhance image links within the gallery
        $block_content = preg_replace_callback(
            '/<a([^>]*?)href=["\']([^"\']*\.(jpg|jpeg|png|gif|webp|svg))["\']([^>]*?)>\s*<img([^>]*?)>\s*<\/a>/i',
            array($this, 'enhanceGalleryImageLink'),
            $block_content
        );

        return $block_content;
    }

    /**
     * Enhance WordPress gallery
     */

    /**
     * Enhance existing gallery HTML structures
     */
    private function enhanceExistingGalleries($content)
    {
        // Patterns to match different gallery types
        $gallery_patterns = array(
            // WordPress gallery containers
            '/(<div[^>]*class[^>]*\bgallery\b[^>]*>)(.*?)(<\/div>)/s',
            // Gutenberg gallery blocks
            '/(<figure[^>]*class[^>]*\bwp-block-gallery\b[^>]*>)(.*?)(<\/figure>)/s',
            // Other gallery containers
            '/(<div[^>]*class[^>]*\btiled-gallery\b[^>]*>)(.*?)(<\/div>)/s'
        );

        foreach ($gallery_patterns as $pattern) {
            $content = preg_replace_callback($pattern, function ($matches) {
                $gallery_open = $matches[1];
                $gallery_content = $matches[2];
                $gallery_close = $matches[3];

                // Add lightbox attributes to images within this gallery
                $enhanced_content = preg_replace_callback(
                    '/<a([^>]*?)href=["\']([^"\']*\.(jpg|jpeg|png|gif|webp|svg))["\']([^>]*?)>\s*<img([^>]*?)>\s*<\/a>/i',
                    array($this, 'enhanceGalleryImageLink'),
                    $gallery_content
                );

                return $gallery_open . $enhanced_content . $gallery_close;
            }, $content);
        }

        return $content;
    }

    /**
     * Enhance WordPress gallery shortcode
     */
    public function enhanceWordpressGallery($output, $attr, $instance)
    {
        // Get plugin settings
        $options = $this->getPluginOptions();

        // Only process if gallery detection is enabled
        if (!$options['detect_all_galleries']) {
            return $output;
        }

        // If output is not empty, it means another plugin already handled it
        // We'll still process it to add lightbox functionality to any unlinked images
        if (!empty($output)) {
            // Process the existing output to add lightbox to unlinked images
            return $this->wrapUnlinkedImagesWithLightbox($output);
        }

        // Let WordPress handle the gallery normally, then we'll process it via the_content filter
        return $output;
    }

    /**
     * Enhance Gutenberg blocks
     */
    public function enhanceGutenbergBlocks($block_content, $block)
    {
        // Get plugin settings
        $options = $this->getPluginOptions();

        // Only process gallery blocks if detection is enabled
        if (!$options['detect_all_galleries'] || !isset($block['blockName'])) {
            return $block_content;
        }

        // Process gallery blocks
        if ($block['blockName'] === 'core/gallery') {
            $block_content = $this->enhanceGalleryBlock($block_content);
        }

        return $block_content;
    }

    /**
     * Enhance gallery block content
     */
    private function enhanceGalleryBlock($content)
    {
        // First handle linked images
        $pattern = '/<a([^>]*?)href=["\']([^"\']*\.(jpg|jpeg|png|gif|webp|svg))["\']([^>]*?)>\s*<img([^>]*?)>\s*<\/a>/i';
        $content = preg_replace_callback($pattern, array($this, 'enhanceGalleryImageLink'), $content);

        // Then handle unlinked images in gallery blocks
        $content = $this->wrapUnlinkedImagesWithLightbox($content);

        return $content;
    }

    /**
     * Wrap unlinked images with lightbox links
     *
     * @param string $content The content to process
     * @return string The processed content
     */
    private function wrapUnlinkedImagesWithLightbox($content)
    {
        // Validate input
        if (empty($content) || !is_string($content)) {
            return $content;
        }

        // Split content into tokens to process context-aware
        $tokens = preg_split('/(<\/?a[^>]*>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        if (!$tokens) {
            return $content;
        }

        $result = '';
        $inside_link = false;

        foreach ($tokens as $token) {
            if (preg_match('/^<a[^>]*>$/i', $token)) {
                // Opening <a> tag
                $inside_link = true;
                $result .= $token;
            } elseif (preg_match('/^<\/a>$/i', $token)) {
                // Closing </a> tag
                $inside_link = false;
                $result .= $token;
            } else {
                // Regular content - check for images only if not inside a link
                if (!$inside_link && strpos($token, '<img') !== false) {
                    $pattern = '/<img[^>]*src=["\']([^"\']*\.(jpg|jpeg|png|gif|webp|svg))["\'][^>]*>/i';
                    $token = preg_replace_callback($pattern, array($this, 'wrapImageWithLightboxLink'), $token);
                }
                $result .= $token;
            }
        }

        return $result;
    }

    /**
     * Callback function to wrap an image with a lightbox link
     *
     * @param array $matches Regex matches array
     * @return string The processed image tag
     */
    private function wrapImageWithLightboxLink($matches)
    {
        // Validate matches
        if (!isset($matches[0]) || !isset($matches[1])) {
            return isset($matches[0]) ? $matches[0] : '';
        }

        $img_tag = $matches[0];
        $src_url = $matches[1];

        // Skip if image already has lightbox attributes
        if (
            strpos($img_tag, 'ml-auto-lightbox') !== false ||
            strpos($img_tag, 'data-src') !== false ||
            strpos($img_tag, 'data-lightbox') !== false
        ) {
            return $img_tag;
        }

        // Skip if this appears to be MetaSlider content
        if (
            preg_match('/class[^>]*msDefaultImage/i', $img_tag) ||
            preg_match('/class[^>]*ms-(external|folder|vimeo|youtube|local-video|external-video|custom-html|postfeed|layer)/i', $img_tag)
        ) {
            return $img_tag;
        }

        // Get the full-size image URL from the src
        $full_size_url = $this->getFullSizeImageUrl($src_url);

        // Validate full-size URL
        if (empty($full_size_url) || !filter_var($full_size_url, FILTER_VALIDATE_URL)) {
            return $img_tag;
        }

        // Extract alt text for caption
        $alt_text = '';
        if (preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_matches)) {
            $alt_text = trim($alt_matches[1]);
        }

        // Build lightbox attributes
        $lightbox_attributes = array(
            'href="' . esc_url($full_size_url) . '"',
            'data-src="' . esc_url($full_size_url) . '"',
            'class="ml-auto-lightbox"'
        );

        if (!empty($alt_text)) {
            $lightbox_attributes[] = 'data-sub-html="' . esc_attr($alt_text) . '"';
        }

        // Get image dimensions for data-lg-size (optional, for performance)
        $dimensions = $this->getImageDimensions($full_size_url);
        if ($dimensions && !empty($dimensions['width']) && !empty($dimensions['height'])) {
            $lightbox_attributes[] = 'data-lg-size="' . $dimensions['width'] . '-' . $dimensions['height'] . '"';
        }

        // Return the wrapped image
        return '<a ' . implode(' ', $lightbox_attributes) . '>' . $img_tag . '</a>';
    }

    /**
     * Get full-size image URL from potentially resized image URL
     *
     * @param string $src_url The source image URL
     * @return string The full-size image URL
     */
    private function getFullSizeImageUrl($src_url)
    {
        // Validate input
        if (empty($src_url) || !is_string($src_url)) {
            return $src_url;
        }

        // If we have WordPress functions available, try to get the actual full-size URL first
        if (function_exists('wp_get_attachment_url') && function_exists('attachment_url_to_postid')) {
            $attachment_id = attachment_url_to_postid($src_url);
            if ($attachment_id) {
                $full_url = wp_get_attachment_url($attachment_id);
                if ($full_url && is_string($full_url)) {
                    return $full_url;
                }
            }
        }

        // Fallback: Remove WordPress size suffixes like -300x200, -150x150, etc.
        $full_size_url = preg_replace('/-\d+x\d+(\.[a-zA-Z]{3,4})$/i', '$1', $src_url);

        return $full_size_url ? $full_size_url : $src_url;
    }

    /**
     * Get image dimensions from URL
     *
     * @param string $image_url The image URL
     * @return array|null Array with width/height or null if unable to determine
     */
    private function getImageDimensions($image_url)
    {
        // Validate input
        if (empty($image_url) || !is_string($image_url)) {
            return null;
        }

        // First try to get dimensions from WordPress attachment
        if (function_exists('attachment_url_to_postid') && function_exists('wp_get_attachment_metadata')) {
            $attachment_id = attachment_url_to_postid($image_url);
            if ($attachment_id) {
                $image_meta = wp_get_attachment_metadata($attachment_id);
                if (is_array($image_meta) && !empty($image_meta['width']) && !empty($image_meta['height'])) {
                    return array(
                        'width' => (int) $image_meta['width'],
                        'height' => (int) $image_meta['height']
                    );
                }
            }
        }

        // Fallback: try to get dimensions from image file
        if (function_exists('getimagesize') && function_exists('wp_upload_dir')) {
            try {
                $upload_dir = wp_upload_dir();
                if (!empty($upload_dir['baseurl']) && !empty($upload_dir['basedir'])) {
                    if (strpos($image_url, $upload_dir['baseurl']) === 0) {
                        $image_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $image_url);
                        if (file_exists($image_path) && is_readable($image_path)) {
                            $size = @getimagesize($image_path);
                            if ($size !== false && isset($size[0]) && isset($size[1])) {
                                return array(
                                    'width' => (int) $size[0],
                                    'height' => (int) $size[1]
                                );
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                // Silently fail - dimensions aren't critical
                return null;
            }
        }

        return null;
    }

    /**
     * Override WordPress "Enlarge on click" functionality
     *
     * @param string $link HTML link element
     * @param int $attachment_id Attachment ID
     * @param string $size Image size
     * @param bool $permalink Whether the link is a permalink
     * @param bool $icon Whether the link is an icon
     * @param string $text Link text
     * @return string Modified link HTML
     */
    public function overrideEnlargeOnClick($link, $attachment_id, $size, $permalink, $icon, $text)
    {
        // Skip if this is a permalink link to the attachment page
        if ($permalink) {
            return $link;
        }

        // Skip if it's an icon
        if ($icon) {
            return $link;
        }


        // Get the full-size image URL
        $full_size_url = wp_get_attachment_url($attachment_id);
        if (!$full_size_url) {
            return $link;
        }

        // Get image metadata for dimensions and caption
        $image_meta = wp_get_attachment_metadata($attachment_id);
        $attachment_post = get_post($attachment_id);

        // Build our lightbox attributes
        $lightbox_attributes = array(
            'href="' . esc_url($full_size_url) . '"',
            'data-src="' . esc_url($full_size_url) . '"',
            'class="ml-auto-lightbox"'
        );

        // Add caption if available
        if ($attachment_post && !empty($attachment_post->post_excerpt)) {
            $lightbox_attributes[] = 'data-sub-html="' . esc_attr($attachment_post->post_excerpt) . '"';
        }

        // Add dimensions if available
        if ($image_meta && !empty($image_meta['width']) && !empty($image_meta['height'])) {
            $lightbox_attributes[] = 'data-lg-size="' . $image_meta['width'] . '-' . $image_meta['height'] . '"';
        }

        // Remove WordPress lightbox attributes from the link and image
        $link = preg_replace('/\s*data-wp-[^=]*=["\'][^"\']*["\']/', '', $link);

        // Add our lightbox attributes to the link
        $link = str_replace('<a ', '<a ' . implode(' ', $lightbox_attributes) . ' ', $link);

        return $link;
    }

    /**
     * Disable WordPress lightbox JavaScript
     */
    public function disableWordpressLightboxJs()
    {
        ?>
        <script type="text/javascript">
        (function() {
            document.addEventListener('DOMContentLoaded', function() {
                // Remove WordPress lightbox attributes from all images
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
            
            // Prevent WordPress lightbox clicks
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
