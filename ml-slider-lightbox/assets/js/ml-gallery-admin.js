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
	const VIEWPORTS = [ 'desktop', 'laptop', 'tablet', 'mobile' ];
	const modes = document.querySelector( '.ml-gallery-preview-modes' );
	const state = {
		id: ( typeof mlGalleryAdmin !== 'undefined' && mlGalleryAdmin.galleryId ) || 0,
		interactive: 1,
		viewport: VIEWPORTS.indexOf( modes && modes.getAttribute( 'data-viewport' ) ) !== -1
			? modes.getAttribute( 'data-viewport' )
			: 'desktop',
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

	function imageSortKeys( attachment ) {
		let name = attachment.filename || '';
		if ( ! name && attachment.url ) {
			name = attachment.url.split( '?' )[0].split( '#' )[0].split( '/' ).pop();
		}
		const parsed = attachment.date ? Date.parse( attachment.date ) : NaN;
		const date   = isNaN( parsed )
			? Math.floor( Date.now() / 1000 )
			: Math.floor( parsed / 1000 ) + ( mlGalleryAdmin.gmtOffset || 0 );
		return {
			date    : date,
			filename: name.toLowerCase(),
		};
	}

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

		if ( $( '#ml-gallery-preview [data-id="' + id + '"]' ).length ) {
			return;
		}

		const keys    = imageSortKeys( attachment );
		const $item   = $( '<div>' ).addClass( 'ml-gallery-item' )
			.attr( {
				'data-id'      : id,
				'data-full'    : attachment.url || thumb,
				'data-date'    : keys.date,
				'data-filename': keys.filename,
			} );
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

	$( document ).on( 'click', '#ml-gallery-add-images', function ( e ) {
		e.preventDefault();
		closeAddMenu();
		openMediaFrame( 'browse' );
	} );

	$( document ).on( 'click', '#ml-gallery-preview.is-empty', function ( e ) {
		e.preventDefault();
		closeAddMenu();
		openMediaFrame( 'browse' );
	} );

	$( document ).on( 'click', '.ml-gallery-add-caret', function ( e ) {
		e.preventDefault();
		const $menu = $( '.ml-gallery-add-menu' );
		const willOpen = $menu.prop( 'hidden' );
		$menu.prop( 'hidden', ! willOpen );
		$( this ).attr( 'aria-expanded', String( willOpen ) );
	} );

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

	$( document ).on( 'click', function ( e ) {
		if ( ! $( e.target ).closest( '.ml-gallery-add-split' ).length ) {
			closeAddMenu();
		}
	} );

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
					m = res.data.message;
				} else if ( '0' === res || '-1' === res || 0 === res ) {
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
				m = jqXHR.responseJSON.data.message;
			} else if ( jqXHR && 413 === jqXHR.status ) {
				m = mlGalleryAdmin.zipRejected;
			} else if ( jqXHR && jqXHR.status ) {
				m += ' (HTTP ' + jqXHR.status + ')';
			}
			$( '#ml-zip-status' ).text( m );
		} );
	} );

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

	function captionPreviewSrc( $item ) {
		return $item.attr( 'data-full' ) || $item.find( 'img' ).attr( 'src' );
	}

	function openCaptionModal( $item ) {
		$editingItem      = $item;

		$( '#ml-caption-preview-img' ).attr( 'src', captionPreviewSrc( $item ) );
		$( '#ml-caption-modal' ).addClass( 'is-open' );
		$( 'body' ).addClass( 'ml-modal-open' );

		updateCaptionNav();

		const wpEd = getWpEditor();
		if ( wpEd && wpEd.remove ) {
			wpEd.remove( EDITOR_ID );
		}

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

	function commitCurrentSource() {
		if ( ! $editingItem || ! captionFields ) { return $.Deferred().resolve().promise(); }

		const field = captionFields[ currentSource ] || { editable: true };
		if ( ! field.editable ) { return $.Deferred().resolve().promise(); }

		const content = getEditorContent();
		captionFields[ currentSource ] = $.extend( {}, field, { value: content } );

		if ( currentSource === 'manual' ) {
			$editingItem.find( '.ml-gallery-caption-input' ).val( content );
			$( document ).trigger( 'ml-gallery:images-changed' );
		}

		refreshCaptionBar( $editingItem );

		const id     = $editingItem.data( 'id' );
		const saving = saveCaptionField( id, currentSource, content );

		if ( currentSource !== 'manual' ) {
			saving.done( function () {
				$( document ).trigger( 'ml-gallery:images-changed' );
			} );
		}

		return saving;
	}

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
		const index  = $items.index( $editingItem );

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
		const hasText = $( '<textarea>' ).html( content.replace( /<[^>]*>/g, '' ) ).val().trim() !== '';
		$( '#ml-caption-preview-overlay' ).html( hasText ? content : '' );
	}

	function navigateCaption( direction ) {
		if ( ! $editingItem ) {
			return;
		}

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

	// wplink's URL bar and Link options dialog sit above the modal and handle
	// their own Esc; closing the caption modal underneath them would strand the
	// half-inserted link.
	function linkUiOpen() {
		return ( window.wpLink && window.wpLink.modalOpen ) ||
			$( '.mce-inline-toolbar-grp:visible' ).length > 0;
	}

	$( document ).on( 'keydown', function ( e ) {
		if ( 27 === e.which && $( '#ml-caption-modal' ).hasClass( 'is-open' ) && ! linkUiOpen() ) {
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
				img.style.filter          = filter;
				img.style.webkitFilter    = filter;
				img.style.borderRadius    = radius > 0 ? radius + 'px' : '';
				img.style.opacity         = op < 100 ? ( op / 100 ) : '';
				img.style.transform       = transformCss;
				img.style.webkitTransform = transformCss;

				var frame = img.closest( '.ml-gallery-item' ) || img;
				frame.style.boxShadow = shadow;
				if ( bw > 0 ) {
					frame.style.border       = bw + 'px ' + bs + ' ' + bc;
					frame.style.borderRadius = radius > 0 ? radius + 'px' : '';
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

		$( document ).on( 'change', '#ml_gallery_autoplay, #ml_gallery_pro_autoplay', function () {
			$( '.ml-autoplay-interval-row' ).toggleClass( 'is-hidden', ! this.checked );
		} );

		$( document ).on( 'change', '#ml_gallery_download', function () {
			$( '.ml-download-sizes-promo' ).toggleClass( 'is-hidden', ! this.checked );
		} );

		$( document ).on( 'change', '#ml_gallery_load_more', function () {
			$( '.ml-gallery-load-more-batch-row' ).toggleClass( 'is-hidden', ! this.checked );
			toggleTriggerColorRows();
		} );

		$( document ).on( 'change', '#ml_gallery_caption_display', function () {
			var value = this.value;
			$( '.ml-caption-style-field' ).toggleClass( 'is-hidden', 'hidden' === value );
			$( '.ml-caption-window-only' ).toggleClass( 'is-hidden', 'hidden' === value || 'gallery' === value );
			$( '.ml-caption-gallery-only' ).toggleClass( 'is-hidden', 'hidden' === value || 'lightbox' === value );
			var layout = $( 'input[name="ml_gallery_settings[layout]"]:checked' ).val() || 'grid';
			$( '.ml-caption-align-row' ).toggleClass( 'is-hidden', 'hidden' === value || 'lightbox' === value || 'carousel' === layout );
		} );

		function toggleColumnsRow( layout ) {
			var hideColumns = layout === 'justified' || layout === 'carousel' || layout === 'showcase';
			var hideHeight  = layout === 'masonry' || layout === 'carousel' || layout === 'showcase';
			var hideGap     = layout === 'carousel' || layout === 'showcase';
			var hideModal   = layout === 'carousel' || layout === 'showcase';
			var hideLoadMore = layout === 'carousel' || layout === 'showcase';
			$( '.ml-gallery-columns-row' ).toggleClass( 'is-hidden', hideColumns );
			$( '.ml-gallery-height-row' ).toggleClass( 'is-hidden', hideHeight );
			$( '.ml-gallery-gap-row' ).toggleClass( 'is-hidden', hideGap );
			$( '.ml-gallery-load-more-row' ).toggleClass( 'is-hidden', hideLoadMore );
			$( '.ml-gallery-load-more-batch-row' ).toggleClass(
				'is-hidden',
				hideLoadMore || ! $( '#ml_gallery_load_more' ).prop( 'checked' )
			);
			$( '.ml-show-in-modal-row' ).toggleClass( 'is-hidden', hideModal );
			$( '.ml-carousel-frame-row' ).toggleClass( 'is-hidden', layout !== 'carousel' );
			$( '.ml-expand-row' ).toggleClass( 'is-hidden', layout !== 'carousel' );
			var captionDisplay = $( '#ml_gallery_caption_display' ).val();
			$( '.ml-caption-align-row' ).toggleClass(
				'is-hidden',
				'carousel' === layout || 'hidden' === captionDisplay || 'lightbox' === captionDisplay
			);
		}

		function toggleCustomSizeRow( $select ) {
			var rowClass = $select.data( 'ml-size-select' );
			if ( ! rowClass ) {
				return;
			}
			var isCustom = 'custom' === $select.val();
			$( '.' + rowClass ).toggleClass( 'is-hidden', ! isCustom );

			var hiddenGroup = $select.closest( '.ml-window-size-group' ).hasClass( 'is-hidden' );
			var id = $select.attr( 'id' );
			$( '#' + id + '_w, #' + id + '_h' ).prop( 'required', isCustom && ! hiddenGroup );
		}

		function generateCustomSizes() {
			var jobs = [];

			$.each( [ 'gallery_size', 'lightbox_size' ], function ( i, key ) {
				var $select = $( '#ml_gallery_' + key );
				if ( 'custom' !== $select.val() ) {
					return;
				}
				if ( $select.closest( '.ml-window-size-group' ).hasClass( 'is-hidden' ) ) {
					return;
				}

				var w = parseInt( $( '#ml_gallery_' + key + '_w' ).val(), 10 );
				var h = parseInt( $( '#ml_gallery_' + key + '_h' ).val(), 10 );
				if ( ! w || ! h ) {
					return;
				}

				jobs.push( {
					w: w,
					h: h,
					crop: $( '#ml_gallery_' + key + '_crop' ).is( ':checked' ) ? 1 : 0
				} );
			} );

			if ( ! jobs.length ) {
				return;
			}

			var ids = ( $( '#ml_gallery_images' ).val() || '' )
				.split( ',' )
				.filter( function ( id ) { return id !== ''; } );
			if ( ! ids.length ) {
				return;
			}

			// One flat queue of batches across every job, so only one request
			// is ever in flight and the queue shrinks unconditionally before
			// each request resolves — this is what guarantees nextBatch()
			// terminates even if every request fails.
			var batches = [];
			$.each( jobs, function ( i, job ) {
				var remaining = ids.slice();
				while ( remaining.length ) {
					batches.push( { job: job, ids: remaining.splice( 0, 10 ) } );
				}
			} );

			var $notice = $( '.ml-save-notice' );
			var $status = $( '.ml-size-gen-status' ).removeClass( 'is-hidden' );
			var $statusText = $status.find( '.ml-size-gen-text' );
			var $spinner = $status.find( '.ml-size-gen-spinner' ).addClass( 'is-active' );
			var total = ids.length * jobs.length;
			var done = 0;
			var failed = 0;

			$notice.removeClass( 'notice-success' ).addClass( 'notice-info' )
				.find( '.ml-save-notice-text' ).addClass( 'is-hidden' );

			function nextBatch() {
				if ( ! batches.length ) {
					$spinner.removeClass( 'is-active' );
					$status.toggleClass( 'has-failures', failed > 0 );
					$notice.removeClass( 'notice-info' ).addClass( 'notice-success' )
						.find( '.ml-save-notice-text' ).removeClass( 'is-hidden' );
					$statusText.text(
						failed
							? mlGalleryAdmin.sizesFailed.replace( '%d', failed )
							: mlGalleryAdmin.sizesDone
					);
					return;
				}

				var current = batches.shift();

				$.post( ajaxurl, {
					action: 'ml_gallery_generate_sizes',
					_wpnonce: mlGalleryAdmin.sizesNonce,
					ids: current.ids,
					w: current.job.w,
					h: current.job.h,
					crop: current.job.crop
				} ).done( function ( res ) {
					if ( res && res.success ) {
						done += res.data.done;
						failed += res.data.failed;
					} else {
						failed += current.ids.length;
					}
				} ).fail( function () {
					failed += current.ids.length;
				} ).always( function () {
					$statusText.text(
						mlGalleryAdmin.sizesProgress
							.replace( '%1$d', done + failed )
							.replace( '%2$d', total )
					);
					nextBatch();
				} );
			}

			nextBatch();
		}

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
			$( '.ml-lightbox-settings-group, .ml-lightbox-only-panel, .ml-window-size-group' ).toggleClass( 'is-hidden', hide );
			$( '[data-ml-size-select]' ).each( function () {
				toggleCustomSizeRow( $( this ) );
			} );
		}

		function toggleButtonSettingsPanel( layout, openInLightbox ) {
			var isGridLike = layout !== 'carousel' && layout !== 'showcase';
			var hide = ! isGridLike || ! openInLightbox;
			$( '.ml-button-settings-group' ).toggleClass( 'is-hidden', hide );
		}

		function toggleTriggerColorRows( modeOverride ) {
			var layout = $( 'input[name="ml_gallery_settings[layout]"]:checked' ).val() || 'grid';
			var openInLightbox = $( '#ml_gallery_open_in_lightbox' ).prop( 'checked' );
			var isGridLike = layout !== 'carousel' && layout !== 'showcase';
			var available = isGridLike && openInLightbox;
			var loadMore = isGridLike && $( '#ml_gallery_load_more' ).prop( 'checked' );
			var select = document.querySelector( '.ml-trigger-select' );
			var mode = modeOverride || ( select ? select.value : 'image' );
			$( '.ml-button-colors-row' ).toggleClass( 'is-hidden', ! ( ( available && mode === 'button' ) || loadMore ) );
			$( '.ml-icon-colors-row' ).toggleClass( 'is-hidden', ! ( available && mode === 'icon' ) );
		}

		const $checkedLayout = $( 'input[name="ml_gallery_settings[layout]"]:checked' );
		if ( $checkedLayout.length ) {
			var $openInLightbox = $( '#ml_gallery_open_in_lightbox' );
			toggleColumnsRow( $checkedLayout.val() );
			toggleCaptionLightboxOptions( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
			toggleLightboxSections( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
			toggleButtonSettingsPanel( $checkedLayout.val(), $openInLightbox.prop( 'checked' ) );
			toggleTriggerColorRows();
		}

		if ( 'undefined' !== typeof mlGalleryAdmin && '1' === mlGalleryAdmin.justSaved ) {
			generateCustomSizes();
		}

		$( '[data-ml-size-select]' ).each( function () {
			toggleCustomSizeRow( $( this ) );
		} );

		$( document ).on( 'change', 'input[name="ml_gallery_settings[layout]"]', function () {
			var openInLightbox = $( '#ml_gallery_open_in_lightbox' ).prop( 'checked' );
			toggleColumnsRow( this.value );
			toggleCaptionLightboxOptions( this.value, openInLightbox );
			toggleLightboxSections( this.value, openInLightbox );
			toggleButtonSettingsPanel( this.value, openInLightbox );
			toggleTriggerColorRows();
		} );

		$( document ).on( 'change', '[data-ml-size-select]', function () {
			toggleCustomSizeRow( $( this ) );
		} );

		$( document ).on( 'change', '#ml_gallery_open_in_lightbox', function () {
			var layout = $( 'input[name="ml_gallery_settings[layout]"]:checked' ).val() || 'grid';
			toggleCaptionLightboxOptions( layout, this.checked );
			toggleLightboxSections( layout, this.checked );
			toggleButtonSettingsPanel( layout, this.checked );
			toggleTriggerColorRows();
		} );

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
			var triggerSelect = triggerRoot.querySelector( '.ml-trigger-select' );
			if ( triggerSelect ) {
				applyTriggerSubfields( triggerSelect.value );
			}
		}
	} );

	// ── Settings sidebar accordion ────────────────────────────────────────── //

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

	// ── Image order ───────────────────────────────────────────────────────── //

	const filenameCollator = new Intl.Collator( 'en', { numeric: true, sensitivity: 'base' } );

	function applyImageOrder() {
		const order  = $( '#ml_gallery_image_order' ).val() || 'manual';
		const $grid  = $( '#ml-gallery-preview' );
		const $items = $grid.find( '.ml-gallery-item' );
		const sorted = 'manual' !== order && 'random' !== order;

		if ( ! sorted ) {
			$items.css( 'order', '' );
		} else {
			const items    = $items.get();
			const domIndex = new Map();
			items.forEach( function ( el, i ) {
				domIndex.set( el, i );
			} );

			items.slice().sort( function ( a, b ) {
				let cmp;
				if ( 'newest' === order || 'oldest' === order ) {
					const da = parseInt( a.getAttribute( 'data-date' ), 10 ) || 0;
					const db = parseInt( b.getAttribute( 'data-date' ), 10 ) || 0;
					cmp = da === db ? 0 : ( da < db ? -1 : 1 );
					if ( 'newest' === order ) {
						cmp = -cmp;
					}
				} else {
					const fa = ( a.getAttribute( 'data-filename' ) || '' ).toLowerCase();
					const fb = ( b.getAttribute( 'data-filename' ) || '' ).toLowerCase();
					cmp = filenameCollator.compare( fa, fb );
					if ( 'ztoa' === order ) {
						cmp = -cmp;
					}
				}
				return 0 !== cmp ? cmp : domIndex.get( a ) - domIndex.get( b );
			} ).forEach( function ( el, i ) {
				el.style.order = i;
			} );
		}

		if ( $grid.data( 'ui-sortable' ) ) {
			$grid.sortable( 'manual' === order ? 'enable' : 'disable' );
		}
		$grid.toggleClass( 'is-order-locked', 'manual' !== order );
		$( '.ml-add-position' ).toggleClass( 'is-hidden', 'manual' !== order );

		let text = '';
		if ( 'random' === order ) {
			text = mlGalleryAdmin.orderNoticeRandom;
		} else if ( 'manual' !== order ) {
			text = mlGalleryAdmin.orderNoticeSorted
				.replace( '%s', $( '#ml_gallery_image_order option:selected' ).text() );
		}
		$( '.ml-image-order-notice' ).text( text ).prop( 'hidden', ! text );
	}

	$( document ).on( 'change', '#ml_gallery_image_order', applyImageOrder );
	$( document ).on( 'ml-gallery:images-changed', applyImageOrder );
	$( function () {
		applyImageOrder();
	} );

	// ── Live preview: Preview ⇄ Arrange ─────────────────────────────────── //
	( function () {
		const $modes = $( '.ml-gallery-preview-modes' );
		if ( ! $modes.length || typeof wp === 'undefined' || ! wp.apiFetch ) {
			return;
		}
		const $frame = $modes.find( '.ml-gallery-preview-frame' );
		const $main = $modes.closest( '.ml-gallery-main' );
		let refreshTimer = null;
		let lastRequested = 0;

		function isInlineLayout() {
			const layout = $( 'input[name="ml_gallery_settings[layout]"]:checked' ).val() || 'grid';
			return 'carousel' === layout || 'showcase' === layout;
		}

		function clearAutoHeight() {
			$frame.removeClass( 'is-auto-height' ).css( 'height', '' );
			$main.removeClass( 'is-auto-height' );
		}

		function sizeFrameToContent() {
			if ( ! isInlineLayout() || $modes.attr( 'data-mode' ) !== 'preview' ) {
				clearAutoHeight();
				return;
			}
			let height = 0;
			try {
				height = $frame[ 0 ].contentDocument.body.scrollHeight;
			} catch ( e ) {
				return;
			}
			if ( ! height ) { return; }
			$frame.addClass( 'is-auto-height' ).css( 'height', Math.ceil( height ) + 'px' );
			$main.addClass( 'is-auto-height' );
		}

		$frame.on( 'load', sizeFrameToContent );

		function currentGalleryInstance() {
			try {
				const doc = $frame[ 0 ].contentDocument;
				if ( ! doc || doc.querySelector( '.lg-container.lg-inline' ) ) { return null; }
				const el = doc.querySelector( '.ml-gallery-container' );
				return ( el && el._mlLgInstance ) || null;
			} catch ( e ) {
				return null;
			}
		}

		function restoreLightbox( index, stamp ) {
			let tries = 0;
			( function poll() {
				if ( stamp !== lastRequested || $modes.attr( 'data-mode' ) !== 'preview' ) { return; }
				const inst = currentGalleryInstance();
				if ( inst && typeof inst.openGallery === 'function' ) {
					const count = ( inst.galleryItems && inst.galleryItems.length ) || 0;
					if ( ! count ) { return; }
					const target = Math.max( 0, Math.min( index, count - 1 ) );
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
				if ( ++tries < 40 ) { setTimeout( poll, 50 ); }
			} )();
		}

		function renderPreview() {
			const state = collectGalleryState();
			const openInst = currentGalleryInstance();
			const reopenAt = ( openInst && openInst.lgOpened ) ? openInst.index : null;
			const stamp = ++lastRequested;
			wp.apiFetch( {
				path: '/ml-slider-lightbox/v1/gallery/preview',
				method: 'POST',
				data: state,
			} ).then( function ( res ) {
				if ( stamp !== lastRequested ) { return; }
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

		let cssTimer = null;
		let lastCssRequested = 0;

		function previewStyleEl() {
			try {
				return $frame[ 0 ].contentDocument.getElementById( 'ml-preview-inline-css' );
			} catch ( e ) {
				return null;
			}
		}

		function patchPreviewCss() {
			const state = collectGalleryState();
			if ( ! previewStyleEl() ) { renderPreview(); return; }
			state.css_only = 1;
			const stamp = ++lastCssRequested;
			wp.apiFetch( {
				path: '/ml-slider-lightbox/v1/gallery/preview',
				method: 'POST',
				data: state,
			} ).then( function ( res ) {
				if ( stamp !== lastCssRequested ) { return; }
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
			if ( mode === 'preview' ) {
				renderPreview();
			} else {
				clearAutoHeight();
			}
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
			if ( $modes.attr( 'data-mode' ) === 'preview' ) { renderPreview(); }
		} );

		const appearanceNeedsRender = [
			'ml_gallery_appearance[caption_transition]',
			'ml_gallery_appearance[caption_position]',
			'ml_gallery_appearance[caption_hover_reveal]',
		];
		$( document ).on( 'change input', '[name^="ml_gallery_settings["],[name^="ml_gallery_appearance["],[name^="ml_gallery_image_styles["],[name^="ml_gallery_pro_settings["]', function ( e ) {
			const name = ( e.target && e.target.name ) || '';
			if ( /^ml_gallery_(image_styles|appearance)\[/.test( name ) && appearanceNeedsRender.indexOf( name ) === -1 ) {
				schedulePreviewCss();
			} else {
				schedulePreview();
			}
		} );
		$( document ).on( 'ml-gallery:images-changed', schedulePreview );

		setMode( $modes.attr( 'data-mode' ) );
	} )();

} )( jQuery );

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { collectGalleryState: collectGalleryState };
}
