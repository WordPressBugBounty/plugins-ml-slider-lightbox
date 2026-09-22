<?php

if (!defined('ABSPATH')) {
    die('No direct access.');
}

if (!class_exists('MLSliderLightbox_EmailCollection')) {
    require_once ML_SLIDER_LIGHTBOX_PATH . 'admin/EmailCollection.php';
}

class MLSliderLightbox_Optin
{
    const USER_STATUS = 'metaslider_lightbox_optin_status';

    protected $version;

    protected $should_show;

    public function __construct($version = '')
    {
        $this->version = $version;

        add_action('admin_enqueue_scripts', array($this, 'enqueueAssets'));
        add_action('admin_footer', array($this, 'renderModal'));
        add_action('wp_ajax_ml_lightbox_optin', array($this, 'handleOptin'));
    }

    /**
     * Decided once, at enqueue time, so the footer cannot disagree with what
     * was enqueued.
     *
     * @return boolean
     */
    protected function shouldShow()
    {
        if (isset($this->should_show)) {
            return $this->should_show;
        }

        $this->should_show = $this->decideShouldShow();

        return $this->should_show;
    }

    protected function decideShouldShow()
    {
        if (!current_user_can('manage_options') || !$this->isGalleryScreen()) {
            return false;
        }

        if (apply_filters('metaslider_lightbox_always_show_optin_notice', false)) {
            return true;
        }

        return !MLSliderLightbox_EmailCollection::site_is_optin()
            && !get_user_option(self::USER_STATUS);
    }

    protected function isGalleryScreen()
    {
        $page = isset($_GET['page']) ? sanitize_key($_GET['page']) : '';

        return 'ml-gallery-editor' === $page;
    }

    public function enqueueAssets()
    {
        if (!$this->shouldShow()) {
            return;
        }

        wp_enqueue_style(
            'ml-lightbox-optin',
            ML_SLIDER_LIGHTBOX_URL . 'assets/css/ml-optin-modal.css',
            array(),
            $this->version
        );

        wp_enqueue_script(
            'ml-lightbox-optin',
            ML_SLIDER_LIGHTBOX_URL . 'assets/js/ml-optin-modal.js',
            array(),
            $this->version,
            true
        );

        wp_localize_script(
            'ml-lightbox-optin',
            'mlLightboxOptin',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('ml_lightbox_optin'),
                'strings' => array(
                    'genericError' => __(
                        'Your preference was saved, but we could not add your address to the mailing list. Please check it and try again.',
                        'ml-slider-lightbox'
                    ),
                    'saveError'    => __('We could not save your preference. Please try again.', 'ml-slider-lightbox'),
                ),
            )
        );
    }

    public function renderModal()
    {
        if (!$this->shouldShow()) {
            return;
        }

        $email = MLSliderLightbox_EmailCollection::suggested_email();

        require ML_SLIDER_LIGHTBOX_PATH . 'admin/views/optin-modal.php';
    }

    public function handleOptin()
    {
        check_ajax_referer('ml_lightbox_optin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(
                array('message' => __('You do not have permission to do that.', 'ml-slider-lightbox')),
                403
            );
        }

        $choice = isset($_POST['choice']) ? sanitize_key(wp_unslash($_POST['choice'])) : '';

        if ('yes' !== $choice) {
            $this->decline();
        }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        // sanitize_email() reduces garbage to an empty string, which would
        // otherwise be stored and then fail validation on the way out.
        if (!is_email($email)) {
            wp_send_json_error(
                array('message' => __('Please enter a valid email address.', 'ml-slider-lightbox')),
                400
            );
        }

        update_option(MLSliderLightbox_EmailCollection::OPTION_EMAIL, $email, true);
        update_option(MLSliderLightbox_EmailCollection::OPTION_OPTIN, '1', true);
        update_user_option(get_current_user_id(), self::USER_STATUS, 'yes', true);

        // Submitting the form is a deliberate request to subscribe, so let it
        // send again for someone re-opting in after losing the first email.
        delete_option(MLSliderLightbox_EmailCollection::OPTION_SENT);

        $report = MLSliderLightbox_EmailCollection::optin();

        $response = array(
            'status' => $report['status'],
            'email'  => $email,
        );

        // Names internal endpoints, so it is off unless a developer asks for it.
        if (apply_filters('metaslider_lightbox_always_show_connect_report', false)) {
            $response['report'] = $report;
        }

        wp_send_json_success($response);
    }

    protected function decline()
    {
        update_user_option(get_current_user_id(), self::USER_STATUS, 'no', true);

        // Leaving the flag on with nothing ever handed off would have every
        // later settings save retry an address the admin has since declined.
        if (!get_option(MLSliderLightbox_EmailCollection::OPTION_SENT)) {
            update_option(MLSliderLightbox_EmailCollection::OPTION_OPTIN, '', true);
        }

        wp_send_json_success(array('status' => 'dismissed'));
    }
}
