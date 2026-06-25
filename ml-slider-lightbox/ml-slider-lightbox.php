<?php

/*
 * Plugin Name: MetaSlider Gallery
 * Plugin URI: https://www.metaslider.com
 * Description: MetaSlider Gallery is the image gallery plugin for WordPress. Create a beautiful display for your photos with carousel, masonry, grid, and more views.
 * Version: 2.33.0
 * Requires PHP: 7.0
 * Author: MetaSlider
 * Author URI: https://www.metaslider.com
 * License: GPL-2.0+
 * Copyright: 2020+ MetaSlider
 *
 * Text Domain: ml-slider-lightbox
 * Domain Path: /languages
 */
if (! defined('ABSPATH')) {
    die('No direct access.');
}

if (! defined('ML_SLIDER_LIGHTBOX_PATH')) {
    define('ML_SLIDER_LIGHTBOX_PATH', plugin_dir_path(__FILE__));
}
if (! defined('ML_SLIDER_LIGHTBOX_URL')) {
    define('ML_SLIDER_LIGHTBOX_URL', plugin_dir_url(__FILE__));
}

if (! class_exists('MetaSlider\Lightbox\MetaSliderLightboxPlugin')) {
    require_once plugin_dir_path(__FILE__) . 'class-ml-slider-lightbox.php';
    add_action('plugins_loaded', array(MetaSlider\Lightbox\MetaSliderLightboxPlugin::getInstance(), 'setup'), 10);

    register_activation_hook(__FILE__, 'metaslider_lightbox_activation');
}

/**
 * Plugin activation hook
 * Sets a transient to trigger redirect to settings page
 */
function metaslider_lightbox_activation()
{
    set_transient('metaslider_lightbox_activation_redirect', true, 30);
} 
