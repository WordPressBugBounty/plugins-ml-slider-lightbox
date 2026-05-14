/**
 * MetaSlider Lightbox Admin JavaScript
 *
 * Handles the color picker functionality in the admin settings
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        const { __, sprintf } = wp.i18n;

        if (typeof $.wp !== 'undefined' && typeof $.wp.wpColorPicker !== 'undefined') {
            $('.ml-color-picker').wpColorPicker({
                defaultColor: false,
                hide: true,
                change: function(event, ui) {
                }
            });
        }

        if ($('.ml-color-inline').length > 0) {
            setTimeout(initializeTooltips, 100);
        }

        $('input[type="range"]').on('input', function () {
            var $this = $(this);
            var value = $this.val();
            var $output = $this.next('output');
            if ($output.length) {
                $output.text(value);
            }
        });

        function initializeSelect2() {
            if (typeof $.fn.select2 !== 'undefined') {
                var isIncludeMode = $('input[name="metaslider_lightbox_content_options[content_processing_mode]"]:checked').val() === 'include';
                var action = isIncludeMode ? mlLightboxText.include : mlLightboxText.exclude;

                if ($('.ml-select2-pages').hasClass('select2-hidden-accessible')) {
                    $('.ml-select2-pages').select2('destroy');
                }
                if ($('.ml-select2-posts').hasClass('select2-hidden-accessible')) {
                    $('.ml-select2-posts').select2('destroy');
                }
                if ($('.ml-select2-post-types').hasClass('select2-hidden-accessible')) {
                    $('.ml-select2-post-types').select2('destroy');
                }

                $('.ml-select2-pages').select2({
                    placeholder: sprintf( mlLightboxText.select_pages_to, action ),
                    allowClear: true,
                    width: '100%',
                    theme: 'default'
                });

                $('.ml-select2-posts').select2({
                    placeholder: sprintf( mlLightboxText.select_posts_to, action ),
                    allowClear: true,
                    width: '100%',
                    theme: 'default'
                });

                $('.ml-select2-post-types').select2({
                    placeholder: sprintf( mlLightboxText.select_post_types_to, action ),
                    allowClear: true,
                    width: '100%',
                    theme: 'default'
                });

                $('.ml-select2-cpt').each(function() {
                    var $select = $(this);
                    var cptLabel = $select.data('cpt-label') || 'items';

                    if ($select.hasClass('select2-hidden-accessible')) {
                        $select.select2('destroy');
                    }

                    $select.select2({
                        placeholder: sprintf(
                            mlLightboxText.select_cpt_to,
                            cptLabel.toLowerCase(),
                            action
                        ),
                        allowClear: true,
                        width: '100%',
                        theme: 'default'
                    });
                });

                if ($('.ml-select2-manual-post-types').length > 0) {
                    if ($('.ml-select2-manual-post-types').hasClass('select2-hidden-accessible')) {
                        $('.ml-select2-manual-post-types').select2('destroy');
                    }

                    $('.ml-select2-manual-post-types').select2({
                        placeholder: mlLightboxText.select_post_types_to_exclude,
                        allowClear: true,
                        width: '100%',
                        theme: 'default'
                    });
                }
            }
        }

        initializeSelect2();

        function updateInclusionLabels() {
            var isIncludeMode = $('input[name="metaslider_lightbox_content_options[content_processing_mode]"]:checked').val() === 'include';
            var prefix = isIncludeMode ? mlLightboxText.include : mlLightboxText.exclude;

            var $pagesTh = $('th').filter(function() { return $(this).text().toLowerCase().indexOf('specific pages') !== -1; });
            if ($pagesTh.length) $pagesTh.text(prefix + ' specific Pages');

            var $postsTh = $('th').filter(function() { return $(this).text().toLowerCase().indexOf('specific posts') !== -1; });
            if ($postsTh.length) $postsTh.text(prefix + ' specific Posts');

            var $postTypesTh = $('th').filter(function() { return $(this).text().toLowerCase().indexOf('specific post types') !== -1; });
            if ($postTypesTh.length) $postTypesTh.text(prefix + ' specific post types');

            $('.ml-cpt-label').each(function() {
                var $label = $(this);
                var cptName = $label.data('cpt-name');
                var $select = $('.ml-select2-cpt-' + cptName);
                if ($select.length) {
                    var cptLabel = $select.data('cpt-label');
                    $label.text(prefix + ' specific ' + cptLabel);
                }
            });

            var $cssTh = $('th').filter(function() { return $(this).text().indexOf('by CSS selector') !== -1; });
            if ($cssTh.length) $cssTh.text('Exclude by CSS selector');

            $('.ml-mode-description').hide();
            $('#' + (isIncludeMode ? 'include' : 'exclude') + '-description').show();

            $('.ml-filter-description').each(function() {
                var $desc = $(this);
                var excludeText = $desc.data('exclude-text');
                var includeText = $desc.data('include-text');

                if (excludeText && includeText) {
                    $desc.text(isIncludeMode ? includeText : excludeText);
                }
            });

            initializeSelect2();
        }

        if ($('.ml-toggle-button').length > 0) {
            $('.ml-toggle-button').click(function() {
                var mode = $(this).data('mode');
                $(this).find('input[type=radio]').prop('checked', true);

                $('.ml-toggle-button').removeClass('active').css({
                    'background': '#ddd',
                    'color': '#646970'
                });

                $(this).addClass('active').css({
                    'background': '#dd6923',
                    'color': '#fff'
                });

                updateInclusionLabels();
            });

            $('.ml-toggle-button').hover(
                function() {
                    if (!$(this).hasClass('active')) {
                        $(this).css('background', '#c9c9c9');
                    } else {
                        $(this).css('background', '#c55a1e');
                    }
                },
                function() {
                    if (!$(this).hasClass('active')) {
                        $(this).css('background', '#ddd');
                    } else {
                        $(this).css('background', '#dd6923');
                    }
                }
            );

            updateInclusionLabels();
        }

        $(document).on('click', '.ml-toggle-switch', function() {
            var targetId = $(this).data('target');
            if (targetId) {
                document.getElementById(targetId).click();
            }
        });

        var $tooltip = null;

        function createTooltip() {
            if (!$tooltip) {
                $tooltip = $('<div class="ml-tooltip"></div>').appendTo('body');
            }
            return $tooltip;
        }

        function showTooltip($element) {
            var tooltipText = $element.attr('data-tooltip');
            if (!tooltipText) return;

            var $tip = createTooltip();
            $tip.text(tooltipText);

            var $button = $element.siblings('.wp-color-result');
            if ($button.length) {
                var offset = $button.offset();
                var buttonWidth = $button.outerWidth();
                var tipWidth = $tip.outerWidth();

                $tip.css({
                    top: offset.top - $tip.outerHeight() - 10,
                    left: offset.left + (buttonWidth / 2) - (tipWidth / 2)
                }).addClass('show');
            }
        }

        function hideTooltip() {
            if ($tooltip) {
                $tooltip.removeClass('show');
            }
        }

        function initializeTooltips() {
            $(document).on('mouseenter', '.ml-color-inline .wp-color-result', function() {
                var $input = $(this).siblings('.ml-has-tooltip');
                if ($input.length) {
                    showTooltip($input);
                }
            });

            $(document).on('mouseleave', '.ml-color-inline .wp-color-result', function() {
                hideTooltip();
            });

            $(document).on('click', '.ml-color-inline .wp-color-result', function() {
                hideTooltip();
            });
        }

        // Initialize Tipsy tooltips (following MetaSlider pattern)
        if (typeof $.fn.tipsy !== 'undefined') {
            $('.tipsy-tooltip-top').tipsy({live: false, delayIn: 500, html: true, gravity: 's'});
        }

        // Handle conditional visibility of icon setting based on button toggle
        var $buttonToggle = $('#ml_lightbox_options_show_lightbox_button');
        var $iconSetting = $('#ml-icon-instead-of-button-setting');

        if ($buttonToggle.length && $iconSetting.length) {
            // Function to toggle icon setting visibility
            function toggleIconSetting() {
                if ($buttonToggle.is(':checked')) {
                    $iconSetting.slideDown(300);
                } else {
                    $iconSetting.slideUp(300);
                }
            }

            // Listen for changes on the button toggle
            $buttonToggle.on('change', toggleIconSetting);

            // Set initial state on page load
            toggleIconSetting();
        }
    });

})(jQuery);