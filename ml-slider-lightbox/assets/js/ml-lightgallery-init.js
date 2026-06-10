/**
 * MetaSlider Lightbox - Clean Implementation
 * Simple class-based lightbox functionality
 */

(function($) {
    'use strict';

    const CONSTANTS = {
        SELECTORS: {
            WP_GALLERY: '.wp-block-gallery',
            ML_LIGHTBOX_ENABLED: '.ml-lightbox-enabled',
            ML_LIGHTBOX_BUTTON: '.ml-lightbox-button',
            LG_INITIALIZED: '.lg-initialized',
            CONTENT_CONTAINERS: '.entry-content, .post-content, .page-content, article, .content, main, [role="main"], .site-main, .elementor-widget-theme-post-content, .et_pb_section, .fusion-body'
        },
        CLASSES: {
            ML_LIGHTBOX_ENABLED: 'ml-lightbox-enabled',
            ML_LIGHTBOX_BUTTON: 'ml-lightbox-button',
            LG_INITIALIZED: 'lg-initialized'
        },
        VIDEO_HANDLERS: {
            youtube: {
                selector: 'div.youtube[data-id]',
                idAttr: 'data-id',
                urlTemplate: (id) => `https://www.youtube.com/watch?v=${id}`,
                thumbTemplate: (id) => `https://img.youtube.com/vi/${id}/maxresdefault.jpg`
            },
            vimeo: {
                selector: 'div.vimeo[data-id]',
                idAttr: 'data-id',
                urlTemplate: (id) => `https://vimeo.com/${id}`,
                thumbTemplate: (id) => `https://vumbnail.com/${id}.jpg`,
                fallbackSelectors: ['[data-slide-id]', 'a[href*="vimeo.com"]']
            },
            externalVideo: {
                selector: 'div.external-video',
                sourcesAttr: 'data-sources',
                posterAttr: 'data-poster'
            },
            localVideo: {
                selector: 'div.local-video',
                sourcesAttr: 'data-sources',
                posterAttr: 'data-poster'
            }
        }
    };

    // Set by initMetaSliderButtonMode so getButtonText() picks up the per-slider icon setting
    var _metasliderButtonSliderId = null;

    function escapeHtml(str) {
        return $('<div>').text(str).html();
    }

    function getButtonText(sliderId) {
        var id = sliderId !== undefined ? sliderId : _metasliderButtonSliderId;
        if (resolveSliderIcon(id)) {
            return '<svg class="ml-lightbox-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M21 9V3H15M21 3L13 11M10 5H7.8C6.11984 5 5.27976 5 4.63803 5.32698C4.07354 5.6146 3.6146 6.07354 3.32698 6.63803C3 7.27976 3 8.11984 3 9.8V16.2C3 17.8802 3 18.7202 3.32698 19.362C3.6146 19.9265 4.07354 20.3854 4.63803 20.673C5.27976 21 6.11984 21 7.8 21H14.2C15.8802 21 16.7202 21 17.362 20.673C17.9265 20.3854 18.3854 19.9265 18.673 19.362C19 18.7202 19 17.8802 19 16.2V14" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        }

        return (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.button_text)
               ? mlLightboxSettings.button_text
               : 'Open in Gallery';
    }

    /**
     * Add accessibility attributes to overlay elements
     * @param {jQuery} $overlay - The overlay element
     * @param {string} type - Type of content ('image' or 'video')
     * @param {string} altText - Alt text or description
     */
    function addOverlayAccessibility($overlay, type, altText) {
        altText = altText || type;
        $overlay.attr({
            'role': 'button',
            'aria-label': 'View ' + type + (altText ? ' - ' + altText : ''),
            'tabindex': '0'
        });

        // Add keyboard support (Enter and Space keys)
        $overlay.on('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                $(this).click();
            }
        });
    }

    /**
     * Add accessible name (aria-label) to a link based on image alt text
     * @param {jQuery} $link - The link element
     * @param {jQuery} $img - The image element inside the link
     */
    function addAccessibleNameToLink($link, $img) {
        if (!$link || !$img || $img.length === 0) return;

        var altText = $img.attr('alt') || '';
        var ariaLabel = 'View image' + (altText ? ': ' + altText : '');

        // Only add aria-label if link doesn't already have accessible text
        if (!$link.attr('aria-label') && !$link.text().trim()) {
            $link.attr('aria-label', ariaLabel);
        }
    }

    function isInsideMetaSliderContainer($element) {
        var $container = $element.closest('.metaslider, [id*="metaslider"], .filmstrip, [id*="filmstrip"]');
        if ($container.length > 0) {
            if ($container.is('body') || $container.is('html')) {
                return false;
            }
        }
        return $container.length > 0;
    }

    $(document).ready(function() {
        // Create screen reader live region for announcements
        if ($('#ml-lightbox-sr-live').length === 0) {
            $('<div id="ml-lightbox-sr-live" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></div>')
                .appendTo('body');
        }

        initLightboxes();
        removeConflictingAttributes();
        ensureAccessibleNames();

        // Add lightGallery event listeners for screen reader announcements
        $(document).on('lgAfterOpen.lg', function(event) {
            var $lgContainer = $(event.target).find('.lg-container');

            // Add accessible name to dialog
            if ($lgContainer.length > 0) {
                var totalItems = $(event.target).find('[data-lg-item-id]').length;
                var ariaLabel = totalItems > 1
                    ? 'Image gallery with ' + totalItems + ' images'
                    : 'Image gallery';
                $lgContainer.attr('aria-label', ariaLabel);
            }

            announceToScreenReader('Gallery opened');
        });

        $(document).on('lgAfterSlide.lg', function(event, prevIndex, index) {
            var $container = $(event.target);
            var $items = $container.find('[data-lg-item-id="' + index + '"]');
            if ($items.length > 0) {
                var $item = $items.first();
                var alt = $item.find('img').attr('alt') || '';
                var caption = $item.attr('data-sub-html') || '';
                var totalItems = $container.find('[data-lg-item-id]').length;

                var announcement = 'Image ' + (index + 1) + ' of ' + totalItems;
                if (alt) announcement += ', ' + alt;
                if (caption) announcement += ', ' + $('<div>').html(caption).text();

                announceToScreenReader(announcement);
            }
        });

        $(document).on('lgBeforeClose.lg', function() {
            announceToScreenReader('Gallery closed');
        });
    });

    /**
     * Announce message to screen readers via live region
     * @param {string} message - The message to announce
     */
    function announceToScreenReader(message) {
        var $liveRegion = $('#ml-lightbox-sr-live');
        if ($liveRegion.length > 0) {
            $liveRegion.text(message);
            // Clear after a delay to allow for new announcements
            setTimeout(function() {
                $liveRegion.text('');
            }, 1000);
        }
    }

    /**
     * Remove conflicting attributes when lightbox button is present
     * Prevents container clicks when buttons are available
     */
    function removeConflictingAttributes() {
        $('.ml-lightbox-enabled').each(function() {
            var $container = $(this);

            if ($container.find('.ml-lightbox-button').length > 0) {
                $container.removeAttr('href');
                $container.removeAttr('data-src');
                $container.removeAttr('data-thumb');
            }
        });
    }

    /**
     * Ensure all lightbox-enabled links have accessible names
     * Adds aria-label to links without accessible text
     */
    function ensureAccessibleNames() {
        // Add aria-labels to all ml-lightbox-enabled links that wrap images
        $('.ml-lightbox-enabled').each(function() {
            var $link = $(this);

            // Skip if link already has accessible text or aria-label
            if ($link.attr('aria-label') || $link.text().trim()) {
                return;
            }

            // Find image inside the link
            var $img = $link.find('img').first();
            if ($img.length > 0) {
                // Ensure image has alt attribute
                ensureImageAltAttribute($img);
                addAccessibleNameToLink($link, $img);
            }
        });

        // Also handle any links with data-src that might be lightbox links
        $('a[data-src]').each(function() {
            var $link = $(this);

            // Skip if already processed or has accessible text
            if ($link.attr('aria-label') || $link.text().trim()) {
                return;
            }

            var $img = $link.find('img').first();
            if ($img.length > 0) {
                // Ensure image has alt attribute
                ensureImageAltAttribute($img);
                addAccessibleNameToLink($link, $img);
            }
        });
    }

    /**
     * Ensure an image has an alt attribute
     * @param {jQuery} $img - The image element
     */
    function ensureImageAltAttribute($img) {
        if (typeof $img.attr('alt') === 'undefined') {
            // Try to infer alt text from image filename
            var src = $img.attr('src') || '';
            var filename = src.split('/').pop().split('?')[0];
            var altText = '';

            if (filename) {
                // Remove extension and convert dashes/underscores to spaces
                altText = filename
                    .replace(/\.(jpg|jpeg|png|gif|webp|svg)$/i, '')
                    .replace(/[-_]/g, ' ')
                    .trim();
            }

            // Set alt attribute (empty if we couldn't infer)
            $img.attr('alt', altText);
        }
    }

    /**
     * Main initialization function
     */
    function initLightboxes() {

        if (typeof lightGallery === 'undefined') {
            console.warn('MetaSlider Lightbox: LightGallery not loaded');
            return;
        }

        if (typeof mlLightboxSettings === 'undefined') {
            console.warn('MetaSlider Lightbox: Settings not available');
            return;
        }

        initLightboxSystems();

    }

    function initLightboxSystems() {
        initMetaSlider();

        var manualExcluded = mlLightboxSettings.manual_excluded === true || mlLightboxSettings.manual_excluded === '1' || mlLightboxSettings.manual_excluded === 1;

        if (!manualExcluded) {
            var $elements = $('.ml-lightbox-enabled');
            var useButtonsForManual = false;
            if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                useButtonsForManual = mlLightboxSettings.metaslider_options.show_lightbox_button !== false;
            }

            $elements.each(function() {
                var $element = $(this);
                if (isInsideMetaSliderContainer($element)) {
                    return;
                }
                initLightboxEnabled($element, useButtonsForManual);
            });
        }

        var overrideEnabled = typeof mlLightboxSettings !== 'undefined' && (mlLightboxSettings.override_enlarge_on_click === true || mlLightboxSettings.override_enlarge_on_click === '1' || mlLightboxSettings.override_enlarge_on_click === 1);
        if (overrideEnabled && !manualExcluded) {
            var $wordpressOverrideGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                var $wpImagesOld = $gallery.find('.wp-lightbox-container img[data-wp-on], .wp-lightbox-container img[data-wp-interactive]');
                var $wpContainersNew = $gallery.find('.wp-lightbox-container[data-wp-interactive="core/image"]');
                return ($wpImagesOld.length > 1 || $wpContainersNew.length > 1) &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized');
            });

            $wordpressOverrideGalleries.each(function() {
                initWordPressGallery($(this), useButtonsForManual);
            });
        }

        var pageExcluded = mlLightboxSettings.page_excluded === true || mlLightboxSettings.page_excluded === '1' || mlLightboxSettings.page_excluded === 1;
        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.enable_galleries && !pageExcluded) {
            var $wordpressGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);

                if ($gallery.attr('data-ml-exclude-gallery') === 'true') {
                    return false;
                }
                var $wpImagesOld = $gallery.find('.wp-lightbox-container img[data-wp-on], .wp-lightbox-container img[data-wp-interactive]');
                var $wpContainersNew = $gallery.find('.wp-lightbox-container[data-wp-interactive="core/image"]');
                var overrideEnabled = mlLightboxSettings.override_enlarge_on_click === true || mlLightboxSettings.override_enlarge_on_click === '1' || mlLightboxSettings.override_enlarge_on_click === 1;
                if (!overrideEnabled && ($wpImagesOld.length > 0 || $wpContainersNew.length > 0)) {
                    return false;
                }
                return ($wpImagesOld.length > 1 || $wpContainersNew.length > 1) &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized');
            });

            $wordpressGalleries.each(function() {
                initWordPressGallery($(this), useButtonsForManual);
            });

            var $classicGalleries = $('.gallery').filter(function() {
                var $gallery = $(this);

                if ($gallery.attr('data-ml-exclude-gallery') === 'true') {
                    return false;
                }

                var $galleryLinks = $gallery.find('a.ml-gallery-lightbox');

                return $galleryLinks.length > 1 &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized');
            });

            $classicGalleries.each(function() {
                initClassicGallery($(this));
            });
        }

        if (!manualExcluded) {
            var $wordpressContainersOld = $('.wp-lightbox-container').has('button[data-wp-on], button.lightbox-trigger');
            var $wordpressContainersNew = $('.wp-lightbox-container[data-wp-interactive="core/image"]');
            var $allWordpressContainers = $wordpressContainersOld.add($wordpressContainersNew);

            var $unprocessedContainers = $allWordpressContainers.filter(function() {
                var $figure = $(this);
                var $parentGallery = $figure.closest('.wp-block-gallery');

                var overrideEnabled = typeof mlLightboxSettings !== 'undefined' && (mlLightboxSettings.override_enlarge_on_click === true || mlLightboxSettings.override_enlarge_on_click === '1' || mlLightboxSettings.override_enlarge_on_click === 1);
                if (!overrideEnabled) {
                    return false;
                }

            if ($parentGallery.length > 0) {
                var $galleryContainers = $parentGallery.find('.wp-lightbox-container[data-wp-interactive="core/image"], .wp-lightbox-container').has('button[data-wp-on], button.lightbox-trigger');
                if ($galleryContainers.length > 1) {
                    return false;
                }
            }

            return !$figure.hasClass('ml-lightbox-enabled') &&
                   !$figure.find('.ml-lightbox-enabled').length &&
                   !$figure.hasClass('lg-initialized') &&
                   ($parentGallery.length === 0 || !$parentGallery.hasClass('lg-initialized'));
        });

            $unprocessedContainers.each(function() {
                var $container = $(this);
                var $img = $container.find('img').first();

                if ($img.length) {
                    if (useButtonsForManual) {
                        initWordPressEnlargeOnClickWithButton($img);
                    } else {
                        initWordPressEnlargeOnClick($img);
                    }
                }
            });
        }

        var overrideEnabled = typeof mlLightboxSettings !== 'undefined' &&
            (mlLightboxSettings.override_enlarge_on_click === true ||
             mlLightboxSettings.override_enlarge_on_click === '1' ||
             mlLightboxSettings.override_enlarge_on_click === 1);

        if (overrideEnabled && !manualExcluded) {
            var $enlargeOnClickGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                var $wpButtons = $gallery.find('button[data-wp-on], button.lightbox-trigger');

                return $wpButtons.length > 1 &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized');
            });

            $enlargeOnClickGalleries.each(function() {
                initEnlargeOnClickGallery($(this), useButtonsForManual);
            });
        }

        var linkOverrideEnabled = typeof mlLightboxSettings !== 'undefined' &&
            (mlLightboxSettings.override_link_to_image_file === true ||
             mlLightboxSettings.override_link_to_image_file === '1' ||
             mlLightboxSettings.override_link_to_image_file === 1);

        if (linkOverrideEnabled && !manualExcluded) {
            var $linkToMediaManualGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                var $mediaLinks = $gallery.find('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]').filter(function() {
                    return $(this).find('img').length > 0;
                });

                return $mediaLinks.length > 1 &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized') &&
                       $gallery.find('.wp-lightbox-container').length === 0 &&
                       $gallery.find('button[data-wp-on], button.lightbox-trigger').length === 0;
            });

            $linkToMediaManualGalleries.each(function() {
                initLinkToMediaManualGallery($(this), useButtonsForManual);
            });
        }

        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.enable_galleries && !pageExcluded) {
            var $linkToMediaGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);

                if ($gallery.attr('data-ml-exclude-gallery') === 'true') {
                    return false;
                }

                var $mediaLinks = $gallery.find('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]').filter(function() {
                    return $(this).find('img').length > 0;
                });

                return $mediaLinks.length > 1 &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized') &&
                       $gallery.find('.wp-lightbox-container').length === 0;
            });

            $linkToMediaGalleries.each(function() {
                initLinkToMediaGallery($(this), useButtonsForManual);
            });
        }

        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.enable_galleries && !pageExcluded) {
            var $plainImageGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);

                if ($gallery.attr('data-ml-exclude-gallery') === 'true') {
                    return false;
                }

                var $images = $gallery.find('img');
                var $mediaLinks = $gallery.find('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]');
                var $wpLightboxImages = $gallery.find('img[data-wp-on], img[data-wp-interactive]');

                return $images.length > 1 &&
                       $mediaLinks.length === 0 &&
                       $wpLightboxImages.length === 0 &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized');
            });

            $plainImageGalleries.each(function() {
                initPlainImageGallery($(this), useButtonsForManual);
            });
        }

        if (mlLightboxSettings.override_link_to_image_file && !manualExcluded) {
            var $mediaLinks = $('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]').filter(function() {
                var $link = $(this);
                var $parentGallery = $link.closest('.wp-block-gallery');
                var $parentContainer = $link.closest('.wp-lightbox-container');
                var $wooGallery = $link.closest('.woocommerce-product-gallery');
                var $mlGallery = $link.closest('.ml-gallery-container');
                return $link.find('img').length > 0 &&
                       $parentGallery.length === 0 &&
                       $parentContainer.length === 0 &&
                       $wooGallery.length === 0 &&
                       $mlGallery.length === 0 &&
                       !$link.hasClass('lg-initialized') &&
                       !$link.closest('.ml-lightbox-enabled').length;
            });

            $mediaLinks.each(function() {
                if (useButtonsForManual) {
                    initLinkToMediaFileWithButton($(this));
                } else {
                    initLinkToMediaFile($(this));
                }
            });
        }

        if (mlLightboxSettings.enable_on_content && !pageExcluded) {
            var contentImageSelectors = [];
            var containers = CONSTANTS.SELECTORS.CONTENT_CONTAINERS.split(', ');
            containers.forEach(function(container) {
                contentImageSelectors.push(container + ' img[src$=".jpg"]');
                contentImageSelectors.push(container + ' img[src$=".jpeg"]');
                contentImageSelectors.push(container + ' img[src$=".png"]');
                contentImageSelectors.push(container + ' img[src$=".gif"]');
                contentImageSelectors.push(container + ' img[src$=".webp"]');
                contentImageSelectors.push(container + ' img[src$=".svg"]');
            });

            var $standaloneImages = $(contentImageSelectors.join(', ')).filter(function() {
                var $img = $(this);
                if ($img.closest('.wp-block-gallery').length > 0) {
                    return false;
                }
                if ($img.closest('.woocommerce-product-gallery').length > 0) {
                    return false;
                }
                if (isInsideMetaSliderContainer($img)) {
                    return false;
                }
                if ($img.closest('.flex-control-nav, .flex-control-thumbs, .filmstrip').length > 0) {
                    return false;
                }
                if ($img.hasClass('ml-lightbox-enabled') ||
                    $img.closest('.ml-lightbox-wrapper').length > 0 ||
                    $img.closest('.ml-image-overlay').length > 0 ||
                    $img.siblings('.ml-lightbox-button').length > 0 ||
                    $img.parent().hasClass('lg-initialized')) {
                    return false;
                }
                if ($img.parent('a').length > 0) {
                    return false;
                }
                if ($img.hasClass('wp-post-image')) {
                    return false;
                }
                if ($img.attr('data-wp-on') || $img.attr('data-wp-interactive')) {
                    return false;
                }
                if ($img.attr('data-ml-exclude') === 'true') {
                    return false;
                }

                var uiClasses = ['custom-logo', 'site-logo', 'logo', 'avatar', 'icon', 'emoji', 'thumbnail', 'thumb'];
                for (var i = 0; i < uiClasses.length; i++) {
                    if ($img.hasClass(uiClasses[i])) {
                        return false;
                    }
                }

                var uiContainers = ['.wp-block-site-logo', '.site-logo', '.site-branding', '.site-header', '.site-footer', '.navigation', '.nav', '.menu', '.widget', '.sidebar'];
                for (var j = 0; j < uiContainers.length; j++) {
                    if ($img.closest(uiContainers[j]).length > 0) {
                        return false;
                    }
                }

                var minWidth = mlLightboxSettings.minimum_image_width || 200;
                var minHeight = mlLightboxSettings.minimum_image_height || 200;

                // Check displayed dimensions (not actual file size) to match server-side behavior
                var imgWidth = $img.width() || $img.attr('width') || 0;
                var imgHeight = $img.height() || $img.attr('height') || 0;

                if (minWidth > 0 && imgWidth > 0 && imgWidth < minWidth) {
                    return false;
                }
                if (minHeight > 0 && imgHeight > 0 && imgHeight < minHeight) {
                    return false;
                }

                return true;
            });

            $standaloneImages.each(function() {
                if (useButtonsForManual) {
                    handleSingleElementWithButton($(this));
                } else {
                    handleSingleElement($(this));
                }
            });
        }

        if (mlLightboxSettings.enable_featured_images && !pageExcluded) {
            $('a.ml-featured-lightbox[data-src]').filter(function() {
                return !$(this).hasClass('lg-initialized');
            }).each(function() {
                if (useButtonsForManual) {
                    initFeaturedImageWithButton($(this));
                } else {
                    initFeaturedImage($(this));
                }
            });
        }

        // Initialize [ml_gallery] shortcode containers.
        // Uses getLightboxSettings(true) so gallery-specific options (counter,
        // thumbnails strip, etc.) apply. Respects useButtonsForManual for
        // consistent button/no-button behaviour across the plugin.
        // Note: pageExcluded does NOT gate gallery init — galleries are explicitly
        // placed by the user and are unaffected by Automatic Mode content filtering.
        {
            /**
             * Apply all per-gallery data-lg-* settings onto a settings object.
             * Used by both regular and carousel init paths.
             */
            function applyGallerySettings(settings, $container) {
                var lgClass = $container.data('lg-class') || '';
                if (lgClass) { settings.addClass = lgClass; }

                settings.mode     = $container.data('lg-mode') || settings.mode;
                settings.controls = !!parseInt($container.data('lg-controls'), 10);
                settings.counter  = !!parseInt($container.data('lg-counter'), 10);
                settings.download = !!parseInt($container.data('lg-download'), 10);
                settings.loop     = !!parseInt($container.data('lg-loop'), 10);

                if (!parseInt($container.data('lg-captions'), 10)) {
                    settings.subHtml = '';
                    settings.getCaptionFromTitleOrAlt = false;
                }

                // Thumbnails
                var wantThumbs = !!parseInt($container.data('lg-thumbnails'), 10);
                if (typeof lgThumbnail !== 'undefined') {
                    if (wantThumbs) {
                        if (settings.plugins.indexOf(lgThumbnail) === -1) {
                            settings.plugins.push(lgThumbnail);
                        }
                    } else {
                        settings.plugins = settings.plugins.filter(function(p) { return p !== lgThumbnail; });
                    }
                }
                if (wantThumbs) {
                    settings.thumbWidth   = 100;
                    settings.thumbHeight  = 80;
                    settings.thumbMargin  = 4;
                    settings.exThumbImage = 'data-thumb';
                }

                // Plugin toggles
                if (!!parseInt($container.data('lg-zoom'), 10) && typeof lgZoom !== 'undefined') {
                    if (settings.plugins.indexOf(lgZoom) === -1) { settings.plugins.push(lgZoom); }
                } else if (typeof lgZoom !== 'undefined') {
                    settings.plugins = settings.plugins.filter(function(p) { return p !== lgZoom; });
                }

                if (!!parseInt($container.data('lg-fullscreen'), 10) && typeof lgFullscreen !== 'undefined') {
                    if (settings.plugins.indexOf(lgFullscreen) === -1) { settings.plugins.push(lgFullscreen); }
                }

                if (!!parseInt($container.data('lg-rotate'), 10) && typeof lgRotate !== 'undefined') {
                    if (settings.plugins.indexOf(lgRotate) === -1) { settings.plugins.push(lgRotate); }
                }

                if (!!parseInt($container.data('lg-share'), 10) && typeof lgShare !== 'undefined') {
                    if (settings.plugins.indexOf(lgShare) === -1) { settings.plugins.push(lgShare); }
                }

                if (!!parseInt($container.data('lg-autoplay'), 10) && typeof lgAutoplay !== 'undefined') {
                    if (settings.plugins.indexOf(lgAutoplay) === -1) { settings.plugins.push(lgAutoplay); }
                    settings.autoplay           = true;
                    settings.progressBar        = true;
                    settings.autoplayFirstVideo = false;
                    settings.autoplayInterval   = parseInt($container.data('lg-autoplay-interval'), 10) || 3000;
                }

                if (!!parseInt($container.data('lg-pager'), 10) && typeof lgPager !== 'undefined') {
                    if (settings.plugins.indexOf(lgPager) === -1) { settings.plugins.push(lgPager); }
                }

                if (!!parseInt($container.data('lg-hash'), 10) && typeof lgHash !== 'undefined') {
                    if (settings.plugins.indexOf(lgHash) === -1) { settings.plugins.push(lgHash); }
                }

                // Built-in behaviour
                settings.swipeToClose = !!parseInt($container.data('lg-swipe-close'), 10);
                settings.mousewheel   = !!parseInt($container.data('lg-mousewheel'), 10);
                settings.keyPress     = !!parseInt($container.data('lg-keyboard'), 10);

                return settings;
            }

            // Map of layout name → lightGallery plugins for inline (container) mode.
            // Add future inline layouts here — one line per layout.
            var inlineLayoutPlugins = {
                carousel: []
            };

            $('.ml-gallery-container[data-ml-gallery]').filter(function() {
                return !$(this).hasClass('lg-initialized') && $(this).data('ml-lightbox') !== 0;
            }).each(function() {
                var $container = $(this);

                // ── Inline layouts (lightGallery container mode) ──────────── //
                var mlLayout = $container.data('ml-layout');

                if ( inlineLayoutPlugins.hasOwnProperty( mlLayout ) ) {
                    var dynamicEl = [];
                    $container.find('a[data-src]').each(function () {
                        var $a = $(this);
                        var el = { src: $a.attr('data-src') };
                        if ($a.attr('data-thumb'))    { el.thumb   = $a.attr('data-thumb'); }
                        if ($a.attr('data-sub-html')) { el.subHtml = $a.attr('data-sub-html'); }
                        dynamicEl.push(el);
                    });

                    if (dynamicEl.length) {
                        var inlineSettings = applyGallerySettings({
                            container       : $container[0],
                            dynamic         : true,
                            dynamicEl       : dynamicEl,
                            plugins         : inlineLayoutPlugins[ mlLayout ],
                            hash            : false,
                            closable        : false,
                            showMaximizeIcon: true,
                            appendSubHtmlTo : '.lg-item',
                            slideDelay      : 400,
                        }, $container);

                        try {
                            var inlineGallery = lightGallery($container[0], inlineSettings);
                            inlineGallery.openGallery();
                            $container.addClass('lg-initialized');
                        } catch (error) {
                            console.error('MetaSlider Lightbox: inline gallery init error:', error);
                        }
                    }
                    return; // skip regular lightbox init for inline layouts
                }

                var settings = getLightboxSettings(true);
                applyGallerySettings(settings, $container);

                if (useButtonsForManual) {
                    $container.find('a[data-src]').each(function() {
                        var $a      = $(this);
                        var dataSrc = $a.attr('data-src');
                        var $img    = $a.find('img').first();
                        var imgSrc  = $img.attr('src') || dataSrc;
                        var alt     = $img.attr('alt') || '';

                        var $btn = $('<a class="ml-lightbox-button ml-button-wordpress ml-button-wordpress-gallery" href="#">' + getButtonText() + '</a>');
                        $btn.attr({
                            'data-src'  : dataSrc,
                            'data-thumb': $a.attr('data-thumb') || imgSrc,
                            'aria-label': mlLightboxSettings.view_image_label + (alt ? ': ' + alt : ''),
                        });
                        $a.css('position', 'relative').append($btn);

                        // Button mode: only the button should open the lightbox.
                        // Strip the wrapper link's own attributes so clicking the
                        // image itself doesn't navigate to the image URL.
                        $a.removeAttr('href').removeAttr('data-src').removeAttr('data-thumb');
                    });
                    settings.selector = '.ml-lightbox-button';
                } else {
                    settings.selector = 'a[data-src]';
                }

                try {
                    $container[0]._mlLgInstance = lightGallery($container[0], settings);
                    $container.addClass('lg-initialized');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing gallery shortcode:', error);
                }
            });
        }
    }

    function initLightboxEnabled($container, useButtonMode) {
        useButtonMode = useButtonMode || false;

        if ($container.hasClass('lg-initialized') || $container.data('lightgallery')) {
            return;
        }
        var $imageLinks = $container.find('a[data-src], a[data-video]');

        if ($imageLinks.length === 0) {
            var $images = $container.find('img');

            if ($images.length > 1) {
                $images.each(function() {
                    var $img = $(this);
                    var $figure = $img.closest('figure, div');
                    if ($figure.length === 0) $figure = $img.parent();

                    if ($figure.find('.ml-image-overlay, .ml-lightbox-button').length > 0 || $figure.hasClass('lg-initialized')) {
                        return;
                    }

                    var src = $img.attr('src');
                    if (!src) return;

                    var fullUrl = removeWordPressSizeSuffix(src);

                    if (useButtonMode) {
                        var $button = $('<a href="#" class="ml-lightbox-button ml-button-manual ml-button-manual-gallery">' + getButtonText() + '</a>');
                        $button.attr({
                            'data-src': fullUrl,
                            'data-thumb': src
                        });
                        $figure.css('position', 'relative').append($button);
                    } else {
                        var $overlay = $('<a href="#" class="ml-image-overlay"></a>');
                        $overlay.attr({
                            'data-src': fullUrl,
                            'data-thumb': src
                        });

                        // Add accessibility attributes
                        var altText = $img.attr('alt') || '';
                        addOverlayAccessibility($overlay, 'image', altText);

                        $figure.css('position', 'relative').prepend($overlay);
                    }
                });

                var gallerySettings = getLightboxSettings(true);
                gallerySettings.selector = useButtonMode ? '.ml-lightbox-button' : '.ml-image-overlay';

                try {
                    var instance = lightGallery($container[0], gallerySettings);
                    $container.addClass('lg-initialized');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing manual gallery:', error);
                }
                return;
            }

            if (useButtonMode) {
                handleSingleElementWithButton($container);
            } else {
                handleSingleElement($container);
            }
            return;
        }

        var isGallery = $imageLinks.length > 1;


        var settings = getLightboxSettings(isGallery);

        try {
            var instance = lightGallery($container[0], settings);
            $container.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing LightGallery:', error);
        }
    }

    /**
     * Handle single elements that don't have PHP-processed data-src links
     */
    function handleSingleElement($element) {
        var $target = null;
        var dataSrc = '';
        var dataThumb = '';
        var isVideo = false;

        if ($element.is('img')) {
            var src = $element.attr('src');
            if (!src) return;

            var $wrapper = $('<div class="ml-lightbox-wrapper"></div>');
            $element.wrap($wrapper);
            $target = $element.parent();

            var fullUrl = removeWordPressSizeSuffix(src);

            $target.attr({
                'data-src': fullUrl,
                'data-thumb': src
            });

            var caption = extractCaption($element);
            if (caption) {
                $target.attr('data-sub-html', caption);
            }


        } else if ($element.is('a')) {
            var href = $element.attr('href');
            if (!href) return;

            $target = $element;
            var $img = $element.find('img').first();
            var imgSrc = $img.length ? $img.attr('src') : href;

            isVideo = isVideoUrl(href);

            if (isVideo) {
                var videoUrl = getVideoUrl(href);
                $target.attr({
                    'data-src': videoUrl,
                    'data-thumb': imgSrc || getVideoThumbnail(href)
                });
            } else {
                $target.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });
            }

            var caption = extractCaption($element);
            if (caption) {
                $target.attr('data-sub-html', caption);
            }

        } else {

            var $iframe = $element.find('iframe[src*="youtube"], iframe[src*="vimeo"]').first();
            var $video = $element.find('video').first();

            if ($iframe.length > 0) {
                var iframeSrc = $iframe.attr('src');

                var $overlay = $('<a href="#" class="ml-video-overlay"></a>');

                var videoUrl = getVideoUrl(iframeSrc);
                $overlay.attr({
                    'data-src': videoUrl,
                    'data-thumb': getVideoThumbnail(iframeSrc)
                });

                var caption = extractCaption($element);
                if (caption) {
                    $overlay.attr('data-sub-html', caption);
                }

                $element.css('position', 'relative').prepend($overlay);
                $target = $element;

                isVideo = true;

            } else if ($video.length > 0) {
                var videoSrc = $video.attr('src') || $video.find('source').first().attr('src');
                if (!videoSrc) return;

                var videoData = {
                    source: [{ src: videoSrc, type: getVideoType(videoSrc) }],
                    attributes: { preload: false, controls: true }
                };

                var $overlay = $('<a href="#" class="ml-video-overlay"></a>');

                $overlay.attr({
                    'data-src': '',
                    'data-video': JSON.stringify(videoData),
                    'data-thumb': $video.attr('poster') || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAwIiBoZWlnaHQ9IjMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxOCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPjQwMHgzMDA8L3RleHQ+PC9zdmc+'
                });

                var caption = extractCaption($element);
                if (caption) {
                    $overlay.attr('data-sub-html', caption);
                }

                $element.css('position', 'relative').prepend($overlay);
                $target = $element;

                isVideo = true;

            } else {
                var $img = $element.find('img').first();
                if (!$img.length) return;

                var src = $img.attr('src');
                if (!src) return;

                var fullUrl = removeWordPressSizeSuffix(src);

                if ($element.hasClass('wp-lightbox-container')) {
                    var $overlay = $('<a href="#" class="ml-image-overlay"></a>');

                    $overlay.attr({
                        'data-src': fullUrl,
                        'data-thumb': src
                    });

                    var caption = extractCaption($element);
                    if (caption) {
                        $overlay.attr('data-sub-html', caption);
                    }

                    // Add accessibility attributes
                    var altText = $img.attr('alt') || '';
                    addOverlayAccessibility($overlay, 'image', altText);

                    $element.css('position', 'relative').prepend($overlay);
                    $target = $element;

                } else {
                    $target = $element;
                    $target.attr({
                        'data-src': fullUrl,
                        'data-thumb': src
                    });

                    var caption = extractCaption($element);
                    if (caption) {
                        $target.attr('data-sub-html', caption);
                    }

                }
            }
        }

        if (!$target) return;

        var settings = getLightboxSettings(false);

        if (isVideo && $target.find('.ml-video-overlay').length > 0) {
            settings.selector = '.ml-video-overlay';
        } else if (!isVideo && $target.find('.ml-image-overlay').length > 0) {
            settings.selector = '.ml-image-overlay';
        } else {
            settings.selector = 'this';
        }

        try {
            var instance = lightGallery($target[0], settings);
            $target.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing single lightbox:', error);
        }
    }

    /**
     * Handle single elements that need button mode instead of overlay mode
     */
    function handleSingleElementWithButton($element) {
        if ($element.hasClass('lg-initialized') || $element.data('lightgallery')) {
            return;
        }

        var $button = null;

        if ($element.is('img')) {
            var src = $element.attr('src');
            if (!src) return;

            var fullUrl = removeWordPressSizeSuffix(src);

            $button = $('<a class="ml-lightbox-button ml-button-manual ml-button-manual-single-img" href="#">' + getButtonText() + '</a>');

            $button.attr({
                'data-src': fullUrl,
                'data-thumb': src
            });

            var caption = extractCaption($element);
            if (caption) {
                $button.attr('data-sub-html', caption);
            }

            $element.wrap('<div style="position: relative; display: inline-block;"></div>');
            $element.parent().append($button);

            var settings = getLightboxSettings(false);
            settings.selector = '.ml-lightbox-button';

            try {
                var instance = lightGallery($element.parent()[0], settings);
                $element.parent().addClass('lg-initialized');
            } catch (error) {
                console.error('MetaSlider Lightbox: Error initializing button mode for manual lightbox:', error);
            }

        } else if ($element.is('a')) {
            var href = $element.attr('href');
            if (!href) return;

            var $img = $element.find('img').first();
            var imgSrc = $img.length > 0 ? $img.attr('src') : '';

            var isVideo = isVideoUrl(href);

            if (isVideo) {
                var videoUrl = getVideoUrl(href);
                $button = $('<a class="ml-lightbox-button ml-button-manual ml-button-manual-single-video" href="#">' + getButtonText() + '</a>');

                $button.attr({
                    'data-src': videoUrl,
                    'data-thumb': imgSrc || getVideoThumbnail(href)
                });
            } else {
                $button = $('<a class="ml-lightbox-button ml-button-manual ml-button-manual-single-link" href="#">' + getButtonText() + '</a>');

                $button.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });
            }

            var caption = extractCaption($element);
            if (caption) {
                $button.attr('data-sub-html', caption);
            }

            $element.css('position', 'relative').append($button);

            var settings = getLightboxSettings(false);
            settings.selector = '.ml-lightbox-button';

            try {
                var instance = lightGallery($element[0], settings);
                $element.addClass('lg-initialized');
            } catch (error) {
                console.error('MetaSlider Lightbox: Error initializing button mode for manual lightbox:', error);
            }

        } else {

            var $existingButton = $element.find('.ml-lightbox-button').first();
            if ($existingButton.length > 0) {
                var settings = getLightboxSettings(false);
                settings.selector = '.ml-lightbox-button';

                try {
                    var instance = lightGallery($element[0], settings);
                    $element.addClass('lg-initialized');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing existing button:', error);
                }
                return;
            }

            var $img = $element.find('img').first();
            var $iframe = $element.find('iframe[src*="youtube"], iframe[src*="vimeo"]').first();
            var $video = $element.find('video').first();

            if ($img.length > 0) {
                var src = $img.attr('src');
                if (src) {
                    var fullUrl = removeWordPressSizeSuffix(src);

                    $button = $('<a class="ml-lightbox-button ml-button-manual ml-button-manual-container-img" href="#">' + getButtonText() + '</a>');

                    $button.attr({
                        'data-src': fullUrl,
                        'data-thumb': src
                    });
                }
            } else if ($iframe.length > 0) {
                var iframeSrc = $iframe.attr('src');
                if (iframeSrc) {
                    var videoUrl = getVideoUrl(iframeSrc);

                    $button = $('<a class="ml-lightbox-button ml-button-manual ml-button-manual-container-iframe" href="#">' + getButtonText() + '</a>');

                    $button.attr({
                        'data-src': videoUrl,
                        'data-thumb': getVideoThumbnail(iframeSrc)
                    });
                }
            } else if ($video.length > 0) {
                var videoSrc = $video.attr('src') || $video.find('source').first().attr('src');
                if (videoSrc) {
                    $button = $('<a class="ml-lightbox-button ml-button-manual ml-button-manual-container-video" href="#">' + getButtonText() + '</a>');

                    var videoData = {
                        source: [{
                            src: videoSrc,
                            type: getVideoType(videoSrc)
                        }],
                        attributes: {
                            preload: false,
                            controls: true
                        }
                    };

                    $button.attr({
                        'data-src': '',
                        'data-video': JSON.stringify(videoData),
                        'data-thumb': $video.attr('poster') || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAwIiBoZWlnaHQ9IjMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxOCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPjQwMHgzMDA8L3RleHQ+PC9zdmc+'
                    });
                }
            }

            if ($button) {
                var caption = extractCaption($element);
                if (caption) {
                    $button.attr('data-sub-html', caption);
                }

                $element.css('position', 'relative').append($button);

                var settings = getLightboxSettings(false);
                settings.selector = '.ml-lightbox-button';

                try {
                    var instance = lightGallery($element[0], settings);
                    $element.addClass('lg-initialized');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing button mode for manual lightbox:', error);
                }
            }
        }
    }

    /**
     * Get LightGallery settings based on admin configuration
     */
    function getLightboxSettings(isGallery) {
        var metasliderOptions = mlLightboxSettings.metaslider_options || {};

        var settings = {
            selector: 'a[data-src]',
            mode: 'lg-fade',
            speed: 350,
            download: false,
            counter: isGallery,
            closable: true,
            closeOnTap: true,
            controls: !!metasliderOptions.show_arrows,
            plugins: [],
            // Accessibility settings
            escapeKey: true,  // Allow Escape key to close lightbox
            keyPress: true,    // Enable keyboard navigation (arrows, Esc)
            mousewheel: true,  // Allow mousewheel navigation
            allowMediaOverlap: false,  // Prevent overlap for better screen reader support
            ariaLabelledby: '',  // Will be set dynamically per image
            ariaDescribedby: ''  // Will be set dynamically per image
        };

        if (typeof _mlLk !== 'undefined') {
            settings.licenseKey = _mlLk;
        }

        if (!shouldShowCaptions()) {
            settings.subHtml = '';
            settings.getCaptionFromTitleOrAlt = false;
        }

        if (isGallery && metasliderOptions.show_thumbnails && typeof lgThumbnail !== 'undefined') {
            settings.plugins.push(lgThumbnail);
            settings.thumbWidth = 100;
            settings.thumbHeight = 80;
            settings.thumbMargin = 4;
            settings.exThumbImage = 'data-thumb';
        }

        if (typeof lgVideo !== 'undefined') {
            settings.plugins.push(lgVideo);
            settings.videoMaxSize = '1280-720';
        }

        if (typeof window.MetaSliderLightboxPro !== 'undefined' &&
            typeof window.MetaSliderLightboxPro.enhanceSettings === 'function') {
            settings = window.MetaSliderLightboxPro.enhanceSettings(settings, null);
        }

        return settings;
    }

    /**
     * Remove WordPress image size suffix to get full-size image URL
     */
    function removeWordPressSizeSuffix(url) {
        if (!url) return '';
        return url.replace(/-\d+x\d+(\.[^.]+)$/, '$1');
    }

    /**
     * Check if URL is a video URL
     */
    function isVideoUrl(url) {
        if (!url) return false;

        return /(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/|vimeo\.com\/(?:video\/)?|\.(?:mp4|webm|ogg|mov|avi)(?:\?|$))/i.test(url);
    }

    /**
     * Get video URL in format that LightGallery expects
     * LightGallery automatically detects and handles YouTube/Vimeo URLs
     */
    function getVideoUrl(url) {
        if (!url) return '';

        var youtubeMatch = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
        if (youtubeMatch) {
            return 'https://www.youtube.com/watch?v=' + youtubeMatch[1];
        }

        var vimeoMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        if (vimeoMatch) {
            return 'https://vimeo.com/' + vimeoMatch[1];
        }

        return url;
    }

    /**
     * Get video thumbnail URL
     */
    function getVideoThumbnail(url) {
        if (!url) return '';

        var youtubeMatch = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
        if (youtubeMatch) {
            return 'https://img.youtube.com/vi/' + youtubeMatch[1] + '/maxresdefault.jpg';
        }

        var vimeoMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        if (vimeoMatch) {
            return 'https://vumbnail.com/' + vimeoMatch[1] + '.jpg';
        }

        return '';
    }

    /**
     * Initialize a WordPress "Enlarge on Click" gallery
     */
    function initWordPressGallery($gallery, useButtonMode) {
        useButtonMode = useButtonMode || false;

        var $wpImagesOld = $gallery.find('.wp-lightbox-container img[data-wp-on], .wp-lightbox-container img[data-wp-interactive]');
        var $wpContainersNew = $gallery.find('.wp-lightbox-container[data-wp-interactive="core/image"]');

        var $allContainers = $();

        $wpImagesOld.each(function() {
            $allContainers = $allContainers.add($(this).closest('.wp-lightbox-container'));
        });

        $allContainers = $allContainers.add($wpContainersNew);

        if ($allContainers.length <= 1) return;

        $allContainers.each(function() {
            var $figure = $(this);
            var $img = $figure.find('img').first();

            if ($figure.find('.ml-image-overlay, .ml-lightbox-button').length > 0 || $figure.hasClass('lg-initialized')) {
                return;
            }

            var src = $img.attr('src');
            if (!src) return;

            var fullUrl = removeWordPressSizeSuffix(src);

            if (useButtonMode) {
                var $button = $('<a href="#" class="ml-lightbox-button ml-button-manual ml-button-manual-gallery">' + getButtonText() + '</a>');
                $button.attr({
                    'data-src': fullUrl,
                    'data-thumb': src
                });
                $figure.css('position', 'relative').append($button);
            } else {
                var $overlay = $('<a href="#" class="ml-image-overlay"></a>');
                $overlay.attr({
                    'data-src': fullUrl,
                    'data-thumb': src
                });

                // Add accessibility attributes
                var altText = $img.attr('alt') || '';
                addOverlayAccessibility($overlay, 'image', altText);

                $figure.css('position', 'relative').prepend($overlay);
            }
        });

        var gallerySettings = getLightboxSettings(true);
        gallerySettings.selector = useButtonMode ? '.ml-lightbox-button' : '.ml-image-overlay';

        gallerySettings.galleryId = 'ml-gallery-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing WordPress gallery:', error);
        }
    }


    /**
     * Initialize a classic [gallery] shortcode gallery
     */
    function initClassicGallery($gallery) {
        var $galleryLinks = $gallery.find('a.ml-gallery-lightbox');

        if ($galleryLinks.length <= 1) return;

        $galleryLinks.each(function() {
            var $link = $(this);
            var $img = $link.find('img').first();

            if ($link.attr('data-src')) {
                return;
            }

            var href = $link.attr('href');
            var src = $img.attr('src');

            if (!href) return;

            $link.attr({
                'data-src': href,
                'data-thumb': src || href
            });

            var caption = escapeHtml($img.attr('alt') || $img.attr('title') || '');
            var $figcaption = $link.closest('.gallery-item').find('figcaption, .wp-caption-text');
            if ($figcaption.length > 0) {
                caption = escapeHtml($figcaption.text().trim());
            }

            if (caption) {
                $link.attr('data-sub-html', '<div class="lg-sub-html"><p>' + caption + '</p></div>');
            }
        });

        var gallerySettings = getLightboxSettings(true);
        gallerySettings.selector = 'a.ml-gallery-lightbox';

        gallerySettings.galleryId = 'ml-gallery-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing classic gallery:', error);
        }
    }

    /**
     * Initialize a single WordPress "Enlarge on Click" image with overlay approach
     */
    function initWordPressEnlargeOnClick($img) {
        var $figure = $img.closest('.wp-lightbox-container');

        var src = $img.attr('src');
        if (!src) return;

        var fullUrl = removeWordPressSizeSuffix(src);

        var $overlay = $('<a href="#" class="ml-image-overlay"></a>');

        $overlay.attr({
            'data-src': fullUrl,
            'data-thumb': src
        });

        var caption = extractCaption($img);
        if (caption) {
            $overlay.attr('data-sub-html', caption);
        }

        // Add accessibility attributes
        var altText = $img.attr('alt') || '';
        addOverlayAccessibility($overlay, 'image', altText);

        var $wpButton = $figure.find('button[data-wp-on], button.lightbox-trigger, button[data-wp-interactive]');
        $wpButton.remove();

        $figure.css('position', 'relative').prepend($overlay);

        var settings = getLightboxSettings(false);
        settings.selector = '.ml-image-overlay';

        try {
            var instance = lightGallery($figure[0], settings);
            $figure.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing Enlarge on Click overlay:', error);
        }
    }

    /**
     * Initialize a single WordPress "Enlarge on Click" image with button approach
     */
    function initWordPressEnlargeOnClickWithButton($img) {
        var $figure = $img.closest('.wp-lightbox-container');

        var src = $img.attr('src');
        if (!src) return;

        var fullUrl = removeWordPressSizeSuffix(src);

        var $button = $('<a class="ml-lightbox-button ml-button-wordpress ml-button-wordpress-enlarge-single" href="#">' + getButtonText() + '</a>');

        $button.attr({
            'data-src': fullUrl,
            'data-thumb': src
        });

        var caption = extractCaption($img);
        if (caption) {
            $button.attr('data-sub-html', caption);
        }

        var $wpButton = $figure.find('button[data-wp-on], button.lightbox-trigger, button[data-wp-interactive]');
        $wpButton.remove();

        $figure.css('position', 'relative').append($button);

        var settings = getLightboxSettings(false);
        settings.selector = '.ml-lightbox-button';

        try {
            var instance = lightGallery($figure[0], settings);
            $figure.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing Enlarge on Click button:', error);
        }
    }

    /**
     * Initialize a "Link to Media File" gallery
     */
    function initLinkToMediaGallery($gallery, useButtonMode) {
        var $mediaLinks = $gallery.find('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]').filter(function() {
            return $(this).find('img').length > 0;
        });

        if ($mediaLinks.length <= 1) return;

        if (useButtonMode) {
            $mediaLinks.each(function() {
                var $link = $(this);
                var href = $link.attr('href');
                var $img = $link.find('img').first();
                var imgSrc = $img.attr('src');

                if (!href || !imgSrc) return;

                var $button = $('<a class="ml-lightbox-button ml-button-wordpress ml-button-wordpress-link-to-media-single" href="#">' + getButtonText() + '</a>');
                $button.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });

                var caption = extractCaption($img);
                if (caption) {
                    $button.attr('data-sub-html', caption);
                }

                $link.parent().append($button);
            });

            var gallerySettings = getLightboxSettings(true);
            gallerySettings.selector = '.ml-lightbox-button';

        } else {
            $mediaLinks.each(function() {
                var $link = $(this);
                var href = $link.attr('href');
                var $img = $link.find('img').first();
                var imgSrc = $img.attr('src');

                if (!href || !imgSrc) return;

                $link.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });

                var caption = extractCaption($img);
                if (caption) {
                    $link.attr('data-sub-html', caption);
                }
            });

            var gallerySettings = getLightboxSettings(true);
            gallerySettings.selector = 'a[data-src]';
        }

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing "Link to Media File" gallery:', error);
        }
    }

    /**
     * Initialize a plain image gallery (no links, no WordPress lightbox)
     */
    function initPlainImageGallery($gallery, useButtonMode) {
        var $images = $gallery.find('img');

        if ($images.length <= 1) return;

        if (useButtonMode) {
            $images.each(function() {
                var $img = $(this);
                var imgSrc = $img.attr('src');

                if (!imgSrc) return;

                var fullUrl = removeWordPressSizeSuffix(imgSrc);

                var $figure = $img.closest('.wp-block-image');
                if ($figure.length === 0) {
                    $figure = $img.parent();
                }

                $figure.attr({
                    'data-src': fullUrl,
                    'data-thumb': imgSrc
                });

                var caption = extractCaption($img);
                if (caption) {
                    $figure.attr('data-sub-html', caption);
                }

                var $button = $('<a href="#" class="ml-lightbox-button ml-button-wordpress ml-button-wordpress-plain-gallery" data-src="' + fullUrl + '" data-thumb="' + imgSrc + '">' + getButtonText() + '</a>');
                if (caption) {
                    $button.attr('data-sub-html', caption);
                }

                $figure.css('position', 'relative').append($button);
            });

            var gallerySettings = getLightboxSettings(true);
            gallerySettings.selector = '.ml-lightbox-button';

        } else {
            $images.each(function() {
                var $img = $(this);
                var imgSrc = $img.attr('src');

                if (!imgSrc) return;

                var fullUrl = removeWordPressSizeSuffix(imgSrc);

                var $wrapper = $('<a href="#" data-src="' + fullUrl + '" data-thumb="' + imgSrc + '"></a>');

                var caption = extractCaption($img);
                if (caption) {
                    $wrapper.attr('data-sub-html', caption);
                }

                $img.wrap($wrapper);
            });

            var gallerySettings = getLightboxSettings(true);
            gallerySettings.selector = 'a[data-src]';
        }

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing plain image gallery:', error);
        }
    }

    /**
     * Initialize a single "Link to Media File" image
     */
    function initLinkToMediaFile($link) {
        var href = $link.attr('href');
        var $img = $link.find('img').first();
        var imgSrc = $img.attr('src');

        if (!href || !imgSrc) return;

        // Get alt text for accessible name
        var altText = $img.attr('alt') || '';
        var ariaLabel = 'View image' + (altText ? ': ' + altText : '');

        $link.attr({
            'data-src': href,
            'data-thumb': imgSrc,
            'aria-label': ariaLabel
        });

        var caption = extractCaption($img);
        if (caption) {
            $link.attr('data-sub-html', caption);
        }

        var settings = getLightboxSettings(false);
        settings.selector = 'this';

        try {
            var instance = lightGallery($link[0], settings);
            $link.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing "Link to Media File" image:', error);
        }
    }

    /**
     * Initialize a single "Link to Media File" image with button approach
     */
    function initLinkToMediaFileWithButton($link) {
        var href = $link.attr('href');
        var $img = $link.find('img').first();
        var imgSrc = $img.attr('src');

        if (!href || !imgSrc) return;

        // Get alt text for accessible name
        var altText = $img.attr('alt') || '';
        var ariaLabel = 'View image' + (altText ? ': ' + altText : '');

        var $button = $('<a class="ml-lightbox-button ml-button-wordpress ml-button-wordpress-link-to-media-single-img" href="#">' + getButtonText() + '</a>');

        $button.attr({
            'data-src': href,
            'data-thumb': imgSrc,
            'aria-label': ariaLabel
        });

        var caption = extractCaption($img);
        if (caption) {
            $button.attr('data-sub-html', caption);
        }

        // Add aria-label to link as well
        $link.attr('aria-label', ariaLabel);

        $link.css('position', 'relative').append($button);

        var settings = getLightboxSettings(false);
        settings.selector = '.ml-lightbox-button';

        try {
            var instance = lightGallery($link[0], settings);
            $link.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing "Link to Media File" button:', error);
        }
    }

    /**
     * Initialize a featured image lightbox where the outer <a> links to the post
     * and data-src holds the image URL for the lightbox
     */
    function initFeaturedImageWithButton($link) {
        var dataSrc = $link.attr('data-src');
        var $img = $link.find('img').first();
        var imgSrc = $img.attr('src');

        if (!dataSrc || !imgSrc) return;

        var altText = $img.attr('alt') || '';
        var ariaLabel = 'View image' + (altText ? ': ' + altText : '');

        var $button = $('<a class="ml-lightbox-button ml-button-wordpress ml-button-wordpress-link-to-media-single-img" href="#">' + getButtonText() + '</a>');
        $button.attr({
            'data-src': dataSrc,
            'data-thumb': imgSrc,
            'aria-label': ariaLabel
        });

        $link.attr('aria-label', ariaLabel).css('position', 'relative').append($button);

        var settings = getLightboxSettings(false);
        settings.selector = '.ml-lightbox-button';

        try {
            lightGallery($link[0], settings);
            $link.addClass('lg-initialized');
        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing featured image lightbox:', error);
        }
    }

    /**
     * Initialize a featured image lightbox without a button — clicking the link opens the lightbox directly
     */
    function initFeaturedImage($link) {
        var dataSrc = $link.attr('data-src');
        var $img = $link.find('img').first();
        var imgSrc = $img.attr('src');

        if (!dataSrc || !imgSrc) return;

        var altText = $img.attr('alt') || '';
        $link.attr('aria-label', 'View image' + (altText ? ': ' + altText : ''));

        var settings = getLightboxSettings(false);
        settings.selector = 'this';

        try {
            lightGallery($link[0], settings);
            $link.addClass('lg-initialized');
        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing featured image lightbox:', error);
        }
    }

    /**
     * Initialize MetaSlider lightbox functionality
     * Based on settings from ml-lightgallery-init.js but simplified for integration
     */
    function initMetaSlider() {
        var $sliders = $('.metaslider, [class*="ml-slider-lightbox-"]');

        if ($sliders.length === 0) {
            return;
        }


        $sliders.each(function() {
            var $slider = $(this);

            if ($slider.hasClass('lg-initialized')) {
                return;
            }

            var sliderId = $slider.attr('id');

            if (!sliderId) {
                var $firstSlide = $slider.find('li').first();
                var slideClass = $firstSlide.attr('class');
                if (slideClass) {
                    var classMatch = slideClass.match(/slider-(\d+)/);
                    if (classMatch) {
                        sliderId = classMatch[1];
                    }
                }

                if (!sliderId) {
                    var $firstImg = $firstSlide.find('img').first();
                    var imgClass = $firstImg.attr('class');
                    if (imgClass) {
                        var imgClassMatch = imgClass.match(/slider-(\d+)/);
                        if (imgClassMatch) {
                            sliderId = imgClassMatch[1];
                        }
                    }
                }
            }

            if (sliderId) {
                var matches = sliderId.match(/(\d+)/);
                if (matches) {
                    sliderId = matches[1];
                }
            }


            if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.slider_settings && sliderId) {
                var sliderSettings = mlLightboxSettings.slider_settings[sliderId];
                if (sliderSettings && sliderSettings.lightbox_enabled === false) {
                    return;
                }
            }

            var showButton = resolveSliderButton(sliderId);

            if (showButton) {
                initMetaSliderButtonMode($slider, sliderId);
            } else {
                initMetaSliderDirectMode($slider, sliderId);
            }
        });
    }

    /**
     * Read a per-slider boolean override (true/false) from slider_settings.
     * PHP sends true/false/null where null means "use global default".
     * Returns the per-slider value when set, otherwise calls globalFn().
     */
    function resolveSliderSetting(sliderId, key, globalFn) {
        var perSlider = mlLightboxSettings.slider_settings &&
                        sliderId &&
                        mlLightboxSettings.slider_settings[sliderId];
        var val = perSlider ? perSlider[key] : null;
        if (val === true)  { return true; }
        if (val === false) { return false; }
        return globalFn();
    }

    function resolveSliderCaptions(sliderId) {
        return resolveSliderSetting(sliderId, 'lightbox_captions', shouldShowCaptions);
    }

    function resolveSliderNavigation(sliderId) {
        return resolveSliderSetting(sliderId, 'lightbox_navigation', function() {
            return (mlLightboxSettings.metaslider_options || {}).show_arrows !== false;
        });
    }

    function resolveSliderButton(sliderId) {
        return resolveSliderSetting(sliderId, 'lightbox_button', function() {
            return (mlLightboxSettings.metaslider_options || {}).show_lightbox_button !== false;
        });
    }

    function resolveSliderIcon(sliderId) {
        return resolveSliderSetting(sliderId, 'lightbox_icon', function() {
            return !!(mlLightboxSettings.metaslider_options || {}).use_icon_instead_of_button;
        });
    }

    /**
     * Initialize MetaSlider in button mode
     */
    function initMetaSliderButtonMode($slider, sliderId) {
        _metasliderButtonSliderId = sliderId;
        try {
            var $slides = $slider.find('li').not('.clone').not('.flex-control-nav li, .flex-control-thumbs li, .filmstrip li, li.ms-thumb');
            var buttonsAdded = 0;

            $slides.each(function() {
                var $slide = $(this);
                var $button = null;

                if ($slide.find('.ml-lightbox-button').length > 0) {
                    return;
                }

                if ($slide.hasClass('ms-youtube')) {
                    $button = createYouTubeButton($slide);
                } else if ($slide.hasClass('ms-vimeo')) {
                    $button = createVimeoButton($slide);
                } else if ($slide.hasClass('ms-external-video')) {
                    $button = createExternalVideoButton($slide);
                } else if ($slide.hasClass('ms-local-video')) {
                    $button = createLocalVideoButton($slide);
                } else if ($slide.hasClass('ms-postfeed')) {
                    $button = createPostFeedButton($slide);
                } else if ($slide.hasClass('ms-layer')) {
                    $button = createLayerButton($slide);
                } else if ($slide.hasClass('ms-custom-html')) {
                    $button = createCustomHtmlButton($slide);
                } else {
                    $button = createImageButton($slide);
                }

                if ($button) {
                    $slide.css('position', 'relative').append($button);
                    buttonsAdded++;
                }
            });

            if (buttonsAdded > 0) {
                var settings = getLightboxSettings(buttonsAdded > 1);
                settings.selector = '.ml-lightbox-button';

                if (!resolveSliderCaptions(sliderId)) {
                    settings.subHtml = '';
                    settings.getCaptionFromTitleOrAlt = false;
                    $slider.find('.ml-lightbox-button').removeAttr('data-sub-html');
                }

                settings.controls = resolveSliderNavigation(sliderId);

                try {
                    lightGallery($slider[0], settings);
                    $slider.addClass('lg-initialized');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing button mode:', error);
                }
            }
        } finally {
            _metasliderButtonSliderId = null;
        }
    }

    /**
     * Initialize MetaSlider in direct-click mode
     */
    function initMetaSliderDirectMode($slider, sliderId) {

        var $slides = $slider.find('li').not('.clone').not('.flex-control-nav li, .flex-control-thumbs li, .filmstrip li, li.ms-thumb');
        var linksAdded = 0;

        $slides.each(function() {
            var $slide = $(this);

            if ($slide.find('.ml-metaslider-link, .ml-video-overlay').length > 0) return;

            var dataAttributes = null;

            if ($slide.hasClass('ms-youtube')) {
                dataAttributes = getYouTubeOverlayData($slide);
            }
            else if ($slide.hasClass('ms-vimeo')) {
                dataAttributes = getVimeoOverlayData($slide);
            }
            else if ($slide.hasClass('ms-external-video')) {
                dataAttributes = getExternalVideoOverlayData($slide);
            }
            else if ($slide.hasClass('ms-local-video')) {
                dataAttributes = getLocalVideoOverlayData($slide);
            }
            else if ($slide.hasClass('ms-postfeed')) {
                dataAttributes = getPostFeedOverlayData($slide);
            }
            else if ($slide.hasClass('ms-layer')) {
                dataAttributes = getLayerOverlayData($slide);
            }
            else if ($slide.hasClass('ms-custom-html')) {
                dataAttributes = getCustomHtmlOverlayData($slide);
            }
            else {
                dataAttributes = getImageOverlayData($slide);
            }

            if (dataAttributes) {
                if (dataAttributes.overlay) {
                    var overlayClass = 'ml-video-overlay';
                    var contentType = 'video';
                    if ($slide.hasClass('ms-image')) {
                        overlayClass = 'ml-image-overlay';
                        contentType = 'image';
                    }
                    var $overlay = $('<a href="#" class="' + overlayClass + '"></a>');
                    $overlay.attr(dataAttributes.attrs);

                    // Add accessibility attributes
                    var $img = $slide.find('img').first();
                    var altText = $img.length > 0 ? ($img.attr('alt') || '') : '';
                    addOverlayAccessibility($overlay, contentType, altText);

                    $slide.css('position', 'relative').prepend($overlay);
                } else {
                    var $link = $slide.find('a').first();
                    if ($link.length > 0) {
                        $link.attr(dataAttributes.attrs).addClass('ml-metaslider-link');
                    }
                }
                linksAdded++;
            }
        });

        if (linksAdded > 0) {
            var settings = getLightboxSettings(linksAdded > 1);
            settings.selector = '.ml-metaslider-link, .ml-video-overlay, .ml-image-overlay';

            if (!resolveSliderCaptions(sliderId)) {
                settings.subHtml = '';
                settings.getCaptionFromTitleOrAlt = false;
                $slider.find('.ml-metaslider-link, .ml-video-overlay, .ml-image-overlay').removeAttr('data-sub-html');
            }

            settings.controls = resolveSliderNavigation(sliderId);

            try {
                lightGallery($slider[0], settings);
                $slider.addClass('lg-initialized');
            } catch (error) {
                console.error('MetaSlider Lightbox: Error initializing direct-click mode:', error);
            }
        }
    }


    /**
     * Check if gallery is already processed
     */
    function isGalleryAlreadyProcessed($gallery) {
        return $gallery.hasClass(CONSTANTS.CLASSES.ML_LIGHTBOX_ENABLED) ||
               $gallery.hasClass(CONSTANTS.CLASSES.LG_INITIALIZED);
    }

    /**
     * Extract video ID using various fallback methods
     */
    function extractVideoId($slide, handler) {
        var $element = $slide.find(handler.selector);
        if ($element.length === 0) return null;

        var id = $element.attr(handler.idAttr);

        if (!id && handler.fallbackSelectors) {
            for (var i = 0; i < handler.fallbackSelectors.length; i++) {
                var fallbackSelector = handler.fallbackSelectors[i];
                var $fallback = $slide.find(fallbackSelector);

                if (fallbackSelector.startsWith('a[href*=')) {
                    var url = $fallback.attr('href');
                    if (url) {
                        var match = url.match(/vimeo\.com\/(\d+)/);
                        if (match) {
                            id = match[1];
                            break;
                        }
                    }
                } else {
                    id = $fallback.attr(handler.idAttr);
                    if (id) break;
                }
            }
        }

        return id;
    }

    /**
     * Unified video button creation function
     */
    function createVideoButton($slide, videoType) {
        var handler = CONSTANTS.VIDEO_HANDLERS[videoType];
        if (!handler) return null;

        var videoData = null;

        if (videoType === 'youtube' || videoType === 'vimeo') {
            var videoId = extractVideoId($slide, handler);
            if (!videoId) return null;

            videoData = {
                url: handler.urlTemplate(videoId),
                thumb: handler.thumbTemplate(videoId)
            };
        } else if (videoType === 'externalVideo' || videoType === 'localVideo') {
            var $element = $slide.find(handler.selector);
            if ($element.length === 0) return null;

            var dataSources = $element.attr(handler.sourcesAttr);
            var videoSources = [];
            var videoUrl = null;

            if (dataSources) {
                try {
                    var sources = JSON.parse(dataSources);
                    if (sources && sources.length > 0) {
                        videoUrl = sources[0].src;
                        videoSources = sources.map(function (source) {
                            return {
                                'src': source.src,
                                'type': source.type || getVideoType(source.src)
                            };
                        });
                    }
                } catch (e) {
                    console.error('MetaSlider Lightbox: Error parsing video sources', e);
                    return null;
                }
            }

            if (!videoUrl || videoSources.length === 0) return null;

            videoData = {
                url: '',
                thumb: $element.attr(handler.posterAttr) || getPlaceholderThumb(),
                videoData: {
                    'source': videoSources,
                    'attributes': {
                        'preload': false,
                        'controls': true
                    }
                }
            };
        }

        if (!videoData) return null;

        var diagnosticClass = 'ml-button-metaslider ml-button-metaslider-' + videoType.toLowerCase().replace(/([A-Z])/g, '-$1').replace(/^-/, '');
        var buttonText = getButtonText();
        var $button = $('<a class="' + CONSTANTS.CLASSES.ML_LIGHTBOX_BUTTON + ' ' + diagnosticClass + '" href="#">' + buttonText + '</a>');

        var baseAttrs = {
            'role': 'button',
            'aria-label': buttonText + ' - ' + videoType + ' video',
            'tabindex': '0'
        };

        if (videoData.videoData) {
            $button.attr($.extend({}, baseAttrs, {
                'data-src': '',
                'data-video': JSON.stringify(videoData.videoData),
                'data-thumb': videoData.thumb
            }));
        } else {
            if (!videoData.url) return null;
            $button.attr($.extend({}, baseAttrs, {
                'data-src': videoData.url,
                'data-thumb': videoData.thumb
            }));
        }

        return $button;
    }

    /**
     * Create lightbox button for regular image slides
     */
    function createImageButton($slide) {
        var $link = $slide.find('a').first();
        var $img = $slide.find('img').first();

        if ($img.length === 0) return null;

        var imgSrc = $img.attr('src');
        var linkHref = ($link.length > 0) ? $link.attr('href') : null;
        if (!linkHref) {
            linkHref = imgSrc;
        }

        var fullSizeUrl = removeWordPressSizeSuffix(linkHref);

        var altText = $img.attr('alt') || 'image';
        var buttonText = getButtonText();

        var $button = $('<a class="ml-lightbox-button ml-button-metaslider ml-button-metaslider-image" href="#">' + buttonText + '</a>');

        $button.attr({
            'data-src': fullSizeUrl,
            'data-thumb': imgSrc,
            'role': 'button',
            'aria-label': buttonText + ' - ' + altText,
            'tabindex': '0'
        });

        var caption = extractCaption($slide);
        if (caption) {
            $button.attr('data-sub-html', caption);
        }

        var caption = extractCaption($slide);
        if (caption) {
            $button.attr('data-sub-html', caption);
        }

        return $button;
    }

    /**
     * Create lightbox button for YouTube slides
     */
    function createYouTubeButton($slide) {
        return createVideoButton($slide, 'youtube');
    }

    /**
     * Create lightbox button for Vimeo slides
     */
    function createVimeoButton($slide) {
        return createVideoButton($slide, 'vimeo');
    }

    /**
     * Create lightbox button for external video slides
     */
    function createExternalVideoButton($slide) {
        return createVideoButton($slide, 'externalVideo');
    }

    /**
     * Create lightbox button for local video slides
     */
    function createLocalVideoButton($slide) {
        return createVideoButton($slide, 'localVideo');
    }

    /**
     * Helper function to get video type from URL
     */
    function getVideoType(url) {
        if (url.includes('.mp4')) return 'video/mp4';
        if (url.includes('.webm')) return 'video/webm';
        if (url.includes('.ogg')) return 'video/ogg';
        return 'video/mp4';
    }

    /**
     * Helper function to get placeholder thumbnail
     */
    function getPlaceholderThumb() {
        return 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAwIiBoZWlnaHQ9IjMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxOCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPjQwMHgzMDA8L3RleHQ+PC9zdmc+';
    }

    /**
     * Create lightbox button for PostFeed slides
     */
    function createPostFeedButton($slide) {
        var $img = $slide.find('img').first();
        if ($img.length === 0) return null;

        var imgSrc = removeWordPressSizeSuffix($img.attr('src'));
        var $button = $('<a class="ml-lightbox-button ml-button-metaslider ml-button-metaslider-postfeed" href="#">' + getButtonText() + '</a>');

        $button.attr({
            'data-src': imgSrc,
            'data-thumb': imgSrc
        });

        var caption = extractCaption($slide);
        if (caption) {
            $button.attr('data-sub-html', caption);
        }

        return $button;
    }

    /**
     * Get overlay data for PostFeed slides in direct-click mode
     */
    function getPostFeedOverlayData($slide) {
        var $img = $slide.find('img').first();
        if ($img.length === 0) return null;

        var imgSrc = removeWordPressSizeSuffix($img.attr('src'));

        var attrs = {
            'data-src': imgSrc,
            'data-thumb': imgSrc
        };

        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: false,
            attrs: attrs
        };
    }

    /**
     * Create lightbox button for Layer slides using iframe data URI approach
     */
    function createLayerButton($slide) {
        var slideContent = $slide.html();
        if (!slideContent || !slideContent.trim()) return null;

        var scaledSlideContent = slideContent.replace(/<div class="layer"[^>]*style="([^"]*)"[^>]*>/g, function(match, styleAttr) {
            var scaledStyle = styleAttr.replace(/(top|left|right|bottom):\s*(\d+(?:\.\d+)?)(px|%)/g, function(cssMatch, property, value, unit) {
                var numValue = parseFloat(value);
                var scaledValue = Math.round(numValue * 2);
                return property + ': ' + scaledValue + unit;
            });
            return match.replace(styleAttr, scaledStyle);
        });

        var $tempDiv = $('<div>').html(slideContent);
        var animationClasses = [];

        $tempDiv.find('[class*="animated"], [class*="animation_"], [data-animation]').each(function() {
            var $elem = $(this);
            var classes = $elem.attr('class') || '';
            var animationType = $elem.attr('data-animation') || '';

            if (classes) {
                animationClasses = animationClasses.concat(classes.split(' ').filter(function(cls) {
                    return cls.match(/animated|fadeIn|pulse|bounce|slide|animation_/i);
                }));
            }

            if (animationType) {
                animationClasses.push(animationType);
            }
        });

        var uniqueAnimationClasses = animationClasses.filter(function(value, index, self) {
            return self.indexOf(value) === index;
        }).join(' ');

        var scaleCSS = `
            .layer-slide-content {
                position: relative !important;
                display: inline-block !important;
            }
            .layer-slide-content .msDefaultImage {
                display: block !important;
                width: 1400px !important;
                height: 600px !important;
                max-width: 80% !important;
                margin: 0 auto !important;
                object-fit: cover !important;
            }
            .layer-slide-content .msHtmlOverlay {
                position: absolute !important;
                top: 0 !important;
                left: 50% !important;
                width: 80% !important;
                height: 100% !important;
                transform: translateX(-50%) !important;
            }
            .layer-slide-content .msHtmlOverlay .content {
                font-size: 1.8em !important;
                line-height: 1.2 !important;
            }
        `;

        var htmlContent = '<div style="display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; box-sizing: border-box; background: #000;"><div class="layer-slide-content ' + uniqueAnimationClasses + '" style="position: relative; display: inline-block; max-width: 100%; max-height: 100vh;">' + scaledSlideContent + '</div></div>';
        var dataUri = 'data:text/html;charset=utf-8,' + encodeURIComponent('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Layer Content</title><style>body{margin:0;padding:0;background:#000;} ' + scaleCSS + '</style><link rel="stylesheet" href="' + window.location.origin + '/wp-content/plugins/metaslider/assets/animate/animate.css" type="text/css" /></head><body>' + htmlContent + '</body></html>');

        var $button = $('<a class="ml-lightbox-button ml-button-metaslider ml-button-metaslider-layer" href="#">' + getButtonText() + '</a>');

        $button.attr({
            'data-src': dataUri,
            'data-thumb': getPlaceholderThumb(),
            'data-iframe': 'true',
            'data-width': '800',
            'data-height': '600'
        });

        return $button;
    }

    /**
     * Get overlay data for Layer slides in direct-click mode using iframe data URI approach
     */
    function getLayerOverlayData($slide) {
        var slideContent = $slide.html();
        if (!slideContent || !slideContent.trim()) return null;

        var scaledSlideContent = slideContent.replace(/<div class="layer"[^>]*style="([^"]*)"[^>]*>/g, function(match, styleAttr) {
            var scaledStyle = styleAttr.replace(/(top|left|right|bottom):\s*(\d+(?:\.\d+)?)(px|%)/g, function(cssMatch, property, value, unit) {
                var numValue = parseFloat(value);
                var scaledValue = Math.round(numValue * 2);
                return property + ': ' + scaledValue + unit;
            });
            return match.replace(styleAttr, scaledStyle);
        });

        var $tempDiv = $('<div>').html(slideContent);
        var animationClasses = [];

        $tempDiv.find('[class*="animated"], [class*="animation_"], [data-animation]').each(function() {
            var $elem = $(this);
            var classes = $elem.attr('class') || '';
            var animationType = $elem.attr('data-animation') || '';

            if (classes) {
                animationClasses = animationClasses.concat(classes.split(' ').filter(function(cls) {
                    return cls.match(/animated|fadeIn|pulse|bounce|slide|animation_/i);
                }));
            }

            if (animationType) {
                animationClasses.push(animationType);
            }
        });

        var uniqueAnimationClasses = animationClasses.filter(function(value, index, self) {
            return self.indexOf(value) === index;
        }).join(' ');

        var scaleCSS = `
            .layer-slide-content {
                position: relative !important;
                display: inline-block !important;
            }
            .layer-slide-content .msDefaultImage {
                display: block !important;
                width: 1400px !important;
                height: 600px !important;
                max-width: 80% !important;
                margin: 0 auto !important;
                object-fit: cover !important;
            }
            .layer-slide-content .msHtmlOverlay {
                position: absolute !important;
                top: 0 !important;
                left: 50% !important;
                width: 80% !important;
                height: 100% !important;
                transform: translateX(-50%) !important;
            }
            .layer-slide-content .msHtmlOverlay .content {
                font-size: 1.8em !important;
                line-height: 1.2 !important;
            }
        `;

        var htmlContent = '<div style="display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; box-sizing: border-box; background: #000;"><div class="layer-slide-content ' + uniqueAnimationClasses + '" style="position: relative; display: inline-block; max-width: 100%; max-height: 100vh;">' + scaledSlideContent + '</div></div>';
        var dataUri = 'data:text/html;charset=utf-8,' + encodeURIComponent('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Layer Content</title><style>body{margin:0;padding:0;background:#000;} ' + scaleCSS + '</style><link rel="stylesheet" href="' + window.location.origin + '/wp-content/plugins/metaslider/assets/animate/animate.css" type="text/css" /></head><body>' + htmlContent + '</body></html>');

        var attrs = {
            'data-src': dataUri,
            'data-thumb': getPlaceholderThumb(),
            'data-iframe': 'true',
            'data-width': '800',
            'data-height': '600'
        };

        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true,
            attrs: attrs
        };
    }

    /**
     * HTML escape function for safe caption display
     */

    /**
     * Check if captions should be shown based on settings
     */
    function shouldShowCaptions() {
        if (typeof mlLightboxSettings !== 'undefined' &&
            mlLightboxSettings.metaslider_options &&
            typeof mlLightboxSettings.metaslider_options.show_captions !== 'undefined') {
            return mlLightboxSettings.metaslider_options.show_captions === true || mlLightboxSettings.metaslider_options.show_captions === 1;
        }
        return true;
    }

    /**
     * Extract caption from various sources for an image or container
     */
    function extractCaption($element) {
        // PHP sets data-sub-html on the slide's <a> for the built-in lightbox.
        // Read this before the shouldShowCaptions() guard so it is available when
        // global captions are off but a per-slider override enables them.
        // resolveSliderCaptions() is the final authority on removal.
        var $childLink = $element.find('a').first();
        var childCaption = $childLink.length ? ($childLink.attr('data-sub-html') || '').trim() : '';

        if (!shouldShowCaptions()) {
            return childCaption;
        }

        var caption = '';
        var $img = $element.is('img') ? $element : $element.find('img').first();

        var $figcaption = $element.closest('figure').find('figcaption');
        if ($figcaption.length > 0) {
            caption = $figcaption.html();
        }

        if (!caption) {
            var $captionWrap = $element.find('.caption-wrap .caption');
            if ($captionWrap.length === 0) {
                $captionWrap = $element.closest('li').find('.caption-wrap .caption');
            }
            if ($captionWrap.length === 0) {
                $captionWrap = $element.closest('.slide').find('.caption-wrap .caption');
            }
            if ($captionWrap.length > 0) {
                caption = $captionWrap.html();
            }
        }

        if (!caption && $img.length > 0) {
            caption = escapeHtml($img.attr('alt') || $img.attr('title') || '');
        }

        if (!caption) {
            caption = $element.attr('data-sub-html') || '';
        }

        if (!caption) {
            caption = childCaption;
        }

        return caption.trim();
    }


    /**
     * Get overlay data for regular images in direct-click mode
     */
    function getImageOverlayData($slide) {
        var $link = $slide.find('a').first();
        var $img = $slide.find('img').first();

        if ($img.length === 0) return null;

        var imgSrc = $img.attr('src');
        var linkHref = ($link.length > 0) ? $link.attr('href') : null;
        if (!linkHref) {
            linkHref = imgSrc;
        }

        var fullSizeUrl = removeWordPressSizeSuffix(linkHref);
        var needsOverlay = ($link.length === 0);

        var attrs = {
            'data-src': fullSizeUrl,
            'data-thumb': imgSrc
        };

        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: needsOverlay,
            attrs: attrs
        };
    }

    /**
     * Get overlay data for YouTube videos in direct-click mode
     */
    function getYouTubeOverlayData($slide) {
        var $youtubeDiv = $slide.find('div.youtube[data-id]');
        if ($youtubeDiv.length === 0) return null;

        var youtubeId = $youtubeDiv.attr('data-id');
        if (!youtubeId) return null;

        var youtubeUrl = 'https://www.youtube.com/watch?v=' + youtubeId;
        var thumbUrl = 'https://img.youtube.com/vi/' + youtubeId + '/maxresdefault.jpg';

        var attrs = {
            'data-src': youtubeUrl,
            'data-thumb': thumbUrl
        };

        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true,
            attrs: attrs
        };
    }

    /**
     * Get overlay data for Vimeo videos in direct-click mode
     */
    function getVimeoOverlayData($slide) {
        var $vimeoDiv = $slide.find('div.vimeo[data-id]');
        if ($vimeoDiv.length === 0) return null;

        var vimeoId = $vimeoDiv.attr('data-id');

        if (!vimeoId) {
            vimeoId = $vimeoDiv.attr('data-slide-id');
        }
        if (!vimeoId) {
            var $existingLink = $slide.find('a[href*="vimeo.com"]').first();
            if ($existingLink.length > 0) {
                var vimeoUrl = $existingLink.attr('href');
                var vimeoMatch = vimeoUrl.match(/vimeo\.com\/(\d+)/);
                if (vimeoMatch) {
                    vimeoId = vimeoMatch[1];
                }
            }
        }

        if (!vimeoId) return null;

        var vimeoUrl = 'https://vimeo.com/' + vimeoId;
        var vimeoPoster = 'https://vumbnail.com/' + vimeoId + '.jpg';

        var attrs = {
            'data-src': vimeoUrl,
            'data-thumb': vimeoPoster
        };

        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true,
            attrs: attrs
        };
    }

    /**
     * Get overlay data for external videos in direct-click mode
     */
    function getExternalVideoOverlayData($slide) {
        var $videoDiv = $slide.find('div.external-video');
        if ($videoDiv.length === 0) return null;

        var dataSources = $videoDiv.attr('data-sources');
        var videoSources = [];
        var videoUrl = null;

        if (dataSources) {
            try {
                var sources = JSON.parse(dataSources);
                if (sources && sources.length > 0) {
                    videoUrl = sources[0].src;
                    videoSources = sources.map(function (source) {
                        return {
                            'src': source.src,
                            'type': source.type || getVideoType(source.src)
                        };
                    });
                }
            } catch (e) {
                console.error('MetaSlider Lightbox: Error parsing video sources', e);
                return null;
            }
        }

        if (!videoUrl || videoSources.length === 0) return null;

        var posterUrl = $videoDiv.attr('data-poster') || getPlaceholderThumb();
        var videoData = {
            'source': videoSources,
            'attributes': {
                'preload': false,
                'controls': true
            }
        };

        var attrs = {
            'data-src': '',
            'data-video': JSON.stringify(videoData),
            'data-thumb': posterUrl
        };

        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true,
            attrs: attrs
        };
    }

    /**
     * Get overlay data for local videos in direct-click mode
     */
    function getLocalVideoOverlayData($slide) {
        var $videoDiv = $slide.find('div.local-video');
        if ($videoDiv.length === 0) return null;

        var dataSources = $videoDiv.attr('data-sources');
        var videoSources = [];
        var videoUrl = null;

        if (dataSources) {
            try {
                var sources = JSON.parse(dataSources);
                if (sources && sources.length > 0) {
                    videoUrl = sources[0].src;
                    videoSources = sources.map(function (source) {
                        return {
                            'src': source.src,
                            'type': source.type || getVideoType(source.src)
                        };
                    });
                }
            } catch (e) {
                console.error('MetaSlider Lightbox: Error parsing video sources', e);
                return null;
            }
        }

        if (!videoUrl || videoSources.length === 0) return null;

        var posterUrl = $videoDiv.attr('data-poster') || getPlaceholderThumb();
        var videoData = {
            'source': videoSources,
            'attributes': {
                'preload': false,
                'controls': true
            }
        };

        var attrs = {
            'data-src': '',
            'data-video': JSON.stringify(videoData),
            'data-thumb': posterUrl
        };

        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true,
            attrs: attrs
        };
    }

    /**
     * Create lightbox button for Custom HTML slides using iframe data URI approach (similar to Layer slides)
     */
    function createCustomHtmlButton($slide) {
        var slideContent = $slide.html();
        if (!slideContent || !slideContent.trim()) return null;

        var responsiveCSS = `
            .custom-html-content {
                width: 100% !important;
                max-width: 100% !important;
                height: 100vh !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 20px !important;
                box-sizing: border-box !important;
                background: #000 !important;
                color: white !important;
                font-family: Arial, sans-serif !important;
                overflow: auto !important;
            }
            .custom-html-content > div {
                max-width: 100% !important;
                max-height: 100% !important;
            }
            @media (max-width: 768px) {
                .custom-html-content {
                    padding: 10px !important;
                }
            }
        `;

        var htmlContent = '<div class="custom-html-content">' + slideContent + '</div>';
        var dataUri = 'data:text/html;charset=utf-8,' + encodeURIComponent('<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Custom HTML</title><style>body{margin:0;padding:0;} ' + responsiveCSS + '</style></head><body>' + htmlContent + '</body></html>');

        var $button = $('<a class="ml-lightbox-button ml-button-metaslider ml-button-metaslider-custom-html" href="#">' + getButtonText() + '</a>');

        $button.attr({
            'data-src': dataUri,
            'data-thumb': getPlaceholderThumb(),
            'data-iframe': 'true',
            'data-width': '90%',
            'data-height': '90%'
        });

        return $button;
    }

    /**
     * Get overlay data for Custom HTML slides in direct-click mode using iframe data URI approach
     */
    function getCustomHtmlOverlayData($slide) {
        var slideContent = $slide.html();
        if (!slideContent || !slideContent.trim()) return null;

        var responsiveCSS = `
            .custom-html-content {
                width: 100% !important;
                max-width: 100% !important;
                height: 100vh !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                padding: 20px !important;
                box-sizing: border-box !important;
                background: #000 !important;
                color: white !important;
                font-family: Arial, sans-serif !important;
                overflow: auto !important;
            }
            .custom-html-content > div {
                max-width: 100% !important;
                max-height: 100% !important;
            }
            @media (max-width: 768px) {
                .custom-html-content {
                    padding: 10px !important;
                }
            }
        `;

        var htmlContent = '<div class="custom-html-content">' + slideContent + '</div>';
        var dataUri = 'data:text/html;charset=utf-8,' + encodeURIComponent('<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Custom HTML</title><style>body{margin:0;padding:0;} ' + responsiveCSS + '</style></head><body>' + htmlContent + '</body></html>');

        var attrs = {
            'data-src': dataUri,
            'data-thumb': getPlaceholderThumb(),
            'data-iframe': 'true',
            'data-width': '90%',
            'data-height': '90%'
        };

        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true,
            attrs: attrs
        };
    }

    /**
     * Initialize "Enlarge on Click" gallery - similar to initPlainImageGallery but for button-based galleries
     */
    function initEnlargeOnClickGallery($gallery, useButtonMode) {
        var $wpContainers = $gallery.find('.wp-lightbox-container').has('button[data-wp-on], button.lightbox-trigger');

        if ($wpContainers.length <= 1) return;

        if (useButtonMode) {
            $wpContainers.each(function() {
                var $container = $(this);
                var $img = $container.find('img').first();
                var $wpButton = $container.find('button[data-wp-on], button.lightbox-trigger, button[data-wp-interactive]');

                if ($img.length && $wpButton.length) {
                    var imgSrc = $img.attr('src');
                    if (!imgSrc) return;

                    var fullUrl = removeWordPressSizeSuffix(imgSrc);

                    $container.attr({
                        'data-src': fullUrl,
                        'data-thumb': imgSrc
                    });

                    var caption = extractCaption($img);
                    if (caption) {
                        $container.attr('data-sub-html', caption);
                    }

                    var $button = $('<a href="#" class="ml-lightbox-button ml-button-wordpress ml-button-wordpress-enlarge-override" data-src="' + fullUrl + '" data-thumb="' + imgSrc + '">' + getButtonText() + '</a>');
                    if (caption) {
                        $button.attr('data-sub-html', caption);
                    }

                    $wpButton.remove();
                    $container.css('position', 'relative').append($button);
                }
            });

            var gallerySettings = getLightboxSettings(true);
            gallerySettings.selector = '.ml-lightbox-button';

        } else {
            $wpContainers.each(function() {
                var $container = $(this);
                var $img = $container.find('img').first();
                var $wpButton = $container.find('button[data-wp-on], button.lightbox-trigger, button[data-wp-interactive]');

                if ($img.length && $wpButton.length) {
                    var imgSrc = $img.attr('src');
                    if (!imgSrc) return;

                    var fullUrl = removeWordPressSizeSuffix(imgSrc);

                    var $wrapper = $('<a href="#" data-src="' + fullUrl + '" data-thumb="' + imgSrc + '"></a>');

                    var caption = extractCaption($img);
                    if (caption) {
                        $wrapper.attr('data-sub-html', caption);
                    }

                    $img.wrap($wrapper);
                    $wpButton.remove();
                }
            });

            var gallerySettings = getLightboxSettings(true);
            gallerySettings.selector = 'a[data-src]';
        }

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');
        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing Enlarge on Click gallery:', error);
        }
    }

    /**
     * Initialize "Link to Media File" gallery when manual override is enabled - similar to automatic processing but runs independently
     */
    function initLinkToMediaManualGallery($gallery, useButtonMode) {
        var $mediaLinks = $gallery.find('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]').filter(function() {
            return $(this).find('img').length > 0;
        });

        if ($mediaLinks.length <= 1) return;

        if (useButtonMode) {
            $mediaLinks.each(function() {
                var $link = $(this);
                var href = $link.attr('href');
                var $img = $link.find('img').first();
                var imgSrc = $img.attr('src');

                if (!href || !imgSrc) return;

                var $container = $link.closest('.wp-block-image');
                if ($container.length === 0) {
                    $container = $link.parent();
                }

                $container.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });

                var caption = extractCaption($img);
                if (caption) {
                    $container.attr('data-sub-html', caption);
                }

                var $button = $('<a href="#" class="ml-lightbox-button ml-button-wordpress ml-button-wordpress-link-to-media" data-src="' + href + '" data-thumb="' + imgSrc + '">' + getButtonText() + '</a>');
                if (caption) {
                    $button.attr('data-sub-html', caption);
                }

                $container.css('position', 'relative').append($button);
            });

            var gallerySettings = getLightboxSettings(true);
            gallerySettings.selector = '.ml-lightbox-button';

        } else {
            $mediaLinks.each(function() {
                var $link = $(this);
                var href = $link.attr('href');
                var $img = $link.find('img').first();
                var imgSrc = $img.attr('src');

                if (!href || !imgSrc) return;

                $link.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });

                var caption = extractCaption($img);
                if (caption) {
                    $link.attr('data-sub-html', caption);
                }
            });

            var gallerySettings = getLightboxSettings(true);
            gallerySettings.selector = 'a[data-src]';
        }

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');
        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing Link to Media File manual gallery:', error);
        }
    }

    function initWooCommerceGallery() {
        var $gallery = $('.woocommerce-product-gallery');

        if ($gallery.length === 0) {
            return;
        }

        var useButtonMode = false;
        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
            useButtonMode = mlLightboxSettings.metaslider_options.show_lightbox_button !== false;
        }

        var $images = $gallery.find('.woocommerce-product-gallery__image');

        if ($images.length === 0) {
            return;
        }

        if (!useButtonMode) {
            $gallery.off('.wc-product-gallery-zoom');
            $gallery.find('img').off('.wc-product-gallery-zoom');
            $gallery.find('a').off('.wc-product-gallery-zoom');

            $images.each(function() {
                var $imageWrapper = $(this);
                var $img = $imageWrapper.find('img').first();
                var $link = $imageWrapper.find('a').first();

                $img.css('pointer-events', 'none');
                $link.css('cursor', 'pointer');
            });

            $gallery.find('.zoomImg').remove();

            $images.find('a.ml-woo-product-image').each(function() {
                var $link = $(this);
                if ($link.hasClass('lg-initialized')) {
                    var lgInstance = $link.data('lightGallery');
                    if (lgInstance) {
                        lgInstance.destroy(true);
                    }
                    $link.removeClass('lg-initialized').removeAttr('data-lg-id');
                }
            });
        }

        if ($gallery.hasClass('lg-initialized')) {
            var galleryInstance = $gallery.data('lightGallery');
            if (galleryInstance) {
                galleryInstance.destroy(true);
            }
            $gallery.removeClass('lg-initialized');
        }

        $images.each(function() {
            var $imageWrapper = $(this);
            var $img = $imageWrapper.find('img').first();
            var $link = $imageWrapper.find('a').first();

            if ($imageWrapper.find('.ml-lightbox-button').length > 0 && useButtonMode) {
                return;
            }

            if (useButtonMode) {
                var fullUrl = $img.attr('data-full-url');
                var thumbUrl = $img.attr('data-thumb-url');

                if (!fullUrl || !thumbUrl) {
                    return;
                }

                var $button = $('<a href="#" class="ml-lightbox-button ml-button-woo">' + getButtonText() + '</a>');
                $button.attr({
                    'data-src': fullUrl,
                    'data-thumb': thumbUrl
                });

                $imageWrapper.css('position', 'relative').append($button);
            }
        });

        var gallerySettings = getLightboxSettings(true);

        if (useButtonMode) {
            gallerySettings.selector = '.ml-lightbox-button';
        } else {
            gallerySettings.selector = '.ml-woo-product-image';
        }

        gallerySettings.galleryId = 'ml-woo-gallery-' + Date.now();

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');

            $gallery.on('found_variation', function(event, variation) {
                if (instance && typeof instance.refresh === 'function') {
                    setTimeout(function() {
                        instance.refresh();
                    }, 100);
                }
            });

            $gallery.on('reset_data', function() {
                if (instance && typeof instance.refresh === 'function') {
                    setTimeout(function() {
                        instance.refresh();
                    }, 100);
                }
            });

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing WooCommerce gallery:', error);
        }
    }

    window.mlInitWooCommerceGallery = initWooCommerceGallery;

})(jQuery);