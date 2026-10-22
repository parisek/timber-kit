/**
 * Contract for assets/js/gutenberg-resizable-sidebar.js.
 *
 * The script runs inside the block editor, next to a sidebar that can hold an
 * ACF block form of several hundred fields (10,000+ DOM nodes). Its cost must
 * not depend on how often the page changes or on how large it is. Each test
 * below pins one part of that, against real jQuery 3.7.1 and jQuery UI 1.14.
 */
import { readFileSync } from 'node:fs';
import { afterEach, describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { boot, closeAll, SCRIPT_PATH, STORAGE_KEY, wait } from './helpers/sidebar-env.js';

/** Fill the content area with `count` nodes, as a large ACF form does. */
function mountLargeForm( env, count = 10000 ) {
	const host = env.document.createElement( 'div' );
	host.className = 'acf-fields';
	const parts = [];
	for ( let i = 0; i < count; i++ ) {
		parts.push( `<div class="acf-field"><input type="text" value="${ i }"></div>` );
	}
	host.innerHTML = parts.join( '' );
	env.sidebar().appendChild( host );
	return host;
}

describe( 'gutenberg-resizable-sidebar.js', () => {
	afterEach( closeAll );

	describe( 'source', () => {
		const source = readFileSync( SCRIPT_PATH, 'utf8' );

		it( 'does not poll', () => {
			assert.doesNotMatch( source, /\bsetInterval\s*\(/, 'a timer that fires while nothing changes is the defect this file once had' );
		} );
	} );

	describe( 'first run', () => {
		it( 'makes the sidebar a resizable widget with exactly one handle', async () => {
			const env = boot();
			await env.settle();

			assert.ok( env.sidebar().classList.contains( 'ui-resizable' ) );
			assert.equal( env.handles().length, 1 );
			assert.ok( env.handles()[ 0 ].classList.contains( 'ui-resizable-w' ), 'the handle is on the west edge' );
			assert.equal( env.counts.resizable, 1 );
		} );

		it( 'waits for a sidebar that React mounts later', async () => {
			const env = boot( { shell: '<div class="edit-post-layout"><div class="interface-pinned-items"></div></div>' } );
			await env.settle();
			assert.equal( env.counts.resizable, 0, 'nothing to init yet' );

			const sidebar = env.document.createElement( 'div' );
			sidebar.className = 'interface-interface-skeleton__sidebar';
			env.layout().appendChild( sidebar );
			await env.settle();

			assert.equal( env.counts.resizable, 1 );
			assert.equal( env.handles( sidebar ).length, 1 );
		} );
	} );

	describe( 'cost', () => {
		it( 'does no work while nothing changes', async () => {
			const env = boot();
			await env.settle();
			env.takeWrites();
			const before = { ...env.counts };

			await wait( 1500 ); // three ticks of the old 500 ms timer

			assert.deepEqual( env.counts, before, 'no widget call, style read or width write while idle' );
			assert.equal( env.takeWrites(), 0, 'no DOM write while idle' );
		} );

		it( 'does not re-initialise the widget while a large form mounts and changes', async () => {
			const env = boot();
			await env.settle();
			const host = mountLargeForm( env );

			for ( let frame = 0; frame < 70; frame++ ) {
				host.children[ frame ].classList.toggle( 'is-open' );
				host.children[ frame + 100 ].setAttribute( 'style', `width:${ frame }px` );
				host.appendChild( env.document.createElement( 'div' ) );
				await wait( 20 );
			}
			await env.settle();

			assert.equal( env.counts.resizable, 1, 'one init for the whole session' );
			assert.equal( env.handles().length, 1 );
		} );

		it( 'reads computed style no more often than a plain init needs', async () => {
			const env = boot();
			await env.settle();
			mountLargeForm( env );
			await env.settle();
			const afterMount = env.counts.computedStyle;

			for ( let i = 0; i < 30; i++ ) {
				env.sidebar().firstElementChild.appendChild( env.document.createElement( 'span' ) );
				await wait( 20 );
			}
			await env.settle();

			assert.equal( env.counts.computedStyle, afterMount, 'ordinary page changes cause no style reads from this script' );
		} );

		it( 'adds no write of its own when an unrelated node changes', async () => {
			const env = boot( { width: '420' } );
			env.pin().classList.add( 'is-pressed' );
			await env.settle();
			env.takeWrites();
			const before = { ...env.counts };

			env.document.querySelector( '.interface-interface-skeleton__content' ).classList.add( 'changed' );
			await env.settle();

			assert.equal( env.takeWrites(), 1, 'only the test\'s own write; the script wrote nothing back' );
			assert.deepEqual( env.counts, before, 'and it called nothing' );
		} );

		it( 'runs at most once per frame, however many mutation batches arrive', async () => {
			const env = boot();
			await env.settle();
			const before = env.scans.sidebar;

			// Each await ends a microtask checkpoint, so the observer is called once per mutation.
			for ( let i = 0; i < 200; i++ ) {
				env.document.querySelector( '.interface-interface-skeleton__content' ).classList.toggle( 'busy' );
				await Promise.resolve();
			}
			await env.settle();

			const runs = env.scans.sidebar - before;
			assert.ok( runs >= 1, 'it did run' );
			assert.ok( runs <= 10, `200 batches caused ${ runs } runs; a debounce keeps this near one per frame` );
		} );

		it( 'settles: its own writes do not wake it again', async () => {
			const env = boot( { width: '420' } );
			await env.settle();
			env.takeWrites();

			await wait( 600 );

			assert.equal( env.takeWrites(), 0 );
		} );
	} );

	describe( 'React re-renders', () => {
		it( 'initialises a replacement sidebar element once', async () => {
			const env = boot();
			await env.settle();
			const old = env.sidebar();

			const fresh = env.document.createElement( 'div' );
			fresh.className = 'interface-interface-skeleton__sidebar';
			old.replaceWith( fresh );
			await env.settle();

			assert.equal( env.sidebar(), fresh );
			assert.ok( fresh.classList.contains( 'ui-resizable' ) );
			assert.equal( env.handles( fresh ).length, 1 );
			assert.equal( env.counts.resizable, 2, 'one init per element' );
		} );

		it( 'restores the widget once when React resets the class and leaves the handle', async () => {
			const env = boot();
			await env.settle();

			env.sidebar().className = 'interface-interface-skeleton__sidebar'; // what a React className update does
			await env.settle();

			assert.ok( env.sidebar().classList.contains( 'ui-resizable' ) );
			assert.equal( env.handles().length, 1, 'no duplicate handle' );

			const calls = env.counts.resizable;
			await wait( 600 );
			assert.equal( env.counts.resizable, calls, 'and then it stays still' );
		} );

		it( 'restores the widget when React removes the handle', async () => {
			const env = boot();
			await env.settle();

			env.handles()[ 0 ].remove();
			await env.settle();

			assert.equal( env.handles().length, 1 );
			assert.ok( env.sidebar().classList.contains( 'ui-resizable' ) );
		} );
	} );

	describe( 'stored width', () => {
		it( 'applies the stored width once', async () => {
			const env = boot( { width: '420' } );
			await env.settle();

			assert.equal( env.sidebar().style.width, '420px' );
			assert.equal( env.counts.widthSetter, 1 );

			await wait( 600 );
			assert.equal( env.counts.widthSetter, 1, 'not again on later frames' );
		} );

		it( 'sets no width when nothing is stored', async () => {
			const env = boot();
			await env.settle();

			assert.equal( env.sidebar().style.width, '' );
			assert.equal( env.counts.widthSetter, 0 );
		} );

		for ( const bad of [ '', 'abc', '0', '-5', 'NaN', 'Infinity', '420junk', '100%', '1e' ] ) {
			it( `ignores the stored value ${ JSON.stringify( bad ) }`, async () => {
				const env = boot( { width: bad } );
				await env.settle();

				assert.equal( env.sidebar().style.width, '' );
				assert.equal( env.counts.widthSetter, 0 );
			} );
		}

		it( 'puts the width back when React resets the inline style', async () => {
			const env = boot( { width: '420' } );
			await env.settle();

			env.sidebar().removeAttribute( 'style' );
			await env.settle();

			assert.equal( env.sidebar().style.width, '420px' );
		} );

		it( 'applies the stored width to a replacement element', async () => {
			const env = boot( { width: '420' } );
			await env.settle();

			const fresh = env.document.createElement( 'div' );
			fresh.className = 'interface-interface-skeleton__sidebar';
			env.sidebar().replaceWith( fresh );
			await env.settle();

			assert.equal( fresh.style.width, '420px' );
		} );

		it( 'stores the width when the user drags, and does not fight the drag', async () => {
			const env = boot( { width: '420' } );
			await env.settle();
			const sidebar = env.sidebar();

			// jQuery UI writes the new width while dragging, then calls `resize`.
			sidebar.style.width = '500px';
			env.$( sidebar ).resizable( 'option', 'resize' ).call( sidebar, {}, {} );
			await env.settle();

			assert.equal( env.window.localStorage.getItem( STORAGE_KEY ), '500' );
			assert.equal( sidebar.style.width, '500px', 'the stored width is the dragged width' );
			assert.equal( sidebar.style.left, '0px' );
		} );

		it( 'does not rewrite the width on any frame of a drag', async () => {
			const env = boot( { width: '420' } );
			await env.settle();
			const sidebar = env.sidebar();
			const setters = env.counts.widthSetter;
			const resize = env.$( sidebar ).resizable( 'option', 'resize' );

			for ( let step = 1; step <= 6; step++ ) {
				sidebar.style.width = `${ 420 + step * 15 }px`; // jQuery UI writes this on every mouse move
				resize.call( sidebar, {}, {} );
				await env.settle();

				assert.equal( sidebar.style.width, `${ 420 + step * 15 }px`, `frame ${ step } keeps the dragged width` );
			}
			assert.equal( env.counts.widthSetter, setters, 'the script never wrote the width while the user dragged' );
		} );

		it( 'keeps the dragged width on a replacement element when storage is blocked', async () => {
			const env = boot( { storageThrows: true } );
			await env.settle();
			const sidebar = env.sidebar();

			sidebar.style.width = '500px';
			env.$( sidebar ).resizable( 'option', 'resize' ).call( sidebar, {}, {} );
			const fresh = env.document.createElement( 'div' );
			fresh.className = 'interface-interface-skeleton__sidebar';
			sidebar.replaceWith( fresh );
			await env.settle();

			assert.equal( fresh.style.width, '500px' );
			assert.equal( env.handles( fresh ).length, 1 );
		} );

		it( 'manages every sidebar element, as the old jQuery selection did', async () => {
			const shell = `<div class="edit-post-layout"><div class="interface-interface-skeleton__sidebar" id="a"></div>
				<div class="interface-interface-skeleton__sidebar" id="b"></div></div>`;
			const env = boot( { shell, width: '420' } );
			await env.settle();

			for ( const id of [ 'a', 'b' ] ) {
				const el = env.document.getElementById( id );
				assert.equal( el.style.width, '420px', `#${ id } width` );
				assert.equal( env.handles( el ).length, 1, `#${ id } handle` );
			}
		} );
	} );

	describe( 'sidebar open state', () => {
		it( 'marks the layout open while a pinned button is pressed', async () => {
			const env = boot();
			await env.settle();
			assert.ok( !env.layout().classList.contains( 'is-sidebar-opened' ) );

			env.pin().classList.add( 'is-pressed' );
			await env.settle();
			assert.ok( env.layout().classList.contains( 'is-sidebar-opened' ) );

			env.pin().classList.remove( 'is-pressed' );
			await env.settle();
			assert.ok( !env.layout().classList.contains( 'is-sidebar-opened' ) );
		} );

		it( 'marks the layout open when the page loads with a pressed button', async () => {
			const shell = `<div class="edit-post-layout"><div class="interface-interface-skeleton__sidebar"></div>
				<div class="interface-pinned-items"><button class="is-pressed"></button></div></div>`;
			const env = boot( { shell } );
			await env.settle();

			assert.ok( env.layout().classList.contains( 'is-sidebar-opened' ) );
		} );

		it( 'covers the site editor layout as well', async () => {
			const shell = `<div class="edit-site-layout"><div class="interface-pinned-items"><button class="is-pressed"></button></div></div>`;
			const env = boot( { shell } );
			await env.settle();

			assert.ok( env.document.querySelector( '.edit-site-layout' ).classList.contains( 'is-sidebar-opened' ) );
		} );

		it( 'keeps the state when React replaces the layout element', async () => {
			const env = boot();
			env.pin().classList.add( 'is-pressed' );
			await env.settle();

			const fresh = env.document.createElement( 'div' );
			fresh.className = 'edit-post-layout';
			fresh.innerHTML = env.layout().innerHTML;
			env.layout().replaceWith( fresh );
			await env.settle();

			assert.ok( env.layout().classList.contains( 'is-sidebar-opened' ) );
		} );
	} );

	describe( 'hostile environments', () => {
		it( 'survives a localStorage that throws', async () => {
			const env = boot( { storageThrows: true } );
			await env.settle();

			assert.equal( env.handles().length, 1, 'the widget still works' );
			assert.equal( env.counts.widthSetter, 0 );
		} );

		it( 'survives a missing jQuery UI and still tracks the open state', async () => {
			const env = boot( { jqueryUi: false } );
			env.pin().classList.add( 'is-pressed' );
			await env.settle();

			assert.ok( env.layout().classList.contains( 'is-sidebar-opened' ) );
			assert.equal( env.sidebar().classList.contains( 'ui-resizable' ), false );
		} );

		it( 'survives a page without a sidebar', async () => {
			const env = boot( { shell: '<div id="other"></div>' } );
			await env.settle();

			assert.equal( env.counts.resizable, 0 );
		} );
	} );
} );
