The format is based on [Keep a Changelog](http://keepachangelog.com/)
and this project adheres to [Semantic Versioning](http://semver.org/).

[2.35.0] - 29 Jul, 2026

- ADDED: Live preview for the gallery editor with a Preview / Arrange toggle and a Desktop / Mobile viewport switch, #455;
- ADDED: Per-gallery Height control for the Grid and Justified layouts, #524;
- ADDED: Caption Content source selector to pull captions from the manual field, the media caption or the media description, #563;
- ADDED: Convert an existing MetaSlider slideshow into a gallery, #569;
- CHANGED: Reorganized the gallery editor sidebar into a single-open accordion, #412;
- CHANGED: Added front-end-matching icons to every gallery editor setting, #501;
- CHANGED: Redesigned the caption editor with a navigable single-image modal and a per-thumbnail caption bar, #382;
- CHANGED: Consolidated the lightbox-trigger toggles into a single "how visitors open images" control, #499;
- CHANGED: Renamed the Settings submenu to "Global Settings" and brought button, icon and progress-bar appearance options onto each gallery, #536;
- CHANGED: Redesigned the welcome flow with a new empty-state panel, #535, #502;
- CHANGED: Made the "No images added yet" area clickable to open the media library, #541;

[2.34.0] - 8 Jul, 2026

- ADDED: Per-gallery image styling — filters, rounded corners, border, shadow, opacity, and rotate/flip, #510;
- CHANGED: Image Styles filters now also apply to the lightbox image and thumbnail strip, #523;
- CHANGED: Updated the toolbar icons to match MetaSlider and added a New Gallery button, #375;
- CHANGED: Justified galleries now stretch the final row to fill the width, #399;
- FIXED: Fullscreen did not work with the carousel layout, #425, #431;
- FIXED: Keep the Gallery admin menu next to MetaSlider when other plugins insert between them, #379;
- FIXED: Small translation issues, including untranslatable gallery close and navigation labels, #507, #383;

[2.33.0] - 25 Jun, 2026

- ADDED: Dedicated "Upgrade to Pro" page, #400;
- ADDED: Pro upgrade prompts within the Gallery interface, #468;
- ADDED: More per-gallery customization for the gallery window, including "Open in Gallery" button, icon and text options, #467;
- ADDED: More ways to add images, including upload, media library, server folder and ZIP import, #454;
- ADDED: Option to keep thumbnail navigation a consistent height, #398;
- ADDED: Left and right margin settings, #347;
- ADDED: Completed translations for all 28 languages, #452;
- CHANGED: Rearranged and reorganized the gallery settings panel, #476;
- CHANGED: Improved caption styling with new customization options, #413;
- CHANGED: Apply caption text, color and background styling to gallery thumbnails and the gallery window, #493;
- CHANGED: Hide related settings when "Show in Gallery Window" is disabled, #465;
- CHANGED: Clarified the confusing Include / Exclude settings, #438;
- CHANGED: Updated the Autoplay, Autoplay Slide Interval and Autoplay Progress Bar Color settings labels so it's clear they're connected, #491;
- CHANGED: Include AI translation updates in the release workflow, #453;
- FIXED: Gallery preview now matches the frontend layout, #503;
- FIXED: Some settings did not show or hide correctly for the Showcase layout, #482;
- FIXED: "Columns (mobile)" setting was ignored and fell back to the desktop Columns value, #396;
- FIXED: Copy shortcode did not work on non-HTTPS sites, #463;

[2.32.3] - 10 Jun, 2026

- FIXED: Gallery image clickthrough when Open in Gallery button is enabled, #466;
- CHANGED: Gallery Pro links, #469;

[2.32.2] - 8 Jun, 2026

- ADDED: Disable Gallery Window Setting, #411;
- FIXED: Gallery images doesn't follow Image Size Setting, #460;
- FIXED: "MetaSlider Gallery" category blocks lightbox, #442;
- FIXED: Authors can see the Gallery menu link, #451;

[2.32.1] - 2 Jun, 2026

- ADDED: Allow users to customize the thumbnail border and hover color, #374;
- FIXED: Sanitize image captions before rendering to prevent XSS via lightGallery data-sub-html, #388;
- FIXED: Require manage_options capability in gallery duplication handler to prevent authorization bypass, #389, #392;
- FIXED: Align REST gallery preview endpoint permission with gallery management policy, #390;
- FIXED: Remove lightGallery license key from named JS global to prevent exposure to site visitors, #393;

[2.32.0] - 28 May, 2026

- ADDED: Allow users to choose the color for the progress bar, #421;
- CHANGED: Update settings and UI text from "lightbox" to "gallery", #391;
- CHANGED: Rename "Rotate" feature to "Rotate and Flip" to reflect available actions, #403;
- CHANGED: Reorganize settings panel between Free and Pro features, #401;
- CHANGED: Update Pro feature description tooltips, #404;
- FIXED: Captions missing in MetaSlider slideshow when "Open in Gallery" button is enabled, #380, #409;
- FIXED: Pro features cannot be disabled from settings, #402;
- FIXED: Content filtering settings incorrectly affecting gallery display, #415;

[2.30.0] - 14 May, 2026

- ADDED: Gutenberg block for inserting galleries in the block editor, #310;
- ADDED: Showcase layout, #223;
- ADDED: Allow users to choose the image size displayed in the gallery window, #249;
- ADDED: Per-slideshow gallery window settings, #361;
- ADDED: Usage column on the gallery list showing which posts and pages embed each gallery, #363;
- ADDED: Click to copy shortcode on the gallery list and editor pages, #323;
- ADDED: Duplicate button in the gallery editor toolbar, #324;
- ADDED: Edit link in the WordPress admin toolbar for galleries, #321;
- CHANGED: Remove the word "Lightbox" from all user-facing labels and descriptions, #346;
- CHANGED: Replace "lightbox" with "gallery" in MetaSlider slideshow settings panel, #362;
- CHANGED: Update plugin description and free/pro naming to MetaSlider Gallery, #348;
- CHANGED: Translation updates for Dutch, Polish, Portuguese, #354;
- CHANGED: Translation updates for Japanese, #353;
- CHANGED: Translation updates for Spanish, #350;
- CHANGED: Translation updates for ES, FR, IT, #344;
- CHANGED: Add missing translation strings across all supported languages, #358;
- CHANGED: Update readme.txt on WordPress.org, #352;
- CHANGED: Plugin icon updated, #345;

[2.23.0] - 28 Apr, 2026

- ADDED: Create a basic post type for MS Galleries, #308;
- ADDED: Allow users to enable or disable the gallery window on individual slides, #298;
- ADDED: Offer an icon instead of "Open in gallery window", #299;
- ADDED: Allow users to customize "Open in gallery window", #311;
- FIXED: gallery window icon doesn't work with custom color schemes, #295;
- FIXED: Featured image option doesn't work correctly, #300;
- FIXED: Autoplay description text, #297;
- FIXED: Layout labels are too long sometimes, #326;
- CHANGED: Plugin name updated to MetaSlider Gallery, #312;
- CHANGED: Plan for menus and settings, #313;
- CHANGED: Rename Hash URLs to Unique Image URLs, #282;
- CHANGED: Clarify width and height settings, #294;
- CHANGED: Change the default settings for "Open in gallery window", #320;
- CHANGED: Move title field to the left, #319;
- CHANGED: Make the gallery title field required, #322;
- CHANGED: Tooltips, #318;
- CHANGED: Translation updates for ES, FR, IT, #293;
- CHANGED: Translate into more languages, #307;
- CHANGED: ES-FR-IT translation updates for Metaslider-gallery window FREE and PRO on weblate, #327;

[2.22.0] - 28 Jan, 2026

- FIXED: Fix toolbar icon CSS selector affecting share dropdown icons, #261;
- FIXED: Include the MetaSlider logo, #283;
- CHANGED: Hash URLs, #282;
- CHANGED: Move sidebar setting to a new panel, #285;

[2.21.0] - 20 Jan, 2026

- ADDED: Test for accessibility, #1245;
- ADDED: Store plugin version and path in db, #264;
- ADDED: Pro Ads/Tab on Free, #262;
- ADDED: Pro Features promos, #263;
- FIXED: Italian, Spanish and French translation updates, #258;
- FIXED: Add Recursion Guard, #268;
- FIXED: Duplicate images, #269;
- FIXED: Dealing with small images, #236;
- FIXED: gallery window still being applied to a slideshow even if not enabled, #260;
- FIXED: Disable gallery window in thumbnails navigation, #191;
- FIXED: First Carousel Slide Shows Cropped Image in gallery window, #270;
- FIXED: Check Woocommerce products/gallery, automatic mode doesn't seem to work, #266;
- FIXED: Cursor is not a "mouse hand" for images below a gallery, #208;

[2.20.0] - 20 Nov, 2025

- ADDED: Detect Pro Version, #242;
- FIXED: Italian, Spanish and French translation updates, #241;
- FIXED: Allow users to change icon colors, #252;
- CHANGED: Match the MetaSlider settings, #179;
- CHANGED: Text updates for Behavior tab, #238;
- CHANGED: Text updates for Appearance tab, #237;
- CHANGED: Allow users to show the images in a larger size?, #214;
- REMOVED: Close Button Position Setting, #248;

[2.12.0] - 30 Oct, 2025

- ADDED: Redirect on install, #222;
- ADDED: Skip over items inserted in the page, #213;
- FIXED: gallery window appears on WooCommerce products even when disabled, #220;
- FIXED: Enlarge on click instructions are not correct, #216;
- CHANGED: Update Appearance Tab, #219, #218, #217;
- CHANGED: Automatic Mode/Manual Mode, #221;

[2.11.1] - 16 Oct, 2025

- CHANGED: Plugin Version, #210;

[2.11.0] - 16 Oct, 2025

- ADDED: Allow users to move the "Close" button and "Open in gallery window" button, #183;
- ADDED: Add Ability to Change "Open in gallery window" text in button, #127;
- ADDED: Allow users to switch to include or exclude, #174;
- FIXED: Post type exclusions not finding all post types, #196;
- CHANGED: Description accuracy, #188;
- CHANGED: Include the MetaSlider logo, #170;
- CHANGED: Add padding to right of settings area, #173;
- REMOVED: Translating screenshots, #184;

[2.10.0] - 29 Sep, 2025

ADDED: Different settings for different post types, #121;
ADDED: Add an option to hide or show slide Image Title Text, #132;
ADDED: Add ability to use MetaSlider slide to show gallery window instead of button, #128;
ADDED: Add settings for manual options, #155;
ADDED: Add settings for button text colors, #162;
CHANGED: Behavior Tab should be for all options (manual and automatic), #152;
FIXED: Custom HTML: Images are zoomed in gallery window when using mobile portrait, #104;
FIXED: Layer Slides: Open in gallery window button overlaps with Play / Pause button, #119;
FIXED: Layer Slides: Text layers are too big when on mobile (portrait and landscape), #105;
FIXED: gallery window not working on content-based videos, #157;
FIXED: Menu conflict when Firelight gallery window is installed, #142;
FIXED: Vimeo with lazyload enabled, #83;

[2.0.0] - 24 Jul, 2025

- ADDED: LightGallery.js implementation with built-in gallery window
  functionality, #70;
- ADDED: Admin settings page with color customization options, #49;
- ADDED: Thumbnail navigation toggle, #58;
- ADDED: Video support for local and external videos with Video.js
  integration, #85;
- ADDED: WordPress "Enlarge on click" override functionality, #50;
- ADDED: CSS per MetaSlider theme for better mobile compatibility, #77;
- ADDED: Tab focus accessibility on "Open in gallery window" buttons, #86;
- ADDED: Icon hover color picker option, #114;
- FIXED: gallery window animation smoothness when opening/closing, #41;
- FIXED: Arrows and close button visibility on mobile/tablet, #43;
- FIXED: Thumbnail navigation overlapping main image, #58;
- FIXED: Slide images being always linked, #62;
- FIXED: Clear button alignment issues, #72;
- FIXED: Color picker functionality without MetaSlider installed, #75;
- FIXED: PHPCS errors and security issues, #89;
- FIXED: External video loading issues, #107;
- FIXED: Post feed "Open in gallery window" button clickability on mobile,
  #106;
- FIXED: Custom HTML slide display issues, #101;
- FIXED: Layer slide text sizing on mobile, #105;
- FIXED: MetaSlider theme compatibility (Radix, Databold, Highway), #91,
   #109;
- FIXED: Close button display on desktop, #45;
- FIXED: Unable to save disabled "Open in gallery window" setting, #42;
- CHANGED: Made gallery window addition automatic for better UX, #38;
- CHANGED: UI improvements and dropdown labels, #60;
- CHANGED: Menu and save button design consistency, #113;

[1.13.4] - 14 Mar, 2025

- CHANGED: Remove discontinued gallery window plugins on description list;

[1.13.3] - 13 Mar, 2025

- CHANGED: Remove active installation stats on readme;

[1.13.2] - 10 Jul, 2024

- ADDED: metaslider_admin_notices hook, #26;

[1.13.1] - 12 Oct, 2021

- FIXED: gallery window setting is broken if using MetaSlider v3.27.13, #18;
- CHANGED: Remove Extendify library;

[1.13.0] - 13 Jul, 2021

- CHANGED: Update the library.

[1.12.2] - 7 Jul, 2021

- FIXED: Bug fixes.
- ADDED: Adds option to disable the library.

[1.12.1] - 17 May, 2021

- FIXED: Bug fixes.

[1.12.0] - 28 Apr, 2021

- ADDED: Adds access to the Extendify template and pattern library.

[1.11.3] - 22 Aug, 2020

- CHANGED: Updates readme and team account info.

[1.11.2] - 09 Apr, 2020

- CHANGED: De-prioritizes recommendation for Responsive gallery window by dFactory due to inactivity.

[1.11.1] - 14 Aug, 2019

- FIXED: Fixes issue where the setting wouldn't save properly.

[1.11.0] - 8 Jul, 2019

- ADDED: Adds support for Gallery Manager Pro.

[1.10.4] - 30 Apr, 2019

- CHANGED: Adds unique class name to admin notices.

[1.10.3] - 04 Jan, 2019

- FIXED: Fixes a bug where WP-Featherlight would not load as a gallery.

[1.10.2] - 14 Jul, 2018

- CHANGED: Updates settings page for WP gallery window 2 to match their update.

[1.10.1] - 16 Mar, 2018

- FIXED: Updates how gallery window plugins are checked for activation.
- FIXED: Addresses a bug that checks for previous slider settings.
- FIXED: Removes an incompatible gallery window plugin (duplicate name).

[1.10.0] - 16 Mar, 2018

- ADDED: Adds support for additional gallery window plugins.
- CHANGED: Refactors gallery window to clean up attribute function.
- CHANGED: Refactors various parts of the code to extract supported plugin data.
- CHANGED: Extracts the class MetaSlidergallery windowPlugin to its own file.
- CHANGED: Changes the logic for check if the plugin is install and active.
- CHANGED: Adds a CSS class to the container that identifies the active gallery window plugin.
- CHANGED: Adds filters to let users manipulate the plugin use.
- CHANGED: Refactors gallery window to clean up attribute function.

[1.9.3] - 14 Nov, 2018

- CHANGED: Fix checks to slide URL.
- FIXED: FooBox Pro compatibility update.
- FIXED: Updates the FooBox Profile name.
- FIXED: Update Gallery Manager plugin settings.

[1.9.2] - 26 Jan, 2018

- CHANGED: Update translation strings.
- CHANGED: Adds warning message when no gallery window is active.

[1.9.0] - 28 Mar, 2017

- FIXED: Simple gallery window use slide caption instead of attachment caption.

[1.8.0] - 16 Mar, 2017

- FIXED: Update slide image URL to comply with new slide post type.

[1.7.0] - 09 May, 2016

- FIXED: Removes defunct gallery window Plus plugin link (thanks to @Hendrik57).

[1.6.0] - 01 Apr, 2015

- ADDED: Adds support for FooBox Image gallery window and WP gallery window 2 *Pro* versions.

[1.5.0] - 30 Jan, 2015

- ADDED: Adds support for FooBox Image gallery window and Responsive gallery window by dFactory.

[1.4.0] - 15 Dec, 2014

- FIXED: Hides dependency warning in admin if WP Video gallery window is activated (reported by and thanks to: vfontj).

[1.3.0] - 28 Oct, 2014

- ADDED: Adds support for Fancy Gallery gallery window plugin (suggested by and thanks to: Zim1).

[1.2.0] - 17 Sep, 2014

- ADDED: Support for additional gallery window plugins.

[1.1.0] - 22 Aug, 2014

- FIXED: Array assignment compatibility PHP < v5.4 (reported by and thanks to: andrea_montuori).

[1.0.0] - 15 Aug, 2014

- ADDED: Initial version.
