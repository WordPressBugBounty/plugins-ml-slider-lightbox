/* global wp, mlGalleryAdmin, jQuery, tinymce */

/**
 * Read the editor form into the state object the preview route expects.
 * Pure: no side effects, no network. Later fields in a group win, which
 * makes a checked checkbox override the hidden "0" that precedes it.
 */
function collectGalleryState() {
	const groups = {
		ml_gallery_settings: 'settings',
		ml_gallery_appearance: 'appearance',
		ml_gallery_image_styles: 'image_styles',
		ml_gallery_pro_settings: 'pro_settings',
	};
	const modes = document.querySelector( '.ml-gallery-preview-modes' );
	const state = {
		id: ( typeof mlGalleryAdmin !== 'undefined' && mlGalleryAdmin.galleryId ) || 0,
		interactive: 1,
		viewport: ( modes && modes.getAttribute( 'data-viewport' ) ) === 'mobile' ? 'mobile' : 'desktop',
		image_ids: [],
		captions: {},
		settings: {},
		appearance: {},
		image_styles: {},
		pro_settings: {},
	};

	const csv = ( document.getElementById( 'ml_gallery_images' ) || {} ).value || '';
	state.image_ids = csv
		.split( ',' )
		.map( function ( s ) { return parseInt( s, 10 ); } )
		.filter( function ( n ) { return ! isNaN( n ); } );

	const fields = document.querySelectorAll( 'input[name], select[name], textarea[name]' );
	fields.forEach( function ( el ) {
		if ( ( el.type === 'checkbox' || el.type === 'radio' ) && ! el.checked ) {
			return;
		}
		const m = el.name.match( /^([a-z_]+)\[([^\]]+)\]$/ );
		if ( ! m ) { return; }
		const prefix = m[ 1 ];
		const key = m[ 2 ];
		if ( prefix === 'ml_gallery_captions' ) {
			state.captions[ key ] = el.value;
		} else if ( groups[ prefix ] ) {
			state[ groups[ prefix ] ][ key ] = el.value;
		}
	} );

	return state;
}

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
	let captionFields = null;   // fetched { source: {value, editable, reason} }
	let currentSource = 'manual';

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
		$( document ).trigger( 'ml-gallery:images-changed' );
	}

	/**
	 * Pencil SVG used on dynamically-added thumbnails.
	 */
	const pencilSVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';

	/**
	 * Append one attachment thumbnail to the preview grid.
	 * Skips duplicates silently.
	 *
	 * @param {Object} attachment wp.media attachment JSON.
	 * @param {string} position   'start' to prepend, otherwise append.
	 */
	function addImageToPreview( attachment, position ) {
		const id    = attachment.id;
		const thumb = ( attachment.sizes && attachment.sizes.medium )
			? attachment.sizes.medium.url
			: ( ( attachment.sizes && attachment.sizes.thumbnail )
				? attachment.sizes.thumbnail.url
				: attachment.url );

		// Skip if already in the grid
		if ( $( '#ml-gallery-preview [data-id="' + id + '"]' ).length ) {
			return;
		}

		// attachment.url is the full-size URL; the caption modal previews it.
		const $item   = $( '<div>' ).addClass( 'ml-gallery-item' )
			.attr( { 'data-id': id, 'data-full': attachment.url || thumb } );
		const $img    = $( '<img>' ).attr( { src: thumb, alt: '' } );

		const $remove = $( '<button>' ).attr( {
			type        : 'button',
			'aria-label': mlGalleryAdmin.removeLabel,
		} ).addClass( 'ml-gallery-remove' ).text( '\u00d7' );

		const $bar = $( '<button>' ).attr( {
			type        : 'button',
			'aria-label': mlGalleryAdmin.addCaptionLabel,
		} ).addClass( 'ml-gallery-caption-bar' );
		$bar.html( pencilSVG );
		$( '<span>' ).addClass( 'ml-gallery-caption-bar-text' )
			.text( mlGalleryAdmin.addCaptionLabel )
			.appendTo( $bar );

		const $captionInput = $( '<input>' ).attr( {
			type: 'hidden',
			name: 'ml_gallery_captions[' + id + ']',
		} ).addClass( 'ml-gallery-caption-input' ).val( '' );

		$item.append( $img ).append( $remove ).append( $bar ).append( $captionInput );

		// Initial bar text reflects the gallery's display source.
		let initial = '';
		if ( mlGalleryAdmin.captionSource === 'media_caption' ) {
			initial = attachment.caption || '';
		} else if ( mlGalleryAdmin.captionSource === 'media_description' ) {
			initial = attachment.description || '';
		}
		const plainInit = $( '<textarea>' ).html( initial.replace( /<[^>]*>/g, '' ) ).val().trim();
		if ( plainInit ) {
			$item.addClass( 'has-caption' );
			$bar.find( '.ml-gallery-caption-bar-text' ).text( plainInit );
		}

		if ( position === 'start' ) {
			$( '#ml-gallery-preview' ).prepend( $item );
		} else {
			$( '#ml-gallery-preview' ).append( $item );
		}

		// Let the Image Styles live preview restyle the new thumbnail.
		$( document ).trigger( 'ml-gallery-image-added' );
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

	// Empty "No images added yet" canvas acts as a large Add Images target.
	$( document ).on( 'click', '#ml-gallery-preview.is-empty', function ( e ) {
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

	function activeGalleryId() {
		return parseInt( mlGalleryAdmin.galleryId, 10 ) || 0;
	}

	function fetchCaptionFields( id ) {
		return $.post( ajaxurl, {
			action        : 'ml_get_caption_fields',
			attachment_id : id,
			gallery_id    : activeGalleryId(),
			_wpnonce      : mlGalleryAdmin.captionNonce,
		} );
	}

	function saveCaptionField( id, source, content ) {
		return $.post( ajaxurl, {
			action        : 'ml_save_caption',
			attachment_id : id,
			gallery_id    : activeGalleryId(),
			source        : source,
			content       : content,
			_wpnonce      : mlGalleryAdmin.captionNonce,
		} );
	}

	// Text shown in the grid caption bar = the gallery's display source,
	// with the media→manual fallback the front-end resolver uses.
	function resolvedBarValue() {
		if ( ! captionFields ) { return ''; }
		const src = mlGalleryAdmin.captionSource || 'manual';
		let val = ( captionFields[ src ] && captionFields[ src ].value ) || '';
		if ( ! val && src !== 'manual' && captionFields.manual ) {
			val = captionFields.manual.value || '';
		}
		return val;
	}

	function renderCaptionTabs() {
		$( '.ml-caption-tab' ).each( function () {
			const src      = $( this ).data( 'source' );
			const isActive = src === currentSource;
			const isShown  = src === mlGalleryAdmin.captionSource;
			$( this ).toggleClass( 'is-active', isActive );
			// A dot marks the source the gallery displays; the words live in the tooltip.
			$( this ).toggleClass( 'is-shown', isShown )
				.attr( 'title', isShown ? mlGalleryAdmin.captionShownBadge : null );
		} );

		const field    = ( captionFields && captionFields[ currentSource ] ) || { editable: true, reason: '' };
		const isMedia  = currentSource === 'media_caption' || currentSource === 'media_description';
		$( '#ml-caption-note' ).prop( 'hidden', ! isMedia ).text( isMedia ? mlGalleryAdmin.captionMediaNote : '' );

		const ro = ! field.editable;
		$( '#ml-caption-readonly' ).prop( 'hidden', ! ro ).text(
			ro ? ( field.reason === 'perm' ? mlGalleryAdmin.captionReadonlyPerm : mlGalleryAdmin.captionReadonlySource ) : ''
		);
		setEditorReadonly( ro );
	}

	function setEditorReadonly( ro ) {
		const ed = typeof tinymce !== 'undefined' ? tinymce.get( EDITOR_ID ) : null;
		if ( ed ) { ed.getBody().setAttribute( 'contenteditable', ro ? 'false' : 'true' ); }
		$( '#ml-caption-tabs' ).closest( '#ml-caption-editor-col' ).toggleClass( 'is-readonly', ro );
	}

	function setEditorContent( html ) {
		const ed = typeof tinymce !== 'undefined' ? tinymce.get( EDITOR_ID ) : null;
		if ( ed ) { ed.setContent( html || '' ); } else { $( '#' + EDITOR_ID ).val( html || '' ); }
		updateCaptionOverlay( html || '' );
	}

	// Full-size URL for the modal preview, falling back to the grid thumbnail.
	function captionPreviewSrc( $item ) {
		return $item.attr( 'data-full' ) || $item.find( 'img' ).attr( 'src' );
	}

	function openCaptionModal( $item ) {
		$editingItem      = $item;

		$( '#ml-caption-preview-img' ).attr( 'src', captionPreviewSrc( $item ) );
		$( '#ml-caption-modal' ).addClass( 'is-open' );
		$( 'body' ).addClass( 'ml-modal-open' );

		updateCaptionNav();

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
						toolbar1   : 'fontsizeselect | bold italic underline strikethrough | bullist numlist blockquote | alignleft aligncenter alignright | link unlink | removeformat | undo redo',
						toolbar2   : '',
						statusbar  : false,
						resize     : false,
					},
					quicktags    : { buttons: 'strong,em,del,ul,ol,li,link,close' },
					mediaButtons : false,
				} );
			}

			// Populate after TinyMCE finishes initialising.
			setTimeout( function () {
				const ed = typeof tinymce !== 'undefined' ? tinymce.get( EDITOR_ID ) : null;
				if ( ed ) {
					ed.on( 'keyup input change', function () {
						updateCaptionOverlay( ed.getContent() );
					} );
					ed.on( 'keydown', function ( e ) {
						if ( 27 === e.keyCode ) {
							e.preventDefault();
							commitCurrentSource().always( closeCaptionModal );
						}
					} );
				}

				currentSource = mlGalleryAdmin.captionSource || 'manual';
				const id = $editingItem.data( 'id' );
				captionFields = null;
				fetchCaptionFields( id ).done( function ( res ) {
					captionFields = ( res && res.data && res.data.fields ) || null;
					renderCaptionTabs();
					const field = ( captionFields && captionFields[ currentSource ] ) || { value: '' };
					setEditorContent( field.value );
					if ( ed ) { ed.focus(); }
				} );
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

	function getEditorContent() {
		const wpEd = getWpEditor();
		if ( wpEd && wpEd.getContent ) {
			return wpEd.getContent( EDITOR_ID ) || '';
		}
		return $( '#' + EDITOR_ID ).val() || '';
	}

	// Save the editor's content to the CURRENT source. Returns the jqXHR
	// (or a resolved deferred when there's nothing to save) for chaining.
	function commitCurrentSource() {
		if ( ! $editingItem || ! captionFields ) { return $.Deferred().resolve().promise(); }

		const field = captionFields[ currentSource ] || { editable: true };
		if ( ! field.editable ) { return $.Deferred().resolve().promise(); }

		const content = getEditorContent();
		captionFields[ currentSource ] = $.extend( {}, field, { value: content } );

		// Keep the manual hidden input authoritative for gallery Save.
		if ( currentSource === 'manual' ) {
			$editingItem.find( '.ml-gallery-caption-input' ).val( content );
			$( document ).trigger( 'ml-gallery:images-changed' );
		}

		refreshCaptionBar( $editingItem );

		const id     = $editingItem.data( 'id' );
		const saving = saveCaptionField( id, currentSource, content );

		// Media sources persist to the attachment, so the Preview (a server render
		// that reads the attachment) must re-render only after the write lands.
		// Manual already refreshed synchronously above via its hidden input.
		if ( currentSource !== 'manual' ) {
			saving.done( function () {
				$( document ).trigger( 'ml-gallery:images-changed' );
			} );
		}

		return saving;
	}

	// Update the grid bar text/state from the gallery's display source.
	function refreshCaptionBar( $item ) {
		const html    = resolvedBarValue();
		const plain   = $( '<textarea>' ).html( html.replace( /<[^>]*>/g, '' ) ).val().trim();
		const hasText = plain !== '';
		$item.toggleClass( 'has-caption', hasText );
		const $bar = $item.find( '.ml-gallery-caption-bar' );
		$bar.find( '.ml-gallery-caption-bar-text' )
			.text( hasText ? plain : mlGalleryAdmin.addCaptionLabel );
		$bar.attr( 'aria-label', hasText ? mlGalleryAdmin.editCaptionLabel : mlGalleryAdmin.addCaptionLabel );
	}

	function updateCaptionNav() {
		const $items = $( '#ml-gallery-preview .ml-gallery-item' );
		const total  = $items.length;
		const index  = $items.index( $editingItem ); // 0-based

		const single = total < 2;
		$( '#ml-caption-prev' ).toggle( ! single ).prop( 'disabled', index <= 0 );
		$( '#ml-caption-next' ).toggle( ! single ).prop( 'disabled', index >= total - 1 );
	}

	function loadCaptionItem( $item ) {
		$editingItem = $item;
		$( '#ml-caption-preview-img' ).attr( 'src', captionPreviewSrc( $item ) );
		currentSource = mlGalleryAdmin.captionSource || 'manual';
		captionFields = null;
		fetchCaptionFields( $item.data( 'id' ) ).done( function ( res ) {
			captionFields = ( res && res.data && res.data.fields ) || null;
			renderCaptionTabs();
			const field = ( captionFields && captionFields[ currentSource ] ) || { value: '' };
			setEditorContent( field.value );
		} );
		updateCaptionNav();
	}

	function updateCaptionOverlay( content ) {
		content = content || getEditorContent();
		// Strip tags + decode entities so the empty check matches refreshCaptionBar().
		const hasText = $( '<textarea>' ).html( content.replace( /<[^>]*>/g, '' ) ).val().trim() !== '';
		$( '#ml-caption-preview-overlay' ).html( hasText ? content : '' );
	}

	function navigateCaption( direction ) {
		if ( ! $editingItem ) {
			return;
		}

		// Commit the caption we are leaving before moving.
		commitCurrentSource();

		const $items = $( '#ml-gallery-preview .ml-gallery-item' );
		const target = $items.index( $editingItem ) + direction;
		if ( target < 0 || target >= $items.length ) {
			return;
		}

		loadCaptionItem( $items.eq( target ) );
	}

	$( document ).on( 'click', '.ml-gallery-caption-bar', function ( e ) {
		e.preventDefault();
		openCaptionModal( $( this ).closest( '.ml-gallery-item' ) );
	} );

	// Tab click: commit the source we're leaving, then switch.
	$( document ).on( 'click', '.ml-caption-tab', function () {
		const next = $( this ).data( 'source' );
		if ( next === currentSource ) { return; }
		commitCurrentSource().always( function () {
			currentSource = next;
			renderCaptionTabs();
			const field = ( captionFields && captionFields[ currentSource ] ) || { value: '' };
			setEditorContent( field.value );
		} );
	} );

	// Done / close / overlay-click: commit the current caption, then close.
	$( document ).on( 'click', '#ml-caption-done, #ml-caption-close, #ml-caption-overlay', function () {
		commitCurrentSource().always( closeCaptionModal );
	} );

	$( document ).on( 'click', '#ml-caption-prev', function () {
		navigateCaption( -1 );
	} );

	$( document ).on( 'click', '#ml-caption-next', function () {
		navigateCaption( 1 );
	} );

	$( document ).on( 'input', '#' + EDITOR_ID, function () {
		updateCaptionOverlay( $( this ).val() );
	} );

	$( document ).on( 'keydown', function ( e ) {
		if ( 27 === e.which && $( '#ml-caption-modal' ).hasClass( 'is-open' ) ) {
			commitCurrentSource().always( closeCaptionModal );
		}
	} );

	// ── Colour pickers & range sliders ────────────────────────────────────── //

	$( function () {
		if ( $.fn.tipsy ) {
			$( '.ml-tipsy' ).tipsy( { gravity: 'e', fade: true } );
			$( '.ml-tipsy-bottom' ).tipsy( { gravity: 'n', fade: true } );
		}

		// ── Image Styles live preview ─────────────────────────────────────── //

		var IMG_STYLE_PRESETS = {
			noir:     'grayscale(100%) contrast(120%)',
			silver:   'grayscale(100%) contrast(130%) brightness(105%)',
			vintage:  'sepia(55%) contrast(110%) brightness(105%)',
			golden:   'sepia(35%) saturate(150%) hue-rotate(-15deg) brightness(105%)',
			toaster:  'sepia(40%) contrast(120%) brightness(95%) saturate(110%)',
			warm:     'saturate(130%) sepia(20%)',
			cool:     'saturate(110%) hue-rotate(15deg) brightness(105%)',
			fade:     'contrast(85%) brightness(110%) saturate(80%)',
			matte:    'contrast(80%) brightness(112%) saturate(85%)',
			pastel:   'brightness(115%) saturate(75%) contrast(90%)',
			vivid:    'saturate(160%) contrast(110%)',
			crisp:    'contrast(140%) saturate(135%) brightness(102%)',
			dramatic: 'contrast(140%) brightness(95%) saturate(120%)',
			negative: 'invert(100%)'
		};
		var IMG_STYLE_SHADOW = {
			light:  '0 2px 8px rgba(0,0,0,0.15)',
			medium: '0 4px 16px rgba(0,0,0,0.25)',
			heavy:  '0 8px 30px rgba(0,0,0,0.35)'
		};

		function imgStyleVal( id ) {
			var el = document.getElementById( id );
			return el ? el.value : '';
		}

		function applyImageStylesPreview() {
			var imgs = document.querySelectorAll( '#ml-gallery-preview .ml-gallery-item img' );
			if ( ! imgs.length ) { return; }

			var filter = IMG_STYLE_PRESETS[ imgStyleVal( 'ml_gallery_filter' ) ] || '';

			var radius = parseInt( imgStyleVal( 'ml_gallery_corner_radius' ), 10 ) || 0;
			var bw     = parseInt( imgStyleVal( 'ml_gallery_border_width' ), 10 ) || 0;
			var bs     = imgStyleVal( 'ml_gallery_border_style' ) || 'solid';
			var bc     = imgStyleVal( 'ml_gallery_border_color' ) || '#dddddd';
			var shadow = IMG_STYLE_SHADOW[ imgStyleVal( 'ml_gallery_box_shadow' ) ] || '';
			var op     = parseInt( imgStyleVal( 'ml_gallery_opacity' ), 10 );
			if ( isNaN( op ) ) { op = 100; }

			var transform = [];
			var deg  = parseInt( imgStyleVal( 'ml_gallery_rotate' ), 10 ) || 0;
			var flip = imgStyleVal( 'ml_gallery_flip' ) || 'none';
			if ( deg !== 0 ) { transform.push( 'rotate(' + deg + 'deg)' ); }
			if ( flip === 'h' ) { transform.push( 'scaleX(-1)' ); }
			else if ( flip === 'v' ) { transform.push( 'scaleY(-1)' ); }
			else if ( flip === 'both' ) { transform.push( 'scale(-1,-1)' ); }
			var transformCss = transform.join( ' ' );

			imgs.forEach( function ( img ) {
				// Content effects stay on the image.
				img.style.filter          = filter;
				img.style.webkitFilter    = filter;
				img.style.borderRadius    = radius > 0 ? radius + 'px' : '';
				img.style.opacity         = op < 100 ? ( op / 100 ) : '';
				img.style.transform       = transformCss;
				img.style.webkitTransform = transformCss;

				// Frame effects (border, shadow) go on the wrapper so the
				// filter can't desaturate the border and the shadow isn't
				// clipped. Mirrors the front-end split (image vs `> a`).
				var frame = img.closest( '.ml-gallery-item' ) || img;
				frame.style.boxShadow = shadow;
				if ( bw > 0 ) {
					frame.style.border       = bw + 'px ' + bs + ' ' + bc;
					frame.style.borderRadius = radius > 0 ? radius + 'px' : '';
					// Hide the item's default chrome border to avoid doubling.
					if ( frame !== img ) { img.style.border = 'none'; }
				} else {
					frame.style.border       = '';
					frame.style.borderRadius = '';
					if ( frame !== img ) { img.style.border = ''; }
				}
			} );
		}

		if ( $.fn.wpColorPicker ) {
			// Iris fires no native change/input on the underlying <input>, so the
			// delegated live-preview listeners never see colour changes. Re-dispatch
			// a native change so both the arrange-grid styling and the Preview iframe
			// refresh — the setTimeout lets Iris write the value first.
			$( '.ml-gallery-color-picker' ).wpColorPicker( {
				change: function () {
					var $input = $( this );
					setTimeout( function () {
						applyImageStylesPreview();
						$input.trigger( 'change' );
					}, 0 );
				},
				clear:  function () {
					applyImageStylesPreview();
					$( this ).trigger( 'change' );
				}
			} );
		}

		$( document ).on( 'change input', '[name^="ml_gallery_image_styles["]', applyImageStylesPreview );
		// Re-apply to thumbnails added to the preview grid after load.
		$( document ).on( 'ml-gallery-image-added', applyImageStylesPreview );
		applyImageStylesPreview();

		$( document ).on( 'input', '.ml-gallery-range', function () {
			const $val   = $( this ).closest( '.ml-gallery-setting' ).find( '.ml-gallery-range-value' );
			const isPx   = $( this ).is( '#ml_gallery_gap, #ml_gallery_height, #ml_gallery_caption_text_size, #ml_gallery_corner_radius, #ml_gallery_border_width, #ml_gallery_frame_border_width' );
			const isMs   = $( this ).is( '#ml_gallery_autoplay_interval, #ml_gallery_pro_autoplay_interval' );
			const isPct  = $( this ).is( '#ml_gallery_opacity' );
			const suffix = isPx ? 'px' : ( isMs ? 'ms' : ( isPct ? '%' : '' ) );
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
			var hideHeight  = layout === 'masonry' || layout === 'carousel' || layout === 'showcase';
			var hideGap     = layout === 'carousel' || layout === 'showcase';
			var hideModal   = layout === 'carousel' || layout === 'showcase';
			$( '.ml-gallery-columns-row' ).toggleClass( 'is-hidden', hideColumns );
			$( '.ml-gallery-mobile-columns-row' ).toggleClass( 'is-hidden', hideColumns );
			$( '.ml-gallery-height-row' ).toggleClass( 'is-hidden', hideHeight );
			$( '.ml-gallery-gap-row' ).toggleClass( 'is-hidden', hideGap );
			$( '.ml-show-in-modal-row' ).toggleClass( 'is-hidden', hideModal );
			$( '.ml-carousel-frame-row' ).toggleClass( 'is-hidden', layout !== 'carousel' );
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

		// Button/icon colour pickers (in the Appearance panel) only apply to a grid-like
		// gallery that opens a window with a button/icon trigger — gate on all three.
		function toggleTriggerColorRows( modeOverride ) {
			var layout = $( 'input[name="ml_gallery_settings[layout]"]:checked' ).val() || 'grid';
			var openInLightbox = $( '#ml_gallery_open_in_lightbox' ).prop( 'checked' );
			var available = layout !== 'carousel' && layout !== 'showcase' && openInLightbox;
			var select = document.querySelector( '.ml-trigger-select' );
			var mode = modeOverride || ( select ? select.value : 'image' );
			$( '.ml-button-colors-row' ).toggleClass( 'is-hidden', ! ( available && mode === 'button' ) );
			$( '.ml-icon-colors-row' ).toggleClass( 'is-hidden', ! ( available && mode === 'icon' ) );
		}

// Init on page load
		const $checkedLayout = $( 'input[name="ml_gallery_settings[layout]"]:checked' );
		if ( $checkedLayout.length ) {
			var $openInLightbox = $( '#ml_gallery_open_in_lightbox' );
			toggleColumnsRow( $checkedLayout.val() );
			toggleCaptionLightboxOptions( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
			toggleLightboxSections( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
			toggleButtonSettingsPanel( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
			toggleTriggerColorRows();
		}

		$( document ).on( 'change', 'input[name="ml_gallery_settings[layout]"]', function () {
			var openInLightbox = $( '#ml_gallery_open_in_lightbox' ).prop( 'checked' );
			toggleColumnsRow( this.value );
			toggleCaptionLightboxOptions( this.value, openInLightbox );
			toggleLightboxSections( this.value, openInLightbox );
			toggleButtonSettingsPanel( this.value, openInLightbox );
			toggleTriggerColorRows();
		} );

		$( document ).on( 'change', '#ml_gallery_open_in_lightbox', function () {
			var layout = $( 'input[name="ml_gallery_settings[layout]"]:checked' ).val() || 'grid';
			toggleCaptionLightboxOptions( layout, this.checked );
			toggleLightboxSections( layout, this.checked );
			toggleButtonSettingsPanel( layout, this.checked );
			toggleTriggerColorRows();
		} );

		// "How to open images" dropdown. Choosing an option updates the two
		// hidden booleans, shows the matching sub-fields, and refreshes the live
		// preview (hidden inputs don't emit change, so we nudge one).
		var triggerRoot = document.querySelector( '.ml-trigger-mode' );
		if ( triggerRoot && window.mlLightboxTrigger ) {
			var applyTriggerSubfields = function ( mode ) {
				$( '.ml-button-text-row' ).toggleClass( 'is-hidden', mode !== 'button' );
				$( '.ml-button-position-row' ).toggleClass( 'is-hidden', mode === 'image' );
				toggleTriggerColorRows( mode );
			};
			window.mlLightboxTrigger.initTriggerMode( triggerRoot, function ( mode ) {
				applyTriggerSubfields( mode );
				$( triggerRoot ).find( '.ml-trigger-show' ).trigger( 'change' );
			} );
			// Reflect the server-rendered initial mode without firing a preview.
			var triggerSelect = triggerRoot.querySelector( '.ml-trigger-select' );
			if ( triggerSelect ) {
				applyTriggerSubfields( triggerSelect.value );
			}
		}
	} );

	// ── Settings sidebar accordion ────────────────────────────────────────── //
	//
	// Collapse the long settings sidebar into single-open panels. Progressive
	// enhancement: the collapse CSS only applies once we add .is-accordion, so a
	// failure here leaves every panel expanded and reachable.

	$( function () {
		var $sidebar = $( '.ml-gallery-sidebar' );
		if ( ! $sidebar.length ) {
			return;
		}

		var $panels = $sidebar.find( '.ml-gallery-sidebar-panel' );
		var chevron = '<span class="ml-accordion-chevron" aria-hidden="true">' +
			'<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" ' +
			'stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">' +
			'<polyline points="6 9 12 15 18 9"/></svg></span>';

		// Wrap each heading's text in a button, keeping the <h3> as the heading
		// landmark (the WAI-ARIA accordion pattern: button inside heading).
		$panels.each( function () {
			var $header = $( this ).children( 'h3' ).first();
			if ( ! $header.length ) {
				return;
			}
			var $btn = $( '<button type="button" class="ml-accordion-toggle" aria-expanded="false">' )
				.text( $header.text() )
				.append( chevron );
			$header.empty().append( $btn );
		} );

		$sidebar.addClass( 'is-accordion' );

		function openPanel( $panel ) {
			$panels.removeClass( 'is-open' )
				.find( '.ml-accordion-toggle' ).attr( 'aria-expanded', 'false' );
			$panel.addClass( 'is-open' )
				.find( '.ml-accordion-toggle' ).attr( 'aria-expanded', 'true' );
		}

		function firstVisiblePanel() {
			return $panels.not( '.is-hidden' ).first();
		}

		// Open the first visible panel (Layout) on load.
		openPanel( firstVisiblePanel() );

		$sidebar.on( 'click', '.ml-accordion-toggle', function () {
			var $panel = $( this ).closest( '.ml-gallery-sidebar-panel' );
			if ( $panel.hasClass( 'is-open' ) ) {
				$panel.removeClass( 'is-open' );
				$( this ).attr( 'aria-expanded', 'false' );
			} else {
				openPanel( $panel );
			}
		} );

		// A layout / "open in gallery window" change can hide whole panels
		// (e.g. Toolbar, Appearance). If that hides the open one, fall back to
		// the first still-visible panel so the sidebar is never fully collapsed.
		// Runs after the existing change handlers have toggled .is-hidden.
		$( document ).on( 'change',
			'input[name="ml_gallery_settings[layout]"], #ml_gallery_open_in_lightbox',
			function () {
				window.setTimeout( function () {
					if ( ! $panels.filter( '.is-open' ).not( '.is-hidden' ).length ) {
						var $fallback = firstVisiblePanel();
						if ( $fallback.length ) {
							openPanel( $fallback );
						}
					}
				}, 0 );
			}
		);
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

	// ── Live preview: Preview ⇄ Arrange ─────────────────────────────────── //
	( function () {
		const $modes = $( '.ml-gallery-preview-modes' );
		if ( ! $modes.length || typeof wp === 'undefined' || ! wp.apiFetch ) {
			return;
		}
		const $frame = $modes.find( '.ml-gallery-preview-frame' );
		let refreshTimer = null;
		let lastRequested = 0;

		// The gallery's lightGallery instance, which ml-lightgallery-init.js stores
		// on the container as `_mlLgInstance`. Returns null for inline layouts
		// (carousel/showcase render lightGallery in-page, so there's no modal state
		// to preserve — they already reflect settings on re-render).
		function currentGalleryInstance() {
			try {
				const doc = $frame[ 0 ].contentDocument;
				if ( ! doc || doc.querySelector( '.lg-container.lg-inline' ) ) { return null; }
				const el = doc.querySelector( '.ml-gallery-container' );
				return ( el && el._mlLgInstance ) || null;
			} catch ( e ) {
				return null; // cross-origin/torn-down frame
			}
		}

		// If the modal lightbox is open, replay the open on the freshly-rendered
		// gallery at the same image so a setting change doesn't close it. Reuses
		// the existing instance's openGallery() (the same path a click builds) —
		// no new lightbox code. Polls because the new frame re-inits on load.
		function restoreLightbox( index, stamp ) {
			let tries = 0;
			( function poll() {
				if ( stamp !== lastRequested || $modes.attr( 'data-mode' ) !== 'preview' ) { return; }
				const inst = currentGalleryInstance();
				if ( inst && typeof inst.openGallery === 'function' ) {
					const count = ( inst.galleryItems && inst.galleryItems.length ) || 0;
					if ( ! count ) { return; } // lightbox no longer applies (e.g. toggled off)
					const target = Math.max( 0, Math.min( index, count - 1 ) );
					// Reopen without the fade so it reads as "stayed open", then
					// restore the durations for later manual opens in this frame.
					const s = inst.settings || {};
					const bd = s.backdropDuration;
					const sa = s.startAnimationDuration;
					s.backdropDuration = 0;
					s.startAnimationDuration = 0;
					inst.openGallery( target );
					setTimeout( function () {
						s.backdropDuration = bd;
						s.startAnimationDuration = sa;
					}, 60 );
					return;
				}
				if ( ++tries < 40 ) { setTimeout( poll, 50 ); } // wait up to ~2s for re-init
			} )();
		}

		function renderPreview() {
			const state = collectGalleryState();
			if ( ! state.id ) {
				// Unsaved gallery: the preview route requires a real post id.
				return;
			}
			// Capture an open modal lightbox before the reload tears it down.
			const openInst = currentGalleryInstance();
			const reopenAt = ( openInst && openInst.lgOpened ) ? openInst.index : null;
			const stamp = ++lastRequested;
			wp.apiFetch( {
				path: '/ml-slider-lightbox/v1/gallery/preview',
				method: 'POST',
				data: state,
			} ).then( function ( res ) {
				if ( stamp !== lastRequested ) { return; } // drop stale responses
				if ( reopenAt !== null ) {
					$frame.one( 'load', function () { restoreLightbox( reopenAt, stamp ); } );
				}
				$frame[ 0 ].srcdoc = res.html;
			} ).catch( function () { /* leave the previous frame visible */ } );
		}

		function schedulePreview() {
			if ( $modes.attr( 'data-mode' ) !== 'preview' ) { return; }
			clearTimeout( refreshTimer );
			refreshTimer = setTimeout( renderPreview, 300 );
		}

		// Fast-path for image-styles/appearance edits: those are pure per-gallery CSS,
		// so fetch just that block (css_only) and swap it into the live iframe's
		// <style> in place. No reload — an open lightbox stays open, no flicker, and
		// the round-trip carries only the CSS text (no HTML/images/re-init).
		let cssTimer = null;
		let lastCssRequested = 0;

		function previewStyleEl() {
			try {
				return $frame[ 0 ].contentDocument.getElementById( 'ml-preview-inline-css' );
			} catch ( e ) {
				return null; // torn-down or not-yet-loaded frame
			}
		}

		function patchPreviewCss() {
			const state = collectGalleryState();
			if ( ! state.id ) { return; }
			if ( ! previewStyleEl() ) { renderPreview(); return; } // nothing to patch yet
			state.css_only = 1;
			const stamp = ++lastCssRequested;
			wp.apiFetch( {
				path: '/ml-slider-lightbox/v1/gallery/preview',
				method: 'POST',
				data: state,
			} ).then( function ( res ) {
				if ( stamp !== lastCssRequested ) { return; } // drop stale responses
				const el = previewStyleEl();
				if ( el ) { el.textContent = res.css || ''; }
			} ).catch( function () { /* keep the current styles */ } );
		}

		function schedulePreviewCss() {
			if ( $modes.attr( 'data-mode' ) !== 'preview' ) { return; }
			clearTimeout( cssTimer );
			cssTimer = setTimeout( patchPreviewCss, 200 );
		}

		function setMode( mode ) {
			$modes.attr( 'data-mode', mode );
			$modes.find( '.ml-mode-btn' ).each( function () {
				$( this ).toggleClass( 'is-active', $( this ).data( 'mode-target' ) === mode );
			} );
			if ( mode === 'preview' ) { renderPreview(); }
		}

		$modes.on( 'click', '.ml-mode-btn', function () {
			setMode( $( this ).data( 'mode-target' ) );
		} );

		$modes.on( 'click', '.ml-viewport-btn', function () {
			const viewport = $( this ).data( 'viewport-target' );
			$modes.attr( 'data-viewport', viewport );
			$modes.find( '.ml-viewport-btn' ).each( function () {
				$( this ).toggleClass( 'is-active', $( this ).data( 'viewport-target' ) === viewport );
			} );
			// Only Preview mode shows the iframe; re-render so the server drops/keeps
			// the desktop-column override for the chosen viewport.
			if ( $modes.attr( 'data-mode' ) === 'preview' ) { renderPreview(); }
		} );

		// Re-render when any sidebar field or the image set changes. Image-styles and
		// appearance are pure per-gallery CSS (except caption_transition, which sets a
		// data attribute read at lightGallery init), so patch those in place instead
		// of reloading; everything else does a full re-render.
		$( document ).on( 'change input', '[name^="ml_gallery_settings["],[name^="ml_gallery_appearance["],[name^="ml_gallery_image_styles["],[name^="ml_gallery_pro_settings["]', function ( e ) {
			const name = ( e.target && e.target.name ) || '';
			if ( /^ml_gallery_(image_styles|appearance)\[/.test( name ) && name !== 'ml_gallery_appearance[caption_transition]' ) {
				schedulePreviewCss();
			} else {
				schedulePreview();
			}
		} );
		$( document ).on( 'ml-gallery:images-changed', schedulePreview );

		// Initialise: reflect the server-set default mode and paint the toggle.
		setMode( $modes.attr( 'data-mode' ) );
	} )();

} )( jQuery );

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { collectGalleryState: collectGalleryState };
}
