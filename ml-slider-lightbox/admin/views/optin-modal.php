<?php

if (!defined('ABSPATH')) {
    die('No direct access.');
}

/**
 * Rendered by MLSliderLightbox_Optin::renderModal().
 *
 * @var string $email Address to prefill the field with, possibly empty.
 */
?>
<div class="ml-optin-overlay" id="ml-optin-modal" hidden>
    <div
        class="ml-optin-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby="ml-optin-title"
        data-title-idle="<?php echo esc_attr__('Thanks for using MetaSlider Gallery', 'ml-slider-lightbox'); ?>"
        data-title-sent="<?php echo esc_attr__('You are subscribed', 'ml-slider-lightbox'); ?>"
        data-title-error="<?php echo esc_attr__('We could not subscribe you', 'ml-slider-lightbox'); ?>"
    >
        <h2 class="ml-optin-title" id="ml-optin-title">
            <?php echo esc_html__('Thanks for using MetaSlider Gallery', 'ml-slider-lightbox'); ?>
        </h2>

        <div class="ml-optin-body">
            <div class="ml-optin-panel" data-panel="idle">
                <p class="ml-optin-lead">
                    <?php echo esc_html__(
                        'Get occasional emails about important MetaSlider Gallery security and feature updates.',
                        'ml-slider-lightbox'
                    ); ?>
                </p>
                <p class="ml-optin-lead">
                    <?php echo esc_html__('You can unsubscribe at any time.', 'ml-slider-lightbox'); ?>
                </p>
            </div>

            <div class="ml-optin-panel" data-panel="error" hidden>
                <p class="ml-optin-lead ml-optin-error-message"></p>
                <p class="ml-optin-fineprint">
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s: link reading "contact support". */
                        __('If this keeps happening, please %s.', 'ml-slider-lightbox'),
                        '<a target="_blank" rel="noopener" href="https://www.metaslider.com/support/">'
                            . esc_html__('contact support', 'ml-slider-lightbox')
                            . '</a>'
                    ));
                    ?>
                </p>
            </div>

            <div class="ml-optin-panel" data-panel="sent" hidden>
                <p class="ml-optin-lead">
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s: the email address that was subscribed. */
                        esc_html__('%s has been added to our mailing list.', 'ml-slider-lightbox'),
                        '<strong class="ml-optin-address"></strong>'
                    ));
                    ?>
                </p>
            </div>

            <div class="ml-optin-field">
                <label class="screen-reader-text" for="ml-optin-email">
                    <?php echo esc_html__('Email address', 'ml-slider-lightbox'); ?>
                </label>
                <input
                    type="email"
                    id="ml-optin-email"
                    class="ml-optin-email"
                    autocomplete="email"
                    placeholder="you@example.com"
                    value="<?php echo esc_attr($email); ?>"
                />
            </div>

            <p class="ml-optin-fineprint ml-optin-privacy">
                <?php
                echo wp_kses_post(sprintf(
                    /* translators: %s: link reading "privacy policy". */
                    __('See our %s.', 'ml-slider-lightbox'),
                    '<a target="_blank" rel="noopener" href="https://www.metaslider.com/privacy-policy">'
                        . esc_html__('privacy policy', 'ml-slider-lightbox')
                        . '</a>'
                ));
                ?>
            </p>

            <details class="ml-optin-report" hidden>
                <summary>
                    <?php echo esc_html__('Connection details', 'ml-slider-lightbox'); ?>
                    <span class="ml-optin-report-status"></span>
                </summary>
                <pre class="ml-optin-report-body"></pre>
            </details>
        </div>

        <div class="ml-optin-actions">
            <button type="button" class="button ml-optin-decline">
                <?php echo esc_html__('No thanks', 'ml-slider-lightbox'); ?>
            </button>
            <button type="button" class="button button-primary ml-optin-submit" disabled>
                <span data-label="idle"><?php echo esc_html__('Agree and continue', 'ml-slider-lightbox'); ?></span>
                <span data-label="sending" hidden><?php echo esc_html__('Sending…', 'ml-slider-lightbox'); ?></span>
                <span data-label="error" hidden><?php echo esc_html__('Try again', 'ml-slider-lightbox'); ?></span>
            </button>
            <button type="button" class="button button-primary ml-optin-finish" hidden>
                <?php echo esc_html__('Got it', 'ml-slider-lightbox'); ?>
            </button>
        </div>
    </div>
</div>
