<?php if (!defined('ABSPATH')) {
    die('No direct access.');
} ?>

<div class="updraft-ad-container ml-discount-ad notice updated">
    <div class="updraft_notice_container">
        <div class="updraft_advert_content_left">
            <img src="<?php echo esc_url(ML_SLIDER_LIGHTBOX_URL . 'assets/images/notices/' . $args['image']); ?>" width="60" height="60" alt="<?php esc_attr_e('MetaSlider Gallery', 'ml-slider-lightbox'); ?>" />
        </div>
        <div class="updraft_advert_content_right">
            <div class="ml-discount-ad-title">
                <?php
                echo esc_html($args['title']);
                if (!empty($args['button_link'])) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    echo $this->get_button_link($args['button_link'], $args['button_meta']);
                }
                ?>
            </div>
            <div class="updraft-advert-dismiss">
                <a class="underline text-blue-dark ml-notice-dismiss" href="#" data-ad-identifier="<?php echo esc_attr($args['dismiss_time']); ?>">
                <?php
                    echo esc_html__('Dismiss', 'ml-slider-lightbox');
                    echo ('' !== $args['hide_time']) ? esc_html(sprintf(' (%s Weeks)', $args['hide_time'])) : '';
                ?>
                </a>
            </div>
        </div>
    </div>
    <div class="clear"></div>
</div>
