/* ML Gallery — justified layout calculation */
( function () {
	'use strict';

	/**
	 * Set flex-basis on each anchor proportional to its image's natural aspect
	 * ratio so the items fill rows at a consistent row height.
	 *
	 * @param {HTMLElement} container  .ml-layout-justified element
	 */
	function justify( container ) {
		var gap        = parseFloat( getComputedStyle( container ).getPropertyValue( '--ml-gap' ) ) || 8;
		var rowHeight  = 220;
		var items      = Array.prototype.slice.call( container.querySelectorAll( 'a' ) );

		items.forEach( function ( item ) {
			var img = item.querySelector( 'img' );
			if ( ! img ) return;

			var w = img.naturalWidth  || parseFloat( img.getAttribute( 'width' ) )  || 4;
			var h = img.naturalHeight || parseFloat( img.getAttribute( 'height' ) ) || 3;
			var aspect = w / h;

			item.style.flexBasis = ( aspect * rowHeight ) + 'px';
			item.style.maxWidth  = ( aspect * rowHeight * 2 ) + 'px';
		} );
	}

	function initShowcase( container ) {
		var links        = Array.prototype.slice.call( container.querySelectorAll( 'a[data-src]' ) );
		var showThumbs   = container.getAttribute( 'data-lg-thumbnails' ) === '1';
		if ( ! links.length ) return;

		var current = 0;

		// Stage
		var stage    = document.createElement( 'div' );
		stage.className = 'ml-showcase-stage';
		var stageImg = document.createElement( 'img' );
		stageImg.className = 'ml-showcase-img';
		stageImg.alt = '';
		stage.appendChild( stageImg );
		// Capture phase fires before child handlers (e.g. ml-lightbox-wrapper),
		// preventing the main plugin from opening a second single-image lightbox.
		stage.addEventListener( 'click', function ( e ) {
			e.stopImmediatePropagation();
			e.preventDefault();
			if ( container._mlLgInstance ) {
				container._mlLgInstance.openGallery( current );
			}
		}, true );

		// Thumbnail strip (optional)
		var thumbItems = [];
		var thumbStrip = null;
		if ( showThumbs ) {
			thumbStrip = document.createElement( 'div' );
			thumbStrip.className = 'ml-showcase-thumbs';
			links.forEach( function ( link, i ) {
				var sourceImg = link.querySelector( 'img' );
				var thumb     = document.createElement( 'button' );
				thumb.type    = 'button';
				thumb.className = 'ml-showcase-thumb';
				var t = document.createElement( 'img' );
				t.src = sourceImg ? sourceImg.src : link.getAttribute( 'data-src' );
				t.alt = sourceImg ? ( sourceImg.alt || '' ) : '';
				thumb.appendChild( t );
				thumb.addEventListener( 'click', function () { goTo( i ); } );
				thumbStrip.appendChild( thumb );
				thumbItems.push( thumb );
			} );
		}

		// Nav
		var nav      = document.createElement( 'div' );
		nav.className = 'ml-showcase-nav';
		var prevBtn  = document.createElement( 'button' );
		prevBtn.type = 'button';
		prevBtn.className = 'ml-showcase-btn ml-showcase-prev';
		prevBtn.setAttribute( 'aria-label', 'Previous' );
		prevBtn.innerHTML = '&larr;';
		var counterEl = document.createElement( 'span' );
		counterEl.className = 'ml-showcase-counter';
		var nextBtn  = document.createElement( 'button' );
		nextBtn.type = 'button';
		nextBtn.className = 'ml-showcase-btn ml-showcase-next';
		nextBtn.setAttribute( 'aria-label', 'Next' );
		nextBtn.innerHTML = '&rarr;';
		nav.appendChild( prevBtn );
		nav.appendChild( counterEl );
		nav.appendChild( nextBtn );
		prevBtn.addEventListener( 'click', function () { goTo( current - 1 ); } );
		nextBtn.addEventListener( 'click', function () { goTo( current + 1 ); } );

		// Insert: stage → thumbs (if any) → nav
		container.insertBefore( stage, container.firstChild );
		if ( thumbStrip ) {
			container.insertBefore( thumbStrip, stage.nextSibling );
		}
		container.insertBefore( nav, ( thumbStrip || stage ).nextSibling );

		function goTo( index ) {
			current = ( index + links.length ) % links.length;
			var sourceImg = links[ current ].querySelector( 'img' );
			stageImg.src = links[ current ].getAttribute( 'data-src' ) || ( sourceImg ? sourceImg.src : '' );
			stageImg.alt = sourceImg ? ( sourceImg.alt || '' ) : '';
			counterEl.textContent = ( current + 1 ) + ' / ' + links.length;
			thumbItems.forEach( function ( t, i ) {
				t.classList.toggle( 'is-active', i === current );
			} );
		}

		goTo( 0 );
	}

	function init() {
		// Showcase layout
		document.querySelectorAll( '.ml-layout-showcase[data-ml-layout]' ).forEach( initShowcase );

		// Justified layout
		var containers = document.querySelectorAll( '.ml-layout-justified[data-ml-layout]' );
		if ( ! containers.length ) return;

		containers.forEach( function ( container ) {
			var images  = Array.prototype.slice.call( container.querySelectorAll( 'img' ) );
			var loaded  = 0;
			var total   = images.length;

			function onLoad() {
				loaded++;
				if ( loaded >= total ) justify( container );
			}

			if ( total === 0 ) {
				justify( container );
				return;
			}

			images.forEach( function ( img ) {
				if ( img.complete && img.naturalWidth ) {
					onLoad();
				} else {
					img.addEventListener( 'load',  onLoad );
					img.addEventListener( 'error', onLoad );
				}
			} );
		} );

		// Re-justify on resize
		if ( typeof ResizeObserver !== 'undefined' ) {
			var ro = new ResizeObserver( function ( entries ) {
				entries.forEach( function ( entry ) {
					justify( entry.target );
				} );
			} );
			containers.forEach( function ( c ) { ro.observe( c ); } );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
