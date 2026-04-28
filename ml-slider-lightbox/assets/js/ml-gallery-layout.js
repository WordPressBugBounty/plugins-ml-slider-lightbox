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

	function init() {
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
