/**
 * MetaSlider Lightbox Admin JavaScript
 *
 * Handles the color picker functionality in the admin settings
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        // Initialize WordPress color picker
        if (typeof $.wp !== 'undefined' && typeof $.wp.wpColorPicker !== 'undefined') {
            $('.ml-color-picker').wpColorPicker({
                defaultColor: false,
                hide: true
            });
        }

        // Enhanced range slider for opacity
        $('input[type="range"]').on('input', function () {
            var $this = $(this);
            var value = $this.val();
            var $output = $this.next('output');
            if ($output.length) {
                $output.text(value);
            }
        });
    });

})(jQuery);