/**
 * Posts → "Well, Actually..." bulk swipe setup screen.
 *
 * Progressive enhancement only: dim a row's inputs when it's excluded or
 * skipped, and drive the AI drafting loop.
 *
 * Vanilla ES5, no build step. `window.wellactuallyBulkSetup` (printed via
 * wp_localize_script) provides { restUrl, nonce, cat, author, concurrency,
 * reviewUrl, i18n }.
 */
( function () {
	'use strict';

	var cfg = window.wellactuallyBulkSetup || {};
	var i18n = cfg.i18n || {};

	/**
	 * Fill %1$s/%2$s-style placeholders, the way sprintf() would in PHP.
	 * Translators reorder them, so positions have to be honoured.
	 *
	 * @param {string} template Translated string with numbered placeholders.
	 * @param {Array}  values   Replacements, in argument order.
	 * @return {string}
	 */
	function format( template, values ) {
		return String( template ).replace( /%(\d+)\$s/g, function ( match, position ) {
			var value = values[ parseInt( position, 10 ) - 1 ];
			return 'undefined' === typeof value ? match : String( value );
		} );
	}

	// Dim statement/verdict when "Never" or "Skip" is checked. Both
	// leave the content untouched on save (Never clears it, Skip holds
	// it), so disabling the inputs is purely a visual cue.
	function syncRow( row ) {
		if ( ! row ) { return; }
		var exclude = row.querySelector( '.wa-exclude-input' );
		var skip = row.querySelector( '.wa-skip-input' );
		var off = ( exclude && exclude.checked ) || ( skip && skip.checked );
		row.classList.toggle( 'wa-row-excluded', !! ( exclude && exclude.checked ) );
		row.classList.toggle( 'wa-row-skipped', !! ( skip && skip.checked ) );
		var s = row.querySelector( '.wa-statement-input' );
		var v = row.querySelector( '.wa-verdict-input' );
		if ( s ) { s.disabled = off; }
		if ( v ) { v.disabled = off; }
	}

	var rows = document.querySelectorAll( '.wa-bulk-table .wa-row' );
	var checkAllSkip = document.querySelector( '.wa-check-all-skip' );
	var checkAllExclude = document.querySelector( '.wa-check-all-exclude' );

	/**
	 * Keep a header checkbox aligned with the row checkboxes beneath it.
	 *
	 * @param {HTMLInputElement|null} master Header checkbox.
	 * @param {string} selector Row-checkbox selector.
	 */
	function syncMaster( master, selector ) {
		if ( ! master ) { return; }
		var checked = 0;
		rows.forEach( function ( row ) {
			var input = row.querySelector( selector );
			if ( input && input.checked ) { checked++; }
		} );
		master.checked = rows.length > 0 && checked === rows.length;
		master.indeterminate = checked > 0 && checked < rows.length;
	}

	function syncMasters() {
		syncMaster( checkAllSkip, '.wa-skip-input' );
		syncMaster( checkAllExclude, '.wa-exclude-input' );
	}

	/**
	 * Apply a Skip or Never header checkbox to every row on this page.
	 *
	 * @param {string} selector Target row-checkbox selector.
	 * @param {string} oppositeSelector Mutually exclusive checkbox selector.
	 * @param {boolean} checked Desired target state.
	 */
	function setAllRows( selector, oppositeSelector, checked ) {
		rows.forEach( function ( row ) {
			var input = row.querySelector( selector );
			var opposite = row.querySelector( oppositeSelector );
			if ( input ) { input.checked = checked; }
			if ( checked && opposite ) { opposite.checked = false; }
			syncRow( row );
		} );
		syncMasters();
	}

	rows.forEach( function ( row ) {
		var exclude = row.querySelector( '.wa-exclude-input' );
		var skip = row.querySelector( '.wa-skip-input' );
		// Never and Skip are mutually exclusive in intent; unchecking the
		// other keeps the UI unambiguous.
		if ( exclude ) {
			exclude.addEventListener( 'change', function () {
				if ( exclude.checked && skip ) { skip.checked = false; }
				syncRow( row );
				syncMasters();
			} );
		}
		if ( skip ) {
			skip.addEventListener( 'change', function () {
				if ( skip.checked && exclude ) { exclude.checked = false; }
				syncRow( row );
				syncMasters();
			} );
		}
		syncRow( row );
	} );

	if ( checkAllSkip ) {
		checkAllSkip.addEventListener( 'change', function () {
			setAllRows( '.wa-skip-input', '.wa-exclude-input', checkAllSkip.checked );
		} );
	}
	if ( checkAllExclude ) {
		checkAllExclude.addEventListener( 'change', function () {
			setAllRows( '.wa-exclude-input', '.wa-skip-input', checkAllExclude.checked );
		} );
	}
	syncMasters();

	// AI drafting loop: enqueue N posts, then process one at a time,
	// showing live progress. Browser-driven so it never blocks a single
	// request and the user watches results as they arrive.
	var btn = document.getElementById( 'wa-ai-draft-btn' );
	var progress = document.getElementById( 'wa-ai-progress' );
	if ( ! cfg.restUrl || ! btn || ! progress ) { return; }

	/**
	 * Replace the progress line with a message and a "Review suggestions"
	 * link. Built as DOM nodes rather than markup so a translated string can
	 * never be parsed as HTML.
	 *
	 * @param {string} message Already-formatted message text.
	 */
	function progressWithReviewLink( message ) {
		progress.textContent = message + ' ';
		var link = document.createElement( 'a' );
		link.href = cfg.reviewUrl;
		link.textContent = i18n.reviewSuggestions;
		progress.appendChild( link );
	}

	function api( path, body ) {
		return fetch( cfg.restUrl + '/' + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': cfg.nonce
			},
			body: JSON.stringify( body || {} )
		} ).then( function ( r ) {
			if ( ! r.ok ) { throw new Error( 'Request failed: ' + r.status ); }
			return r.json();
		} );
	}

	var running = false;

	btn.addEventListener( 'click', function () {
		if ( running ) { return; }
		var countEl = document.getElementById( 'wa-ai-count' );
		var count = Math.max( 1, Math.min( 200, parseInt( countEl && countEl.value, 10 ) || 10 ) );

		running = true;
		btn.disabled = true;
		progress.textContent = i18n.queuing;

		// Hand back the previous batch id. If that run stopped early
		// with work outstanding, the server resumes it instead of
		// starting a new batch and stranding the old one's posts.
		var previous = '';
		try { previous = window.sessionStorage.getItem( 'waAiBatch' ) || ''; } catch ( e ) {}

		// wp_localize_script stringifies scalars, so these ids arrive as "0"
		// rather than 0; the REST route wants numbers.
		var cat = parseInt( cfg.cat, 10 ) || 0;
		var author = parseInt( cfg.author, 10 ) || 0;

		api( 'ai/enqueue', { count: count, cat: cat, author: author, batch: previous } ).then( function ( res ) {
			var batch = res.batch;
			var total = res.queued || 0;

			try { window.sessionStorage.setItem( 'waAiBatch', batch ); } catch ( e ) {}

			if ( ! total ) {
				progress.textContent = i18n.nothingToDraft;
				running = false;
				btn.disabled = false;
				return;
			}

			// Counted separately and never subtracted from one another:
			// a lost response is not a failed draft, and inferring one
			// from the other is what produced negative totals before.
			var drafted = 0, failed = 0, unknown = 0;
			// Set when the provider tells us we're going too fast.
			// Every worker checks it, so one refusal stops the run
			// rather than each worker discovering it separately.
			var abortedByRateLimit = false;

			function summary() {
				var parts = [ format( i18n.countDrafted, [ drafted ] ) ];
				if ( failed ) {
					parts.push( format( 1 === failed ? i18n.countError : i18n.countErrors, [ failed ] ) );
				}
				if ( unknown ) {
					parts.push( format( i18n.countUnconfirmed, [ unknown ] ) );
				}
				return parts.join( i18n.listSeparator );
			}

			function report() {
				progress.textContent = format( i18n.drafting, [ drafted + failed, total, summary() ] );
			}

			function finish( remaining ) {
				if ( abortedByRateLimit ) {
					try { window.sessionStorage.setItem( 'waAiBatch', batch ); } catch ( e ) {}
					progressWithReviewLink( i18n.rateLimited );
					running = false;
					btn.disabled = false;
					return;
				}

				if ( remaining > 0 ) {
					// The server still holds work for this batch, so
					// this run stopped short rather than finished.
					progressWithReviewLink( format( i18n.stoppedEarly, [ summary(), remaining ] ) );
				} else {
					try { window.sessionStorage.removeItem( 'waAiBatch' ); } catch ( e ) {}
					progressWithReviewLink( format( i18n.finished, [ summary() ] ) );
				}
				running = false;
				btn.disabled = false;
			}

			var workers = Math.max( 1, Math.min( 20, parseInt( cfg.concurrency, 10 ) || 5 ) );
			var alive = Math.min( workers, total );
			var lastRemaining = total;

			function retire() {
				alive--;
				if ( alive <= 0 ) { finish( lastRemaining ); }
			}

			function worker() {
				if ( abortedByRateLimit ) { retire(); return; }

				fetch( cfg.restUrl + '/ai/process', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': cfg.nonce
					},
					body: JSON.stringify( { batch: batch } )
				} ).then( function ( r ) {
					// Site-wide ceiling is full (other tabs, other
					// users). The work is still queued, so wait and
					// retry instead of counting a failure.
					if ( r.status === 429 ) {
						var wait = ( parseInt( r.headers.get( 'Retry-After' ), 10 ) || 2 ) * 1000;
						setTimeout( worker, wait );
						return null;
					}
					if ( ! r.ok ) { throw new Error( 'Request failed: ' + r.status ); }
					return r.json();
				} ).then( function ( out ) {
					if ( ! out ) { return; }

					if ( out.counts && typeof out.counts.remaining !== 'undefined' ) {
						lastRemaining = out.counts.remaining;
					}

					// Provider rate limit: stop the whole run. Note
					// this arrives as a field, not a 429 — a 429 here
					// is our own concurrency ceiling, which is a
					// "wait and retry", not a "stop".
					if ( 'rate_limited' === out.abort ) {
						abortedByRateLimit = true;
						retire();
						return;
					}

					if ( out.processed ) {
						if ( 'ready' === out.processed.status ) {
							drafted++;
						} else if ( 'stale' === out.processed.status ) {
							// Reassigned to another run mid-flight;
							// whoever owns it now reports the outcome.
						} else {
							failed++;
						}
						report();
						worker();
					} else {
						retire();
					}
				} ).catch( function () {
					// The request didn't come back. The server may or
					// may not have drafted it, so this is neither a
					// success nor a failure — record it as unconfirmed
					// and let the server's batch counts decide whether
					// the run is actually finished.
					unknown++;
					report();
					retire();
				} );
			}

			report();
			for ( var w = 0; w < alive; w++ ) {
				worker();
			}
		} ).catch( function () {
			progress.textContent = i18n.startFailed;
			running = false;
			btn.disabled = false;
		} );
	} );
}() );
