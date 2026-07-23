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
	// Resolved in start() rather than here: this script may be parsed before
	// #wa-app exists in the DOM.
	var appEl = null;
	var prefersReducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var isCoarsePointer = window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches;

	var EXIT_ANIMATION_MS = prefersReducedMotion ? 0 : 250;
	var PREFETCH_THRESHOLD = 5;

	/**
	 * Central application state.
	 */
	var state = {
		phase: 'loading', // loading | card | reveal | done | milestone | error
		deck: [], // array of { id, statement, replay? }
		currentIndex: 0,
		total: 0,
		progress: { seen: [], wrong: [], correct_count: 0, answered_count: 0 },
		busy: false, // true while animating or a request is in flight
		fetchingMore: false,
		pendingFetch: null, // the in-flight fetchDeckBatch() promise, if any — lets callers await the SAME request rather than firing a duplicate or racing ahead of it
		replay: false, // true while working through a "replay wrong ones" round
		lastMilestoneShown: 0, // highest answered_count a milestone share screen has already been shown for, so it fires once per 20 rather than on every re-render
	};

	var MILESTONE_INTERVAL = 20;

	/**
	 * Build the "I scored X/Y" share text, with a link back to the quiz so
	 * whoever it's shared with can play too.
	 *
	 * @param {number} correct  Correct answer count.
	 * @param {number} answered Total answered count.
	 * @return {string}
	 */
	function buildShareText( correct, answered ) {
		var pct = Math.round( ( correct / answered ) * 100 );
		var quizUrl = window.location.origin + window.location.pathname;
		return 'I scored ' + correct + '/' + answered + ' (' + pct + '%) on the "Well, Actually..." swipe quiz on ' +
			( config.siteName || 'this blog' ) + '. Try it yourself: ' + quizUrl;
	}

	/**
	 * The share row markup (text box + copy button) shared by the milestone
	 * and end-of-deck screens.
	 *
	 * @param {string} shareText Share text to prefill.
	 * @return {string}
	 */
	function buildShareRow( shareText ) {
		return '<div class="wa-share-row">' +
			'<input type="text" class="wa-share-text" readonly value="' + escapeHtml( shareText ) + '" aria-label="Share text" />' +
			'<button type="button" class="wa-btn wa-copy-btn">Copy</button>' +
			'</div>';
	}

	/**
	 * Wire up the Copy button in a just-rendered share row.
	 */
	function attachShareRowEvents() {
		var copyBtn = appEl.querySelector( '.wa-copy-btn' );
		var shareInput = appEl.querySelector( '.wa-share-text' );
		if ( copyBtn && shareInput ) {
			copyBtn.addEventListener( 'click', function () {
				copyShareText( shareInput, copyBtn );
			} );
		}
	}

	/**
	 * Join a route onto the REST base, with an optional query string.
	 *
	 * config.restUrl has no trailing slash, and on sites using plain
	 * permalinks it is itself a query string (…/?rest_route=/wellactually/v1),
	 * so the query separator has to be chosen rather than assumed.
	 *
	 * @param {string} path    Route relative to the namespace root, e.g. 'deck'.
	 * @param {string} [query] Query string without a leading ? or &.
	 * @return {string}
	 */
	function restUrl( path, query ) {
		var base = String( config.restUrl || '' ).replace( /\/+$/, '' );
		var url = base + '/' + String( path ).replace( /^\/+/, '' );

		if ( query ) {
			url += ( url.indexOf( '?' ) === -1 ? '?' : '&' ) + query;
		}

		return url;
	}

	/**
	 * Minimal REST helper.
	 *
	 * @param {string} path    Route relative to the REST namespace root.
	 * @param {Object} [opts]  fetch() options.
	 * @param {string} [query] Query string without a leading ? or &.
	 * @return {Promise<Object>}
	 */
	function apiFetch( path, opts, query ) {
		opts = opts || {};
		var headers = opts.headers || {};
		headers[ 'X-WP-Nonce' ] = config.nonce;
		if ( opts.body ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		opts.headers = headers;
		opts.credentials = 'same-origin';

		return fetch( restUrl( path, query ), opts ).then( function ( res ) {
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
		// Return the SAME promise to every caller while a fetch is in
		// flight, rather than a resolved no-op — callers that actually need
		// to know when new cards have arrived (advanceAfterReveal, when the
		// deck runs out mid-prefetch) must be able to await the real request.
		if ( state.pendingFetch ) {
			return state.pendingFetch;
		}
		state.fetchingMore = true;

		state.pendingFetch = apiFetch( 'deck', null, 'exclude=' + encodeURIComponent( excludeParam() ) )
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
				state.pendingFetch = null;
			} );

		return state.pendingFetch;
	}

	/**
	 * Prefetch more cards if the deck is running low. Replay rounds fetch
	 * their whole (bounded) set up front, so no prefetch is needed there.
	 */
	function maybePrefetch() {
		if ( state.replay ) {
			return;
		}
		var remaining = state.deck.length - state.currentIndex;
		if ( remaining <= PREFETCH_THRESHOLD ) {
			fetchDeckBatch().then( render );
		}
	}

	/**
	 * Fisher-Yates shuffle (new array, doesn't mutate the input).
	 *
	 * @param {Array} arr Array to shuffle.
	 * @return {Array}
	 */
	function shuffleArray( arr ) {
		var out = arr.slice();
		for ( var i = out.length - 1; i > 0; i-- ) {
			var j = Math.floor( Math.random() * ( i + 1 ) );
			var tmp = out[ i ];
			out[ i ] = out[ j ];
			out[ j ] = tmp;
		}
		return out;
	}

	/**
	 * Start a replay round covering every currently-wrong statement.
	 * Fetches in chunks of 20 (the /deck batch size) up front, since a
	 * replay set is bounded and doesn't need lazy prefetching.
	 */
	function startReplay() {
		var wrongIds = state.progress.wrong.slice();
		if ( ! wrongIds.length ) {
			return;
		}

		state.phase = 'loading';
		render();

		var chunks = [];
		for ( var i = 0; i < wrongIds.length; i += 20 ) {
			chunks.push( wrongIds.slice( i, i + 20 ) );
		}

		Promise.all(
			chunks.map( function ( chunk ) {
				return apiFetch( 'deck', null, 'include=' + encodeURIComponent( chunk.join( ',' ) ) );
			} )
		)
			.then( function ( results ) {
				var cards = [];
				results.forEach( function ( data ) {
					( data.cards || [] ).forEach( function ( card ) {
						card.replay = true;
						cards.push( card );
					} );
				} );

				cards = shuffleArray( cards );

				state.deck = cards;
				state.currentIndex = 0;
				state.replay = true;
				state.phase = cards.length ? 'card' : 'done';
				render();
			} )
			.catch( function () {
				state.phase = 'error';
				render();
			} );
	}

	/**
	 * Check for newly-published deck posts the player hasn't seen, useful
	 * on the done screen when the deck grew after they finished it.
	 *
	 * @return {number} Count of new, not-yet-fetched eligible posts (best-effort).
	 */
	function newStatementsAvailable() {
		return Math.max( 0, state.total - state.progress.seen.length );
	}

	/**
	 * Escape a string for safe HTML insertion — safe both as element text
	 * content and inside a double-quoted attribute value. The
	 * textContent/innerHTML round-trip alone only escapes &, <, > (the
	 * characters unsafe in a text node); it leaves " and ' untouched, since
	 * neither is special there. Callers that splice the result into
	 * value="..." (e.g. the share text box) need those escaped too, or a
	 * literal " in the source string — like the quote marks around
	 * "Well, Actually..." — closes the attribute early and corrupts the
	 * surrounding markup.
	 *
	 * @param {string} str Raw string.
	 * @return {string}
	 */
	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
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
		} else if ( 'milestone' === state.phase ) {
			renderMilestone();
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

		var totalLabel;
		if ( state.replay ) {
			totalLabel = 'Replay: ' + ( state.currentIndex + 1 ) + ' of ' + state.deck.length;
		} else {
			var seenPosition = state.progress.answered_count + 1;
			totalLabel = state.total > 0 ? seenPosition + ' of ' + state.total : String( seenPosition );
		}
		var hintText = isCoarsePointer
			? waSwipeStrings().hintTouch
			: waSwipeStrings().hintKeyboard;

		appEl.innerHTML =
			'<div class="wa-game">' +
			'<div class="wa-header">' +
			'<span class="wa-header-stats">' +
			'<span class="wa-score">' + escapeHtml( waSwipeStrings().scoreLabel( state.progress.correct_count, state.progress.answered_count ) ) + '</span>' +
			'<span class="wa-deck-progress">' + escapeHtml( totalLabel ) + '</span>' +
			'</span>' +
			'<button type="button" class="wa-reset-link">Start over</button>' +
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

		var resetBtn = appEl.querySelector( '.wa-reset-link' );
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', handleResetClick );
		}

		if ( typeof window.waAttachGestures === 'function' ) {
			window.waAttachGestures( document.getElementById( 'wa-card' ), answer );
		}
	}

	/**
	 * Milestone interstitial: a brief share prompt shown every
	 * MILESTONE_INTERVAL answered cards, so players who never reach the end
	 * of a (potentially large) deck still get an invitation to share their
	 * running score. Dismissing it resumes with the next card.
	 */
	function renderMilestone() {
		var answered = state.progress.answered_count;
		var correct = state.progress.correct_count;
		var shareText = buildShareText( correct, answered );

		appEl.innerHTML =
			'<div class="wa-done wa-milestone">' +
			'<p class="wa-done-score">' + escapeHtml( correct + '/' + answered + ' correct so far' ) + '</p>' +
			'<div class="wa-done-actions"><button type="button" class="wa-btn wa-btn-agree wa-milestone-continue">Keep swiping</button></div>' +
			buildShareRow( shareText ) +
			'</div>';

		var continueBtn = appEl.querySelector( '.wa-milestone-continue' );
		if ( continueBtn ) {
			continueBtn.addEventListener( 'click', function () {
				state.phase = 'card';
				render();
				maybePrefetch();
			} );
		}

		attachShareRowEvents();
	}

	/**
	 * End-of-deck screen: final score, a tier line, and options to replay
	 * the wrong ones, start over, or (if the deck grew) fetch what's new.
	 */
	function renderDone() {
		state.replay = false;

		var answered = state.progress.answered_count;
		var correct = state.progress.correct_count;
		var wrongCount = state.progress.wrong.length;
		var newCount = newStatementsAvailable();

		if ( 0 === answered ) {
			appEl.innerHTML =
				'<div class="wa-done">' +
				'<p class="wa-done-score">' + escapeHtml( 'No swipe statements yet — check back soon.' ) + '</p>' +
				'</div>';
			return;
		}

		var pct = Math.round( ( correct / answered ) * 100 );
		var tier = tierLine( pct );
		var shareText = buildShareText( correct, answered );

		var actions = '';

		if ( wrongCount > 0 ) {
			actions += '<button type="button" class="wa-btn wa-btn-agree wa-replay-btn">Replay the ones you got wrong (' + wrongCount + ')</button>';
		}

		if ( newCount > 0 ) {
			actions += '<button type="button" class="wa-btn wa-more-btn">New statements have been added — ' + newCount + ' more await</button>';
		}

		actions += '<button type="button" class="wa-btn wa-reset-btn">Start over</button>';

		appEl.innerHTML =
			'<div class="wa-done">' +
			'<p class="wa-done-score">' + escapeHtml( correct + '/' + answered + ' correct (' + pct + '%)' ) + '</p>' +
			'<p class="wa-done-tier">' + escapeHtml( tier ) + '</p>' +
			'<div class="wa-done-actions">' + actions + '</div>' +
			buildShareRow( shareText ) +
			'</div>';

		var replayBtn = appEl.querySelector( '.wa-replay-btn' );
		if ( replayBtn ) {
			replayBtn.addEventListener( 'click', startReplay );
		}

		var moreBtn = appEl.querySelector( '.wa-more-btn' );
		if ( moreBtn ) {
			moreBtn.addEventListener( 'click', function () {
				state.phase = 'loading';
				render();
				fetchDeckBatch().then( function () {
					state.phase = state.deck.length ? 'card' : 'done';
					render();
				} );
			} );
		}

		var resetBtn = appEl.querySelector( '.wa-reset-btn' );
		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', handleResetClick );
		}

		attachShareRowEvents();
	}

	/**
	 * Copy the share text to the clipboard, with a textarea+execCommand
	 * fallback for browsers without the async Clipboard API.
	 *
	 * @param {HTMLInputElement} inputEl Share text input.
	 * @param {HTMLButtonElement} btnEl  Copy button (label flashes "Copied!").
	 */
	function copyShareText( inputEl, btnEl ) {
		var text = inputEl.value;
		var done = function () {
			var original = btnEl.textContent;
			btnEl.textContent = 'Copied!';
			setTimeout( function () {
				btnEl.textContent = original;
			}, 1500 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done ).catch( function () {
				inputEl.select();
			} );
			return;
		}

		inputEl.select();
		try {
			document.execCommand( 'copy' );
			done();
		} catch ( e ) {
			// Selection is still visible for a manual copy.
		}
	}

	/**
	 * Pick a fun tier line for a final percentage.
	 *
	 * @param {number} pct Percentage correct (0-100).
	 * @return {string}
	 */
	function tierLine( pct ) {
		if ( pct >= 90 ) {
			return 'Well, actually… you should be writing this blog.';
		}
		if ( pct >= 70 ) {
			return 'Solid instincts. A few well-actuallys to go.';
		}
		if ( pct >= 50 ) {
			return 'Halfway there — the archives are calling.';
		}
		return 'Time to hit the archives.';
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
		var gestureHandledExit = !! ( cardEl && cardEl.classList.contains( 'wa-gesture-exiting' ) );
		var exitClass = 'wa-exit-' + ( 'agree' === answerValue ? 'right' : 'disagree' === answerValue ? 'left' : 'up' );

		if ( cardEl && ! prefersReducedMotion && ! gestureHandledExit ) {
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
			setTimeout( resolve, gestureHandledExit ? 0 : EXIT_ANIMATION_MS );
		} );

		Promise.all( [ request, animationDone ] ).then( function ( results ) {
			var response = results[ 0 ];

			if ( ! response ) {
				// The request failed (network hiccup, expired nonce, rate
				// limit, server error, …). Don't guess at a judgment: leave
				// seen/wrong/score untouched and put the same card back up so
				// the player can retry. render() rebuilds the card element
				// from scratch, which also clears any exit animation/inline
				// drag transform left over from this attempt.
				state.busy = false;
				render();
				showAnswerRetryNotice();
				return;
			}

			recordAnswer( card, response, isReplay );
			state.currentIndex++;
			state.busy = false;
			updateHeaderDisplay();

			if ( typeof window.waShowReveal === 'function' ) {
				state.phase = 'reveal';
				window.waShowReveal( response, answerValue, advanceAfterReveal );
			} else {
				advanceAfterReveal();
			}
		} );
	}

	/**
	 * Brief toast telling the player their answer didn't go through and the
	 * card is still available to retry.
	 */
	function showAnswerRetryNotice() {
		var el = document.createElement( 'div' );
		el.className = 'wa-answer-error';
		el.setAttribute( 'role', 'status' );
		el.textContent = 'Couldn’t submit that — check your connection and try again.';
		document.body.appendChild( el );
		setTimeout( function () {
			el.remove();
		}, 3000 );
	}

	/**
	 * Update progress bookkeeping for an answered card.
	 *
	 * During a replay round (card.replay / isReplay), the card was already
	 * counted in seen/answered_count on its first pass, so we only ever
	 * adjust the wrong list (removing it once corrected) rather than
	 * inflating answered_count/correct_count a second time.
	 *
	 * @param {Object}      card     The card that was answered.
	 * @param {Object|null} response The /swipe response, if the request succeeded.
	 * @param {boolean}     isReplay Whether this answer was part of a replay round.
	 */
	function recordAnswer( card, response, isReplay ) {
		var correct = response ? !! response.correct : false;

		if ( ! isReplay ) {
			state.progress.answered_count++;
			if ( correct ) {
				state.progress.correct_count++;
			}
			if ( state.progress.seen.indexOf( card.id ) === -1 ) {
				state.progress.seen.push( card.id );
			}
		}

		var wrongIndex = state.progress.wrong.indexOf( card.id );
		if ( correct && wrongIndex !== -1 ) {
			state.progress.wrong.splice( wrongIndex, 1 );
		} else if ( ! isReplay && ! correct && wrongIndex === -1 ) {
			state.progress.wrong.push( card.id );
		}

		if ( typeof window.waSaveProgress === 'function' ) {
			window.waSaveProgress( state.progress );
		}
	}

	/**
	 * Update the score/progress header in place (used right after an
	 * answer is recorded, before a reveal overlay opens over the card).
	 */
	function updateHeaderDisplay() {
		var scoreEl = appEl && appEl.querySelector( '.wa-score' );
		var progressEl = appEl && appEl.querySelector( '.wa-deck-progress' );

		if ( scoreEl ) {
			scoreEl.textContent = waSwipeStrings().scoreLabel( state.progress.correct_count, state.progress.answered_count );
		}
		if ( progressEl ) {
			if ( state.replay ) {
				progressEl.textContent = 'Replay: ' + state.currentIndex + ' of ' + state.deck.length;
			} else {
				var seenPosition = state.progress.answered_count;
				progressEl.textContent = state.total > 0 ? seenPosition + ' of ' + state.total : String( seenPosition );
			}
		}
	}

	/**
	 * Move on to the next card (or the done screen) after a reveal closes.
	 */
	function advanceAfterReveal() {
		if ( state.deck[ state.currentIndex ] ) {
			var answered = state.progress.answered_count;
			if ( ! state.replay && answered > 0 && answered % MILESTONE_INTERVAL === 0 && answered !== state.lastMilestoneShown ) {
				state.lastMilestoneShown = answered;
				state.phase = 'milestone';
				render();
				return;
			}

			state.phase = 'card';
			render();
			maybePrefetch();
			return;
		}

		// Deck exhausted locally. A slow connection could mean the next
		// batch just hasn't arrived yet — don't conclude 'done' until a
		// fetch has actually confirmed there's nothing left. fetchDeckBatch()
		// returns the SAME promise as any prefetch already in flight (see its
		// dedup logic), so this doesn't duplicate a request that maybePrefetch()
		// already kicked off a few cards ago; it only fires a fresh one if
		// none was pending.
		state.phase = 'loading';
		render();

		fetchDeckBatch()
			.then( function () {
				state.phase = state.deck[ state.currentIndex ] ? 'card' : 'done';
				render();
			} )
			.catch( function () {
				state.phase = 'error';
				render();
			} );
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
	 * Confirm, then reset all progress and restart the deck from scratch.
	 */
	function handleResetClick() {
		if ( ! window.confirm( 'Start over? This clears your saved progress.' ) ) {
			return;
		}

		state.deck = [];
		state.currentIndex = 0;
		state.phase = 'loading';
		render();

		var resetPromise = typeof window.waResetProgressAsync === 'function'
			? window.waResetProgressAsync()
			: Promise.resolve( typeof window.waResetProgress === 'function' ? window.waResetProgress() : freshLocalProgress() );

		resetPromise
			.then( function ( progress ) {
				state.progress = progress;
				return fetchDeckBatch();
			} )
			.then( function () {
				state.phase = state.deck.length ? 'card' : 'done';
				render();
			} )
			.catch( function () {
				state.phase = 'error';
				render();
			} );
	}

	/**
	 * @return {Object} A fresh, empty progress object.
	 */
	function freshLocalProgress() {
		return { seen: [], wrong: [], correct_count: 0, answered_count: 0 };
	}

	/**
	 * Bootstrap: load progress, fetch the first batch, show the first card.
	 */
	function boot() {
		getInitialProgress()
			.then( function ( progress ) {
				state.progress = progress;
				return fetchDeckBatch();
			} )
			.then( function () {
				state.phase = state.deck.length ? 'card' : 'done';
				render();
			} )
			.catch( function () {
				state.phase = 'error';
				render();
			} );
	}

	/**
	 * Load initial progress, preferring the async (server-merging) loader
	 * from #12 when available and falling back to the sync localStorage
	 * loader from #11.
	 *
	 * @return {Promise<Object>}
	 */
	function getInitialProgress() {
		if ( typeof window.waLoadProgressAsync === 'function' ) {
			return window.waLoadProgressAsync();
		}
		if ( typeof window.waLoadProgress === 'function' ) {
			return Promise.resolve( window.waLoadProgress() );
		}
		return Promise.resolve( state.progress );
	}

	/**
	 * Entry point. Deferred until the DOM is ready for two reasons: #wa-app
	 * may not be parsed yet, and the storage/sync modules further down this
	 * file must have registered their window hooks before boot() reads
	 * saved progress.
	 */
	function start() {
		appEl = document.getElementById( 'wa-app' );
		if ( ! appEl ) {
			return;
		}
		attachKeyboardHandler();
		boot();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}

	// Exposed for later issues (reveal overlay, gestures, storage) and for debugging.
	window.waSwipeState = state;
	window.waSwipeAnswer = answer;
} )();

/**
 * Touch/mouse swipe gestures. Attached to the card element by the main
 * render loop via window.waAttachGestures( cardEl, answerFn ).
 */
( function () {
	'use strict';

	var prefersReducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	var COMMIT_DISTANCE_RATIO = 0.35; // Fraction of viewport dimension to auto-commit.
	var COMMIT_VELOCITY = 0.6; // px/ms flick velocity to auto-commit even under threshold.
	var MAX_ROTATION_DEG = 12;
	var FLY_OFF_MS = 220;
	var SPRING_BACK_MS = 200;

	/**
	 * Attach pointer-based swipe gestures to a card element.
	 *
	 * @param {HTMLElement} cardEl   The card element to make draggable.
	 * @param {Function}    answerFn Called with 'agree'|'disagree'|'unsure' once a drag commits.
	 */
	window.waAttachGestures = function ( cardEl, answerFn ) {
		if ( ! cardEl ) {
			return;
		}

		var activePointerId = null;
		var startX = 0;
		var startY = 0;
		var lastX = 0;
		var lastY = 0;
		var lastT = 0;
		var velocityX = 0;
		var velocityY = 0;
		var dragging = false;

		var agreeLabel = cardEl.querySelector( '.wa-drag-label-agree' );
		var disagreeLabel = cardEl.querySelector( '.wa-drag-label-disagree' );
		var unsureLabel = cardEl.querySelector( '.wa-drag-label-unsure' );

		cardEl.style.touchAction = 'none';

		cardEl.addEventListener( 'pointerdown', onPointerDown );

		/**
		 * @param {PointerEvent} e Pointer event.
		 */
		function onPointerDown( e ) {
			if ( null !== activePointerId ) {
				return; // Ignore a second simultaneous pointer.
			}
			if ( e.button !== undefined && e.button !== 0 ) {
				return; // Left-click / primary touch only.
			}

			activePointerId = e.pointerId;
			dragging = true;
			startX = lastX = e.clientX;
			startY = lastY = e.clientY;
			lastT = performance.now();
			velocityX = 0;
			velocityY = 0;

			cardEl.setPointerCapture( activePointerId );
			cardEl.style.transition = 'none';

			cardEl.addEventListener( 'pointermove', onPointerMove );
			cardEl.addEventListener( 'pointerup', onPointerUp );
			cardEl.addEventListener( 'pointercancel', onPointerCancel );
		}

		/**
		 * @param {PointerEvent} e Pointer event.
		 */
		function onPointerMove( e ) {
			if ( ! dragging || e.pointerId !== activePointerId ) {
				return;
			}

			var now = performance.now();
			var dt = Math.max( 1, now - lastT );

			var dx = e.clientX - startX;
			var dy = e.clientY - startY;

			velocityX = ( e.clientX - lastX ) / dt;
			velocityY = ( e.clientY - lastY ) / dt;
			lastX = e.clientX;
			lastY = e.clientY;
			lastT = now;

			applyDragTransform( dx, dy );
		}

		/**
		 * @param {number} dx Horizontal offset from drag start.
		 * @param {number} dy Vertical offset from drag start.
		 */
		function applyDragTransform( dx, dy ) {
			var rotation = Math.max( -MAX_ROTATION_DEG, Math.min( MAX_ROTATION_DEG, ( dx / window.innerWidth ) * MAX_ROTATION_DEG * 2 ) );
			cardEl.style.transform = 'translate(' + dx + 'px, ' + dy + 'px) rotate(' + rotation + 'deg)';

			var horizontalRatio = Math.min( 1, Math.abs( dx ) / ( window.innerWidth * COMMIT_DISTANCE_RATIO ) );
			var verticalRatio = Math.min( 1, Math.abs( dy ) / ( window.innerHeight * COMMIT_DISTANCE_RATIO ) );

			var dominant = getDominantDirection( dx, dy );

			setLabelOpacity( agreeLabel, 'right' === dominant ? horizontalRatio : 0 );
			setLabelOpacity( disagreeLabel, 'left' === dominant ? horizontalRatio : 0 );
			setLabelOpacity( unsureLabel, 'up' === dominant ? verticalRatio : 0 );
		}

		/**
		 * @param {HTMLElement|null} el      Label element.
		 * @param {number}           opacity Target opacity (0-1).
		 */
		function setLabelOpacity( el, opacity ) {
			if ( el ) {
				el.style.opacity = String( opacity );
			}
		}

		/**
		 * Determine the dominant drag direction, or null if downward/negligible.
		 *
		 * @param {number} dx Horizontal offset.
		 * @param {number} dy Vertical offset.
		 * @return {string|null} 'right'|'left'|'up'|null
		 */
		function getDominantDirection( dx, dy ) {
			if ( Math.abs( dx ) >= Math.abs( dy ) ) {
				return dx > 0 ? 'right' : 'left';
			}
			return dy < 0 ? 'up' : null; // Downward drags don't commit to anything.
		}

		/**
		 * @param {PointerEvent} e Pointer event.
		 */
		function onPointerUp( e ) {
			if ( e.pointerId !== activePointerId ) {
				return;
			}
			endDrag( e.clientX - startX, e.clientY - startY );
		}

		/**
		 * Cancel the drag and spring back without committing.
		 */
		function onPointerCancel() {
			springBack();
			cleanupListeners();
		}

		/**
		 * @param {number} dx Final horizontal offset.
		 * @param {number} dy Final vertical offset.
		 */
		function endDrag( dx, dy ) {
			cleanupListeners();

			var direction = getDominantDirection( dx, dy );
			var horizontalRatio = Math.abs( dx ) / ( window.innerWidth * COMMIT_DISTANCE_RATIO );
			var verticalRatio = Math.abs( dy ) / ( window.innerHeight * COMMIT_DISTANCE_RATIO );
			var pastThreshold = ( 'up' === direction ) ? verticalRatio >= 1 : horizontalRatio >= 1;
			var flicked = Math.abs( velocityX ) >= COMMIT_VELOCITY || Math.abs( velocityY ) >= COMMIT_VELOCITY;

			if ( direction && ( pastThreshold || flicked ) ) {
				commit( direction );
			} else {
				springBack();
			}
		}

		/**
		 * Remove per-drag listeners and release the pointer.
		 */
		function cleanupListeners() {
			dragging = false;
			cardEl.removeEventListener( 'pointermove', onPointerMove );
			cardEl.removeEventListener( 'pointerup', onPointerUp );
			cardEl.removeEventListener( 'pointercancel', onPointerCancel );
			if ( null !== activePointerId ) {
				try {
					cardEl.releasePointerCapture( activePointerId );
				} catch ( err ) {
					// Pointer may already be released; ignore.
				}
			}
			activePointerId = null;
		}

		/**
		 * Animate the card back to its resting position.
		 */
		function springBack() {
			if ( prefersReducedMotion ) {
				cardEl.style.transition = 'none';
				cardEl.style.transform = '';
			} else {
				cardEl.style.transition = 'transform ' + SPRING_BACK_MS + 'ms ease';
				cardEl.style.transform = '';
			}
			setLabelOpacity( agreeLabel, 0 );
			setLabelOpacity( disagreeLabel, 0 );
			setLabelOpacity( unsureLabel, 0 );
		}

		/**
		 * Fly the card off screen in the committed direction, then answer.
		 *
		 * @param {string} direction 'right'|'left'|'up'.
		 */
		function commit( direction ) {
			var answerValue = 'right' === direction ? 'agree' : 'left' === direction ? 'disagree' : 'unsure';

			cardEl.classList.add( 'wa-gesture-exiting' );

			if ( prefersReducedMotion ) {
				answerFn( answerValue );
				return;
			}

			var flyX = 'right' === direction ? window.innerWidth * 1.5 : 'left' === direction ? -window.innerWidth * 1.5 : lastX - startX;
			var flyY = 'up' === direction ? -window.innerHeight * 1.5 : lastY - startY;
			var rotation = 'right' === direction ? MAX_ROTATION_DEG * 1.5 : 'left' === direction ? -MAX_ROTATION_DEG * 1.5 : 0;

			cardEl.style.transition = 'transform ' + FLY_OFF_MS + 'ms ease-in, opacity ' + FLY_OFF_MS + 'ms ease-in';
			cardEl.style.transform = 'translate(' + flyX + 'px, ' + flyY + 'px) rotate(' + rotation + 'deg)';
			cardEl.style.opacity = '0';

			setTimeout( function () {
				answerFn( answerValue );
			}, FLY_OFF_MS );
		}
	};
} )();

/**
 * Reveal overlay: quick "correct" flash for straightforward right answers,
 * or a full overlay card (verdict, post excerpt/link, aggregate %) when
 * the player was wrong, unsure, or the statement was debatable.
 */
( function () {
	'use strict';

	var prefersReducedMotion = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var CORRECT_FLASH_MS = 600;
	var TRANSITION_MS = prefersReducedMotion ? 0 : 300;

	/**
	 * Escape a string for safe HTML insertion — safe both as element text
	 * content and inside a double-quoted attribute value. The
	 * textContent/innerHTML round-trip alone only escapes &, <, > (the
	 * characters unsafe in a text node); it leaves " and ' untouched, since
	 * neither is special there. Callers that splice the result into
	 * value="..." (e.g. the share text box) need those escaped too, or a
	 * literal " in the source string — like the quote marks around
	 * "Well, Actually..." — closes the attribute early and corrupts the
	 * surrounding markup.
	 *
	 * @param {string} str Raw string.
	 * @return {string}
	 */
	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	/**
	 * Build the banner heading + sub-line for the overlay.
	 *
	 * @param {Object} response   The /swipe response.
	 * @param {string} answerValue The answer the player gave.
	 * @return {{heading: string, sub: string}}
	 */
	function bannerFor( response, answerValue ) {
		if ( 'debatable' === response.verdict ) {
			return { heading: '🤔 It’s debatable', sub: '' };
		}

		var verdictLabel = 'true' === response.verdict ? 'TRUE' : 'FALSE';
		var sub = 'This one’s ' + verdictLabel + '.';

		if ( 'unsure' === answerValue ) {
			return { heading: 'Not sure? Here’s the answer', sub: sub };
		}

		return { heading: '✗ Well, actually…', sub: sub };
	}

	/**
	 * Show either the quick correct-flash or the full reveal overlay.
	 *
	 * @param {Object}   response    The /swipe response.
	 * @param {string}   answerValue The answer the player gave.
	 * @param {Function} onContinue  Called once the player is ready to move on.
	 */
	window.waShowReveal = function ( response, answerValue, onContinue ) {
		if ( ! response.show_post ) {
			showCorrectFlash( onContinue );
			return;
		}
		showOverlay( response, answerValue, onContinue );
	};

	/**
	 * Brief inline "correct" acknowledgement, no overlay.
	 *
	 * @param {Function} onContinue Called after the flash.
	 */
	function showCorrectFlash( onContinue ) {
		var flash = document.createElement( 'div' );
		flash.className = 'wa-correct-flash';
		flash.setAttribute( 'role', 'status' );
		flash.textContent = '✓ Correct';
		document.body.appendChild( flash );

		setTimeout( function () {
			flash.remove();
			onContinue();
		}, prefersReducedMotion ? 0 : CORRECT_FLASH_MS );
	}

	/**
	 * Full reveal overlay with verdict, post details, and a continue action.
	 *
	 * @param {Object}   response    The /swipe response.
	 * @param {string}   answerValue The answer the player gave.
	 * @param {Function} onContinue  Called once the player dismisses the overlay.
	 */
	function showOverlay( response, answerValue, onContinue ) {
		var previouslyFocused = document.activeElement;
		var banner = bannerFor( response, answerValue );

		var overlay = document.createElement( 'div' );
		overlay.className = 'wa-reveal-overlay';
		overlay.setAttribute( 'role', 'dialog' );
		overlay.setAttribute( 'aria-modal', 'true' );
		overlay.setAttribute( 'aria-labelledby', 'wa-reveal-heading' );

		var pctLine = '';
		if ( null !== response.pct_agreed && undefined !== response.pct_agreed ) {
			pctLine = '<p class="wa-reveal-pct">' + escapeHtml( response.pct_agreed + '% of readers agreed.' ) + '</p>';
		}

		overlay.innerHTML =
			'<div class="wa-reveal-card">' +
			'<p class="wa-reveal-banner" id="wa-reveal-heading">' + escapeHtml( banner.heading ) + '</p>' +
			( banner.sub ? '<p class="wa-reveal-sub">' + escapeHtml( banner.sub ) + '</p>' : '' ) +
			'<div class="wa-reveal-post">' +
			'<a class="wa-reveal-post-title" href="' + encodeURI( response.url || '#' ) + '" target="_blank" rel="noopener">' + escapeHtml( response.title ) + '</a>' +
			'<p class="wa-reveal-excerpt">' + escapeHtml( response.excerpt ) + '</p>' +
			'<a class="wa-reveal-read-btn" href="' + encodeURI( response.url || '#' ) + '" target="_blank" rel="noopener">Read the full post →</a>' +
			'</div>' +
			pctLine +
			'<button type="button" class="wa-btn wa-reveal-continue">Continue</button>' +
			'</div>';

		document.body.appendChild( overlay );

		var cardEl = overlay.querySelector( '.wa-reveal-card' );
		var continueBtn = overlay.querySelector( '.wa-reveal-continue' );

		// Force layout, then trigger the slide-up transition.
		if ( ! prefersReducedMotion ) {
			overlay.classList.add( 'wa-reveal-entering' );
			// eslint-disable-next-line no-unused-expressions
			cardEl.offsetHeight;
			overlay.classList.remove( 'wa-reveal-entering' );
		}

		continueBtn.focus();

		var dismissed = false;

		/**
		 * Dismiss the overlay and continue the game.
		 */
		function dismiss() {
			if ( dismissed ) {
				return;
			}
			dismissed = true;

			overlay.removeEventListener( 'keydown', onKeydown );

			if ( prefersReducedMotion ) {
				cleanup();
				return;
			}

			overlay.classList.add( 'wa-reveal-leaving' );
			setTimeout( cleanup, TRANSITION_MS );
		}

		/**
		 * Remove the overlay from the DOM and hand control back.
		 */
		function cleanup() {
			overlay.remove();
			if ( previouslyFocused && typeof previouslyFocused.focus === 'function' ) {
				previouslyFocused.focus();
			}
			onContinue();
		}

		/**
		 * The overlay's focusable controls, in DOM (tab) order.
		 *
		 * @return {HTMLElement[]}
		 */
		function getFocusable() {
			return Array.prototype.slice.call(
				overlay.querySelectorAll( 'a[href], button:not([disabled])' )
			);
		}

		/**
		 * @param {KeyboardEvent} e Keydown event.
		 */
		function onKeydown( e ) {
			// Enter/Space/ArrowDown are a keyboard shortcut for the Continue
			// button specifically — only fire it when Continue itself is
			// focused, so the same keys still activate the post links normally.
			if ( document.activeElement === continueBtn &&
				( 'Enter' === e.key || ' ' === e.key || 'ArrowDown' === e.key ) ) {
				e.preventDefault();
				dismiss();
				return;
			}

			if ( 'Tab' !== e.key ) {
				return;
			}

			// Real focus trap: cycle through every focusable control in both
			// directions instead of always snapping back to Continue, so the
			// post title link and "Read the full post" link stay reachable.
			var focusable = getFocusable();
			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];
			var current = document.activeElement;
			var atOrOutside = -1 === focusable.indexOf( current );

			if ( e.shiftKey ) {
				if ( current === first || atOrOutside ) {
					e.preventDefault();
					last.focus();
				}
			} else {
				if ( current === last || atOrOutside ) {
					e.preventDefault();
					first.focus();
				}
			}
		}

		// Bind both 'pointerdown' and 'click': on some browsers the reveal
		// card's entrance still has an in-flight pointer/click sequence from
		// the swipe gesture that just closed the previous card, and a 'click'
		// listener alone can miss the player's first tap on this
		// freshly-inserted button, requiring a second tap. 'pointerdown'
		// fires immediately and reliably for mouse/touch; 'click' remains as
		// the fallback for keyboard activation. dismiss()'s own guard makes
		// it safe if both fire for the same interaction.
		continueBtn.addEventListener( 'pointerdown', dismiss );
		continueBtn.addEventListener( 'click', dismiss );
		overlay.addEventListener( 'keydown', onKeydown );
	};
} )();

/**
 * localStorage-backed progress persistence for anonymous visitors.
 * Logged-in sync (issue #12) reads/writes through the same
 * window.waLoadProgress/waSaveProgress hooks, merging with the server copy.
 */
( function () {
	'use strict';

	var STORAGE_KEY = 'wa_progress';
	var memoryFallback = null; // Used when localStorage is unavailable.
	var storageAvailable = isStorageAvailable();

	/**
	 * Feature-detect a usable localStorage (Safari private mode can throw
	 * on write even though the object exists).
	 *
	 * @return {boolean}
	 */
	function isStorageAvailable() {
		try {
			var testKey = '__wa_test__';
			window.localStorage.setItem( testKey, '1' );
			window.localStorage.removeItem( testKey );
			return true;
		} catch ( e ) {
			return false;
		}
	}

	/**
	 * A fresh, empty progress object.
	 *
	 * @return {Object}
	 */
	function freshProgress() {
		return { seen: [], wrong: [], correct_count: 0, answered_count: 0 };
	}

	/**
	 * Validate and normalize a possibly-corrupt progress object.
	 *
	 * @param {*} raw Parsed (or otherwise obtained) candidate progress object.
	 * @return {Object}
	 */
	function normalize( raw ) {
		if ( ! raw || 'object' !== typeof raw ) {
			return freshProgress();
		}

		var seen = Array.isArray( raw.seen ) ? raw.seen.filter( isPositiveInt ) : [];
		var wrong = Array.isArray( raw.wrong ) ? raw.wrong.filter( isPositiveInt ) : [];

		seen = uniqueInts( seen );
		wrong = uniqueInts( wrong ).filter( function ( id ) {
			return seen.indexOf( id ) !== -1;
		} );

		var answeredCount = Number.isFinite( raw.answered_count ) ? Math.max( 0, Math.floor( raw.answered_count ) ) : seen.length;
		var correctCount = Number.isFinite( raw.correct_count ) ? Math.max( 0, Math.floor( raw.correct_count ) ) : Math.max( 0, seen.length - wrong.length );

		return {
			seen: seen,
			wrong: wrong,
			correct_count: Math.min( correctCount, answeredCount ),
			answered_count: answeredCount,
		};
	}

	/**
	 * @param {*} val Candidate value.
	 * @return {boolean}
	 */
	function isPositiveInt( val ) {
		return Number.isInteger( val ) && val > 0;
	}

	/**
	 * @param {number[]} arr Array of ints.
	 * @return {number[]}
	 */
	function uniqueInts( arr ) {
		var seenMap = {};
		var out = [];
		arr.forEach( function ( n ) {
			if ( ! seenMap[ n ] ) {
				seenMap[ n ] = true;
				out.push( n );
			}
		} );
		return out;
	}

	/**
	 * Show a subtle one-time notice that progress won't persist.
	 */
	var noticeShown = false;
	function maybeShowNoStorageNotice() {
		if ( noticeShown || storageAvailable ) {
			return;
		}
		noticeShown = true;

		var notice = document.createElement( 'div' );
		notice.className = 'wa-storage-notice';
		notice.setAttribute( 'role', 'status' );
		notice.textContent = 'Your progress won’t be saved in this browser.';
		document.body.appendChild( notice );

		setTimeout( function () {
			notice.remove();
		}, 5000 );
	}

	/**
	 * Load progress from localStorage (or the in-memory fallback).
	 *
	 * @return {Object}
	 */
	window.waLoadProgress = function () {
		if ( ! storageAvailable ) {
			maybeShowNoStorageNotice();
			return normalize( memoryFallback );
		}

		try {
			var raw = window.localStorage.getItem( STORAGE_KEY );
			return normalize( raw ? JSON.parse( raw ) : null );
		} catch ( e ) {
			return freshProgress();
		}
	};

	/**
	 * Persist progress to localStorage (or the in-memory fallback).
	 *
	 * @param {Object} progress Progress object to save.
	 */
	window.waSaveProgress = function ( progress ) {
		var normalized = normalize( progress );

		if ( ! storageAvailable ) {
			memoryFallback = normalized;
			maybeShowNoStorageNotice();
			return;
		}

		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( normalized ) );
		} catch ( e ) {
			storageAvailable = false;
			memoryFallback = normalized;
			maybeShowNoStorageNotice();
		}
	};

	/**
	 * Reset all local progress to a fresh, empty state.
	 */
	window.waResetProgress = function () {
		var fresh = freshProgress();
		window.waSaveProgress( fresh );
		return fresh;
	};
} )();

/**
 * Logged-in progress sync: merges localStorage with the server copy on
 * load, and pushes local changes to the server (throttled) afterward.
 * A no-op for anonymous visitors — makes no /progress requests.
 */
( function () {
	'use strict';

	var config = window.waSwipe || {};

	if ( ! config.isLoggedIn ) {
		return;
	}

	var localSave = window.waSaveProgress;
	var localReset = window.waResetProgress;

	var PUT_DEBOUNCE_MS = 2000;
	var putTimer = null;
	var pendingProgress = null;

	/**
	 * Join a route onto the REST base. See the matching helper in the main
	 * module — duplicated rather than shared to keep each IIFE self-contained.
	 *
	 * @param {string} path Route relative to the namespace root.
	 * @return {string}
	 */
	function restUrl( path ) {
		var base = String( config.restUrl || '' ).replace( /\/+$/, '' );
		return base + '/' + String( path ).replace( /^\/+/, '' );
	}

	/**
	 * @param {string} path Route relative to the REST namespace root.
	 * @param {Object} [opts] fetch() options.
	 * @return {Promise<Object>}
	 */
	function apiFetch( path, opts ) {
		opts = opts || {};
		opts.headers = opts.headers || {};
		opts.headers[ 'X-WP-Nonce' ] = config.nonce;
		if ( opts.body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
		}
		opts.credentials = 'same-origin';

		return fetch( restUrl( path ), opts ).then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( 'Request failed: ' + res.status );
			}
			return res.json();
		} );
	}

	/**
	 * Merge two progress objects: union of seen/wrong, recomputed counts.
	 *
	 * A card is only "corrected" from a side's own point of view: it's in that
	 * side's seen list but NOT in that side's wrong list. Simply unioning both
	 * wrong lists isn't enough — a stale local 'wrong' entry for a card the
	 * server has since confirmed correct would otherwise survive the union
	 * (it's still in the merged 'seen', so the old "was it seen" filter alone
	 * doesn't catch it). A card is only kept wrong here if NEITHER side has
	 * evidence it was answered correctly.
	 *
	 * @param {Object} a First progress object.
	 * @param {Object} b Second progress object.
	 * @return {Object}
	 */
	function mergeProgress( a, b ) {
		var seen = uniqueInts( ( a.seen || [] ).concat( b.seen || [] ) );
		var wrongCandidates = uniqueInts( ( a.wrong || [] ).concat( b.wrong || [] ) );

		var wrong = wrongCandidates.filter( function ( id ) {
			if ( seen.indexOf( id ) === -1 ) {
				return false;
			}
			return ! correctedOnSide( a, id ) && ! correctedOnSide( b, id );
		} );

		return {
			seen: seen,
			wrong: wrong,
			answered_count: seen.length,
			correct_count: Math.max( 0, seen.length - wrong.length ),
		};
	}

	/**
	 * Whether a single progress object has evidence a card was answered
	 * correctly: it's been seen, and it's not (or no longer) in that side's
	 * wrong list.
	 *
	 * @param {Object} side Progress object (local or server).
	 * @param {number} id   Post id.
	 * @return {boolean}
	 */
	function correctedOnSide( side, id ) {
		var seenSide = side.seen || [];
		var wrongSide = side.wrong || [];
		return seenSide.indexOf( id ) !== -1 && wrongSide.indexOf( id ) === -1;
	}

	/**
	 * @param {number[]} arr Array of ints.
	 * @return {number[]}
	 */
	function uniqueInts( arr ) {
		var seenMap = {};
		var out = [];
		arr.forEach( function ( n ) {
			if ( Number.isInteger( n ) && n > 0 && ! seenMap[ n ] ) {
				seenMap[ n ] = true;
				out.push( n );
			}
		} );
		return out;
	}

	/**
	 * Load local + server progress, merge, and persist the merged result
	 * both places.
	 *
	 * @return {Promise<Object>}
	 */
	window.waLoadProgressAsync = function () {
		var local = window.waLoadProgress ? window.waLoadProgress() : { seen: [], wrong: [], correct_count: 0, answered_count: 0 };

		return apiFetch( 'progress' )
			.then( function ( server ) {
				var merged = mergeProgress( local, server );
				localSave( merged );
				pushToServer( merged );
				return merged;
			} )
			.catch( function () {
				return local; // Server unreachable; carry on with local progress only.
			} );
	};

	/**
	 * Reset both local and server progress.
	 *
	 * @return {Promise<Object>}
	 */
	window.waResetProgressAsync = function () {
		var fresh = localReset();
		return apiFetch( 'progress', {
			method: 'PUT',
			body: JSON.stringify( fresh ),
		} ).catch( function () {
			// Best-effort; local reset already happened.
		} ).then( function () {
			return fresh;
		} );
	};

	/**
	 * Push progress to the server, debounced so fast swipers don't spam it.
	 *
	 * @param {Object} progress Progress object to push.
	 */
	function pushToServer( progress ) {
		pendingProgress = progress;

		if ( putTimer ) {
			return;
		}

		putTimer = setTimeout( function () {
			var toSend = pendingProgress;
			putTimer = null;
			pendingProgress = null;

			apiFetch( 'progress', {
				method: 'PUT',
				body: JSON.stringify( toSend ),
			} ).catch( function () {
				// One retry on failure; otherwise give up silently until the next save.
				apiFetch( 'progress', {
					method: 'PUT',
					body: JSON.stringify( toSend ),
				} ).catch( function () {} );
			} );
		}, PUT_DEBOUNCE_MS );
	}

	// Wrap the local save so every local write also queues a server push.
	window.waSaveProgress = function ( progress ) {
		localSave( progress );
		pushToServer( progress );
	};
} )();
