/**
 * WellActually swipe mode frontend.
 *
 * Vanilla ES6, no build step. `window.waSwipe` (printed via
 * wp_localize_script) provides { restUrl, nonce, isLoggedIn, homeUrl, siteName }.
 *
 * State machine (built out across issues #8-#13): loading -> card -> reveal -> done.
 */
( function () {
	'use strict';

	var config = window.waSwipe || {};
	var appEl = document.getElementById( 'wa-app' );

	console.log( 'WellActually config', config );

	if ( appEl ) {
		appEl.innerHTML = '<div class="wa-loading">WellActually loaded</div>';
	}
} )();
