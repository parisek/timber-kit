import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const require = createRequire( import.meta.url );
const root = fileURLToPath( new URL( '../../../', import.meta.url ) );

export const SCRIPT_PATH = `${ root }assets/js/gutenberg-resizable-sidebar.js`;
export const STORAGE_KEY = 'portadesign_gutenberg_sidebar_width';

const readFile = ( path ) => readFileSync( path, 'utf8' );
const jquerySource = readFile( require.resolve( 'jquery/dist/jquery.js' ) );
const jqueryUiSource = readFile( require.resolve( 'jquery-ui/dist/jquery-ui.js' ) );

const open = [];

/** Close every window `boot()` opened. A window left open keeps its timers, and the test process, alive. */
export function closeAll() {
	while ( open.length ) {
		open.pop().close();
	}
}

/** Resolve after `ms` of real time. rAF runs at 60 Hz in jsdom (pretendToBeVisual). */
export const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/**
 * Block editor shell as WordPress renders it: the layout wrapper, the sidebar
 * region that React mounts, and the pinned-items toolbar.
 */
const SHELL = `
	<div class="edit-post-layout">
		<div class="interface-interface-skeleton__body">
			<div class="interface-interface-skeleton__content"></div>
			<div class="interface-interface-skeleton__sidebar" role="region"></div>
		</div>
		<div class="interface-pinned-items"><button type="button" id="pin">Settings</button></div>
	</div>`;

/**
 * Boot a jsdom editor, load jQuery 3.7.1 and jQuery UI 1.14, then run the
 * script under test the way WordPress does: after the DOM exists.
 *
 * Counters are installed before the script runs, so they see every call.
 *
 * @param {object}   [options]
 * @param {boolean}  [options.jqueryUi=true]  Load jQuery UI. `false` models a page where the dependency is missing.
 * @param {string}   [options.shell]          Replace the editor markup.
 * @param {string}   [options.width]          Value to seed into localStorage before the script runs.
 * @param {boolean}  [options.storageThrows]  Make localStorage throw, as a blocked-cookies browser does.
 * @param {boolean}  [options.run=true]       Run the script now; `false` returns before the run.
 */
export function boot( options = {} ) {
	const { jqueryUi = true, shell = SHELL, width, storageThrows = false, run = true } = options;

	const dom = new JSDOM( `<!doctype html><html><body>${ shell }</body></html>`, {
		url: 'https://example.test/wp-admin/post.php',
		runScripts: 'outside-only',
		pretendToBeVisual: true,
	} );
	const { window } = dom;
	open.push( window );

	if ( storageThrows ) {
		Object.defineProperty( window, 'localStorage', {
			get() {
				throw new window.DOMException( 'denied', 'SecurityError' );
			},
		} );
	} else if ( undefined !== width ) {
		window.localStorage.setItem( STORAGE_KEY, width );
	}

	window.eval( jquerySource );
	if ( jqueryUi ) {
		window.eval( jqueryUiSource );
	}
	const $ = window.jQuery;

	const counts = { resizable: 0, destroy: 0, computedStyle: 0, widthSetter: 0 };
	// Runs of sync() leave one scan for the sidebar each. Kept apart from `counts`,
	// which tests compare for "the script called nothing".
	const scans = { sidebar: 0 };
	const { document } = window;
	for ( const method of [ 'querySelector', 'querySelectorAll' ] ) {
		const original = document[ method ].bind( document );
		document[ method ] = ( selector, ...rest ) => {
			if ( '.interface-interface-skeleton__sidebar' === selector ) {
				scans.sidebar++;
			}
			return original( selector, ...rest );
		};
	}
	if ( $.fn.resizable ) {
		const original = $.fn.resizable;
		$.fn.resizable = function ( ...args ) {
			if ( 'destroy' === args[ 0 ] ) {
				counts.destroy++;
			} else if ( 'string' !== typeof args[ 0 ] ) {
				counts.resizable++;
			}
			return original.apply( this, args );
		};
		Object.assign( $.fn.resizable, original );
	}
	const getComputedStyle = window.getComputedStyle.bind( window );
	window.getComputedStyle = ( ...args ) => {
		counts.computedStyle++;
		return getComputedStyle( ...args );
	};
	const originalWidth = $.fn.width;
	$.fn.width = function ( ...args ) {
		if ( args.length && null !== args[ 0 ] && undefined !== args[ 0 ] ) {
			counts.widthSetter++;
		}
		return originalWidth.apply( this, args );
	};

	// Every DOM write under <body>, counted by a probe that is independent of the script.
	let writes = 0;
	const probe = new window.MutationObserver( ( records ) => {
		writes += records.length;
	} );
	const attach = () => probe.observe( window.document.body, {
		childList: true, subtree: true, attributes: true, characterData: true,
	} );
	attach();

	const api = {
		window,
		document: window.document,
		$,
		counts,
		scans,
		sidebar: () => window.document.getElementsByClassName( 'interface-interface-skeleton__sidebar' )[ 0 ],
		layout: () => window.document.querySelector( '.edit-post-layout' ),
		pin: () => window.document.getElementById( 'pin' ),
		handles: ( el = api.sidebar() ) => [ ...el.children ].filter( ( c ) => c.classList.contains( 'ui-resizable-handle' ) ),
		/** DOM writes since the last call, by anyone. Takes the probe's pending records first. */
		takeWrites() {
			writes += probe.takeRecords().length;
			const n = writes;
			writes = 0;
			return n;
		},
		/** Let the script's debounce run: three animation frames, then a short tick. */
		settle: () => new Promise( ( resolve ) => {
			let frames = 3;
			const next = () => ( --frames > 0 ? window.requestAnimationFrame( next ) : setTimeout( resolve, 20 ) );
			window.requestAnimationFrame( next );
		} ),
		start() {
			window.eval( readFile( SCRIPT_PATH ) );
			return api;
		},
	};

	if ( run ) {
		api.start();
	}
	return api;
}
