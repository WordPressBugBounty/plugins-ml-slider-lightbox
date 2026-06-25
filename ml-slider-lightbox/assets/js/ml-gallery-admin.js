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

	// navigator.clipboard only exists in secure contexts (HTTPS / localhost).
	// On plain HTTP it is undefined, so fall back to execCommand('copy').
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			var ta = document.createElement( 'textarea' );
			ta.value = text;
			ta.setAttribute( 'readonly', '' );
			ta.style.position = 'fixed';
			ta.style.left = '-9999px';
			document.body.appendChild( ta );
			ta.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( ta );
			ok ? resolve() : reject();
		} );
	}

	$( document ).on( 'click', '.ml-shortcode-copy', function () {
		var $el  = $( this );
		var text = $el.find( '.ml-shortcode-value' ).text().trim();
		copyText( text ).then( function () {
			var $check = $el.siblings( '.ml-shortcode-copied' );
			$check.show();
			setTimeout( function () { $check.hide(); }, 2000 );
		} );
	} );

	function copyShortcodeFromRow( $row ) {
		var text = $row.find( '.ml-gallery-shortcode-pre' ).text().trim();
		copyText( text ).then( function () {
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
	 * @param {string} position   'start' to prepend, otherwise append.
	 */
	function addImageToPreview( attachment, position ) {
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

		if ( position === 'start' ) {
			$( '#ml-gallery-preview' ).prepend( $item );
		} else {
			$( '#ml-gallery-preview' ).append( $item );
		}
	}

	// ── Media library ─────────────────────────────────────────────────────── //

	/**
	 * Open the wp.media frame on a given tab.
	 *
	 * @param {string} mode 'upload' (Upload Files tab) or 'browse' (Media Library tab).
	 */
	function openMediaFrame( mode ) {
		if ( ! mediaUploader ) {
			mediaUploader = wp.media( {
				title   : mlGalleryAdmin.selectTitle,
				button  : { text: mlGalleryAdmin.selectButton },
				multiple: true,
				library : { type: 'image' },
			} );

			mediaUploader.on( 'select', function () {
				const position = $( 'input[name="ml_gallery_settings[add_position]"]:checked' ).val() || 'end';
				let selected   = mediaUploader.state().get( 'selection' ).toJSON();

				// Prepending each item reverses order, so reverse the batch first
				// to keep the user's selection order at the top of the grid.
				if ( position === 'start' ) {
					selected = selected.slice().reverse();
				}

				selected.forEach( function ( attachment ) {
					addImageToPreview( attachment, position );
				} );
				syncHiddenField();
			} );
		}

		mediaUploader.open();

		if ( mode && mediaUploader.content ) {
			mediaUploader.content.mode( mode );
		}
	}

	/**
	 * Close the add-images method menu and reset the caret state.
	 */
	function closeAddMenu() {
		$( '.ml-gallery-add-menu' ).prop( 'hidden', true );
		$( '.ml-gallery-add-caret' ).attr( 'aria-expanded', 'false' );
	}

	// Main button: default to the Media Library tab (preserves prior behavior).
	$( document ).on( 'click', '#ml-gallery-add-images', function ( e ) {
		e.preventDefault();
		closeAddMenu();
		openMediaFrame( 'browse' );
	} );

	// Caret: toggle the method menu.
	$( document ).on( 'click', '.ml-gallery-add-caret', function ( e ) {
		e.preventDefault();
		const $menu = $( '.ml-gallery-add-menu' );
		const willOpen = $menu.prop( 'hidden' );
		$menu.prop( 'hidden', ! willOpen );
		$( this ).attr( 'aria-expanded', String( willOpen ) );
	} );

	// Menu item: open the media frame, or the server-folder modal.
	$( document ).on( 'click', '.ml-gallery-add-menu [role="menuitem"]', function ( e ) {
		e.preventDefault();
		const method = this.dataset.method;
		closeAddMenu();
		if ( 'server' === method ) {
			openFolderModal();
		} else if ( 'zip' === method ) {
			openZipPicker();
		} else {
			openMediaFrame( method );
		}
	} );

	// Dismiss the menu on outside click.
	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.ml-gallery-add-split' ).length ) {
			closeAddMenu();
		}
	} );

	// Dismiss the menu on Escape and return focus to the caret.
	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! $( '.ml-gallery-add-menu' ).prop( 'hidden' ) ) {
			closeAddMenu();
			$( '.ml-gallery-add-caret' ).trigger( 'focus' );
		}
	} );

	// ── Server folder import ──────────────────────────────────────────────────── //

	function openFolderModal() {
		$( '#ml-folder-modal' ).addClass( 'is-open' );
		browseFolder( '' );
		$( '#ml-folder-close' ).trigger( 'focus' );
	}

	function closeFolderModal() {
		$( '#ml-folder-modal' ).removeClass( 'is-open' );
		$( '#ml-folder-results' ).empty();
		$( '#ml-folder-breadcrumb' ).empty();
		$( '#ml-folder-status' ).text( '' );
		$( '#ml-gallery-add-images' ).trigger( 'focus' );
	}

	// Selected-state class so the highlight works without CSS :has().
	$( document ).on( 'change', '.ml-folder-check', function () {
		$( this ).closest( '.ml-folder-tile' ).toggleClass( 'is-selected', this.checked );
	} );

	function browseFolder( path ) {
		const $results = $( '#ml-folder-results' ).html(
			'<p class="ml-folder-msg">' + mlGalleryAdmin.folderLoading + '</p>'
		);
		$( '#ml-folder-status' ).text( '' );

		$.post( ajaxurl, {
			action  : 'ml_gallery_browse_folder',
			_wpnonce: mlGalleryAdmin.folderNonce,
			path    : path,
		} ).done( function ( res ) {
			if ( ! res || ! res.success ) {
				$results.html( '<p class="ml-folder-msg">' + mlGalleryAdmin.folderError + '</p>' );
				return;
			}
			renderFolder( res.data );
		} ).fail( function () {
			$results.html( '<p class="ml-folder-msg">' + mlGalleryAdmin.folderError + '</p>' );
		} );
	}

	function renderFolder( data ) {
		const $bc = $( '#ml-folder-breadcrumb' ).empty();
		data.breadcrumb.forEach( function ( crumb, i ) {
			if ( i > 0 ) {
				$bc.append( document.createTextNode( ' / ' ) );
			}
			$( '<a href="#" class="ml-folder-crumb"></a>' )
				.text( crumb.name )
				.attr( 'data-path', crumb.path )
				.appendTo( $bc );
		} );

		const $results = $( '#ml-folder-results' ).empty();

		data.folders.forEach( function ( folder ) {
			$( '<button type="button" class="ml-folder-tile ml-folder-dir"></button>' )
				.attr( 'data-path', folder.path )
				.html( '<span class="ml-folder-icon" aria-hidden="true">📁</span>' )
				.append( $( '<span class="ml-folder-name"></span>' ).text( folder.name ) )
				.appendTo( $results );
		} );

		data.images.forEach( function ( img ) {
			const $tile = $( '<label class="ml-folder-tile ml-folder-img"></label>' );
			$( '<input type="checkbox" class="ml-folder-check">' ).attr( 'data-path', img.path ).appendTo( $tile );
			$( '<img>' ).attr( { src: img.url, alt: '' } ).appendTo( $tile );
			$( '<span class="ml-folder-name"></span>' ).text( img.name ).appendTo( $tile );
			$tile.appendTo( $results );
		} );

		if ( ! data.folders.length && ! data.images.length ) {
			$results.html( '<p class="ml-folder-msg">' + mlGalleryAdmin.folderEmpty + '</p>' );
		}

		$( '#ml-folder-status' ).text( data.truncated ? mlGalleryAdmin.folderTruncated : '' );
	}

	$( document ).on( 'click', '.ml-folder-dir', function () {
		browseFolder( $( this ).attr( 'data-path' ) );
	} );

	$( document ).on( 'click', '.ml-folder-crumb', function ( e ) {
		e.preventDefault();
		browseFolder( $( this ).attr( 'data-path' ) );
	} );

	$( document ).on( 'click', '#ml-folder-close, #ml-folder-cancel, #ml-folder-overlay', function () {
		closeFolderModal();
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && $( '#ml-folder-modal' ).hasClass( 'is-open' ) ) {
			closeFolderModal();
		}
	} );

	$( document ).on( 'click', '#ml-folder-import', function () {
		const paths = $( '#ml-folder-results .ml-folder-check:checked' ).map( function () {
			return $( this ).attr( 'data-path' );
		} ).get();

		if ( ! paths.length ) {
			$( '#ml-folder-status' ).text( mlGalleryAdmin.folderNoSel );
			return;
		}

		const $btn = $( this ).prop( 'disabled', true );
		$( '#ml-folder-status' ).text( mlGalleryAdmin.folderImporting );

		$.post( ajaxurl, {
			action  : 'ml_gallery_import_folder',
			_wpnonce: mlGalleryAdmin.folderNonce,
			paths   : paths,
		} ).done( function ( res ) {
			if ( res && res.success && res.data && res.data.attachments ) {
				const position = $( 'input[name="ml_gallery_settings[add_position]"]:checked' ).val() || 'end';
				let list = res.data.attachments;
				if ( 'start' === position ) {
					list = list.slice().reverse();
				}
				list.forEach( function ( att ) {
					addImageToPreview( {
						id   : att.id,
						url  : att.url,
						sizes: { thumbnail: { url: att.thumb } },
					}, position );
				} );
				syncHiddenField();

				const skipped = res.data.skipped || 0;
				if ( skipped > 0 ) {
					// Keep the modal open so silently-dropped files are visible.
					$( '#ml-folder-status' ).text(
						mlGalleryAdmin.folderImported
							.replace( '%1$d', list.length )
							.replace( '%2$d', skipped )
					);
				} else {
					closeFolderModal();
				}
			} else {
				$( '#ml-folder-status' ).text( mlGalleryAdmin.folderError );
			}
		} ).fail( function () {
			$( '#ml-folder-status' ).text( mlGalleryAdmin.folderError );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// ── ZIP import ────────────────────────────────────────────────────────── //

	function openZipPicker() {
		$( '#ml-zip-input' ).val( '' ).trigger( 'click' );
	}

	function closeZipModal() {
		$( '#ml-zip-modal' ).removeClass( 'is-open' );
		$( '#ml-zip-status' ).text( '' );
		$( '#ml-gallery-add-images' ).trigger( 'focus' );
	}

	$( document ).on( 'click', '#ml-zip-close, #ml-zip-overlay', function () {
		closeZipModal();
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && $( '#ml-zip-modal' ).hasClass( 'is-open' ) ) {
			closeZipModal();
		}
	} );

	$( document ).on( 'change', '#ml-zip-input', function () {
		const file = this.files && this.files[0];
		if ( ! file ) {
			return;
		}

		$( '#ml-zip-modal' ).addClass( 'is-open' );
		$( '#ml-zip-close' ).trigger( 'focus' );
		$( '#ml-zip-status' ).text( mlGalleryAdmin.zipUploading );

		const fd = new FormData();
		fd.append( 'action', 'ml_gallery_import_zip' );
		fd.append( '_wpnonce', mlGalleryAdmin.folderNonce );
		fd.append( 'zip', file );

		$.ajax( {
			url        : ajaxurl,
			method     : 'POST',
			data       : fd,
			processData: false,
			contentType: false,
			xhr        : function () {
				const xhr = new window.XMLHttpRequest();
				xhr.upload.addEventListener( 'progress', function ( e ) {
					if ( e.lengthComputable ) {
						const pct = Math.round( ( e.loaded / e.total ) * 100 );
						if ( pct >= 100 ) {
							$( '#ml-zip-status' ).text( mlGalleryAdmin.zipImporting );
						} else {
							$( '#ml-zip-status' ).text( mlGalleryAdmin.zipUploading + ' ' + pct + '%' );
						}
					}
				} );
				return xhr;
			},
		} ).done( function ( res ) {
			if ( ! res || ! res.success || ! res.data ) {
				let m = mlGalleryAdmin.zipError;
				if ( res && res.data && res.data.message ) {
					// Our endpoint's own JSON error (bad file, unreadable, etc.).
					m = res.data.message;
				} else if ( '0' === res || '-1' === res || 0 === res ) {
					// admin-ajax rejected the request before our handler ran —
					// most often the upload exceeded the server's size limit.
					m = mlGalleryAdmin.zipRejected;
				}
				$( '#ml-zip-status' ).text( m );
				return;
			}

			const atts = res.data.attachments || [];
			if ( atts.length ) {
				const position = $( 'input[name="ml_gallery_settings[add_position]"]:checked' ).val() || 'end';
				let list = atts;
				if ( 'start' === position ) {
					list = list.slice().reverse();
				}
				list.forEach( function ( att ) {
					addImageToPreview( {
						id   : att.id,
						url  : att.url,
						sizes: { thumbnail: { url: att.thumb } },
					}, position );
				} );
				syncHiddenField();
			}

			const skipped = res.data.skipped || 0;
			if ( ! atts.length && ! skipped ) {
				$( '#ml-zip-status' ).text( mlGalleryAdmin.zipNone );
				return;
			}

			let msg = mlGalleryAdmin.zipDone
				.replace( '%1$d', atts.length )
				.replace( '%2$d', skipped );
			if ( res.data.truncated ) {
				msg += ' ' + mlGalleryAdmin.zipTruncated;
			}
			$( '#ml-zip-status' ).text( msg );

			if ( ! skipped && ! res.data.truncated ) {
				closeZipModal();
			}
		} ).fail( function ( jqXHR ) {
			let m = mlGalleryAdmin.zipError;
			if ( jqXHR && jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message ) {
				// Our endpoint's JSON error (it sets an HTTP status, so it lands here).
				m = jqXHR.responseJSON.data.message;
			} else if ( jqXHR && 413 === jqXHR.status ) {
				m = mlGalleryAdmin.zipRejected;
			} else if ( jqXHR && jqXHR.status ) {
				// No JSON body (e.g. a PHP fatal) — surface the status so it's diagnosable.
				m += ' (HTTP ' + jqXHR.status + ')';
			}
			$( '#ml-zip-status' ).text( m );
		} );
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
			const isGap  = $( this ).is( '#ml_gallery_gap, #ml_gallery_caption_text_size' );
			const isMs   = $( this ).is( '#ml_gallery_autoplay_interval, #ml_gallery_pro_autoplay_interval' );
			const suffix = isGap ? 'px' : ( isMs ? 'ms' : '' );
			$val.text( this.value + suffix );
		} );

		// Show/hide autoplay interval row based on autoplay toggle
		$( document ).on( 'change', '#ml_gallery_autoplay, #ml_gallery_pro_autoplay', function () {
			$( '.ml-autoplay-interval-row' ).toggleClass( 'is-hidden', ! this.checked );
		} );

		// Show/hide caption style fields based on the Captions dropdown
		$( document ).on( 'change', '#ml_gallery_caption_display', function () {
			var value = this.value;
			// Text/color/background apply wherever captions show; hide only when off.
			$( '.ml-caption-style-field' ).toggleClass( 'is-hidden', 'hidden' === value );
			// Transition is popup-window-only, so it also hides for Gallery Only.
			// This selector overlaps the line above on the Transition field and
			// runs last, so the more-restrictive rule wins for that field.
			$( '.ml-caption-window-only' ).toggleClass( 'is-hidden', 'hidden' === value || 'gallery' === value );
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

		// Lightbox caption options ("Lightbox + Gallery", "Lightbox Only") only
		// apply when a gallery window is available — hide them for Carousel/Showcase
		// or when "Show in Gallery Window" is disabled.
		function toggleCaptionLightboxOptions( layout, openInLightbox ) {
			var hide    = layout === 'carousel' || layout === 'showcase' || ! openInLightbox;
			var $select = $( '#ml_gallery_caption_display' );
			if ( ! $select.length ) {
				return;
			}
			if ( hide && ( $select.val() === 'both' || $select.val() === 'lightbox' ) ) {
				$select.val( 'gallery' ).trigger( 'change' );
			}
			$select.find( 'option[value="both"], option[value="lightbox"]' ).prop( { disabled: hide, hidden: hide } );
		}

		function toggleLightboxSections( layout, openInLightbox ) {
			var isInlineLayout = layout === 'carousel' || layout === 'showcase';
			var hide = ! isInlineLayout && ! openInLightbox;
			$( '.ml-lightbox-settings-group, .ml-lightbox-only-panel' ).toggleClass( 'is-hidden', hide );
		}

		// The "Open in Gallery Button" panel only applies to grid-like layouts that
		// open a gallery window. Hidden for carousel/showcase or when the gallery
		// window is disabled.
		function toggleButtonSettingsPanel( layout, openInLightbox ) {
			var isGridLike = layout !== 'carousel' && layout !== 'showcase';
			var hide = ! isGridLike || ! openInLightbox;
			$( '.ml-button-settings-group' ).toggleClass( 'is-hidden', hide );
		}

		function toggleButtonSubOptions( showButton ) {
			$( '.ml-button-suboptions' ).toggleClass( 'is-hidden', ! showButton );
		}

		function toggleButtonTextRow( useIcon ) {
			$( '.ml-button-text-row' ).toggleClass( 'is-hidden', useIcon );
		}

// Init on page load
		const $checkedLayout = $( 'input[name="ml_gallery_settings[layout]"]:checked' );
		if ( $checkedLayout.length ) {
			var $openInLightbox = $( '#ml_gallery_open_in_lightbox' );
			toggleColumnsRow( $checkedLayout.val() );
			toggleCaptionLightboxOptions( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
			toggleLightboxSections( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
			toggleButtonSettingsPanel( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
		}

		$( document ).on( 'change', 'input[name="ml_gallery_settings[layout]"]', function () {
			var openInLightbox = $( '#ml_gallery_open_in_lightbox' ).prop( 'checked' );
			toggleColumnsRow( this.value );
			toggleCaptionLightboxOptions( this.value, openInLightbox );
			toggleLightboxSections( this.value, openInLightbox );
			toggleButtonSettingsPanel( this.value, openInLightbox );
		} );

		$( document ).on( 'change', '#ml_gallery_open_in_lightbox', function () {
			var layout = $( 'input[name="ml_gallery_settings[layout]"]:checked' ).val() || 'grid';
			toggleCaptionLightboxOptions( layout, this.checked );
			toggleLightboxSections( layout, this.checked );
			toggleButtonSettingsPanel( layout, this.checked );
		} );

		$( document ).on( 'change', '#ml_gallery_show_lightbox_button', function () {
			toggleButtonSubOptions( this.checked );
		} );

		$( document ).on( 'change', '#ml_gallery_button_icon', function () {
			toggleButtonTextRow( this.checked );
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
