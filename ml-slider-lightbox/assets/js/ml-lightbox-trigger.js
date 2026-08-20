/**
 * Shared "how to open images" trigger-mode control.
 *
 * Approach A: the visible dropdown is backed by the two existing boolean hidden
 * inputs. These pure mappers convert between the booleans and the single mode
 * string; initTriggerMode() wires the <select>. No stored data or front-end
 * read ever changes.
 */
( function ( root ) {
	'use strict';

	function modeFromBooleans( showButton, useIcon ) {
		if ( ! showButton ) { return 'image'; }
		return useIcon ? 'icon' : 'button';
	}

	function booleansFromMode( mode ) {
		return {
			showButton: mode === 'icon' || mode === 'button',
			useIcon: mode === 'icon',
		};
	}

	function initTriggerMode( rootEl, onChange ) {
		if ( ! rootEl ) { return; }
		var hiddenShow = rootEl.querySelector( '.ml-trigger-show' );
		var hiddenIcon = rootEl.querySelector( '.ml-trigger-icon' );
		var dropdown = rootEl.querySelector( '.ml-trigger-select' );
		if ( ! dropdown ) { return; }

		dropdown.addEventListener( 'change', function () {
			var mode = dropdown.value;
			var b = booleansFromMode( mode );
			if ( hiddenShow ) { hiddenShow.value = b.showButton ? '1' : '0'; }
			if ( hiddenIcon ) { hiddenIcon.value = b.useIcon ? '1' : '0'; }
			if ( typeof onChange === 'function' ) { onChange( mode ); }
		} );
	}

	var api = {
		modeFromBooleans: modeFromBooleans,
		booleansFromMode: booleansFromMode,
		initTriggerMode: initTriggerMode,
	};
	if ( typeof module !== 'undefined' && module.exports ) { module.exports = api; }
	root.mlLightboxTrigger = api;
} )( typeof window !== 'undefined' ? window : this );
