/**
 * WellActually swipe mode frontend.
 *
 * Vanilla ES6, no build step. `window.waSwipe` (printed via
 * wp_localize_script) provides { restUrl, nonce, isLoggedIn, homeUrl, siteName }.
 *
 * State machine: loading -> card -> reveal -> done.
 */
( function () {
	'use strict';

	var config = window.waSwipe || {};
	var appEl = document.getElementById( 'wa-app' );
	var prefersReducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var isCoarsePointer = window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches;

	var EXIT_ANIMATION_MS = prefersReducedMotion ? 0 : 250;
	var PREFETCH_THRESHOLD = 5;

	/**
	 * Central application state.
	 */
	var state = {
		phase: 'loading', // loading | card | reveal | done | error
		deck: [], // array of { id, statement }
		currentIndex: 0,
		total: 0,
		progress: { seen: [], wrong: [], correct_count: 0, answered_count: 0 },
		busy: false, // true while animating or a request is in flight
		fetchingMore: false,
	};

	/**
	 * Minimal REST helper.
	 *
	 * @param {string} path   Path relative to the REST namespace root.
	 * @param {Object} [opts] fetch() options.
	 * @return {Promise<Object>}
	 */
	function apiFetch( path, opts ) {
		opts = opts || {};
		var headers = opts.headers || {};
		headers[ 'X-WP-Nonce' ] = config.nonce;
		if ( opts.body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		opts.headers = headers;
		opts.credentials = 'same-origin';

		return fetch( config.restUrl + path, opts ).then( function ( res ) {
			if ( ! res.ok ) {
				return res.json().catch( function () {
					return {};
				} ).then( function ( body ) {
					var err = new Error( body.message || 'Request failed' );
					err.status = res.status;
					throw err;
				} );
			}
			return res.json();
		} );
	}

	/**
	 * Build the exclude param from progress.seen.
	 *
	 * @return {string}
	 */
	function excludeParam() {
		return state.progress.seen.join( ',' );
	}

	/**
	 * Fetch a batch of cards and append to the deck (dedup against seen/existing deck).
	 *
	 * @return {Promise<void>}
	 */
	function fetchDeckBatch() {
		if ( state.fetchingMore ) {
			return Promise.resolve();
		}
		state.fetchingMore = true;

		return apiFetch( 'deck?exclude=' + encodeURIComponent( excludeParam() ) )
			.then( function ( data ) {
				state.total = data.total || 0;

				var existingIds = {};
				state.deck.forEach( function ( c ) {
					existingIds[ c.id ] = true;
				} );
				state.progress.seen.forEach( function ( id ) {
					existingIds[ id ] = true;
				} );

				( data.cards || [] ).forEach( function ( card ) {
					if ( ! existingIds[ card.id ] ) {
						state.deck.push( card );
						existingIds[ card.id ] = true;
					}
				} );
			} )
			.finally( function () {
				state.fetchingMore = false;
			} );
	}

	/**
	 * Prefetch more cards if the deck is running low.
	 */
	function maybePrefetch() {
		var remaining = state.deck.length - state.currentIndex;
		if ( remaining <= PREFETCH_THRESHOLD ) {
			fetchDeckBatch().then( render );
		}
	}

	/**
	 * Escape a string for safe HTML insertion.
	 *
	 * @param {string} str Raw string.
	 * @return {string}
	 */
	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}

	/**
	 * Render the current phase.
	 */
	function render() {
		if ( ! appEl ) {
			return;
		}

		if ( 'loading' === state.phase ) {
			renderLoading();
		} else if ( 'error' === state.phase ) {
			renderError();
		} else if ( 'card' === state.phase ) {
			renderCard();
		} else if ( 'done' === state.phase ) {
			renderDone();
		}
		// 'reveal' phase rendering is handled by showReveal() directly.
	}

	/**
	 * Render the loading state.
	 */
	function renderLoading() {
		appEl.innerHTML = '<div class="wa-loading">' + escapeHtml( waSwipeStrings().loading ) + '</div>';
	}

	/**
	 * Render a retryable error state.
	 */
	function renderError() {
		appEl.innerHTML =
			'<div class="wa-error">' +
			'<p>' + escapeHtml( waSwipeStrings().error ) + '</p>' +
			'<button type="button" class="wa-btn wa-retry">' + escapeHtml( waSwipeStrings().retry ) + '</button>' +
			'</div>';

		var retryBtn = appEl.querySelector( '.wa-retry' );
		if ( retryBtn ) {
			retryBtn.addEventListener( 'click', function () {
				state.phase = 'loading';
				render();
				boot();
			} );
		}
	}

	/**
	 * Render the current card.
	 */
	function renderCard() {
		var card = state.deck[ state.currentIndex ];
		if ( ! card ) {
			state.phase = 'done';
			render();
			return;
		}

		var seenPosition = state.progress.answered_count + 1;
		var totalLabel = state.total > 0 ? seenPosition + ' of ' + state.total : String( seenPosition );
		var hintText = isCoarsePointer
			? waSwipeStrings().hintTouch
			: waSwipeStrings().hintKeyboard;

		appEl.innerHTML =
			'<div class="wa-game">' +
			'<div class="wa-header">' +
			'<span class="wa-score">' + escapeHtml( waSwipeStrings().scoreLabel( state.progress.correct_count, state.progress.answered_count ) ) + '</span>' +
			'<span class="wa-deck-progress">' + escapeHtml( totalLabel ) + '</span>' +
			'</div>' +
			'<div class="wa-card-area">' +
			'<div class="wa-card" id="wa-card" tabindex="-1">' +
			'<p class="wa-statement" aria-live="polite">' + escapeHtml( card.statement ) + '</p>' +
			'<div class="wa-drag-label wa-drag-label-agree">' + escapeHtml( waSwipeStrings().agree ) + '</div>' +
			'<div class="wa-drag-label wa-drag-label-disagree">' + escapeHtml( waSwipeStrings().disagree ) + '</div>' +
			'<div class="wa-drag-label wa-drag-label-unsure">' + escapeHtml( waSwipeStrings().unsure ) + '</div>' +
			'</div>' +
			'</div>' +
			'<div class="wa-hint-footer">' +
			'<button type="button" class="wa-btn wa-btn-disagree" data-answer="disagree">&larr; ' + escapeHtml( waSwipeStrings().disagree ) + '</button>' +
			'<button type="button" class="wa-btn wa-btn-unsure" data-answer="unsure">&uarr; ' + escapeHtml( waSwipeStrings().unsure ) + '</button>' +
			'<button type="button" class="wa-btn wa-btn-agree" data-answer="agree">' + escapeHtml( waSwipeStrings().agree ) + ' &rarr;</button>' +
			'</div>' +
			'<p class="wa-hint-text">' + escapeHtml( hintText ) + '</p>' +
			'</div>';

		var buttons = appEl.querySelectorAll( '[data-answer]' );
		buttons.forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				answer( btn.getAttribute( 'data-answer' ) );
			} );
		} );

		if ( typeof window.waAttachGestures === 'function' ) {
			window.waAttachGestures( document.getElementById( 'wa-card' ), answer );
		}
	}

	/**
	 * Placeholder for the end-of-deck screen (built out in issue #13).
	 */
	function renderDone() {
		appEl.innerHTML =
			'<div class="wa-done">' +
			'<p>' + escapeHtml( waSwipeStrings().doneStub( state.progress.correct_count, state.progress.answered_count ) ) + '</p>' +
			'</div>';
	}

	/**
	 * i18n-ish strings. Kept simple (no wp.i18n dependency) since this is a
	 * plain JS file with no build step.
	 *
	 * @return {Object}
	 */
	function waSwipeStrings() {
		return {
			loading: 'Loading…',
			error: 'Something went wrong loading swipe mode.',
			retry: 'Try again',
			agree: 'Agree',
			disagree: 'Disagree',
			unsure: 'Not sure',
			hintKeyboard: '← Disagree   ↑ Not sure   Agree →',
			hintTouch: 'Swipe left to disagree, up if not sure, right to agree',
			scoreLabel: function ( correct, answered ) {
				return correct + '/' + answered + ' correct';
			},
			doneStub: function ( correct, answered ) {
				return 'Done! ' + correct + '/' + answered + ' correct.';
			},
		};
	}

	/**
	 * Map a keyboard event to an answer, if applicable.
	 *
	 * @param {KeyboardEvent} e Keydown event.
	 * @return {string|null}
	 */
	function keyToAnswer( e ) {
		if ( 'ArrowRight' === e.key ) {
			return 'agree';
		}
		if ( 'ArrowLeft' === e.key ) {
			return 'disagree';
		}
		if ( 'ArrowUp' === e.key ) {
			return 'unsure';
		}
		return null;
	}

	/**
	 * Handle an answer (from keyboard, button, or gesture).
	 *
	 * @param {string} answerValue One of agree|disagree|unsure.
	 */
	function answer( answerValue ) {
		if ( state.busy || 'card' !== state.phase ) {
			return;
		}

		var card = state.deck[ state.currentIndex ];
		if ( ! card ) {
			return;
		}

		state.busy = true;

		var cardEl = document.getElementById( 'wa-card' );
		var exitClass = 'wa-exit-' + ( 'agree' === answerValue ? 'right' : 'disagree' === answerValue ? 'left' : 'up' );

		if ( cardEl && ! prefersReducedMotion ) {
			cardEl.classList.add( exitClass );
		}

		var isReplay = !! card.replay;

		var request = apiFetch( 'swipe', {
			method: 'POST',
			body: JSON.stringify( { post_id: card.id, answer: answerValue, replay: isReplay } ),
		} ).catch( function () {
			return null; // Network hiccups shouldn't strand the player; treat as unknown/no reveal.
		} );

		var animationDone = new Promise( function ( resolve ) {
			setTimeout( resolve, EXIT_ANIMATION_MS );
		} );

		Promise.all( [ request, animationDone ] ).then( function ( results ) {
			var response = results[ 0 ];
			recordAnswer( card, response, answerValue );
			state.currentIndex++;
			state.busy = false;

			if ( typeof window.waShowReveal === 'function' && response ) {
				window.waShowReveal( response, advanceAfterReveal );
			} else {
				advanceAfterReveal();
			}
		} );
	}

	/**
	 * Update progress bookkeeping for an answered card.
	 *
	 * @param {Object}      card     The card that was answered.
	 * @param {Object|null} response The /swipe response, if the request succeeded.
	 */
	function recordAnswer( card, response ) {
		var correct = response ? !! response.correct : false;

		state.progress.answered_count++;
		if ( correct ) {
			state.progress.correct_count++;
		}

		if ( state.progress.seen.indexOf( card.id ) === -1 ) {
			state.progress.seen.push( card.id );
		}

		var wrongIndex = state.progress.wrong.indexOf( card.id );
		if ( correct && wrongIndex !== -1 ) {
			state.progress.wrong.splice( wrongIndex, 1 );
		} else if ( ! correct && wrongIndex === -1 ) {
			state.progress.wrong.push( card.id );
		}

		if ( typeof window.waSaveProgress === 'function' ) {
			window.waSaveProgress( state.progress );
		}
	}

	/**
	 * Move on to the next card (or the done screen) after a reveal closes.
	 */
	function advanceAfterReveal() {
		state.phase = state.deck[ state.currentIndex ] ? 'card' : 'done';
		maybePrefetch();
		render();
	}

	/**
	 * Wire up keyboard listeners.
	 */
	function attachKeyboardHandler() {
		document.addEventListener( 'keydown', function ( e ) {
			if ( 'card' !== state.phase ) {
				return;
			}
			var a = keyToAnswer( e );
			if ( a ) {
				e.preventDefault();
				answer( a );
			}
		} );
	}

	/**
	 * Bootstrap: load progress, fetch the first batch, show the first card.
	 */
	function boot() {
		if ( typeof window.waLoadProgress === 'function' ) {
			state.progress = window.waLoadProgress();
		}

		fetchDeckBatch()
			.then( function () {
				state.phase = state.deck.length ? 'card' : 'done';
				render();
			} )
			.catch( function () {
				state.phase = 'error';
				render();
			} );
	}

	if ( appEl ) {
		attachKeyboardHandler();
		boot();
	}

	// Exposed for later issues (reveal overlay, gestures, storage) and for debugging.
	window.waSwipeState = state;
	window.waSwipeAnswer = answer;
} )();
