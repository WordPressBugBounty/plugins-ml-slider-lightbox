/* global wp, mlGalleryAdmin, jQuery, tinymce */
( function ( $ ) {
	'use strict';

	// ── Usage modal (runs on list page) ──────────────────────────────────── //

	function closeUsageModal( id ) {
		$( '#ml-usage-modal-' + id ).fadeOut( 150 );
		$( '#ml-usage-overlay-' + id ).fadeOut( 150 );
	}

	$( document ).on( 'click', '.ml-usage-btn', function () {
		var id = $( this ).data( 'id' );
		$( '#ml-usage-modal-' + id ).fadeIn( 200 );
		$( '#ml-usage-overlay-' + id ).fadeIn( 200 );
	} );

	$( document ).on( 'click', '.ml-usage-modal-close, .ml-modal-overlay', function () {
		closeUsageModal( $( this ).data( 'id' ) );
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 27 === e.which ) {
			$( '.ml-usage-modal:visible' ).each( function () {
				closeUsageModal( $( this ).data( 'id' ) );
			} );
		}
	} );

	// ── Click-to-copy shortcode (runs on list + editor) ───────────────────── //

	$( document ).on( 'click', '.ml-shortcode-copy', function () {
		var $el  = $( this );
		var text = $el.find( '.ml-shortcode-value' ).text().trim();
		if ( ! navigator.clipboard ) return;
		navigator.clipboard.writeText( text ).then( function () {
			var $check = $el.siblings( '.ml-shortcode-copied' );
			$check.show();
			setTimeout( function () { $check.hide(); }, 2000 );
		} );
	} );

	function copyShortcodeFromRow( $row ) {
		var text = $row.find( '.ml-gallery-shortcode-pre' ).text().trim();
		if ( ! navigator.clipboard ) return;
		navigator.clipboard.writeText( text ).then( function () {
			var $icon = $row.find( '.ml-shortcode-copy-btn .dashicons' );
			$icon.removeClass( 'dashicons-clipboard' ).addClass( 'dashicons-yes' );
			setTimeout( function () {
				$icon.removeClass( 'dashicons-yes' ).addClass( 'dashicons-clipboard' );
			}, 2000 );
		} );
	}

	$( document ).on( 'click', '.ml-shortcode-copy-btn', function () {
		copyShortcodeFromRow( $( this ).closest( '.ml-shortcode-row' ) );
	} );

	$( document ).on( 'click', '.ml-gallery-shortcode-pre', function () {
		copyShortcodeFromRow( $( this ).closest( '.ml-shortcode-row' ) );
	} );

	if ( typeof wp === 'undefined' || typeof wp.media === 'undefined' ) {
		return;
	}

	if ( typeof mlGalleryAdmin === 'undefined' ) {
		return;
	}

	const EDITOR_ID = 'ml-gallery-caption-editor';

	let mediaUploader;
	let $editingItem = null;

	/**
	 * Rebuild the hidden CSV field from current preview items (preserving order).
	 */
	function syncHiddenField() {
		const ids = [];
		$( '#ml-gallery-preview .ml-gallery-item' ).each( function () {
			ids.push( $( this ).data( 'id' ) );
		} );
		$( '#ml_gallery_images' ).val( ids.join( ',' ) );
		$( '#ml-gallery-preview' ).toggleClass( 'is-empty', ids.length === 0 );
	}

	/**
	 * Pencil SVG used on dynamically-added thumbnails.
	 */
	const pencilSVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';

	/**
	 * Append one attachment thumbnail to the preview grid.
	 * Skips duplicates silently.
	 *
	 * @param {Object} attachment wp.media attachment JSON.
	 */
	function addImageToPreview( attachment ) {
		const id    = attachment.id;
		const thumb = ( attachment.sizes && attachment.sizes.thumbnail )
			? attachment.sizes.thumbnail.url
			: attachment.url;

		// Skip if already in the grid
		if ( $( '#ml-gallery-preview [data-id="' + id + '"]' ).length ) {
			return;
		}

		const $item   = $( '<div>' ).addClass( 'ml-gallery-item' ).attr( 'data-id', id );
		const $img    = $( '<img>' ).attr( { src: thumb, alt: '' } );

		const $remove = $( '<button>' ).attr( {
			type        : 'button',
			'aria-label': mlGalleryAdmin.removeLabel,
		} ).addClass( 'ml-gallery-remove' ).text( '\u00d7' );

		const $edit = $( '<button>' ).attr( {
			type        : 'button',
			'aria-label': mlGalleryAdmin.editCaptionLabel,
		} ).addClass( 'ml-gallery-edit-caption' ).html( pencilSVG );

		const $captionInput = $( '<input>' ).attr( {
			type: 'hidden',
			name: 'ml_gallery_captions[' + id + ']',
		} ).addClass( 'ml-gallery-caption-input' ).val( '' );

		$item.append( $img ).append( $remove ).append( $edit ).append( $captionInput );
		$( '#ml-gallery-preview' ).append( $item );
	}

	// ── Media library ─────────────────────────────────────────────────────── //

	$( document ).on( 'click', '#ml-gallery-add-images', function ( e ) {
		e.preventDefault();

		if ( mediaUploader ) {
			mediaUploader.open();
			return;
		}

		mediaUploader = wp.media( {
			title   : mlGalleryAdmin.selectTitle,
			button  : { text: mlGalleryAdmin.selectButton },
			multiple: true,
			library : { type: 'image' },
		} );

		mediaUploader.on( 'select', function () {
			mediaUploader.state().get( 'selection' ).toJSON().forEach( function ( attachment ) {
				addImageToPreview( attachment );
			} );
			syncHiddenField();
		} );

		mediaUploader.open();
	} );

	// Remove an individual image from the grid
	$( document ).on( 'click', '.ml-gallery-remove', function ( e ) {
		e.preventDefault();
		$( this ).closest( '.ml-gallery-item' ).remove();
		syncHiddenField();
	} );

	// ── Caption modal ─────────────────────────────────────────────────────── //

	/**
	 * Returns the classic-editor API regardless of whether Gutenberg is active.
	 * Gutenberg overwrites wp.editor with its own package; WP preserves the
	 * TinyMCE helpers as wp.oldEditor.
	 *
	 * @returns {Object|null}
	 */
	function getWpEditor() {
		if ( ! window.wp ) {
			return null;
		}
		return wp.oldEditor || wp.editor || null;
	}

	function openCaptionModal( $item ) {
		$editingItem      = $item;
		const caption     = $item.find( '.ml-gallery-caption-input' ).val() || '';
		const imgSrc      = $item.find( 'img' ).attr( 'src' );

		$( '#ml-caption-preview-img' ).attr( 'src', imgSrc );
		$( '#ml-caption-modal' ).addClass( 'is-open' );
		$( 'body' ).addClass( 'ml-modal-open' );

		// Destroy any previous instance before re-initialising
		const wpEd = getWpEditor();
		if ( wpEd && wpEd.remove ) {
			wpEd.remove( EDITOR_ID );
		}

		// Small delay lets the modal fully paint before TinyMCE mounts
		setTimeout( function () {
			if ( wpEd && wpEd.initialize ) {
				wpEd.initialize( EDITOR_ID, {
					tinymce: {
						wpautop    : false,
						height     : 160,
						toolbar1   : 'bold italic underline | link unlink | undo redo',
						toolbar2   : '',
						statusbar  : false,
						resize     : false,
					},
					quicktags    : { buttons: 'strong,em,link,close' },
					mediaButtons : false,
				} );
			}

			// Populate with existing caption after TinyMCE finishes initialising
			setTimeout( function () {
				const ed = typeof tinymce !== 'undefined'
					? tinymce.get( EDITOR_ID ) : null;
				if ( ed ) {
					ed.setContent( caption );
					ed.focus();
				} else {
					$( '#' + EDITOR_ID ).val( caption ).trigger( 'focus' );
				}
			}, 300 );
		}, 50 );
	}

	function closeCaptionModal() {
		const wpEd = getWpEditor();
		if ( wpEd && wpEd.remove ) {
			wpEd.remove( EDITOR_ID );
		}
		$( '#ml-caption-modal' ).removeClass( 'is-open' );
		$( 'body' ).removeClass( 'ml-modal-open' );
		$editingItem = null;
	}

	$( document ).on( 'click', '.ml-gallery-edit-caption', function ( e ) {
		e.preventDefault();
		openCaptionModal( $( this ).closest( '.ml-gallery-item' ) );
	} );

	$( document ).on( 'click', '#ml-caption-save', function () {
		if ( ! $editingItem ) {
			return;
		}

		let content = '';
		const wpEd = getWpEditor();
		if ( wpEd && wpEd.getContent ) {
			content = wpEd.getContent( EDITOR_ID ) || '';
		} else {
			content = $( '#' + EDITOR_ID ).val() || '';
		}

		$editingItem.find( '.ml-gallery-caption-input' ).val( content );

		// Toggle indicator dot — strip tags to check for real text content
		const hasText = content.replace( /<[^>]*>/g, '' ).trim() !== '';
		$editingItem.toggleClass( 'has-caption', hasText );

		closeCaptionModal();
	} );

	$( document ).on( 'click', '#ml-caption-cancel, #ml-caption-close, #ml-caption-overlay', function () {
		closeCaptionModal();
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 27 === e.which && $( '#ml-caption-modal' ).hasClass( 'is-open' ) ) {
			closeCaptionModal();
		}
	} );

	// ── Colour pickers & range sliders ────────────────────────────────────── //

	$( function () {
		if ( $.fn.tipsy ) {
			$( '.ml-tipsy' ).tipsy( { gravity: 'e', fade: true } );
		}

		if ( $.fn.wpColorPicker ) {
			$( '.ml-gallery-color-picker' ).wpColorPicker();
		}

		$( document ).on( 'input', '.ml-gallery-range', function () {
			const $val   = $( this ).closest( '.ml-gallery-setting' ).find( '.ml-gallery-range-value' );
			const isGap  = $( this ).is( '#ml_gallery_gap' );
			const isMs   = $( this ).is( '#ml_gallery_autoplay_interval, #ml_gallery_pro_autoplay_interval' );
			const suffix = isGap ? 'px' : ( isMs ? 'ms' : '' );
			$val.text( this.value + suffix );
		} );

		// Show/hide autoplay interval row based on autoplay toggle
		$( document ).on( 'change', '#ml_gallery_autoplay, #ml_gallery_pro_autoplay', function () {
			$( '.ml-autoplay-interval-row' ).toggleClass( 'is-hidden', ! this.checked );
		} );

		// Show/hide Columns row based on selected layout
		function toggleColumnsRow( layout ) {
			var hideColumns = layout === 'justified' || layout === 'carousel' || layout === 'showcase';
			var hideGap     = layout === 'carousel' || layout === 'showcase';
			var hideModal   = layout === 'carousel' || layout === 'showcase';
			$( '.ml-gallery-columns-row' ).toggleClass( 'is-hidden', hideColumns );
			$( '.ml-gallery-mobile-columns-row' ).toggleClass( 'is-hidden', hideColumns );
			$( '.ml-gallery-gap-row' ).toggleClass( 'is-hidden', hideGap );
			$( '.ml-show-in-modal-row' ).toggleClass( 'is-hidden', hideModal );
		}

// Init on page load
		const $checkedLayout = $( 'input[name="ml_gallery_settings[layout]"]:checked' );
		if ( $checkedLayout.length ) {
			toggleColumnsRow( $checkedLayout.val() );
		}

		$( document ).on( 'change', 'input[name="ml_gallery_settings[layout]"]', function () {
			toggleColumnsRow( this.value );
		} );
	} );

	// ── Drag-to-reorder ───────────────────────────────────────────────────── //

	$( function () {
		$( '#ml-gallery-preview' ).sortable( {
			items : '.ml-gallery-item',
			cursor: 'move',
			update: function () {
				syncHiddenField();
			},
		} );
	} );

} )( jQuery );
