/* ML Gallery — justified layout calculation */

/**
 * Strip .ml-item-hidden from the next `batch` still-hidden items.
 * Pure DOM mutation, no side effects beyond the class change, so the reveal
 * handler stays testable. Falls back to 12 for a missing or unusable batch.
 */
function revealNextBatch( container, batch ) {
	var size = parseInt( batch, 10 );
	if ( isNaN( size ) || size < 1 ) {
		size = 12;
	}
	var hidden   = Array.prototype.slice.call( container.querySelectorAll( 'a.ml-item-hidden' ) );
	var revealed = hidden.slice( 0, size );
	revealed.forEach( function ( a ) {
		a.classList.remove( 'ml-item-hidden' );
	} );
	return { revealed: revealed, remaining: hidden.length - revealed.length };
}

( function () {
	'use strict';

	function justify( container ) {
		var gap       = parseFloat( getComputedStyle( container ).getPropertyValue( '--ml-gap' ) ) || 8;
		var rowHeight = parseFloat( getComputedStyle( container ).getPropertyValue( '--ml-row-height' ) ) || 220;
		var items     = Array.prototype.slice.call( container.querySelectorAll( 'a' ) )
			.filter( function ( a ) {
					return ! a.classList.contains( 'ml-lightbox-button' )
					&& ! a.classList.contains( 'ml-item-hidden' )
					&& !! a.querySelector( 'img' );
			} );
		if ( ! items.length ) return;

		var cs       = getComputedStyle( container );
		var rowWidth = container.clientWidth - ( parseFloat( cs.paddingLeft ) || 0 ) - ( parseFloat( cs.paddingRight ) || 0 );
		if ( rowWidth <= 0 ) return;

		var metas = items.map( function ( item ) {
			var img = item.querySelector( 'img' );
			var w = ( img && ( img.naturalWidth  || parseFloat( img.getAttribute( 'width' ) ) ) )  || 4;
			var h = ( img && ( img.naturalHeight || parseFloat( img.getAttribute( 'height' ) ) ) ) || 3;
			var aspect = w / h;
			return { item: item, baseWidth: aspect * rowHeight };
		} );

		function flushRow( row, sumBaseWidth ) {
			if ( ! row.length ) return;
			var k = ( rowWidth - gap * ( row.length - 1 ) ) / sumBaseWidth;
			row.forEach( function ( m ) {
				m.item.style.width = Math.floor( m.baseWidth * k ) + 'px';
			} );
		}

		var row = [];
		var sumBaseWidth = 0;
		metas.forEach( function ( m ) {
			row.push( m );
			sumBaseWidth += m.baseWidth;
			if ( sumBaseWidth + gap * ( row.length - 1 ) >= rowWidth ) {
				flushRow( row, sumBaseWidth );
				row = [];
				sumBaseWidth = 0;
			}
		} );
		flushRow( row, sumBaseWidth );
	}

	function justifyWhenLoaded( container ) {
		var images = Array.prototype.slice.call( container.querySelectorAll( 'a:not(.ml-item-hidden) img' ) );
		var loaded = 0;
		var total  = images.length;

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
	}

	function initLoadMore( button ) {
		var container = document.getElementById( button.getAttribute( 'aria-controls' ) );
		if ( ! container ) return;
		var wrap   = button.parentNode;
		var status = wrap.querySelector( '.ml-load-more-status' );

		button.addEventListener( 'click', function () {
			var result = revealNextBatch( container, button.getAttribute( 'data-ml-batch' ) );
			if ( ! result.revealed.length ) return;

			// Must run before refresh: this is what strips href/data-src from the
			// wrapper anchors, so a revealed-but-unprocessed item would navigate
			// to the raw image instead of opening the gallery window.
			if ( typeof container._mlEnsureButtons === 'function' ) {
				container._mlEnsureButtons();
			}

			if ( container.getAttribute( 'data-ml-layout' ) === 'justified' ) {
				justifyWhenLoaded( container );
			}

			if ( container._mlLgInstance && typeof container._mlLgInstance.refresh === 'function' ) {
				container._mlLgInstance.refresh();
			}

			if ( status ) {
				var template = status.getAttribute( 'data-ml-status-template' ) || '%1$d more images loaded, %2$d remaining';
				status.textContent = template
					.replace( '%1$d', result.revealed.length )
					.replace( '%2$d', result.remaining );
			}

			if ( ! result.remaining ) {
				// Only the button goes — the status span has to survive to carry
				// the final announcement to a screen reader.
				wrap.removeChild( button );
				var first = result.revealed[ 0 ];
				if ( first ) {
					first.setAttribute( 'tabindex', '-1' );
					first.focus();
				}
			}
		} );
	}

	function initShowcase( container ) {
		var links        = Array.prototype.slice.call( container.querySelectorAll( 'a[data-src]' ) );
		var showThumbs   = container.getAttribute( 'data-lg-thumbnails' ) === '1';
		var showControls = container.getAttribute( 'data-lg-controls' ) === '1';
		var showCounter  = container.getAttribute( 'data-lg-counter' ) === '1';
		var showPager    = container.getAttribute( 'data-lg-pager' ) === '1';
		if ( ! links.length ) return;

		var current           = 0;
		var initialized       = false;
		var slideMode         = container.getAttribute( 'data-lg-mode' ) || 'lg-fade';
		var captionTransition = container.getAttribute( 'data-lg-caption-transition' ) || 'none';

		var stage    = document.createElement( 'div' );
		stage.className = 'ml-showcase-stage';
		var stageImg = document.createElement( 'img' );
		stageImg.className = 'ml-showcase-img';
		stageImg.alt = '';
		stage.appendChild( stageImg );

		var showCaption = container.classList.contains( 'ml-has-thumb-captions' );
		var captionEl   = null;
		if ( showCaption ) {
			captionEl = document.createElement( 'div' );
			captionEl.className = 'ml-showcase-caption';
			stage.appendChild( captionEl );
		}

		// Capture phase fires before child handlers (e.g. ml-lightbox-wrapper),
		// preventing the main plugin from opening a second single-image lightbox.
		stage.addEventListener( 'click', function ( e ) {
			e.stopImmediatePropagation();
			e.preventDefault();
			if ( container._mlLgInstance ) {
				container._mlLgInstance.openGallery( current );
			}
		}, true );

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

		var nav       = null;
		var prevBtn   = null;
		var nextBtn   = null;
		var counterEl = null;
		if ( showControls || showCounter ) {
			nav = document.createElement( 'div' );
			nav.className = 'ml-showcase-nav';
			if ( showControls ) {
				prevBtn = document.createElement( 'button' );
				prevBtn.type = 'button';
				prevBtn.className = 'ml-showcase-btn ml-showcase-prev';
				prevBtn.setAttribute( 'aria-label', 'Previous' );
				prevBtn.innerHTML = '&larr;';
				nav.appendChild( prevBtn );
				prevBtn.addEventListener( 'click', function () { goTo( current - 1 ); } );
			}
			if ( showCounter ) {
				counterEl = document.createElement( 'span' );
				counterEl.className = 'ml-showcase-counter';
				nav.appendChild( counterEl );
			}
			if ( showControls ) {
				nextBtn = document.createElement( 'button' );
				nextBtn.type = 'button';
				nextBtn.className = 'ml-showcase-btn ml-showcase-next';
				nextBtn.setAttribute( 'aria-label', 'Next' );
				nextBtn.innerHTML = '&rarr;';
				nav.appendChild( nextBtn );
				nextBtn.addEventListener( 'click', function () { goTo( current + 1 ); } );
			}
		}

		var pagerEl   = null;
		var pagerDots = [];
		if ( showPager ) {
			pagerEl = document.createElement( 'div' );
			pagerEl.className = 'ml-showcase-pager';
			links.forEach( function ( _, i ) {
				var dot = document.createElement( 'button' );
				dot.type = 'button';
				dot.className = 'ml-showcase-pager-dot';
				dot.setAttribute( 'aria-label', 'Go to slide ' + ( i + 1 ) );
				dot.addEventListener( 'click', function () { goTo( i ); } );
				pagerEl.appendChild( dot );
				pagerDots.push( dot );
			} );
		}

		container.insertBefore( stage, container.firstChild );
		var lastInserted = stage;
		if ( thumbStrip ) {
			container.insertBefore( thumbStrip, lastInserted.nextSibling );
			lastInserted = thumbStrip;
		}
		if ( nav ) {
			container.insertBefore( nav, lastInserted.nextSibling );
			lastInserted = nav;
		}
		if ( pagerEl ) {
			container.insertBefore( pagerEl, lastInserted.nextSibling );
		}

		function animateImage( newSrc, newAlt, dir ) {
			var stale = stage.querySelector( '.ml-showcase-incoming' );
			if ( stale && stale.parentNode ) { stale.parentNode.removeChild( stale ); }

			var incoming = document.createElement( 'img' );
			incoming.className = 'ml-showcase-img ml-showcase-incoming';
			incoming.alt = newAlt;

			var initClass = 'ml-tr-fade';
			if ( slideMode === 'lg-slide' ) {
				initClass = dir < 0 ? 'ml-tr-slide-prev' : 'ml-tr-slide-next';
			} else if ( slideMode === 'lg-zoom-in-out' ) {
				initClass = 'ml-tr-zoom';
			}
			incoming.classList.add( initClass );
			incoming.src = newSrc;
			stage.appendChild( incoming );

			// Reflow so the transition runs from the initial state.
			void incoming.offsetWidth;
			incoming.classList.add( 'ml-tr-active' );

			var finalize = function () {
				if ( incoming._mlDone ) { return; }
				incoming._mlDone = true;
				stageImg.src = newSrc;
				stageImg.alt = newAlt;
				if ( incoming.parentNode ) { incoming.parentNode.removeChild( incoming ); }
			};
			incoming.addEventListener( 'transitionend', finalize, { once: true } );
			setTimeout( finalize, 600 );
		}

		function goTo( index ) {
			var prevIndex = current;
			current = ( index + links.length ) % links.length;
			var sourceImg = links[ current ].querySelector( 'img' );
			var newSrc    = links[ current ].getAttribute( 'data-src' ) || ( sourceImg ? sourceImg.src : '' );
			var newAlt    = sourceImg ? ( sourceImg.alt || '' ) : '';

			if ( ! initialized ) {
				stageImg.src = newSrc;
				stageImg.alt = newAlt;
			} else {
				animateImage( newSrc, newAlt, current < prevIndex ? -1 : 1 );
			}

			if ( counterEl ) {
				counterEl.textContent = ( current + 1 ) + ' / ' + links.length;
			}
			thumbItems.forEach( function ( t, i ) {
				t.classList.toggle( 'is-active', i === current );
			} );
			pagerDots.forEach( function ( dot, i ) {
				dot.classList.toggle( 'is-active', i === current );
			} );
			if ( captionEl ) {
				var srcCaption = links[ current ].querySelector( '.ml-gallery-caption' );
				var capHtml    = srcCaption ? srcCaption.innerHTML : '';
				captionEl.innerHTML     = capHtml;
				captionEl.style.display = capHtml ? '' : 'none';
				if ( capHtml && captionTransition !== 'none' ) {
					captionEl.classList.remove( 'ml-showcase-cap-' + captionTransition );
					void captionEl.offsetWidth;
					captionEl.classList.add( 'ml-showcase-cap-' + captionTransition );
				}
			}
		}

		goTo( 0 );
		initialized = true;
	}

	function init() {
		document.querySelectorAll( '.ml-layout-showcase[data-ml-layout]' ).forEach( initShowcase );

		document.querySelectorAll( '.ml-load-more' ).forEach( initLoadMore );

		var containers = document.querySelectorAll( '.ml-layout-justified[data-ml-layout]' );
		if ( ! containers.length ) return;

		containers.forEach( justifyWhenLoaded );

		if ( typeof ResizeObserver !== 'undefined' ) {
			var scheduled = false;
			var ro = new ResizeObserver( function ( entries ) {
				if ( scheduled ) return;
				scheduled = true;
				requestAnimationFrame( function () {
					scheduled = false;
					entries.forEach( function ( entry ) {
						var width = Math.round( entry.contentRect.width );
						if ( entry.target._mlLastWidth === width ) return;
						entry.target._mlLastWidth = width;
						justify( entry.target );
					} );
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

if ( typeof module !== 'undefined' && module.exports ) {
	module.exports = { revealNextBatch: revealNextBatch };
}
