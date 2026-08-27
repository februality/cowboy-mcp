( function() {
	'use strict';

	if ( typeof cowboyMcpNotice === 'undefined' ) {
		return;
	}

	// Fire-and-forget admin-ajax POST. keepalive lets the request finish even
	// when the click also navigates (the review / support links).
	function send( params ) {
		var body = new URLSearchParams( params );
		if ( window.fetch ) {
			window.fetch( cowboyMcpNotice.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true } ).catch( function() {} );
			return;
		}
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cowboyMcpNotice.ajaxUrl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.send( body.toString() );
	}

	/* ── Post-activation setup notice ── */
	var setup = document.querySelector( '.mcp-setup-notice' );
	if ( setup ) {
		// Core injects the × into every .is-dismissible notice on DOM ready and
		// removes the notice on click, so listen on the notice and let the click
		// bubble up rather than binding to a button that may not exist yet.
		setup.addEventListener( 'click', function( e ) {
			if ( e.target.closest( '.notice-dismiss' ) ) {
				send( { action: 'cowboy_mcp_dismiss_setup_notice', _wpnonce: cowboyMcpNotice.nonce } );
			}
		} );
	}

	/* ── Feedback prompt ── */
	var fb = document.querySelector( '.mcp-feedback-notice' );
	if ( ! fb || ! cowboyMcpNotice.feedbackNonce ) {
		return;
	}

	function decide( decision ) {
		send( { action: 'cowboy_mcp_feedback', _wpnonce: cowboyMcpNotice.feedbackNonce, decision: decision } );
	}

	function showPanel( name ) {
		fb.querySelectorAll( '[data-panel]' ).forEach( function( el ) {
			el.hidden = el.getAttribute( 'data-panel' ) !== name;
		} );
	}

	function finish() {
		showPanel( 'thanks' );
		window.setTimeout( function() {
			fb.classList.add( 'is-leaving' );
			window.setTimeout( function() { fb.remove(); }, 300 );
		}, 1500 );
	}

	fb.addEventListener( 'click', function( e ) {
		if ( e.target.closest( '.notice-dismiss' ) ) {
			decide( 'later' ); // core removes the notice itself
			return;
		}
		var control = e.target.closest( '[data-feedback]' );
		if ( ! control ) {
			return;
		}
		switch ( control.getAttribute( 'data-feedback' ) ) {
			case 'positive':
			case 'negative':
				showPanel( control.getAttribute( 'data-feedback' ) );
				break;
			case 'later':
				decide( 'later' );
				fb.remove();
				break;
			case 'already':
				decide( 'already' );
				finish();
				break;
			case 'review':
			case 'support':
				// Real link: let it open in its tab, just record the outcome.
				decide( control.getAttribute( 'data-feedback' ) );
				finish();
				break;
		}
	} );
} )();
