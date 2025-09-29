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

        // Initialize Select2 for exclusion fields
        if (typeof $.fn.select2 !== 'undefined') {
            $('.ml-select2-pages').select2({
                placeholder: 'Select pages to exclude...',
                allowClear: true,
                width: '100%',
                theme: 'default'
            });

            $('.ml-select2-posts').select2({
                placeholder: 'Select posts to exclude...',
                allowClear: true,
                width: '100%',
                theme: 'default'
            });

            $('.ml-select2-post-types').select2({
                placeholder: 'Select post types to exclude...',
                allowClear: true,
                width: '100%',
                theme: 'default'
            });
        }
    });

})(jQuery);