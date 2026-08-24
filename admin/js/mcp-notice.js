( function() {
	'use strict';

	var notice = document.querySelector( '.mcp-setup-notice' );
	if ( ! notice || typeof cowboyMcpNotice === 'undefined' ) {
		return;
	}

	// Core injects the × into every .is-dismissible notice on DOM ready and
	// removes the notice on click, so listen on the notice and let the click
	// bubble up rather than binding to a button that may not exist yet.
	notice.addEventListener( 'click', function( e ) {
		if ( ! e.target.closest( '.notice-dismiss' ) ) {
			return;
		}
		var xhr = new XMLHttpRequest();
		xhr.open( 'POST', cowboyMcpNotice.ajaxUrl );
		xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
		xhr.send( 'action=cowboy_mcp_dismiss_setup_notice&_wpnonce=' + encodeURIComponent( cowboyMcpNotice.nonce ) );
	} );
} )();
