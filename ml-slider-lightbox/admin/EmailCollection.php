<?php

if (!defined('ABSPATH')) {
    die('No direct access.');
}

if (! class_exists('MLSliderLightbox_EmailCollection')) {
    /**
     * Hands the opt-in email address to MetaSlider's connect service, which
     * forwards it to our mailing list provider.
     */
    class MLSliderLightbox_EmailCollection
    {
        const CONNECT_KEY = '803ee115a6335b218396b35c7e2fc4ca';

        const PLUGIN_SLUG = 'ml-slider-lightbox';

        const OPTION_OPTIN = 'metaslider_lightbox_optin';

        const OPTION_EMAIL = 'metaslider_lightbox_optin_email';

        const OPTION_SENT = 'metaslider_lightbox_optin_email_sent';

        /**
         * Whether this site has opted in.
         *
         * @return boolean
         */
        public static function site_is_optin()
        {
            return filter_var(get_option(self::OPTION_OPTIN), FILTER_VALIDATE_BOOLEAN);
        }

        /**
         * The address to prefill the opt-in field with. Falls back to the
         * MetaSlider Slideshow address, then to the address on the current
         * user's account, so an admin doesn't have to type it again.
         *
         * @return string
         */
        public static function suggested_email()
        {
            foreach (array(self::OPTION_EMAIL, 'metaslider_optin_email') as $option) {
                $email = get_option($option);
                if (is_email($email)) {
                    return $email;
                }
            }

            $email = wp_get_current_user()->user_email;

            return is_email($email) ? $email : '';
        }

        /**
         * Send the opted-in address to the connect service.
         *
         * Deduplicates on the address rather than the opt-in flag, so re-saving
         * settings doesn't send again but changing the address does. A failed
         * handoff is not recorded as sent, so a later save retries it - after a
         * short cooldown rather than on every call in between.
         *
         * @return array 'status' is one of 'subscribed', 'duplicate', 'invalid'
         *               or 'failed'. 'duplicate' is a success case for the UI.
         */
        public static function optin()
        {
            $email = get_option(self::OPTION_EMAIL);

            if (! is_email($email)) {
                return array(
                    'status'  => 'invalid',
                    'message' => 'No valid email address to send.',
                );
            }

            if (get_option(self::OPTION_SENT) === $email) {
                return array(
                    'status'  => 'duplicate',
                    'message' => 'This address was already handed off, so nothing was sent again.',
                    'sent'    => array('plugin' => self::PLUGIN_SLUG, 'email' => $email),
                );
            }

            $cooldown_key = 'metaslider_lightbox_optin_retry_' . md5($email);

            if (get_transient($cooldown_key)) {
                return array(
                    'status'  => 'failed',
                    'message' => 'A previous attempt failed recently - not retrying immediately.',
                );
            }

            $on_failure = function ($report) use ($cooldown_key) {
                set_transient($cooldown_key, 1, 60);
                return $report;
            };

            $endpoint = apply_filters(
                'metaslider_lightbox_connect_endpoint',
                'https://connect.metaslider.com/wp-json/connect/v1/subscribe'
            );

            $payload = array(
                'plugin' => self::PLUGIN_SLUG,
                'email'  => $email,
            );

            $response = wp_remote_post($endpoint, array(
                // The connect service dispatches the confirmation email before
                // it answers, so this round trip includes sending mail.
                // phpcs:ignore WordPressVIPMinimum.Performance.RemoteRequestTimeout.timeout_timeout
                'timeout' => 10,
                'headers' => array(
                    'X-MetaSlider-Connect-Key' => apply_filters(
                        'metaslider_lightbox_connect_key',
                        self::CONNECT_KEY
                    ),
                ),
                'body'    => $payload,
            ));

            $report = array(
                'status'   => 'failed',
                'endpoint' => $endpoint,
                'sent'     => $payload,
            );

            if (is_wp_error($response)) {
                $report['message'] = 'The connect service could not be reached.';
                $report['error']   = $response->get_error_message();
                return $on_failure($report);
            }

            $report['http_code'] = (int) wp_remote_retrieve_response_code($response);

            // A misrouted REST request can return the site's HTML with a 200,
            // so require the JSON success payload before recording the handoff.
            $body = json_decode(wp_remote_retrieve_body($response), true);

            if (! is_array($body)) {
                $report['message'] = 'The connect service did not return JSON. Check the endpoint URL.';
                return $on_failure($report);
            }

            $report['connect'] = isset($body['data']) ? $body['data'] : $body;

            if (200 !== $report['http_code'] || empty($body['success'])) {
                $reason = isset($body['data']) && is_string($body['data']) ? $body['data'] : '';

                $report['message'] = $reason
                    ? 'The connect service rejected the request: ' . $reason
                    : 'The connect service rejected the request.';

                return $on_failure($report);
            }

            update_option(self::OPTION_SENT, $email, true);

            $report['status']  = 'subscribed';
            $report['message'] = 'Subscribed. The address is on the mailing list now.';

            return $report;
        }
    }
}
