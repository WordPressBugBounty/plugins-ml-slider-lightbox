/**
 * MetaSlider Lightbox - Clean Implementation
 * Simple class-based lightbox functionality
 */

(function($) {
    'use strict';

    // Constants for maintainability
    const CONSTANTS = {
        SELECTORS: {
            WP_GALLERY: '.wp-block-gallery',
            ML_LIGHTBOX_ENABLED: '.ml-lightbox-enabled',
            ML_LIGHTBOX_BUTTON: '.ml-lightbox-button',
            LG_INITIALIZED: '.lg-initialized'
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

    // Initialize when DOM is ready
    $(document).ready(function() {
        initLightboxes();
        removeConflictingAttributes();
    });

    /**
     * Remove conflicting attributes when lightbox button is present
     * Prevents container clicks when buttons are available
     */
    function removeConflictingAttributes() {
        $('.ml-lightbox-enabled').each(function() {
            var $container = $(this);

            // Check if this container has a lightbox button inside
            if ($container.find('.ml-lightbox-button').length > 0) {
                // Remove href and lightbox data attributes to prevent conflicts
                $container.removeAttr('href');
                $container.removeAttr('data-src');
                $container.removeAttr('data-thumb');
            }
        });
    }

    /**
     * Main initialization function
     */
    function initLightboxes() {

        // Check if LightGallery is available
        if (typeof lightGallery === 'undefined') {
            console.warn('MetaSlider Lightbox: LightGallery not loaded');
            return;
        }

        // Check if settings are available
        if (typeof mlLightboxSettings === 'undefined') {
            console.warn('MetaSlider Lightbox: Settings not available');
            return;
        }

        // Priority-based initialization: only one system handles each element
        initLightboxSystems();

    }

    /**
     * Initialize MetaSlider lightbox (Priority 0)
     */
    function initMetaSliderLightbox() {
        initMetaSlider();
    }

    /**
     * Initialize manual lightbox elements (Priority 1)
     */
    function initManualLightboxElements() {
        var useButtonsForManual = false;
        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
            useButtonsForManual = mlLightboxSettings.metaslider_options.show_lightbox_button !== false;
        }

        $(CONSTANTS.SELECTORS.ML_LIGHTBOX_ENABLED).each(function() {
            initLightboxEnabled($(this), useButtonsForManual);
        });
    }

    /**
     * Initialize WordPress galleries (Priority 2)
     */
    function initWordPressGalleries() {
        if (typeof mlLightboxSettings === 'undefined' || !mlLightboxSettings.enable_galleries) {
            return;
        }

        var $wordpressGalleries = $(CONSTANTS.SELECTORS.WP_GALLERY).filter(function() {
            var $gallery = $(this);
            var $wpImages = $gallery.find('.wp-lightbox-container img[data-wp-on-async--click="actions.showLightbox"]');

            // Check override setting - if disabled, skip WordPress lightbox images
            var overrideEnabled = mlLightboxSettings.override_enlarge_on_click === true ||
                                mlLightboxSettings.override_enlarge_on_click === '1' ||
                                mlLightboxSettings.override_enlarge_on_click === 1;
            if (!overrideEnabled && $wpImages.length > 0) {
                return false; // Skip processing when override is disabled and images have WordPress lightbox
            }

            // Must have WordPress lightbox images, not have .ml-lightbox-enabled, and not be initialized
            return $wpImages.length > 1 &&
                   !isGalleryAlreadyProcessed($gallery);
        });

        $wordpressGalleries.each(function() {
            initWordPressGallery($(this));
        });
    }

    /**
     * Initialize individual WordPress images (Priority 3)
     */
    function initIndividualWordPressImages() {
        if (typeof mlLightboxSettings === 'undefined' || !mlLightboxSettings.enable_galleries) {
            return;
        }

        // Individual WordPress "Enlarge on Click" images logic would go here
        // (extracted from the existing massive function)
    }

    /**
     * Initialize "Enlarge on Click" galleries (Priority 3.5)
     */
    function initEnlargeOnClickGalleries() {
        // Logic for enlarge on click galleries would go here
        // (extracted from the existing massive function)
    }

    /**
     * Initialize "Link to Media File" galleries (Priority 4-5)
     */
    function initLinkToMediaGalleries() {
        // Logic for link to media file galleries would go here
        // (extracted from the existing massive function)
    }

    /**
     * Initialize lightbox systems with priority-based approach
     * Priority: 0) MetaSlider, 1) .ml-lightbox-enabled, 2) WordPress galleries, etc.
     */
    function initLightboxSystems() {
        // Priority-based initialization: only one system handles each element
        initMetaSliderLightbox();           // Priority 0
        initManualLightboxElements();       // Priority 1
        initWordPressGalleries();          // Priority 2
        initIndividualWordPressImages();   // Priority 3
        initEnlargeOnClickGalleries();     // Priority 3.5
        initLinkToMediaGalleries();        // Priority 4-5

        // PRIORITY 0: Handle MetaSlider lightbox first (highest priority)
        initMetaSlider();

        // PRIORITY 1: Handle .ml-lightbox-enabled elements first
        // Check if button mode is enabled for manual lightbox
        var useButtonsForManual = false;
        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
            useButtonsForManual = mlLightboxSettings.metaslider_options.show_lightbox_button !== false;
        }

        $('.ml-lightbox-enabled').each(function() {
            initLightboxEnabled($(this), useButtonsForManual);
        });

        // PRIORITY 1.5: Handle WordPress "Enlarge on Click" galleries via manual override (independent of enable_galleries setting)
        var overrideEnabled = typeof mlLightboxSettings !== 'undefined' && (mlLightboxSettings.override_enlarge_on_click === true || mlLightboxSettings.override_enlarge_on_click === '1' || mlLightboxSettings.override_enlarge_on_click === 1);
        if (overrideEnabled) {
            var $wordpressOverrideGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                // Look for both old and new WordPress lightbox formats
                var $wpImagesOld = $gallery.find('.wp-lightbox-container img[data-wp-on-async--click="actions.showLightbox"]');
                var $wpContainersNew = $gallery.find('.wp-lightbox-container[data-wp-interactive="core/image"]');

                // Must have WordPress lightbox images (old OR new format), not have .ml-lightbox-enabled, and not be initialized
                return ($wpImagesOld.length > 1 || $wpContainersNew.length > 1) &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized');
            });

            $wordpressOverrideGalleries.each(function() {
                initWordPressGallery($(this));
            });
        }

        // PRIORITY 2: Handle WordPress "Enlarge on Click" galleries ONLY if enabled and not already processed
        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.enable_galleries) {
            var $wordpressGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                // Look for both old and new WordPress lightbox formats
                var $wpImagesOld = $gallery.find('.wp-lightbox-container img[data-wp-on-async--click="actions.showLightbox"]');
                var $wpContainersNew = $gallery.find('.wp-lightbox-container[data-wp-interactive="core/image"]');

                // Check override setting - if disabled, skip WordPress lightbox images
                var overrideEnabled = mlLightboxSettings.override_enlarge_on_click === true || mlLightboxSettings.override_enlarge_on_click === '1' || mlLightboxSettings.override_enlarge_on_click === 1;
                if (!overrideEnabled && ($wpImagesOld.length > 0 || $wpContainersNew.length > 0)) {
                    return false; // Skip processing when override is disabled and images have WordPress lightbox
                }

                // Must have WordPress lightbox images (old OR new format), not have .ml-lightbox-enabled, and not be initialized
                return ($wpImagesOld.length > 1 || $wpContainersNew.length > 1) &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized');
            });

            $wordpressGalleries.each(function() {
                initWordPressGallery($(this));
            });
        }

        // PRIORITY 3: Handle individual WordPress "Enlarge on Click" images ONLY if not in processed gallery
        // Handle both old format (with buttons) and new format (with data-wp-interactive)
        var $wordpressContainersOld = $('.wp-lightbox-container').has('button[data-wp-on-async--click="actions.showLightbox"]');
        var $wordpressContainersNew = $('.wp-lightbox-container[data-wp-interactive="core/image"]');
        var $allWordpressContainers = $wordpressContainersOld.add($wordpressContainersNew);

        var $unprocessedContainers = $allWordpressContainers.filter(function() {
            var $figure = $(this);
            var $parentGallery = $figure.closest('.wp-block-gallery');

            // Check override setting - if disabled, skip WordPress lightbox images
            var overrideEnabled = typeof mlLightboxSettings !== 'undefined' && (mlLightboxSettings.override_enlarge_on_click === true || mlLightboxSettings.override_enlarge_on_click === '1' || mlLightboxSettings.override_enlarge_on_click === 1);
            if (!overrideEnabled) {
                return false; // Skip processing when override is disabled
            }

            // CRITICAL: Skip individual processing if this container is part of a gallery with multiple WordPress lightbox images
            if ($parentGallery.length > 0) {
                var $galleryContainers = $parentGallery.find('.wp-lightbox-container[data-wp-interactive="core/image"], .wp-lightbox-container').has('button[data-wp-on-async--click="actions.showLightbox"]');
                if ($galleryContainers.length > 1) {
                    // This is part of a gallery - should be handled by gallery processing, not individual
                    return false;
                }
            }

            // Only process if:
            // - Figure doesn't have .ml-lightbox-enabled
            // - Figure isn't already initialized
            // - Either not in a gallery OR gallery isn't processed by us
            return !$figure.hasClass('ml-lightbox-enabled') &&
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

        // PRIORITY 3.5: Handle "Enlarge on Click" galleries when override is enabled
        var overrideEnabled = typeof mlLightboxSettings !== 'undefined' &&
            (mlLightboxSettings.override_enlarge_on_click === true ||
             mlLightboxSettings.override_enlarge_on_click === '1' ||
             mlLightboxSettings.override_enlarge_on_click === 1);

        if (overrideEnabled) {
            var $enlargeOnClickGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                var $wpButtons = $gallery.find('button[data-wp-on-async--click="actions.showLightbox"]');

                // Must have multiple WordPress lightbox buttons and not be processed yet
                return $wpButtons.length > 1 &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized');
            });

            $enlargeOnClickGalleries.each(function() {
                initEnlargeOnClickGallery($(this), useButtonsForManual);
            });
        }

        // PRIORITY 3.7: Handle "Link to Media File" galleries when override is enabled
        var linkOverrideEnabled = typeof mlLightboxSettings !== 'undefined' &&
            (mlLightboxSettings.override_link_to_image_file === true ||
             mlLightboxSettings.override_link_to_image_file === '1' ||
             mlLightboxSettings.override_link_to_image_file === 1);

        if (linkOverrideEnabled) {
            var $linkToMediaManualGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                var $mediaLinks = $gallery.find('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]').filter(function() {
                    return $(this).find('img').length > 0; // Must contain an image
                });

                // Must have multiple media links, not be processed yet, and not have WordPress lightbox or Enlarge on Click buttons
                return $mediaLinks.length > 1 &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized') &&
                       $gallery.find('.wp-lightbox-container').length === 0 && // No WordPress lightbox
                       $gallery.find('button[data-wp-on-async--click="actions.showLightbox"]').length === 0; // No Enlarge on Click buttons
            });

            $linkToMediaManualGalleries.each(function() {
                initLinkToMediaManualGallery($(this), useButtonsForManual);
            });
        }

        // PRIORITY 4: Handle "Link to Media File" galleries ONLY if enabled
        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.enable_galleries) {
            var $linkToMediaGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                var $mediaLinks = $gallery.find('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]').filter(function() {
                    return $(this).find('img').length > 0; // Must contain an image
                });

                // Must have multiple media links, not be processed yet, and not have WordPress lightbox enabled
                return $mediaLinks.length > 1 &&
                       !$gallery.hasClass('ml-lightbox-enabled') &&
                       !$gallery.hasClass('lg-initialized') &&
                       $gallery.find('.wp-lightbox-container').length === 0; // No WordPress lightbox enabled
            });

            $linkToMediaGalleries.each(function() {
                initLinkToMediaGallery($(this), useButtonsForManual);
            });
        }

        // PRIORITY 4.5: Handle plain image galleries (no links, no WordPress lightbox)
        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.enable_galleries) {
            var $plainImageGalleries = $('.wp-block-gallery').filter(function() {
                var $gallery = $(this);
                var $images = $gallery.find('img');
                var $mediaLinks = $gallery.find('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]');
                var $wpLightboxImages = $gallery.find('img[data-wp-on-async--click="actions.showLightbox"]');

                // Must have multiple images, no media links, no WordPress lightbox, and not be processed yet
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

        // PRIORITY 5: Handle individual "Link to Media File" images
        // Check if Link to Media File override is enabled
        if (mlLightboxSettings.override_link_to_image_file) {
            var $mediaLinks = $('a[href*=".jpg"], a[href*=".jpeg"], a[href*=".png"], a[href*=".gif"], a[href*=".webp"]').filter(function() {
                var $link = $(this);
                var $parentGallery = $link.closest('.wp-block-gallery');
                var $parentContainer = $link.closest('.wp-lightbox-container');

                // Must contain an image, not be in any gallery, not have WordPress lightbox, and not be processed yet
                return $link.find('img').length > 0 &&
                       $parentGallery.length === 0 && // Skip ALL gallery images - let gallery processing handle them
                       $parentContainer.length === 0 &&
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
    }

    /**
     * Initialize a single .ml-lightbox-enabled element
     */
    function initLightboxEnabled($container, useButtonMode) {
        useButtonMode = useButtonMode || false;

        // Skip if already initialized
        if ($container.hasClass('lg-initialized') || $container.data('lightgallery')) {
            return;
        }

        // Note: Manual .ml-lightbox-enabled elements should always work regardless of settings

        // First check for PHP-processed links with data-src or data-video (gallery case)
        var $imageLinks = $container.find('a[data-src], a[data-video]');

        // If no PHP-processed links, check for multiple images and handle as gallery
        if ($imageLinks.length === 0) {
            var $images = $container.find('img');

            // If multiple images, treat as manual gallery (like WordPress galleries)
            if ($images.length > 1) {
                // Create overlays/buttons for each image (similar to initWordPressGallery)
                $images.each(function() {
                    var $img = $(this);
                    var $figure = $img.closest('figure, div');
                    if ($figure.length === 0) $figure = $img.parent();

                    // Skip if already has overlay/button or is processed
                    if ($figure.find('.ml-image-overlay, .ml-lightbox-button').length > 0 || $figure.hasClass('lg-initialized')) {
                        return;
                    }

                    var src = $img.attr('src');
                    if (!src) return;

                    // Generate full-size URL
                    var fullUrl = removeWordPressSizeSuffix(src);

                    if (useButtonMode) {
                        // Create button for this image
                        var $button = $('<a href="#" class="ml-lightbox-button">Open in Lightbox</a>');
                        $button.attr({
                            'data-src': fullUrl,
                            'data-thumb': src
                        });
                        $figure.css('position', 'relative').append($button);
                    } else {
                        // Create overlay for this image
                        var $overlay = $('<a href="#" class="ml-image-overlay"></a>');
                        $overlay.attr({
                            'data-src': fullUrl,
                            'data-thumb': src
                        });
                        $figure.css('position', 'relative').prepend($overlay);
                    }
                });

                // Initialize LightGallery on the main container
                var gallerySettings = getLightboxSettings(true); // true = gallery mode
                gallerySettings.selector = useButtonMode ? '.ml-lightbox-button' : '.ml-image-overlay';

                try {
                    var instance = lightGallery($container[0], gallerySettings);
                    $container.addClass('lg-initialized');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing manual gallery:', error);
                }
                return;
            }

            // Single image case
            if (useButtonMode) {
                handleSingleElementWithButton($container);
            } else {
                handleSingleElement($container);
            }
            return;
        }

        // Determine if this is a gallery (multiple images) or single image
        var isGallery = $imageLinks.length > 1;


        // Get settings based on admin configuration
        var settings = getLightboxSettings(isGallery);

        // Initialize LightGallery
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
            // Direct img element with .ml-lightbox-enabled class
            var src = $element.attr('src');
            if (!src) return;

            // Create a wrapper container for LightGallery
            var $wrapper = $('<div class="ml-lightbox-wrapper"></div>');
            $element.wrap($wrapper);
            $target = $element.parent();

            var fullUrl = removeWordPressSizeSuffix(src);

            $target.attr({
                'data-src': fullUrl,
                'data-thumb': src
            });

            // Add caption if available
            var caption = extractCaption($element);
            if (caption) {
                $target.attr('data-sub-html', caption);
            }


        } else if ($element.is('a')) {
            // Link element with .ml-lightbox-enabled class
            var href = $element.attr('href');
            if (!href) return;

            $target = $element;
            var $img = $element.find('img').first();
            var imgSrc = $img.length ? $img.attr('src') : href;

            // Check if it's a video link
            isVideo = isVideoUrl(href);

            if (isVideo) {
                // For video links, use the URL directly - LightGallery will handle YouTube/Vimeo automatically
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

            // Add caption if available
            var caption = extractCaption($element);
            if (caption) {
                $target.attr('data-sub-html', caption);
            }

        } else {
            // Container element (div, figure, etc.) with .ml-lightbox-enabled class

            // First check for video content (YouTube/Vimeo iframes or video tags)
            var $iframe = $element.find('iframe[src*="youtube"], iframe[src*="vimeo"]').first();
            var $video = $element.find('video').first();

            if ($iframe.length > 0) {
                // YouTube or Vimeo iframe - use overlay approach
                var iframeSrc = $iframe.attr('src');

                // Create semi-transparent overlay
                var $overlay = $('<a href="#" class="ml-video-overlay"></a>');

                // For YouTube/Vimeo, use data-src with the video URL (LightGallery handles it automatically)
                var videoUrl = getVideoUrl(iframeSrc);
                $overlay.attr({
                    'data-src': videoUrl,
                    'data-thumb': getVideoThumbnail(iframeSrc)
                });

                // Add caption if available
                var caption = extractCaption($element);
                if (caption) {
                    $overlay.attr('data-sub-html', caption);
                }

                // Add overlay to video container (preserves iframe)
                $element.css('position', 'relative').prepend($overlay);
                $target = $element;

                isVideo = true;

            } else if ($video.length > 0) {
                // HTML5 video element - use overlay approach
                var videoSrc = $video.attr('src') || $video.find('source').first().attr('src');
                if (!videoSrc) return;

                var videoData = {
                    source: [{ src: videoSrc, type: getVideoType(videoSrc) }],
                    attributes: { preload: false, controls: true }
                };

                // Create semi-transparent overlay
                var $overlay = $('<a href="#" class="ml-video-overlay"></a>');

                // For HTML5 videos, use data-video JSON format (not data-src)
                $overlay.attr({
                    'data-src': '', // Empty for HTML5 videos
                    'data-video': JSON.stringify(videoData),
                    'data-thumb': $video.attr('poster') || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAwIiBoZWlnaHQ9IjMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxOCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPjQwMHgzMDA8L3RleHQ+PC9zdmc+'
                });

                // Add caption if available
                var caption = extractCaption($element);
                if (caption) {
                    $overlay.attr('data-sub-html', caption);
                }

                // Add overlay to video container (preserves video element)
                $element.css('position', 'relative').prepend($overlay);
                $target = $element;

                isVideo = true;

            } else {
                // Regular image container
                var $img = $element.find('img').first();
                if (!$img.length) return;

                var src = $img.attr('src');
                if (!src) return;

                var fullUrl = removeWordPressSizeSuffix(src);

                // Check if this is a WordPress lightbox container
                if ($element.hasClass('wp-lightbox-container')) {
                    // Use overlay approach for WordPress lightbox containers
                    var $overlay = $('<a href="#" class="ml-image-overlay"></a>');

                    $overlay.attr({
                        'data-src': fullUrl,
                        'data-thumb': src
                    });

                    // Add caption if available
                    var caption = extractCaption($element);
                    if (caption) {
                        $overlay.attr('data-sub-html', caption);
                    }

                    $element.css('position', 'relative').prepend($overlay);
                    $target = $element;

                } else {
                    // For non-WordPress containers, add data directly to the element
                    $target = $element;
                    $target.attr({
                        'data-src': fullUrl,
                        'data-thumb': src
                    });

                    // Add caption if available
                    var caption = extractCaption($element);
                    if (caption) {
                        $target.attr('data-sub-html', caption);
                    }

                }
            }
        }

        if (!$target) return;

        // Get settings for single image/video (not gallery)
        var settings = getLightboxSettings(false);

        if (isVideo && $target.find('.ml-video-overlay').length > 0) {
            // For videos with overlay, use overlay as selector
            settings.selector = '.ml-video-overlay';
        } else if (!isVideo && $target.find('.ml-image-overlay').length > 0) {
            // For WordPress images with overlay, use overlay as selector
            settings.selector = '.ml-image-overlay';
        } else {
            // For images or direct video links, target the element itself
            settings.selector = 'this';
        }

        // Initialize LightGallery
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
            // Direct img element with .ml-lightbox-enabled class
            var src = $element.attr('src');
            if (!src) return;

            var fullUrl = removeWordPressSizeSuffix(src);

            $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

            $button.attr({
                'data-src': fullUrl,
                'data-thumb': src
            });

            // Add caption if available
            var caption = extractCaption($element);
            if (caption) {
                $button.attr('data-sub-html', caption);
            }

            // Wrap the image in a positioned container and add button
            $element.wrap('<div style="position: relative; display: inline-block;"></div>');
            $element.parent().append($button);

            // Initialize LightGallery on the button
            var settings = getLightboxSettings(false);
            settings.selector = '.ml-lightbox-button';

            try {
                var instance = lightGallery($element.parent()[0], settings);
                $element.parent().addClass('lg-initialized');
            } catch (error) {
                console.error('MetaSlider Lightbox: Error initializing button mode for manual lightbox:', error);
            }

        } else if ($element.is('a')) {
            // Link element with .ml-lightbox-enabled class
            var href = $element.attr('href');
            if (!href) return;

            var $img = $element.find('img').first();
            var imgSrc = $img.length > 0 ? $img.attr('src') : '';

            var isVideo = isVideoUrl(href);

            if (isVideo) {
                var videoUrl = getVideoUrl(href);
                $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

                $button.attr({
                    'data-src': videoUrl,
                    'data-thumb': imgSrc || getVideoThumbnail(href)
                });
            } else {
                $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

                $button.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });
            }

            // Add caption if available
            var caption = extractCaption($element);
            if (caption) {
                $button.attr('data-sub-html', caption);
            }

            // Add button to the link element
            $element.css('position', 'relative').append($button);

            // Initialize LightGallery on the button
            var settings = getLightboxSettings(false);
            settings.selector = '.ml-lightbox-button';

            try {
                var instance = lightGallery($element[0], settings);
                $element.addClass('lg-initialized');
            } catch (error) {
                console.error('MetaSlider Lightbox: Error initializing button mode for manual lightbox:', error);
            }

        } else {
            // Container element with .ml-lightbox-enabled class - check for images or videos inside

            // Skip if button already exists (PHP-generated buttons)
            var $existingButton = $element.find('.ml-lightbox-button').first();
            if ($existingButton.length > 0) {
                // Initialize LightGallery on the existing button
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

                    $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

                    $button.attr({
                        'data-src': fullUrl,
                        'data-thumb': src
                    });
                }
            } else if ($iframe.length > 0) {
                var iframeSrc = $iframe.attr('src');
                if (iframeSrc) {
                    var videoUrl = getVideoUrl(iframeSrc);

                    $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

                    $button.attr({
                        'data-src': videoUrl,
                        'data-thumb': getVideoThumbnail(iframeSrc)
                    });
                }
            } else if ($video.length > 0) {
                var videoSrc = $video.attr('src') || $video.find('source').first().attr('src');
                if (videoSrc) {
                    $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

                    // Create video data in LightGallery format for HTML5 videos
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
                        'data-src': '', // Empty for HTML5 videos
                        'data-video': JSON.stringify(videoData),
                        'data-thumb': $video.attr('poster') || 'data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNDAwIiBoZWlnaHQ9IjMwMCIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj48cmVjdCB3aWR0aD0iMTAwJSIgaGVpZ2h0PSIxMDAlIiBmaWxsPSIjZGRkIi8+PHRleHQgeD0iNTAlIiB5PSI1MCUiIGZvbnQtZmFtaWx5PSJBcmlhbCIgZm9udC1zaXplPSIxOCIgZmlsbD0iIzk5OSIgdGV4dC1hbmNob3I9Im1pZGRsZSIgZHk9Ii4zZW0iPjQwMHgzMDA8L3RleHQ+PC9zdmc+'
                    });
                }
            }

            if ($button) {
                // Add caption if available
                var caption = extractCaption($element);
                if (caption) {
                    $button.attr('data-sub-html', caption);
                }

                $element.css('position', 'relative').append($button);

                // Initialize LightGallery on the button
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
            counter: isGallery, // Show counter only for galleries
            closable: true,
            closeOnTap: true,
            controls: !!metasliderOptions.show_arrows, // Behavior tab: Show arrows
            plugins: []
        };

        // Control caption display - if captions are disabled, tell LightGallery not to extract captions
        if (!shouldShowCaptions()) {
            settings.subHtml = '';  // Explicitly set empty caption selector
            settings.getCaptionFromTitleOrAlt = false;  // Disable using alt or title
        }

        // Add thumbnail plugin if enabled for galleries
        if (isGallery && metasliderOptions.show_thumbnails && typeof lgThumbnail !== 'undefined') {
            settings.plugins.push(lgThumbnail);
            settings.thumbWidth = 100;
            settings.thumbHeight = 80;
            settings.thumbMargin = 4;
            settings.exThumbImage = 'data-thumb';
        }

        // Add video plugin if available
        if (typeof lgVideo !== 'undefined') {
            settings.plugins.push(lgVideo);
            settings.videoMaxSize = '1280-720';
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

        // YouTube - convert embed URLs to watch URLs (LightGallery prefers this format)
        var youtubeMatch = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
        if (youtubeMatch) {
            return 'https://www.youtube.com/watch?v=' + youtubeMatch[1];
        }

        // Vimeo - convert player URLs to standard vimeo URLs
        var vimeoMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        if (vimeoMatch) {
            return 'https://vimeo.com/' + vimeoMatch[1];
        }

        // For other video formats, return as-is
        return url;
    }

    /**
     * Get video thumbnail URL
     */
    function getVideoThumbnail(url) {
        if (!url) return '';

        // YouTube thumbnail
        var youtubeMatch = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
        if (youtubeMatch) {
            return 'https://img.youtube.com/vi/' + youtubeMatch[1] + '/maxresdefault.jpg';
        }

        // Vimeo thumbnail
        var vimeoMatch = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
        if (vimeoMatch) {
            return 'https://vumbnail.com/' + vimeoMatch[1] + '.jpg';
        }

        // For HTML5 videos or other formats, return empty (will be handled by video poster or fallback)
        return '';
    }

    /**
     * Initialize a WordPress "Enlarge on Click" gallery
     */
    function initWordPressGallery($gallery) {
        // Find WordPress lightbox containers - both old and new formats
        var $wpImagesOld = $gallery.find('.wp-lightbox-container img[data-wp-on-async--click="actions.showLightbox"]');
        var $wpContainersNew = $gallery.find('.wp-lightbox-container[data-wp-interactive="core/image"]');

        // Combine both formats - get all WordPress lightbox containers
        var $allContainers = $();

        // Add old format containers
        $wpImagesOld.each(function() {
            $allContainers = $allContainers.add($(this).closest('.wp-lightbox-container'));
        });

        // Add new format containers
        $allContainers = $allContainers.add($wpContainersNew);

        if ($allContainers.length <= 1) return;

        // Add overlays to each container in the gallery (only if not already present)
        $allContainers.each(function() {
            var $figure = $(this);
            var $img = $figure.find('img').first();

            // Skip if this figure already has an overlay or is processed
            if ($figure.find('.ml-image-overlay').length > 0 || $figure.hasClass('lg-initialized')) {
                return;
            }

            var src = $img.attr('src');
            if (!src) return;

            // Generate full-size URL
            var fullUrl = removeWordPressSizeSuffix(src);

            // Create overlay for this image
            var $overlay = $('<a href="#" class="ml-image-overlay"></a>');

            $overlay.attr({
                'data-src': fullUrl,
                'data-thumb': src
            });

            $figure.css('position', 'relative').prepend($overlay);
        });

        // Initialize LightGallery on the main gallery container
        var gallerySettings = getLightboxSettings(true); // true = gallery mode
        gallerySettings.selector = '.ml-image-overlay';

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing WordPress gallery:', error);
        }
    }


    /**
     * Initialize a single WordPress "Enlarge on Click" image with overlay approach
     */
    function initWordPressEnlargeOnClick($img) {
        var $figure = $img.closest('.wp-lightbox-container');

        var src = $img.attr('src');
        if (!src) return;

        // Generate full-size URL by removing WordPress size suffix
        var fullUrl = removeWordPressSizeSuffix(src);

        // Create semi-transparent overlay (same approach as videos)
        var $overlay = $('<a href="#" class="ml-image-overlay"></a>');

        $overlay.attr({
            'data-src': fullUrl,
            'data-thumb': src
        });

        // Add caption if available
        var caption = extractCaption($img);
        if (caption) {
            $overlay.attr('data-sub-html', caption);
        }

        // Remove WordPress button and add overlay to figure container
        var $wpButton = $figure.find('button[data-wp-on-async--click="actions.showLightbox"]');
        $wpButton.remove();

        // Add overlay to figure container
        $figure.css('position', 'relative').prepend($overlay);

        // Get settings for single image
        var settings = getLightboxSettings(false);
        settings.selector = '.ml-image-overlay';

        // Initialize LightGallery on the figure container
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

        // Generate full-size URL by removing WordPress size suffix
        var fullUrl = removeWordPressSizeSuffix(src);

        // Create lightbox button
        var $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

        $button.attr({
            'data-src': fullUrl,
            'data-thumb': src
        });

        // Add caption if available
        var caption = extractCaption($img);
        if (caption) {
            $button.attr('data-sub-html', caption);
        }

        // Remove WordPress button and add our button
        var $wpButton = $figure.find('button[data-wp-on-async--click="actions.showLightbox"]');
        $wpButton.remove();

        // Add button to figure container
        $figure.css('position', 'relative').append($button);

        // Get settings for single image
        var settings = getLightboxSettings(false);
        settings.selector = '.ml-lightbox-button';

        // Initialize LightGallery on the figure container
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
            // Button mode - create lightbox buttons for each image
            $mediaLinks.each(function() {
                var $link = $(this);
                var href = $link.attr('href');
                var $img = $link.find('img').first();
                var imgSrc = $img.attr('src');

                if (!href || !imgSrc) return;

                // Create lightbox button
                var $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');
                $button.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });

                // Add caption if available
                var caption = extractCaption($img);
                if (caption) {
                    $button.attr('data-sub-html', caption);
                }

                // Add button to the link's parent container
                $link.parent().append($button);
            });

            // Initialize LightGallery on the main gallery container
            var gallerySettings = getLightboxSettings(true); // true = gallery mode
            gallerySettings.selector = '.ml-lightbox-button';

        } else {
            // Direct-click mode - add data attributes to existing links
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

                // Add caption if available
                var caption = extractCaption($img);
                if (caption) {
                    $link.attr('data-sub-html', caption);
                }
            });

            // Initialize LightGallery on the main gallery container
            var gallerySettings = getLightboxSettings(true); // true = gallery mode
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
            // Button mode: Add lightbox buttons to each image
            $images.each(function() {
                var $img = $(this);
                var imgSrc = $img.attr('src');

                if (!imgSrc) return;

                // Get full-size URL by removing WordPress size suffix
                var fullUrl = removeWordPressSizeSuffix(imgSrc);

                // Add data attributes to the image's figure container
                var $figure = $img.closest('.wp-block-image');
                if ($figure.length === 0) {
                    $figure = $img.parent();
                }

                $figure.attr({
                    'data-src': fullUrl,
                    'data-thumb': imgSrc
                });

                // Add caption if available
                var caption = extractCaption($img);
                if (caption) {
                    $figure.attr('data-sub-html', caption);
                }

                // Create and add the lightbox button
                var $button = $('<a href="#" class="ml-lightbox-button" data-src="' + fullUrl + '" data-thumb="' + imgSrc + '">Open in Lightbox</a>');
                if (caption) {
                    $button.attr('data-sub-html', caption);
                }

                $figure.css('position', 'relative').append($button);
            });

            // Initialize LightGallery on the main gallery container
            var gallerySettings = getLightboxSettings(true); // true = gallery mode
            gallerySettings.selector = '.ml-lightbox-button';

        } else {
            // Direct-click mode: Create wrapper links for each image
            $images.each(function() {
                var $img = $(this);
                var imgSrc = $img.attr('src');

                if (!imgSrc) return;

                // Get full-size URL by removing WordPress size suffix
                var fullUrl = removeWordPressSizeSuffix(imgSrc);

                // Wrap the image in a lightbox link
                var $wrapper = $('<a href="#" data-src="' + fullUrl + '" data-thumb="' + imgSrc + '"></a>');

                // Add caption if available
                var caption = extractCaption($img);
                if (caption) {
                    $wrapper.attr('data-sub-html', caption);
                }

                $img.wrap($wrapper);
            });

            // Initialize LightGallery on the main gallery container
            var gallerySettings = getLightboxSettings(true); // true = gallery mode
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


        // Add data attributes to the link
        $link.attr({
            'data-src': href,
            'data-thumb': imgSrc
        });

        // Add caption if available
        var caption = extractCaption($img);
        if (caption) {
            $link.attr('data-sub-html', caption);
        }

        // Get settings for single image
        var settings = getLightboxSettings(false);
        settings.selector = 'this';

        // Initialize LightGallery on the link itself
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

        // Create lightbox button
        var $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

        $button.attr({
            'data-src': href,
            'data-thumb': imgSrc
        });

        // Add caption if available
        var caption = extractCaption($img);
        if (caption) {
            $button.attr('data-sub-html', caption);
        }

        // Add button to the link element
        $link.css('position', 'relative').append($button);

        // Get settings for single image
        var settings = getLightboxSettings(false);
        settings.selector = '.ml-lightbox-button';

        // Initialize LightGallery on the button
        try {
            var instance = lightGallery($link[0], settings);
            $link.addClass('lg-initialized');

        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing "Link to Media File" button:', error);
        }
    }

    /**
     * Initialize MetaSlider lightbox functionality
     * Based on settings from ml-lightgallery-init.js but simplified for integration
     */
    function initMetaSlider() {
        // Find MetaSlider containers
        var $sliders = $('.metaslider, [class*="ml-slider-lightbox-"]');

        if ($sliders.length === 0) {
            return;
        }


        $sliders.each(function() {
            var $slider = $(this);

            // Skip if already initialized
            if ($slider.hasClass('lg-initialized')) {
                return;
            }

            // Try to extract slider ID from multiple sources
            var sliderId = $slider.attr('id');

            // If no ID on metaslider, try to find it from slide data or slider class
            if (!sliderId) {
                // Look for slide with data-date attribute or slide class that might contain ID
                var $firstSlide = $slider.find('li').first();
                var slideClass = $firstSlide.attr('class');
                if (slideClass) {
                    var classMatch = slideClass.match(/slider-(\d+)/);
                    if (classMatch) {
                        sliderId = classMatch[1];
                    }
                }
            }

            // Extract numeric ID if present
            if (sliderId) {
                var matches = sliderId.match(/(\d+)/);
                if (matches) {
                    sliderId = matches[1];
                }
            }


            // Check if lightbox is enabled for this slider (only if we have both settings and ID)
            if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.slider_settings && sliderId) {
                var sliderSettings = mlLightboxSettings.slider_settings[sliderId];
                // If slider settings exist, check if lightbox is enabled. If no settings, default to enabled.
                if (sliderSettings && sliderSettings.lightbox_enabled === false) {
                    return;
                }
            }

            // Get button mode setting
            var showButton = true;
            if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                showButton = mlLightboxSettings.metaslider_options.show_lightbox_button !== false;
            }

            if (showButton) {
                initMetaSliderButtonMode($slider);
            } else {
                initMetaSliderDirectMode($slider);
            }
        });
    }

    /**
     * Initialize MetaSlider in button mode
     */
    function initMetaSliderButtonMode($slider) {
        // Get all slides (exclude clones)
        var $slides = $slider.find('li').not('.clone');
        var buttonsAdded = 0;

        $slides.each(function() {
            var $slide = $(this);
            var $button = null;

            // Skip if button already exists
            if ($slide.find('.ml-lightbox-button').length > 0) {
                return;
            }

            // Handle different slide types
            if ($slide.hasClass('ms-youtube')) {
                $button = createYouTubeButton($slide);
            }
            else if ($slide.hasClass('ms-vimeo')) {
                $button = createVimeoButton($slide);
            }
            else if ($slide.hasClass('ms-external-video')) {
                $button = createExternalVideoButton($slide);
            }
            else if ($slide.hasClass('ms-local-video')) {
                $button = createLocalVideoButton($slide);
            }
            else if ($slide.hasClass('ms-postfeed')) {
                $button = createPostFeedButton($slide);
            }
            else if ($slide.hasClass('ms-layer')) {
                $button = createLayerButton($slide);
            }
            else if ($slide.hasClass('ms-custom-html')) {
                $button = createCustomHtmlButton($slide);
            }
            else {
                // Handle regular images
                $button = createImageButton($slide);
            }

            if ($button) {
                $slide.css('position', 'relative').append($button);
                buttonsAdded++;
            }
        });

        if (buttonsAdded > 0) {
            // Initialize LightGallery on slider with button selector
            var settings = getLightboxSettings(buttonsAdded > 1);
            settings.selector = '.ml-lightbox-button';

            try {
                lightGallery($slider[0], settings);
                $slider.addClass('lg-initialized');
            } catch (error) {
                console.error('MetaSlider Lightbox: Error initializing button mode:', error);
            }
        }
    }

    /**
     * Initialize MetaSlider in direct-click mode
     */
    function initMetaSliderDirectMode($slider) {

        // Get all slides (exclude clones)
        var $slides = $slider.find('li').not('.clone');
        var linksAdded = 0;

        $slides.each(function() {
            var $slide = $(this);

            // Skip if already processed
            if ($slide.find('.ml-metaslider-link, .ml-video-overlay').length > 0) return;

            var dataAttributes = null;

            // Handle different slide types
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
                // Handle regular images
                dataAttributes = getImageOverlayData($slide);
            }

            if (dataAttributes) {
                // Create overlay or modify existing link
                if (dataAttributes.overlay) {
                    // Create overlay for videos or images without links
                    var overlayClass = 'ml-video-overlay';
                    if ($slide.hasClass('ms-image')) {
                        overlayClass = 'ml-image-overlay';
                    }
                    var $overlay = $('<a href="#" class="' + overlayClass + '"></a>');
                    $overlay.attr(dataAttributes.attrs);
                    $slide.css('position', 'relative').prepend($overlay);
                } else {
                    // For images with existing links, modify the link
                    var $link = $slide.find('a').first();
                    if ($link.length > 0) {
                        $link.attr(dataAttributes.attrs).addClass('ml-metaslider-link');
                    }
                }
                linksAdded++;
            }
        });

        if (linksAdded > 0) {
            // Initialize LightGallery on slider with combined selector
            var settings = getLightboxSettings(linksAdded > 1);
            settings.selector = '.ml-metaslider-link, .ml-video-overlay, .ml-image-overlay';

            try {
                lightGallery($slider[0], settings);
                $slider.addClass('lg-initialized');
            } catch (error) {
                console.error('MetaSlider Lightbox: Error initializing direct-click mode:', error);
            }
        }
    }

    // Utility Functions

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

        // Try fallback selectors for complex cases like Vimeo
        if (!id && handler.fallbackSelectors) {
            for (var i = 0; i < handler.fallbackSelectors.length; i++) {
                var fallbackSelector = handler.fallbackSelectors[i];
                var $fallback = $slide.find(fallbackSelector);

                if (fallbackSelector.startsWith('a[href*=')) {
                    // Extract from URL
                    var url = $fallback.attr('href');
                    if (url) {
                        var match = url.match(/vimeo\.com\/(\d+)/);
                        if (match) {
                            id = match[1];
                            break;
                        }
                    }
                } else {
                    // Extract from attribute
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
                url: '', // Empty for HTML5 videos
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

        var $button = $('<a class="' + CONSTANTS.CLASSES.ML_LIGHTBOX_BUTTON + '" href="#">Open in Lightbox</a>');

        if (videoData.videoData) {
            // HTML5 video with JSON data
            $button.attr({
                'data-src': '', // Empty for HTML5 videos
                'data-video': JSON.stringify(videoData.videoData),
                'data-thumb': videoData.thumb
            });
        } else {
            // Regular video (YouTube, Vimeo)
            if (!videoData.url) return null;
            $button.attr({
                'data-src': videoData.url,
                'data-thumb': videoData.thumb
            });
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

        var $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

        $button.attr({
            'data-src': linkHref,
            'data-thumb': imgSrc
        });

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
        return 'video/mp4'; // Default
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
        var $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

        $button.attr({
            'data-src': imgSrc,
            'data-thumb': imgSrc
        });

        // Add caption if available
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

        // Add caption if available
        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: false, // Modify existing link (PostFeed always has links)
            attrs: attrs
        };
    }

    /**
     * Create lightbox button for Layer slides using iframe data URI approach
     */
    function createLayerButton($slide) {
        var slideContent = $slide.html();
        if (!slideContent || !slideContent.trim()) return null;

        // Scale positioning in slide content (2x to double positioning values only)
        var scaledSlideContent = slideContent.replace(/<div class="layer"[^>]*style="([^"]*)"[^>]*>/g, function(match, styleAttr) {
            var scaledStyle = styleAttr.replace(/(top|left|right|bottom):\s*(\d+(?:\.\d+)?)(px|%)/g, function(cssMatch, property, value, unit) {
                var numValue = parseFloat(value);
                var scaledValue = Math.round(numValue * 2);
                return property + ': ' + scaledValue + unit;
            });
            return match.replace(styleAttr, scaledStyle);
        });

        // Extract animation classes
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

        // Create iframe content with CSS
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

        var $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

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

        // Scale positioning in slide content (2x to double positioning values only)
        var scaledSlideContent = slideContent.replace(/<div class="layer"[^>]*style="([^"]*)"[^>]*>/g, function(match, styleAttr) {
            var scaledStyle = styleAttr.replace(/(top|left|right|bottom):\s*(\d+(?:\.\d+)?)(px|%)/g, function(cssMatch, property, value, unit) {
                var numValue = parseFloat(value);
                var scaledValue = Math.round(numValue * 2);
                return property + ': ' + scaledValue + unit;
            });
            return match.replace(styleAttr, scaledStyle);
        });

        // Extract animation classes
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

        // Create iframe content with CSS
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

        // Add caption if available
        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true, // Layer slides always need overlay for complex content
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
        return true; // Default to showing captions
    }

    /**
     * Extract caption from various sources for an image or container
     */
    function extractCaption($element) {
        if (!shouldShowCaptions()) {
            return '';
        }

        var caption = '';
        var $img = $element.is('img') ? $element : $element.find('img').first();

        // 1. Look for WordPress caption (figcaption)
        var $figcaption = $element.closest('figure').find('figcaption');
        if ($figcaption.length > 0) {
            caption = $figcaption.html();
        }

        // 2. Look for caption wrap (MetaSlider style)
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

        // 3. Look for alt or title attributes on image
        if (!caption && $img.length > 0) {
            caption = $img.attr('alt') || $img.attr('title') || '';
        }

        // 4. Look for existing data-sub-html attribute
        if (!caption) {
            caption = $element.attr('data-sub-html') || '';
        }

        return caption.trim();
    }

    // Direct-click mode overlay data functions

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

        // If no existing link, create overlay like videos do
        var needsOverlay = ($link.length === 0);

        var attrs = {
            'data-src': linkHref,
            'data-thumb': imgSrc
        };

        // Add caption if available
        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: needsOverlay, // Create overlay if no link exists
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

        // Add caption if available
        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true, // Create video overlay
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

        // Fallback methods for vimeo ID
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

        // Add caption if available
        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true, // Create video overlay
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
            'data-src': '', // Empty for external videos
            'data-video': JSON.stringify(videoData),
            'data-thumb': posterUrl
        };

        // Add caption if available
        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true, // Create video overlay
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
            'data-src': '', // Empty for local videos
            'data-video': JSON.stringify(videoData),
            'data-thumb': posterUrl
        };

        // Add caption if available
        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true, // Create video overlay
            attrs: attrs
        };
    }

    /**
     * Create lightbox button for Custom HTML slides using iframe data URI approach (similar to Layer slides)
     */
    function createCustomHtmlButton($slide) {
        var slideContent = $slide.html();
        if (!slideContent || !slideContent.trim()) return null;

        // Create iframe content with responsive CSS
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

        var $button = $('<a class="ml-lightbox-button" href="#">Open in Lightbox</a>');

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

        // Create iframe content with responsive CSS
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

        // Add caption if available
        var caption = extractCaption($slide);
        if (caption) {
            attrs['data-sub-html'] = caption;
        }

        return {
            overlay: true, // Custom HTML slides always need overlay for iframe content
            attrs: attrs
        };
    }

    /**
     * Initialize "Enlarge on Click" gallery - similar to initPlainImageGallery but for button-based galleries
     */
    function initEnlargeOnClickGallery($gallery, useButtonMode) {
        var $wpContainers = $gallery.find('.wp-lightbox-container').has('button[data-wp-on-async--click="actions.showLightbox"]');

        if ($wpContainers.length <= 1) return;

        if (useButtonMode) {
            // Button mode: Add lightbox buttons and data attributes to each container
            $wpContainers.each(function() {
                var $container = $(this);
                var $img = $container.find('img').first();
                var $wpButton = $container.find('button[data-wp-on-async--click="actions.showLightbox"]');

                if ($img.length && $wpButton.length) {
                    var imgSrc = $img.attr('src');
                    if (!imgSrc) return;

                    // Get full-size URL by removing WordPress size suffix
                    var fullUrl = removeWordPressSizeSuffix(imgSrc);

                    // Add data attributes to the container
                    $container.attr({
                        'data-src': fullUrl,
                        'data-thumb': imgSrc
                    });

                    // Add caption if available
                    var caption = extractCaption($img);
                    if (caption) {
                        $container.attr('data-sub-html', caption);
                    }

                    // Create and add the lightbox button
                    var $button = $('<a href="#" class="ml-lightbox-button" data-src="' + fullUrl + '" data-thumb="' + imgSrc + '">Open in Lightbox</a>');
                    if (caption) {
                        $button.attr('data-sub-html', caption);
                    }

                    // Remove WordPress button and add our button
                    $wpButton.remove();
                    $container.css('position', 'relative').append($button);
                }
            });

            // Initialize LightGallery on the main gallery container
            var gallerySettings = getLightboxSettings(true); // true = gallery mode
            gallerySettings.selector = '.ml-lightbox-button';

        } else {
            // Direct-click mode: Add data attributes to containers and create overlays
            $wpContainers.each(function() {
                var $container = $(this);
                var $img = $container.find('img').first();
                var $wpButton = $container.find('button[data-wp-on-async--click="actions.showLightbox"]');

                if ($img.length && $wpButton.length) {
                    var imgSrc = $img.attr('src');
                    if (!imgSrc) return;

                    // Get full-size URL by removing WordPress size suffix
                    var fullUrl = removeWordPressSizeSuffix(imgSrc);

                    // Create wrapper link around the image
                    var $wrapper = $('<a href="#" data-src="' + fullUrl + '" data-thumb="' + imgSrc + '"></a>');

                    // Add caption if available
                    var caption = extractCaption($img);
                    if (caption) {
                        $wrapper.attr('data-sub-html', caption);
                    }

                    // Wrap the image and remove WordPress button
                    $img.wrap($wrapper);
                    $wpButton.remove();
                }
            });

            // Initialize LightGallery on the main gallery container
            var gallerySettings = getLightboxSettings(true); // true = gallery mode
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
            // Button mode - create lightbox buttons for each image
            $mediaLinks.each(function() {
                var $link = $(this);
                var href = $link.attr('href');
                var $img = $link.find('img').first();
                var imgSrc = $img.attr('src');

                if (!href || !imgSrc) return;

                // Get the parent figure container
                var $container = $link.closest('.wp-block-image');
                if ($container.length === 0) {
                    $container = $link.parent();
                }

                // Add data attributes to the container
                $container.attr({
                    'data-src': href,
                    'data-thumb': imgSrc
                });

                // Add caption if available
                var caption = extractCaption($img);
                if (caption) {
                    $container.attr('data-sub-html', caption);
                }

                // Create and add the lightbox button
                var $button = $('<a href="#" class="ml-lightbox-button" data-src="' + href + '" data-thumb="' + imgSrc + '">Open in Lightbox</a>');
                if (caption) {
                    $button.attr('data-sub-html', caption);
                }

                $container.css('position', 'relative').append($button);
            });

            // Initialize LightGallery on the main gallery container
            var gallerySettings = getLightboxSettings(true); // true = gallery mode
            gallerySettings.selector = '.ml-lightbox-button';

        } else {
            // Direct-click mode - add data attributes to existing links
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

                // Add caption if available
                var caption = extractCaption($img);
                if (caption) {
                    $link.attr('data-sub-html', caption);
                }
            });

            // Initialize LightGallery on the main gallery container
            var gallerySettings = getLightboxSettings(true); // true = gallery mode
            gallerySettings.selector = 'a[data-src]';
        }

        try {
            var instance = lightGallery($gallery[0], gallerySettings);
            $gallery.addClass('lg-initialized');
        } catch (error) {
            console.error('MetaSlider Lightbox: Error initializing Link to Media File manual gallery:', error);
        }
    }

})(jQuery);