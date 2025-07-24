<?php

/*
 * Plugin Name: MetaSlider Lightbox
 * Plugin URI: https://www.metaslider.com
 * Description: Adds lightbox plugin integration to MetaSlider. Requires MetaSlider and one compatible lightbox plugin to be installed and activated.
 * Version: 2.0.0
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

if (! class_exists('MetaSlider\Lightbox\MetaSliderLightboxPlugin')) {
    require_once plugin_dir_path(__FILE__) . 'class-ml-slider-lightbox.php';
    add_action('plugins_loaded', array(MetaSlider\Lightbox\MetaSliderLightboxPlugin::getInstance(), 'setup'), 10);
}
