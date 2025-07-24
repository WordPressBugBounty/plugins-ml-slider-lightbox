/**
 * MetaSlider Lightbox - LightGallery.js Integration
 *
 * Initializes LightGallery.js for MetaSlider when built-in lightbox is enabled
 * Optimized for smooth fade transitions with consistent settings
 */
(function ($) {
    'use strict';

    var isInitializing = false;

    // Initialize when document is ready
    $(document).ready(function () {
        // Clean up duplicate auto-lightbox links in MetaSlider slides
        cleanupDuplicateLightboxLinks();
        initLightbox();
    });

    // Also initialize on AJAX complete for dynamic content
    $(document).ajaxComplete(function () {
        // Add a small delay to prevent multiple rapid calls
        setTimeout(function () {
            cleanupDuplicateLightboxLinks();
            initLightbox();
        }, 50);
    });

    /**
     * Clean up duplicate auto-lightbox links in MetaSlider slides
     * This removes ml-auto-lightbox links that are inside MetaSlider slides
     * since MetaSlider provides its own lightbox buttons
     */
    function cleanupDuplicateLightboxLinks() {
        // Find all MetaSlider slides that contain auto-lightbox links
        $('.ms-custom-html, .ms-external, .ms-folder, .ms-vimeo, .ms-youtube, .ms-local-video, .ms-external-video, .ms-postfeed, .ms-layer').each(function() {
            var $slide = $(this);
            
            // Remove any ml-auto-lightbox links inside this slide
            $slide.find('a.ml-auto-lightbox').each(function() {
                var $autoLink = $(this);
                var $img = $autoLink.find('img');
                
                // If there's an image inside the auto-lightbox link, unwrap it
                if ($img.length > 0) {
                    $autoLink.replaceWith($img);
                } else {
                    // If no image, just remove the link wrapper
                    $autoLink.replaceWith($autoLink.contents());
                }
            });
        });
        
        // Also check for any slides with both ml-auto-lightbox and ml-lightbox-button
        $('.ml-lightbox-slide').each(function() {
            var $slide = $(this);
            
            // If this slide has a lightbox button, remove any auto-lightbox links
            if ($slide.find('.ml-lightbox-button').length > 0) {
                $slide.find('a.ml-auto-lightbox').each(function() {
                    var $autoLink = $(this);
                    var $img = $autoLink.find('img');
                    
                    if ($img.length > 0) {
                        $autoLink.replaceWith($img);
                    } else {
                        $autoLink.replaceWith($autoLink.contents());
                    }
                });
            }
        });
    }

    /**
     * Get placeholder thumbnail image (SVG data URL)
     */
    function getPlaceholderThumb()
    {
        // Create a simple SVG placeholder with a camera icon
        var svgContent = '<svg xmlns="http://www.w3.org/2000/svg" width="100" height="80" viewBox="0 0 100 80" fill="none">' +
            '<rect width="100" height="80" fill="#f0f0f0" stroke="#ddd" stroke-width="1"/>' +
            '<path d="M30 25h40c2 0 4 2 4 4v22c0 2-2 4-4 4H30c-2 0-4-2-4-4V29c0-2 2-4 4-4z" fill="#ccc"/>' +
            '<circle cx="50" cy="40" r="8" fill="#999"/>' +
            '<circle cx="50" cy="40" r="5" fill="#666"/>' +
            '<rect x="65" y="30" width="4" height="3" rx="1" fill="#999"/>' +
            '<text x="50" y="65" text-anchor="middle" font-family="Arial, sans-serif" font-size="8" fill="#999">No Image</text>' +
            '</svg>';

        return 'data:image/svg+xml;base64,' + btoa(svgContent);
    }

    /**
     * Get common LightGallery 2.x settings
     */
    function getCommonLightboxSettings()
    {
        return {
            speed: 350,
            mode: 'lg-fade',
            easing: 'ease-in-out',
            hideBarsDelay: 2000,
            showBarsAfter: 100,
            backdropDuration: 300,
            loadYouTubeThumbnail: true,
            loadVimeoThumbnail: true,
            zoom: false,
            scale: 1,
            zoomFromOrigin: false,
            dynamic: false,
            swipeThreshold: 50,
            enableSwipe: true,
            enableDrag: true,
            preload: 2,
            allowMediaOverlap: true,
            animateThumb: true,
            toggleThumb: true,
            hideScrollbar: true,
            loop: true,
            escKey: true,
            controls: true,
            download: false,
            counter: true,
            mobileSettings: {
                controls: true,
                showCloseIcon: true,
                download: false
            }
        };
    }

    /**
     * Initialize lightbox functionality
     */
    function initLightbox()
    {
        // Prevent multiple simultaneous initializations
        if (isInitializing) {
            return;
        }
        isInitializing = true;

        // Check settings
        var detectGalleries = false;
        var detectStandaloneImages = false;
        var detectVideos = false;

        if (typeof mlLightboxSettings !== 'undefined') {
            detectGalleries = mlLightboxSettings.detect_all_galleries === true || mlLightboxSettings.detect_all_galleries === '1';
            detectStandaloneImages = mlLightboxSettings.detect_all_images === true || mlLightboxSettings.detect_all_images === '1';
            detectVideos = mlLightboxSettings.detect_all_videos === true || mlLightboxSettings.detect_all_videos === '1';
        }

        // Initialize MetaSlider lightbox
        initMetaSliderLightbox();

        // Initialize galleries
        if (detectGalleries) {
            initWordPressGalleries();
        }

        // Initialize standalone images
        if (detectStandaloneImages) {
            initStandaloneImageLightbox();
        }

        // Initialize videos
        if (detectVideos) {
            initAllVideos();
        }

        // Reset initialization flag
        isInitializing = false;
    }

    /**
     * Initialize WordPress galleries
     */
    function initWordPressGalleries()
    {
        $('.gallery, .wp-block-gallery, .tiled-gallery').each(function () {
            var $gallery = $(this);

            // Skip if already initialized
            if ($gallery.hasClass('lg-initialized')) {
                return;
            }

            // Check if this gallery is inside a MetaSlider container
            var $metaSliderContainer = $gallery.closest('.metaslider, [class*="ml-slider-lightbox-"]');
            if ($metaSliderContainer.length > 0) {
                // Get MetaSlider ID and check if lightbox is disabled
                var sliderId = $metaSliderContainer.attr('id');
                if (sliderId) {
                    var matches = sliderId.match(/(\d+)/);
                    if (matches) {
                        sliderId = matches[1];
                        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.slider_settings && sliderId) {
                            var sliderSettings = mlLightboxSettings.slider_settings[sliderId];
                            if (!sliderSettings || !sliderSettings.lightbox_enabled) {
                                return; // Skip if MetaSlider lightbox is disabled
                            }
                        }
                    }
                }
            }

            // Only initialize galleries that have been processed by PHP (have lightbox attributes)
            var $lightboxLinks = $gallery.find('a.ml-auto-lightbox, a[data-src]');

            if ($lightboxLinks.length > 0 && typeof lightGallery !== 'undefined') {
                try {
                    // Initialize LightGallery on the gallery container
                    var gallerySettings = getCommonLightboxSettings();
                    gallerySettings.selector = 'a.ml-auto-lightbox, a[data-src]';
                    gallerySettings.controls = true;
                    gallerySettings.counter = true;
                    gallerySettings.closable = true;
                    gallerySettings.closeOnTap = true;
                    gallerySettings.hideBarsDelay = 2000;

                    lightGallery($gallery[0], gallerySettings);


                    $gallery.addClass('lg-initialized');
                    $lightboxLinks.addClass('lg-initialized');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing gallery', error);
                }
            }
        });
    }

    /**
     * Initialize standalone images (not in galleries or sliders)
     */
    function initStandaloneImageLightbox()
    {
        // Find standalone images that aren't inside galleries or sliders and have been processed by PHP
        var $standaloneLinks = $('a.ml-auto-lightbox, a[data-src]').filter(function () {
            var $link = $(this);
            var href = $link.attr('href');

            // Must be an image
            if (!href || !isImageUrl(href)) {
                return false;
            }

            // Check if inside a MetaSlider container (exclude ALL MetaSlider content)
            var $metaSliderContainer = $link.closest('.metaslider, [class*="ml-slider-lightbox-"], [id*="metaslider_"], .ms-external, .ms-folder, .ms-vimeo, .ms-youtube, .ms-local-video, .ms-external-video, .ms-custom-html, .ms-postfeed, .ms-layer, .youtube, .vimeo, .local-video, .external-video');
            if ($metaSliderContainer.length > 0) {
                return false; // Always skip MetaSlider content - it has its own button handling
            }

            // Must not be inside a gallery/slider (excluding MetaSlider which we handled above)
            return $link.closest('.gallery, .wp-block-gallery, .tiled-gallery').length === 0;
        });

        if ($standaloneLinks.length > 0 && typeof lightGallery !== 'undefined') {
            $standaloneLinks.each(function () {
                var $link = $(this);

                // Skip if already initialized
                if ($link.hasClass('lg-initialized')) {
                    return;
                }

                try {
                    // Wrap in container and initialize
                    var $wrapper = $('<div class="ml-standalone-wrapper"></div>');
                    $link.wrap($wrapper);

                    var standaloneSettings = getCommonLightboxSettings();
                    standaloneSettings.selector = 'a';
                    standaloneSettings.loop = false;
                    standaloneSettings.controls = false;
                    standaloneSettings.counter = false;

                    lightGallery($link.parent()[0], standaloneSettings);

                    // Apply theme class if settings are available
                    if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.theme) {
                        $link.parent().on('lgAfterOpen', function () {
                            var $lgOuter = $('.lg-outer');
                            if ($lgOuter.length && mlLightboxSettings.theme !== 'default') {
                                $lgOuter.addClass('lg-' + mlLightboxSettings.theme + '-theme');
                            }
                        });
                    }

                    $link.addClass('lg-initialized');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing standalone image', error);
                }
            });
        }
    }

    /**
     * Detect Radix theme flex-pauseplay content type and add CSS class
     */
    function detectRadixPausePlayContent() {
        $('.metaslider.ms-theme-radix').each(function() {
            var $slider = $(this);
            var $flexPausePlay = $slider.find('.flex-pauseplay');
            
            if ($flexPausePlay.length > 0) {
                // Check the width of the flex-pauseplay a elements
                var $pausePlayLinks = $flexPausePlay.find('a');
                var isIconOnly = false;
                
                if ($pausePlayLinks.length > 0) {
                    // Check if any of the links has a width of 30px (icon-only mode)
                    $pausePlayLinks.each(function() {
                        var width = $(this).outerWidth();
                        if (width === 30) {
                            isIconOnly = true;
                            return false; // break out of each loop
                        }
                    });
                }
                
                if (isIconOnly) {
                    $slider.addClass('ml-radix-icon-only');
                } else {
                    $slider.addClass('ml-radix-with-text');
                }
            }
        });
    }

    /**
     * Initialize LightGallery for MetaSlider instances
     */
    function initMetaSliderLightbox()
    {
        // Detect Radix theme content type first
        detectRadixPausePlayContent();

        // Find all MetaSlider containers
        $('.metaslider, [class*="ml-slider-lightbox-"]').each(function () {
            var $slider = $(this);

            // Skip if already initialized
            if ($slider.hasClass('lg-initialized')) {
                return;
            }

            // Get slider ID from various possible attributes
            var sliderId = $slider.attr('id');
            if (sliderId) {
                // Extract numeric ID from string like "metaslider_123"
                var matches = sliderId.match(/(\d+)/);
                if (matches) {
                    sliderId = matches[1];
                }
            }

            // Check if lightbox is enabled for this specific slider
            if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.slider_settings && sliderId) {
                var sliderSettings = mlLightboxSettings.slider_settings[sliderId];
                if (!sliderSettings || !sliderSettings.lightbox_enabled) {
                    return; // Skip if lightbox is disabled for this slider
                }
            }

            // Initialize empty collection - we'll only use our button approach
            var $lightboxLinks = $();

            // Handle external images using ms-external class (exclude clones)
            var $externalImageSlides = $slider.find('.ms-external').not('.clone');
            if ($externalImageSlides.length > 0) {
                $externalImageSlides.each(function () {
                    var $slide = $(this);
                    var $img = $slide.find('img').first();

                    if ($img.length > 0) {
                        var imgSrc = $img.attr('src');

                        // Get MetaSlider lightbox settings
                        var showArrows = true;
                        var showThumbnails = false;

                        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                            showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                            showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                        }

                        // Get actual image dimensions for smooth loading
                        var actualWidth = $img.get(0).naturalWidth || $img.width() || $img.attr('width') || $img.data('width') || 1280;
                        var actualHeight = $img.get(0).naturalHeight || $img.height() || $img.attr('height') || $img.data('height') || 720;

                        // Add caption from img alt or title
                        var caption = $img.attr('alt') || $img.attr('title') || '';

                        // Create lightbox button using helper function
                        var $lightboxBtn = createLightboxButton(imgSrc, imgSrc, 'Open in Lightbox', {
                            showArrows: showArrows,
                            showThumbnails: showThumbnails,
                            slideType: 'external',
                            caption: caption ? '<p>' + escapeHtml(caption) + '</p>' : null,
                            lgSize: actualWidth + '-' + actualHeight,
                            dataThumb: imgSrc  // Add data-thumb for LightGallery 2.x
                        });

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }

            // Handle Vimeo video slides using ms-vimeo class (exclude clones)
            var $vimeoSlides = $slider.find('.ms-vimeo').not('.clone');

            if ($vimeoSlides.length > 0) {
                $vimeoSlides.each(function () {
                    var $slide = $(this);
                    var $vimeoDiv = $slide.find('div.vimeo[data-url*="vimeo.com"]');

                    if ($vimeoDiv.length > 0) {
                        var dataUrl = $vimeoDiv.attr('data-url');
                        var vimeoId = $vimeoDiv.attr('data-id');

                        if (!vimeoId) {
                            return;
                        }

                        // Get MetaSlider lightbox settings
                        var showArrows = true;
                        var showThumbnails = false;

                        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                            showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                            showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                        }

                        // For Vimeo, use the slide's existing image as fallback poster
                        var $slideImg = $slide.find('img').first();
                        var vimeoPoster = null;
                        if ($slideImg.length > 0) {
                            vimeoPoster = $slideImg.attr('src');
                        }

                        // Create lightbox button using helper function
                        // Note: Vimeo thumbnails plugin will automatically load thumbnails for Vimeo URLs
                        var $lightboxBtn = createLightboxButton('//vimeo.com/' + vimeoId, '//vimeo.com/' + vimeoId, 'Open in Lightbox', {
                            showArrows: showArrows,
                            showThumbnails: showThumbnails,
                            slideType: 'vimeo',
                            lgSize: '1280-720',
                            dataVideo: 'true',
                            poster: vimeoPoster,
                            // Don't set dataThumb - let Vimeo thumbnails plugin handle it automatically
                        });

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }

            // Handle YouTube video slides using ms-youtube class (exclude clones)
            var $youtubeSlides = $slider.find('.ms-youtube').not('.clone');

            if ($youtubeSlides.length > 0) {
                $youtubeSlides.each(function () {
                    var $slide = $(this);
                    var $youtubeDiv = $slide.find('div.youtube');

                    if ($youtubeDiv.length > 0) {
                        // Try to get video ID from data-id attribute first
                        var youtubeId = $youtubeDiv.attr('data-id');

                        // If no data-id, try to extract from data-url
                        if (!youtubeId) {
                            var dataUrl = $youtubeDiv.attr('data-url');
                            if (dataUrl) {
                                youtubeId = extractYouTubeId(dataUrl);
                            }
                        }

                        if (!youtubeId) {
                            return;
                        }

                        // Get MetaSlider lightbox settings
                        var showArrows = true;
                        var showThumbnails = false;

                        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                            showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                            showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                        }

                        // Add YouTube thumbnail poster image
                        var youtubePoster = 'https://img.youtube.com/vi/' + youtubeId + '/maxresdefault.jpg';

                        // Create lightbox button using helper function
                        var $lightboxBtn = createLightboxButton('//www.youtube.com/watch?v=' + youtubeId, '//www.youtube.com/watch?v=' + youtubeId, 'Open in Lightbox', {
                            showArrows: showArrows,
                            showThumbnails: showThumbnails,
                            slideType: 'youtube',
                            lgSize: '1280-720',
                            dataVideo: 'true',
                            poster: youtubePoster,
                            dataThumb: youtubePoster  // Add data-thumb for LightGallery 2.x
                        });

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }

            // Handle Local Video slides using ms-local-video class (exclude clones)
            var $localVideoSlides = $slider.find('.ms-local-video').not('.clone');

            if ($localVideoSlides.length > 0) {
                $localVideoSlides.each(function () {
                    var $slide = $(this);
                    var $videoDiv = $slide.find('div.local-video');

                    if ($videoDiv.length > 0) {
                        // Get video sources from data-sources attribute
                        var dataSources = $videoDiv.attr('data-sources');
                        var videoSources = [];
                        var videoUrl = null;

                        if (dataSources) {
                            try {
                                var sources = JSON.parse(dataSources);
                                if (sources && sources.length > 0) {
                                    // Use the first source as the primary video URL
                                    videoUrl = sources[0].src;

                                    // Prepare all sources for LightGallery
                                    videoSources = sources.map(function (source) {
                                        return {
                                            'src': source.src,
                                            'type': source.type || getVideoType(source.src)
                                        };
                                    });
                                }
                            } catch (e) {
                                console.error('MetaSlider Lightbox: Error parsing local video sources', e);
                            }
                        }

                        if (!videoUrl || videoSources.length === 0) {
                            return;
                        }

                        // Get MetaSlider lightbox settings
                        var showArrows = true;
                        var showThumbnails = false;

                        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                            showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                            showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                        }

                        // Get poster image if available
                        var posterUrl = $videoDiv.attr('data-poster');

                        // Create data-video JSON attribute with Video.js configuration
                        var videoData = {
                            'source': videoSources,
                            'attributes': {
                                'preload': false,
                                'controls': true
                            },
                            'videojs': true,
                            'videojsOptions': {
                                'fluid': true,
                                'responsive': true,
                                'playsinline': true,
                                'preload': 'none'
                            }
                        };

                        // Don't add poster to Video.js to avoid overlay issues

                        // Create lightbox button (special handling for local video)
                        var $lightboxBtn = $('<a class="ml-lightbox-button" data-lg-size="1280-720">Open in Lightbox</a>');
                        $lightboxBtn.attr('data-lightbox-arrows', showArrows ? 'true' : 'false');
                        $lightboxBtn.attr('data-lightbox-thumbnails', showThumbnails ? 'true' : 'false');
                        $lightboxBtn.attr('data-slide-type', 'local-video');
                        $lightboxBtn.attr('data-video', JSON.stringify(videoData));
                        if (posterUrl) {
                            $lightboxBtn.attr('data-poster', posterUrl);
                            $lightboxBtn.attr('data-thumb', posterUrl);  // Add data-thumb for thumbnails
                        } else {
                            $lightboxBtn.attr('data-thumb', getPlaceholderThumb());  // Use placeholder if no poster
                        }

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }

            // Handle External Video slides using ms-external-video class (exclude clones)
            var $externalVideoSlides = $slider.find('.ms-external-video').not('.clone');

            if ($externalVideoSlides.length > 0) {
                $externalVideoSlides.each(function () {
                    var $slide = $(this);
                    var $videoDiv = $slide.find('div.external-video');

                    if ($videoDiv.length > 0) {
                        // Get video sources from data-sources attribute
                        var dataSources = $videoDiv.attr('data-sources');
                        var videoSources = [];
                        var videoUrl = null;

                        if (dataSources) {
                            try {
                                var sources = JSON.parse(dataSources);
                                if (sources && sources.length > 0) {
                                    // Use the first source as the primary video URL
                                    videoUrl = sources[0].src;

                                    // Prepare all sources for LightGallery
                                    videoSources = sources.map(function (source) {
                                        return {
                                            'src': source.src,
                                            'type': source.type || getVideoType(source.src)
                                        };
                                    });
                                }
                            } catch (e) {
                                console.error('MetaSlider Lightbox: Error parsing video sources', e);
                            }
                        }

                        if (!videoUrl || videoSources.length === 0) {
                            return;
                        }

                        // Get MetaSlider lightbox settings
                        var showArrows = true;
                        var showThumbnails = false;

                        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                            showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                            showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                        }

                        // Get poster image if available
                        var posterUrl = $videoDiv.attr('data-poster');

                        // Create data-video JSON attribute with Video.js configuration
                        var videoData = {
                            'source': videoSources,
                            'attributes': {
                                'preload': false,
                                'controls': true
                            },
                            'videojs': true,
                            'videojsOptions': {
                                'fluid': true,
                                'responsive': true,
                                'playsinline': true,
                                'preload': 'none'
                            }
                        };

                        // Don't add poster to Video.js to avoid overlay issues

                        // Create lightbox button using helper function
                        // For videos with data-video attribute, don't set href/data-src to avoid conflicts
                        var $lightboxBtn = createLightboxButton('', '', 'Open in Lightbox', {
                            showArrows: showArrows,
                            showThumbnails: showThumbnails,
                            slideType: 'external-video',
                            lgSize: '1280-720',
                            dataVideo: JSON.stringify(videoData),
                            dataPoster: posterUrl,
                            dataThumb: posterUrl || getPlaceholderThumb()
                        });

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }

            // Handle Custom HTML slides using ms-custom-html class (exclude clones)
            var $customHtmlSlides = $slider.find('.ms-custom-html').not('.clone');

            if ($customHtmlSlides.length > 0) {
                $customHtmlSlides.each(function () {
                    var $slide = $(this);

                    // Get MetaSlider lightbox settings
                    var showArrows = true;
                    var showThumbnails = false;

                    if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                        showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                        showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                    }

                    // Get the current slide content for the lightbox
                    var slideContent = $slide.html();

                    // Create lightbox button with placeholder image using helper function
                    var placeholderSrc = getPlaceholderThumb();
                    var $lightboxBtn = createLightboxButton(placeholderSrc, placeholderSrc, 'Open in Lightbox', {
                        showArrows: showArrows,
                        showThumbnails: showThumbnails,
                        slideType: 'custom-html',
                        caption: slideContent,
                        lgSize: '1280-720',
                        dataThumb: placeholderSrc
                    });

                    // Insert button into slide
                    $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                    // Add to lightbox links
                    $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                });
            }

            // Handle Image Folder slides using ms-folder class (exclude clones)
            var $folderSlides = $slider.find('.ms-folder').not('.clone');

            if ($folderSlides.length > 0) {
                $folderSlides.each(function () {
                    var $slide = $(this);

                    // Get MetaSlider lightbox settings
                    var showArrows = true;
                    var showThumbnails = false;

                    if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                        showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                        showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                    }

                    // Find all images in the folder slide
                    var $images = $slide.find('img');

                    if ($images.length > 0) {
                        // Use the first image as representative for the folder
                        var $firstImg = $images.first();
                        var imgSrc = $firstImg.attr('src');
                        var imgAlt = $firstImg.attr('alt') || '';

                        // Get actual image dimensions for smooth loading
                        var actualWidth = $firstImg.get(0).naturalWidth || $firstImg.width() || $firstImg.attr('width') || $firstImg.data('width') || 1280;
                        var actualHeight = $firstImg.get(0).naturalHeight || $firstImg.height() || $firstImg.attr('height') || $firstImg.data('height') || 720;

                        // Create lightbox button using helper function
                        var $lightboxBtn = createLightboxButton(imgSrc, imgSrc, 'Open in Lightbox', {
                            showArrows: showArrows,
                            showThumbnails: showThumbnails,
                            slideType: 'image-folder',
                            caption: imgAlt ? '<p>' + escapeHtml(imgAlt) + '</p>' : null,
                            lgSize: actualWidth + '-' + actualHeight,
                            dataThumb: imgSrc  // Add data-thumb for LightGallery 2.x
                        });

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }

            // Handle Post Feed slides using ms-postfeed class (exclude clones)
            var $postFeedSlides = $slider.find('.ms-postfeed').not('.clone');

            if ($postFeedSlides.length > 0) {
                $postFeedSlides.each(function () {
                    var $slide = $(this);

                    // Get MetaSlider lightbox settings
                    var showArrows = true;
                    var showThumbnails = false;

                    if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                        showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                        showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                    }

                    // Find the main image in the post feed slide
                    var $img = $slide.find('img').first();

                    if ($img.length > 0) {
                        var imgSrc = $img.attr('src');

                        // Extract caption information from the post feed slide
                        var caption = '';
                        var $captionWrap = $slide.find('.caption-wrap .caption');

                        if ($captionWrap.length > 0) {
                            // Use the existing caption content
                            caption = $captionWrap.html();
                        } else {
                            // Fallback to image alt text
                            var imgAlt = $img.attr('alt') || '';
                            if (imgAlt) {
                                caption = '<p>' + escapeHtml(imgAlt) + '</p>';
                            }
                        }

                        // Get actual image dimensions for smooth loading
                        var actualWidth = $img.get(0).naturalWidth || $img.width() || $img.attr('width') || $img.data('width') || 1280;
                        var actualHeight = $img.get(0).naturalHeight || $img.height() || $img.attr('height') || $img.data('height') || 720;

                        // Create lightbox button using helper function
                        var $lightboxBtn = createLightboxButton(imgSrc, imgSrc, 'Open in Lightbox', {
                            showArrows: showArrows,
                            showThumbnails: showThumbnails,
                            slideType: 'post-feed',
                            caption: caption,
                            lgSize: actualWidth + '-' + actualHeight,
                            dataThumb: imgSrc  // Add data-thumb for LightGallery 2.x
                        });

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }

            // Handle Layer slides using ms-layer class (exclude clones)
            var $layerSlides = $slider.find('.ms-layer').not('.clone');

            if ($layerSlides.length > 0) {
                $layerSlides.each(function () {
                    var $slide = $(this);

                    // Get MetaSlider lightbox settings
                    var showArrows = true;
                    var showThumbnails = false;

                    if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                        showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                        showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                    }

                    // Find the background image in the layer slide
                    var $backgroundImg = $slide.find('img.msDefaultImage');

                    if ($backgroundImg.length > 0) {
                        var imgSrc = $backgroundImg.attr('src');
                        var imgAlt = $backgroundImg.attr('alt') || '';

                        // Get layer content from the overlay
                        var layerContent = '';
                        var $layerOverlay = $slide.find('.msHtmlOverlay');

                        if ($layerOverlay.length > 0) {
                            // Extract the text content from layers, preserving basic formatting
                            var $layers = $layerOverlay.find('.layer .content');
                            if ($layers.length > 0) {
                                var layerTexts = [];
                                $layers.each(function () {
                                    var $layer = $(this);
                                    var layerHtml = $layer.html();
                                    if (layerHtml && layerHtml.trim() !== '') {
                                        layerTexts.push('<div class="lightbox-layer-content">' + layerHtml + '</div>');
                                    }
                                });
                                if (layerTexts.length > 0) {
                                    layerContent = '<div class="lightbox-layer-wrapper">' + layerTexts.join('') + '</div>';
                                }
                            }
                        }

                        // Get actual image dimensions
                        var actualWidth = $backgroundImg.get(0).naturalWidth || $backgroundImg.width() || $backgroundImg.attr('width') || $backgroundImg.data('width') || 1280;
                        var actualHeight = $backgroundImg.get(0).naturalHeight || $backgroundImg.height() || $backgroundImg.attr('height') || 720;

                        // Combine image alt and layer content for caption
                        var captionHtml = '';
                        if (imgAlt) {
                            captionHtml += '<p>' + escapeHtml(imgAlt) + '</p>';
                        }
                        if (layerContent) {
                            captionHtml += layerContent;
                        }

                        // Create lightbox button using helper function
                        var $lightboxBtn = createLightboxButton(imgSrc, imgSrc, 'Open in Lightbox', {
                            showArrows: showArrows,
                            showThumbnails: showThumbnails,
                            slideType: 'layer',
                            caption: captionHtml || null,
                            lgSize: actualWidth + '-' + actualHeight,
                            dataThumb: imgSrc  // Add data-thumb for LightGallery 2.x
                        });

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }

            // Handle regular MetaSlider image slides (ms-image or regular slides)
            var $regularImageSlides = $slider.find('li').not('.clone').filter(function () {
                var $slide = $(this);
                // Skip if this slide is already handled by other slide types
                return !$slide.hasClass('ms-external') &&
                       !$slide.hasClass('ms-vimeo') &&
                       !$slide.hasClass('ms-youtube') &&
                       !$slide.hasClass('ms-external-video') &&
                       !$slide.hasClass('ms-local-video') &&
                       !$slide.hasClass('ms-custom-html') &&
                       !$slide.hasClass('ms-folder') &&
                       !$slide.hasClass('ms-postfeed') &&
                       !$slide.hasClass('ms-layer');
            });

            if ($regularImageSlides.length > 0) {
                $regularImageSlides.each(function () {
                    var $slide = $(this);
                    var $img = $slide.find('img').first();

                    if ($img.length > 0) {
                        var imgSrc = $img.attr('src');

                        // Get MetaSlider lightbox settings
                        var showArrows = true;
                        var showThumbnails = false;

                        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                            showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                            showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                        }

                        // Add caption from img alt or title
                        var caption = $img.attr('alt') || $img.attr('title') || '';
                        var captionHtml = caption ? '<p>' + escapeHtml(caption) + '</p>' : null;

                        // Get actual image dimensions for smooth loading
                        var actualWidth = $img.get(0).naturalWidth || $img.width() || $img.attr('width') || $img.data('width') || 1280;
                        var actualHeight = $img.get(0).naturalHeight || $img.height() || $img.attr('height') || $img.data('height') || 720;

                        // Create lightbox button using helper function
                        var $lightboxBtn = createLightboxButton(imgSrc, imgSrc, 'Open in Lightbox', {
                            showArrows: showArrows,
                            showThumbnails: showThumbnails,
                            slideType: 'image',
                            caption: captionHtml,
                            lgSize: actualWidth + '-' + actualHeight,
                            dataThumb: imgSrc
                        });

                        // Insert button into slide
                        $slide.addClass('ml-lightbox-slide').append($lightboxBtn);

                        // Add to lightbox links
                        $lightboxLinks = $lightboxLinks.add($lightboxBtn);
                    }
                });
            }



            // Only initialize if we have lightbox links
            if ($lightboxLinks.length > 0) {
                // Check if lightGallery is already initialized on this slider
                if ($slider.data('lightgallery') || $slider.hasClass('lg-initialized')) {
                    return;
                }

                // Check if lightGallery is available
                if (typeof lightGallery === 'undefined') {
                    console.error('MetaSlider Lightbox: lightGallery is not defined. Make sure the LightGallery library is loaded.');
                    return;
                }

                try {
                    var $firstSlide = $slider.find('a[data-src], a[data-video]').first();
                    var showArrows = true;
                    var showThumbnails = false;

                    if ($firstSlide.length > 0) {
                        showArrows = $firstSlide.attr('data-lightbox-arrows') !== 'false';
                        showThumbnails = $firstSlide.attr('data-lightbox-thumbnails') === 'true';
                    } else {
                        if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.metaslider_options) {
                            showArrows = mlLightboxSettings.metaslider_options.show_arrows !== false;
                            showThumbnails = mlLightboxSettings.metaslider_options.show_thumbnails === true;
                        }
                    }

                    var metaSliderSettings = getCommonLightboxSettings();
                    metaSliderSettings.selector = 'a[data-src]:not(.clone):not(.clone *), a[data-video]:not(.clone):not(.clone *)';
                    metaSliderSettings.hideBarsDelay = 6000;
                    metaSliderSettings.controls = showArrows;
                    metaSliderSettings.counter = true;

                    // Set enterprise license key from PHP settings
                    if (typeof mlLightboxSettings !== 'undefined' && mlLightboxSettings.license_key) {
                        metaSliderSettings.licenseKey = mlLightboxSettings.license_key;
                    }

                    metaSliderSettings.plugins = [];
                    if (typeof lgVideo !== 'undefined') {
                        metaSliderSettings.plugins.push(lgVideo);
                    }
                    // Check if Video.js is available and configure LightGallery to use it
                    if (typeof videojs !== 'undefined') {
                        metaSliderSettings.videojs = true;  // Enable Video.js in LightGallery
                        // Video.js library loaded - LightGallery configured to use Video.js
                    }
                    if (showThumbnails && typeof lgThumbnail !== 'undefined') {
                        metaSliderSettings.plugins.push(lgThumbnail);
                        metaSliderSettings.thumbnail = true;
                        metaSliderSettings.thumbWidth = 100;
                        metaSliderSettings.thumbHeight = "80px";
                        metaSliderSettings.thumbMargin = 5;
                        metaSliderSettings.exThumbImage = 'data-thumb';
                        metaSliderSettings.enableThumbDrag = true;
                        metaSliderSettings.enableThumbSwipe = true;
                        metaSliderSettings.toggleThumb = true;
                        metaSliderSettings.alignThumbnails = "middle";
                        metaSliderSettings.animateThumb = true;

                        // Keep thumbnails on one line
                        metaSliderSettings.thumbContHeight = 80;  // Fixed height to prevent wrapping
                        metaSliderSettings.thumbHeight = "80px";  // Keep consistent height

                        if (typeof lgVimeoThumbnail !== 'undefined') {
                            metaSliderSettings.plugins.push(lgVimeoThumbnail);
                            metaSliderSettings.loadVimeoThumbnail = true;
                        } else {
                            metaSliderSettings.loadVimeoThumbnail = false;
                        }
                        metaSliderSettings.loadYouTubeThumbnail = true;
                    } else {
                        metaSliderSettings.thumbnail = false;
                    }
                    metaSliderSettings.thumbImg = function (currentSlide, index) {
                        var slideElement;
                        var imgSrc;
                        if (typeof currentSlide === 'string') {
                            slideElement = document.querySelector(currentSlide);
                        } else if (currentSlide && currentSlide.slideElement) {
                            slideElement = currentSlide.slideElement;
                        } else if (currentSlide && currentSlide.el) {
                            slideElement = currentSlide.el;
                        } else if (currentSlide && currentSlide.tagName) {
                            slideElement = currentSlide;
                        } else {
                            return '';
                        }

                        var $slide = $(slideElement);

                        var poster = $slide.attr('data-poster');
                        var isVideo = $slide.attr('data-video') === 'true';
                        imgSrc = $slide.attr('data-src') || $slide.attr('href');


                        if (isVideo && poster) {
                            return poster;
                        } else if (imgSrc) {
                            return imgSrc;
                        } else {
                            return getPlaceholderThumb();
                        }
                    };
                    metaSliderSettings.prevHtml = '<span class="lg-icon lg-icon-prev" aria-label="Previous image"></span>';
                    metaSliderSettings.nextHtml = '<span class="lg-icon lg-icon-next" aria-label="Next image"></span>';
                    metaSliderSettings.aria = {
                        slideShow: 'MetaSlider image slideshow',
                        slide: 'Image {index} of {totalSlides}',
                        closeGallery: 'Close gallery'
                    };

                    var lgInstance = lightGallery($slider[0], metaSliderSettings);

                    $slider.addClass('lg-initialized');

                    if (showThumbnails) {
                        $slider.on('lgAfterOpen', function () {
                            setTimeout(function () {
                                $lightboxLinks.each(function (index) {
                                    var $link = $(this);
                                    var $thumbnail = $('.lg-thumb-item').eq(index);

                                    if ($link.attr('data-video') === 'true') {
                                        $thumbnail.attr('data-video', 'true');
                                        if ($link.attr('data-poster')) {
                                            $thumbnail.attr('data-poster', $link.attr('data-poster'));
                                        }
                                    }
                                });
                            }, 100);
                        });
                    }

                    $slider.addClass('lg-initialized');
                    $slider.addClass('ml-lightgallery-active');
                } catch (error) {
                    console.error('MetaSlider Lightbox: Error initializing LightGallery', error);
                }
            }
        });
    }

    /**
     * Check if URL is an image
     * @param {string} url
     * @returns {boolean}
     */
    function isImageUrl(url)
    {
        if (!url) {
            return false;
        }
        var imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'tiff'];
        var extension = url.split('.').pop().toLowerCase().split('?')[0].split('#')[0];
        return imageExtensions.indexOf(extension) !== -1;
    }

    /**
     * Extract YouTube video ID from URL
     * @param {string} url
     * @returns {string|null}
     */
    function extractYouTubeId(url)
    {
        if (!url) {
            return null;
        }

        // Match various YouTube URL formats
        var youtubeRegex = /^.*(youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/;
        var match = url.match(youtubeRegex);

        if (match && match[2] && match[2].length === 11) {
            return match[2];
        }

        return null;
    }

    /**
     * Get video MIME type from file extension
     * @param {string} url
     * @returns {string}
     */
    function getVideoType(url)
    {
        if (!url) {
            return 'video/mp4'; // Default fallback
        }

        var extension = url.split('.').pop().toLowerCase().split('?')[0].split('#')[0];

        switch (extension) {
            case 'mp4':
                return 'video/mp4';
            case 'webm':
                return 'video/webm';
            case 'ogg':
            case 'ogv':
                return 'video/ogg';
            case 'mov':
                return 'video/quicktime';
            case 'avi':
                return 'video/x-msvideo';
            case 'm4v':
                return 'video/mp4';
            default:
                return 'video/mp4'; // Default fallback
        }
    }

    /**
     * Escape HTML to prevent XSS
     * @param {string} text
     * @returns {string}
     */
    function escapeHtml(text)
    {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function (m) {
            return map[m]; });
    }

    /**
     * Create a lightbox button with CSS classes
     * @param {string} href The image/video URL
     * @param {string} dataSrc The data-src attribute value
     * @param {string} buttonText The button text
     * @param {object} options Additional options like slideType, caption, etc.
     * @returns {jQuery} The button element
     */
    function createLightboxButton(href, dataSrc, buttonText, options)
    {
        options = options || {};

        var $button = $('<a class="ml-lightbox-button" href="' + href + '" data-src="' + dataSrc + '">' + buttonText + '</a>');
        $button.attr({
            'role': 'button',
            'tabindex': '0',
            'aria-label': 'Open ' + (options.slideType || 'image') + ' in lightbox'
        });

        // Add lightbox settings
        if (options.showArrows !== undefined) {
            $button.attr('data-lightbox-arrows', options.showArrows ? 'true' : 'false');
        }
        if (options.showThumbnails !== undefined) {
            $button.attr('data-lightbox-thumbnails', options.showThumbnails ? 'true' : 'false');
        }
        if (options.slideType) {
            $button.attr('data-slide-type', options.slideType);
        }
        if (options.caption) {
            $button.attr('data-sub-html', options.caption);
        }
        if (options.lgSize) {
            $button.attr('data-lg-size', options.lgSize);
        }
        if (options.dataVideo) {
            $button.attr('data-video', options.dataVideo);
        }
        if (options.poster) {
            $button.attr('data-poster', options.poster);
        }
        if (options.dataThumb) {
            $button.attr('data-thumb', options.dataThumb);
        } else if (options.showThumbnails !== false) {
            // Use placeholder thumbnail if thumbnails are enabled but no specific thumb provided
            $button.attr('data-thumb', getPlaceholderThumb());
        }

        return $button;
    }

    /**
     * Reinitialize lightbox after MetaSlider updates
     */
    $(document).on('metaslider:after:resize metaslider:after:change', function (event, slider) {
        if (slider && slider.container) {
            var $container = $(slider.container);
            if ($container.hasClass('ml-builtin-lightbox') || ($container.attr('class') && $container.attr('class').indexOf('metaslider-built-in-lightbox') !== -1)) {
                // Properly destroy existing LightGallery instance
                if ($container.data('lightgallery')) {
                    $container.data('lightgallery').destroy();
                }

                // Remove existing initialization markers
                $container.removeClass('lg-initialized ml-lightgallery-active');

                // Reinitialize after a short delay
                setTimeout(function () {
                    initLightbox();
                }, 100);
            }
        }
    });

    /**
     * Initialize all videos detection
     */
    function initAllVideos()
    {
        // Find all video elements on the page (not inside MetaSlider)
        var $videos = $('iframe[src*="youtube.com"], iframe[src*="youtu.be"], iframe[src*="vimeo.com"], video')
            .not('.metaslider iframe, .metaslider video')
            .not('.ml-lightbox-processed');

        $videos.each(function () {
            var $video = $(this);
            var $container = $video.closest('div, p, figure, .wp-block-embed, .wp-block-video');

            // Skip if already processed
            if ($video.hasClass('ml-lightbox-processed') || $container.find('.ml-lightbox-button').length > 0) {
                return;
            }

            // Mark as processed
            $video.addClass('ml-lightbox-processed');

            // Get video URL
            var videoUrl = '';
            var videoType = '';
            var posterUrl = '';

            if ($video.is('iframe')) {
                var src = $video.attr('src');
                if (src.includes('youtube.com') || src.includes('youtu.be')) {
                    videoType = 'youtube';
                    // Extract YouTube ID
                    var youtubeId = src.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
                    if (youtubeId) {
                        videoUrl = 'https://www.youtube.com/watch?v=' + youtubeId[1];
                        posterUrl = 'https://img.youtube.com/vi/' + youtubeId[1] + '/maxresdefault.jpg';
                    }
                } else if (src.includes('vimeo.com')) {
                    videoType = 'vimeo';
                    // Extract Vimeo ID
                    var vimeoId = src.match(/vimeo\.com\/(?:video\/)?(\d+)/);
                    if (vimeoId) {
                        videoUrl = 'https://vimeo.com/' + vimeoId[1];
                    }
                }
            } else if ($video.is('video')) {
                videoType = 'external-video';
                videoUrl = $video.attr('src') || $video.find('source').first().attr('src');
                posterUrl = $video.attr('poster');
            }

            if (videoUrl) {
                // Create lightbox button
                var $button = $('<a class="ml-lightbox-button ml-auto-lightbox" title="Open in Lightbox">Open in Lightbox</a>');

                // Add common lightbox attributes
                $button.attr('data-lg-size', '1280-720');
                $button.attr('data-lightbox-arrows', 'true');
                $button.attr('data-lightbox-thumbnails', 'true');
                $button.attr('data-slide-type', videoType);

                if (videoType === 'external-video') {
                    // For external videos (MP4, etc.), use data-video JSON format
                    var videoMimeType = 'video/mp4';
                    if (videoUrl.includes('.webm')) {
                        videoMimeType = 'video/webm';
                    } else if (videoUrl.includes('.ogg')) {
                        videoMimeType = 'video/ogg';
                    } else if (videoUrl.includes('.mov')) {
                        videoMimeType = 'video/quicktime';
                    }

                    var videoData = {
                        source: [{
                            src: videoUrl,
                            type: videoMimeType
                        }],
                        attributes: {
                            preload: false,
                            controls: true
                        }
                    };

                    $button.attr('data-video', JSON.stringify(videoData));

                    if (posterUrl) {
                        $button.attr('data-poster', posterUrl);
                    }
                } else {
                    // For YouTube/Vimeo, use standard format
                    $button.attr('href', videoUrl);
                    $button.attr('data-src', videoUrl);
                    $button.attr('data-video', 'true');

                    if (posterUrl) {
                        $button.attr('data-poster', posterUrl);
                    }
                }

                // Position container relatively (CSS class will handle button styling)
                $container.css('position', 'relative');

                // Add button to container
                $container.append($button);

                // Initialize lightbox on the button
                if (typeof lightGallery !== 'undefined') {
                    var settings = getCommonLightboxSettings();
                    settings.selector = 'a.ml-auto-lightbox';
                    settings.plugins = [
                        ...(typeof lgVideo !== 'undefined' ? [lgVideo] : [])
                    ];

                    lightGallery($container[0], settings);
                }
            }
        });
    }

})(jQuery);