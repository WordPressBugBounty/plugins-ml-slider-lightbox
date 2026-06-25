<?php

if (!defined('ABSPATH')) {
    die('No direct access.');
}

if (!class_exists('Updraft_Notices_1_0')) {
    require_once ML_SLIDER_LIGHTBOX_PATH . 'admin/lib/Updraft_Notices.php';
}

class MLSliderLightbox_Notices extends Updraft_Notices_1_0
{
    protected $ads;

    protected $is_pro;

    protected $version;

    public function __construct($version = '', $is_pro = false)
    {
        $this->version = $version;
        $this->is_pro  = (bool) $is_pro;

        add_action('admin_enqueue_scripts', array($this, 'add_notice_assets'));
        add_action('wp_ajax_ml_lightbox_notice_handler', array($this, 'ajax_notice_handler'));
        add_action('metaslider_lightbox_admin_notices', array($this, 'show_admin_notices'));
    }

    public function notices_init()
    {
        if (isset($this->ads)) {
            return;
        }

        $this->ads = $this->lite_notices();
        $this->notices_content = ($this->ad_delay_has_finished()) ? $this->ads : array();
    }

    protected function lite_notices()
    {
        $ads = array(
            'rate_plugin' => array(
                'title'               => __('Enjoying MetaSlider Gallery? Please help us with a 5-star review on WordPress.org.', 'ml-slider-lightbox'),
                'text'                => '',
                'image'               => 'metaslider_logo.png',
                'button_link'         => 'review_plugin',
                'button_meta'         => 'review',
                'dismiss_time'        => 'rate_plugin',
                'hide_time'           => 12,
                'supported_positions' => array('header'),
            ),
            'upgrade_pro' => array(
                'title'               => __('Unlock zoom, fullscreen, sharing and more with MetaSlider Gallery Pro.', 'ml-slider-lightbox'),
                'text'                => '',
                'image'               => 'metaslider_logo.png',
                'button_link'         => 'upgrade_gallery',
                'button_meta'         => 'buy',
                'dismiss_time'        => 'upgrade_pro',
                'hide_time'           => 12,
                'supported_positions' => array('header'),
                'validity_function'   => 'pro_is_not_installed',
            ),
        );

        return $ads;
    }

    protected function pro_is_not_installed()
    {
        return ! $this->is_pro;
    }

    protected function check_notice_dismissed($ad_identifier)
    {
        if ($this->force_ads()) {
            return false;
        }
        return (time() < get_option("mll_hide_{$ad_identifier}_ads_until"));
    }

    protected function is_page_with_ads()
    {
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

        return in_array($page, array('metaslider-lightbox', 'ml-gallery-editor'), true);
    }

    protected function ad_delay_has_finished()
    {
        $delay = get_option('mll_hide_all_ads_until');

        if ($this->force_ads()) {
            return true;
        }

        if (!$this->is_page_with_ads() && !$delay) {
            return false;
        }

        if (!$delay) {
            return !update_option('mll_hide_all_ads_until', time() + 2 * 7 * 86400);
        } elseif ((time() > $delay) && !get_option('mll_ads_first_seen_on')) {
            update_option('mll_ads_first_seen_on', time());
            return true;
        } elseif (time() < $delay) {
            return false;
        } elseif (get_option('mll_ads_first_seen_on')) {
            return true;
        }

        return false;
    }

    public function show_admin_notices()
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $this->do_notice(false, 'header', true);
    }

    public function add_notice_assets()
    {
        if (!$this->is_page_with_ads()) {
            return;
        }

        wp_enqueue_style('ml-lightbox-notices-css', ML_SLIDER_LIGHTBOX_URL . 'assets/css/notices.css', false, $this->version);
        wp_register_script('ml-lightbox-notices-extra-js', '', array('jquery'), $this->version, true);
        wp_enqueue_script('ml-lightbox-notices-extra-js');
        $nonce = wp_create_nonce('ml_lightbox_handle_notices_nonce');

        // Dismiss handler bound via delegation (no inline onclick — CSP-friendly).
        $script  = 'window.ml_lightbox_notices_handle_notices_nonce = ' . wp_json_encode($nonce) . ';';
        $script .= 'jQuery(function($){$(document).on("click",".ml-notice-dismiss",function(e){'
            . 'e.preventDefault();'
            . 'var $c=$(this).closest(".updraft-ad-container");'
            . '$.post(ajaxurl,{action:"ml_lightbox_notice_handler",ad_identifier:$(this).data("ad-identifier"),_wpnonce:window.ml_lightbox_notices_handle_notices_nonce})'
            . '.always(function(){$c.slideUp();});'
            . '});});';
        $this->wp_add_inline_script('ml-lightbox-notices-extra-js', $script);
    }

    protected function render_specified_notice($notice_information, $return_instead_of_echo = false, $position = 'header')
    {
        return $this->include_template('header-notice.php', $return_instead_of_echo, $notice_information);
    }

    public function include_template($path, $return_instead_of_echo = false, $args = array())
    {
        if ($return_instead_of_echo) {
            ob_start();
        }

        include ML_SLIDER_LIGHTBOX_PATH . 'admin/views/notices/' . $path;

        if ($return_instead_of_echo) {
            return ob_get_clean();
        }
    }

    public function get_button_link($link, $type)
    {
        $messages = array(
            'review' => __('Review MetaSlider Gallery &rarr;', 'ml-slider-lightbox'),
            'buy'    => __('Get Gallery Pro &rarr;', 'ml-slider-lightbox'),
        );

        $message = isset($messages[$type]) ? $messages[$type] : __('Read more', 'ml-slider-lightbox');

        return '<a class="updraft_notice_link ml-discount-ad-button" target="_blank" href="' . esc_url($this->get_notice_url($link)) . '">' . esc_html($message) . '</a>';
    }

    public function get_notice_url($link_id)
    {
        $urls = array(
            'review_plugin'   => 'https://wordpress.org/support/plugin/ml-slider-lightbox/reviews?rate=5#new-post',
            'upgrade_gallery' => 'https://www.metaslider.com/upgrade-gallery/',
        );

        if (!isset($urls[$link_id])) {
            return 'https://www.metaslider.com';
        }

        if (strpos($urls[$link_id], 'utm_source')) {
            return esc_url($urls[$link_id]);
        }

        return esc_url(add_query_arg(array(
            'utm_source' => 'gallery-plugin',
            'utm_medium' => 'banner',
        ), $urls[$link_id]));
    }

    public function ajax_notice_handler()
    {
        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_key($_REQUEST['_wpnonce']), 'ml_lightbox_handle_notices_nonce')) {
            wp_send_json_error(array(
                'message' => __('The security check failed. Please refresh the page and try again.', 'ml-slider-lightbox'),
            ), 401);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Access denied. Sorry, you do not have permission to complete this task.', 'ml-slider-lightbox'),
            ), 403);
        }

        if (!isset($_POST['ad_identifier'])) {
            wp_send_json_error(array(
                'message' => __('Bad request', 'ml-slider-lightbox'),
            ), 400);
        }

        $ad_data = $this->ad_exists(sanitize_key($_POST['ad_identifier']));

        if (is_wp_error($ad_data)) {
            wp_send_json_error(array(
                'message' => __('This item does not exist. Please refresh the page and try again.', 'ml-slider-lightbox'),
            ), 401);
        }

        $result = $this->dismiss_ad($ad_data['dismiss_time'], $ad_data['hide_time']);

        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
            ), 409);
        }

        wp_send_json_success(array(
            'message' => __('The option was successfully updated', 'ml-slider-lightbox'),
        ), 200);
    }

    public function ad_exists($ad_identifier)
    {
        $all = $this->lite_notices();
        if (isset($all[$ad_identifier])) {
            return $all[$ad_identifier];
        }

        return new WP_Error('bad_call', __('The requested data does not exist.', 'ml-slider-lightbox'), array('status' => 401));
    }

    public function dismiss_ad($ad_identifier, $weeks)
    {
        $weeks  = is_int($weeks) ? $weeks + 1 : 9999;
        $result = update_option("mll_hide_{$ad_identifier}_ads_until", time() + $weeks * 7 * 86400);

        if (get_option('mll_ads_first_seen_on')) {
            update_option('mll_hide_all_ads_until', time() + 12 * 7 * 86400);
        }

        return $result ? $result : new WP_Error('update_failed', __('The attempt to update the option failed.', 'ml-slider-lightbox'), array('status' => 409));
    }

    private function force_ads()
    {
        return defined('MLL_FORCE_NOTICES') && MLL_FORCE_NOTICES;
    }

    public function wp_add_inline_script($handle, $data, $position = 'after')
    {
        if (function_exists('wp_add_inline_script')) {
            return wp_add_inline_script($handle, $data, $position);
        }
        global $wp_scripts;
        if (!$data) {
            return false;
        }
        $script  = $wp_scripts->get_data($handle, 'data');
        $script .= $data;
        return $wp_scripts->add_data($handle, 'data', $script);
    }
}
