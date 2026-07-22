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
			recordAnswer( card, response, answerValue );
			state.currentIndex++;
			state.busy = false;
			updateHeaderDisplay();

			if ( typeof window.waShowReveal === 'function' && response ) {
				state.phase = 'reveal';
				window.waShowReveal( response, answerValue, advanceAfterReveal );
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
			var seenPosition = state.progress.answered_count;
			var totalLabel = state.total > 0 ? seenPosition + ' of ' + state.total : String( seenPosition );
			progressEl.textContent = totalLabel;
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
	 * Confirm, then reset all progress and restart the deck from scratch.
	 */
	function handleResetClick() {
		if ( ! window.confirm( 'Start over? This clears your saved progress.' ) ) {
			return;
		}

		if ( typeof window.waResetProgress === 'function' ) {
			state.progress = window.waResetProgress();
		} else {
			state.progress = { seen: [], wrong: [], correct_count: 0, answered_count: 0 };
		}

		state.deck = [];
		state.currentIndex = 0;
		state.phase = 'loading';
		render();

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
		 * @param {KeyboardEvent} e Keydown event.
		 */
		function onKeydown( e ) {
			if ( 'Enter' === e.key || ' ' === e.key || 'ArrowDown' === e.key ) {
				e.preventDefault();
				dismiss();
				return;
			}

			if ( 'Tab' === e.key ) {
				// Single focusable target (Continue); keep focus trapped on it.
				e.preventDefault();
				continueBtn.focus();
			}
		}

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
