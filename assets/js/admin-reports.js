/**
 * Settings → "Well, Actually..." → Reports tab.
 *
 * Inline editing, in the spirit of the post list's Quick Edit: the row turns
 * into fields in place, saves over REST, and updates itself without a page
 * reload.
 *
 * Vanilla ES5, no build step. `window.wellactuallyReports` (printed via
 * wp_localize_script) provides { restUrl, nonce, verdicts, i18n }.
 */
( function () {
	'use strict';

	var cfg = window.wellactuallyReports || {};
	var i18n = cfg.i18n || {};
	if ( ! cfg.restUrl ) { return; }

	function closeEditor( row ) {
		var editor = row.querySelector( '.wa-inline-editor' );
		if ( editor ) { editor.remove(); }
		row.querySelectorAll( '.wa-cell-hidden' ).forEach( function ( el ) {
			el.classList.remove( 'wa-cell-hidden' );
		} );
	}

	/**
	 * Create an element with its text set safely. Everything in the editor is
	 * translated or author-entered, so it goes in as text, never as markup.
	 *
	 * @param {string} tag     Tag name.
	 * @param {Object} props   Properties to assign (className, id, type…).
	 * @param {string} [text]  textContent, if any.
	 * @return {HTMLElement}
	 */
	function el( tag, props, text ) {
		var node = document.createElement( tag );
		Object.keys( props || {} ).forEach( function ( key ) {
			node[ key ] = props[ key ];
		} );
		if ( 'undefined' !== typeof text ) {
			node.textContent = text;
		}
		return node;
	}

	function buildEditor( headline, verdict ) {
		var editor = el( 'div', { className: 'wa-inline-editor' } );

		editor.appendChild( el(
			'label',
			{ className: 'screen-reader-text', htmlFor: 'wa-ie-statement' },
			i18n.statementLabel
		) );
		// Set as a value rather than in the markup so the text can't
		// break out of the attribute.
		var textarea = el( 'textarea', { id: 'wa-ie-statement', className: 'wa-ie-statement', rows: 2 } );
		textarea.value = headline;
		editor.appendChild( textarea );

		editor.appendChild( el(
			'label',
			{ className: 'screen-reader-text', htmlFor: 'wa-ie-verdict' },
			i18n.verdictLabel
		) );
		var select = el( 'select', { id: 'wa-ie-verdict', className: 'wa-ie-verdict' } );
		Object.keys( cfg.verdicts || {} ).forEach( function ( key ) {
			var option = el( 'option', { value: key, selected: key === verdict }, cfg.verdicts[ key ] );
			select.appendChild( option );
		} );
		editor.appendChild( select );

		var actions = el( 'span', { className: 'wa-ie-actions' } );
		actions.appendChild( el(
			'button',
			{ type: 'button', className: 'button button-primary wa-ie-save' },
			i18n.update
		) );
		actions.appendChild( document.createTextNode( ' ' ) );
		actions.appendChild( el(
			'button',
			{ type: 'button', className: 'button wa-ie-cancel' },
			i18n.cancel
		) );
		var status = el( 'span', { className: 'wa-ie-status' } );
		status.setAttribute( 'aria-live', 'polite' );
		actions.appendChild( status );
		editor.appendChild( actions );

		return editor;
	}

	function openEditor( row ) {
		if ( row.querySelector( '.wa-inline-editor' ) ) { return; }

		var headlineCell = row.querySelector( '.wa-col-headline' );
		var answerCell = row.querySelector( '.wa-col-answer' );
		var headline = row.querySelector( '.wa-headline-text' ).textContent;
		var verdict = row.querySelector( '.wa-answer-text' ).getAttribute( 'data-verdict' );

		var editor = buildEditor( headline, verdict );

		headlineCell.classList.add( 'wa-cell-hidden' );
		answerCell.classList.add( 'wa-cell-hidden' );
		row.querySelector( '.wa-col-post' ).appendChild( editor );

		editor.querySelector( '.wa-ie-statement' ).focus();

		editor.querySelector( '.wa-ie-cancel' ).addEventListener( 'click', function () {
			closeEditor( row );
		} );

		editor.querySelector( '.wa-ie-save' ).addEventListener( 'click', function () {
			save( row, editor );
		} );

		editor.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key ) { closeEditor( row ); }
			// Enter saves from the select, and from the textarea with
			// a modifier (plain Enter should still add a line break).
			if ( 'Enter' === e.key && ( e.target.tagName === 'SELECT' || e.metaKey || e.ctrlKey ) ) {
				e.preventDefault();
				save( row, editor );
			}
		} );
	}

	function save( row, editor ) {
		var statement = editor.querySelector( '.wa-ie-statement' ).value;
		var verdict = editor.querySelector( '.wa-ie-verdict' ).value;
		var status = editor.querySelector( '.wa-ie-status' );
		var saveBtn = editor.querySelector( '.wa-ie-save' );

		saveBtn.disabled = true;
		status.textContent = i18n.saving;

		fetch( cfg.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
			body: JSON.stringify( {
				post_id: parseInt( row.getAttribute( 'data-post' ), 10 ),
				statement: statement,
				verdict: verdict
			} )
		} ).then( function ( r ) {
			return r.json().then( function ( body ) {
				if ( ! r.ok ) { throw new Error( body.message || i18n.saveFailed ); }
				return body;
			} );
		} ).then( function ( body ) {
			row.querySelector( '.wa-headline-text' ).textContent = body.statement;
			var answer = row.querySelector( '.wa-answer-text' );
			answer.textContent = cfg.verdicts[ body.verdict ] || body.verdict;
			answer.setAttribute( 'data-verdict', body.verdict );
			closeEditor( row );
			row.classList.add( 'wa-row-saved' );
			setTimeout( function () { row.classList.remove( 'wa-row-saved' ); }, 1200 );
		} ).catch( function ( err ) {
			saveBtn.disabled = false;
			status.textContent = err.message || i18n.saveFailed;
		} );
	}

	document.querySelectorAll( '.wa-quick-edit-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var row = btn.closest( '.wa-report-row' );
			// One editor at a time, like the post list.
			document.querySelectorAll( '.wa-report-row' ).forEach( function ( other ) {
				if ( other !== row ) { closeEditor( other ); }
			} );
			openEditor( row );
		} );
	} );
}() );
