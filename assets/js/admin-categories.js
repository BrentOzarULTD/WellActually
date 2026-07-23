/**
 * Settings → "Well, Actually..." → Categories tab.
 *
 * Skipping a category skips everything beneath it, so show the descendants as
 * checked and lock them while the parent is. They submit nothing while
 * disabled, which is fine — only the parent needs storing, and the server
 * expands it back out to the whole branch (so categories added under it later
 * are covered too).
 *
 * Vanilla ES5, no build step. No user-visible strings.
 */
( function () {
	'use strict';

	var boxes = Array.prototype.slice.call( document.querySelectorAll( '.wa-cat-checkbox' ) );
	if ( ! boxes.length ) { return; }

	// term id -> its direct child checkboxes.
	var childrenOf = {};
	boxes.forEach( function ( box ) {
		var parent = box.getAttribute( 'data-parent-id' );
		if ( ! childrenOf[ parent ] ) { childrenOf[ parent ] = []; }
		childrenOf[ parent ].push( box );
	} );

	function apply( box ) {
		var kids = childrenOf[ box.getAttribute( 'data-term-id' ) ] || [];
		kids.forEach( function ( kid ) {
			if ( box.checked || box.disabled ) {
				kid.checked = true;
				kid.disabled = true;
			} else {
				kid.disabled = false;
			}
			apply( kid );
		} );
	}

	boxes.forEach( function ( box ) {
		box.addEventListener( 'change', function () { apply( box ); } );
	} );

	// Top-level first, so locking cascades down the tree on load.
	( childrenOf[ '0' ] || [] ).forEach( apply );
}() );
