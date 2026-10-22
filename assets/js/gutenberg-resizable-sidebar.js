/**
 * Resizable block editor sidebar.
 *
 * Makes the settings sidebar draggable on its west edge, keeps its width in
 * localStorage, and mirrors the pinned-items state onto the layout as
 * `is-sidebar-opened` (the stylesheet hides the sidebar without it).
 *
 * React owns the sidebar element. It can replace the element, reset its class
 * or inline style, and remove the widget's handle. The script repairs each of
 * these once, when it happens.
 *
 * There is no timer. One MutationObserver wakes `sync()` at most once per
 * frame, and `sync()` only reads attributes and writes when a check fails.
 * Its own writes therefore end the cycle they start. This matters on a page
 * whose sidebar holds a large ACF block form: jQuery UI reads computed style
 * on every `.resizable()` call, and a timer that calls it blocked the main
 * thread for a quarter of a second per tick.
 */
( function ( window, $ ) {
	'use strict';

	var STORAGE_KEY = 'portadesign_gutenberg_sidebar_width';
	var SIDEBAR = '.interface-interface-skeleton__sidebar';
	var LAYOUTS = '.edit-post-layout, .edit-site-layout';
	var PRESSED = '.interface-pinned-items button.is-pressed';
	var OPEN = 'is-sidebar-opened';

	// Per sidebar element: the inline width this script last saw or wrote.
	var seen = new WeakMap();
	var queued = false;

	function storedWidth() {
		var raw;
		try {
			raw = window.localStorage.getItem( STORAGE_KEY );
		} catch ( e ) {
			return null; // Storage is blocked.
		}
		var width = parseFloat( raw );
		return isFinite( width ) && width > 0 ? width : null;
	}

	function remember( width ) {
		try {
			window.localStorage.setItem( STORAGE_KEY, width );
		} catch ( e ) {
			// Storage is blocked. The width lasts until the next load.
		}
	}

	function hasHandle( el ) {
		for ( var i = 0; i < el.children.length; i++ ) {
			if ( el.children[ i ].classList.contains( 'ui-resizable-handle' ) ) {
				return true;
			}
		}
		return false;
	}

	function makeResizable( el ) {
		var $el = $( el );
		if ( ! $el.resizable ) {
			return; // jQuery UI is missing.
		}
		if ( el.classList.contains( 'ui-resizable' ) && hasHandle( el ) ) {
			return;
		}
		// React reset the class or removed the handle. Re-initialising over a
		// live widget would stack a second handle, so remove the first.
		if ( $el.resizable( 'instance' ) ) {
			$el.resizable( 'destroy' );
		}
		$el.resizable( {
			handles: 'w',
			resize: function () {
				var $this = $( this );
				$this.css( { left: 0 } );
				remember( $this.width() );
				seen.set( this, this.style.width );
			},
		} );
	}

	function applyWidth( el ) {
		var width = storedWidth();
		if ( null === width || seen.get( el ) === el.style.width ) {
			return;
		}
		$( el ).width( width );
		seen.set( el, el.style.width );
	}

	function syncOpenState() {
		var open = null !== window.document.querySelector( PRESSED );
		var layouts = window.document.querySelectorAll( LAYOUTS );
		for ( var i = 0; i < layouts.length; i++ ) {
			if ( layouts[ i ].classList.contains( OPEN ) !== open ) {
				layouts[ i ].classList.toggle( OPEN, open );
			}
		}
	}

	function sync() {
		var sidebar = window.document.querySelector( SIDEBAR );
		if ( sidebar ) {
			makeResizable( sidebar );
			applyWidth( sidebar );
		}
		syncOpenState();
	}

	function schedule() {
		if ( queued ) {
			return;
		}
		queued = true;
		( window.requestAnimationFrame || window.setTimeout )( function () {
			queued = false;
			sync();
		} );
	}

	$( function () {
		sync();
		new window.MutationObserver( schedule ).observe( window.document.body, {
			childList: true,
			subtree: true,
			attributes: true,
			attributeFilter: [ 'class', 'style' ],
		} );
	} );
}( window, window.jQuery ) );
