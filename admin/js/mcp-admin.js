( function() {
	'use strict';

	/* ── Helpers ─────────────────────────────────────────── */

	/**
	 * Copy text to clipboard with fallback for older browsers.
	 */
	function copyToClipboard( text, onSuccess, onFailure ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( onSuccess ).catch( function() {
				fallbackCopy( text, onSuccess, onFailure );
			} );
		} else {
			fallbackCopy( text, onSuccess, onFailure );
		}
	}

	function fallbackCopy( text, onSuccess, onFailure ) {
		var textarea = document.createElement( 'textarea' );
		textarea.value = text;
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild( textarea );
		textarea.select();
		try {
			if ( document.execCommand( 'copy' ) ) {
				onSuccess();
			} else {
				onFailure();
			}
		} catch ( e ) {
			onFailure();
		}
		document.body.removeChild( textarea );
	}

	/* ── Copy-to-clipboard buttons ────────────────────────── */
	document.querySelectorAll( '.mcp-copy-btn' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			var targetId = btn.getAttribute( 'data-copy-target' );
			if ( ! targetId ) {
				return;
			}
			var el = document.getElementById( targetId );
			if ( ! el ) {
				return;
			}
			var originalText = btn.textContent;
			copyToClipboard(
				el.textContent,
				function() {
					btn.textContent = 'Copied!';
					btn.classList.add( 'mcp-copy-btn--copied' );
					setTimeout( function() {
						btn.textContent = originalText;
						btn.classList.remove( 'mcp-copy-btn--copied' );
					}, 2000 );
				},
				function() {
					btn.textContent = 'Failed to copy';
					setTimeout( function() {
						btn.textContent = originalText;
					}, 2000 );
				}
			);
		} );
	} );

	/* ── Confirm dialogs via data attribute ───────────────── */
	document.querySelectorAll( '[data-confirm]' ).forEach( function( el ) {
		el.addEventListener( 'click', function( e ) {
			if ( ! confirm( el.getAttribute( 'data-confirm' ) ) ) {
				e.preventDefault();
			}
		} );
	} );

	/* ── "I've saved my key" dismiss buttons (one per client panel) ── */
	var dismissBtns = document.querySelectorAll( '.mcp-dismiss-key' );
	if ( dismissBtns.length && typeof cowboyMcpAdmin !== 'undefined' ) {
		var dismissKeySteps = function() {
			document.querySelectorAll( '.mcp-key-step' ).forEach( function( step ) {
				step.classList.remove( 'mcp-step--completed' );
				step.classList.add( 'mcp-step--active' );
				var number = step.querySelector( '.mcp-step-number' );
				if ( number ) {
					number.textContent = '1';
				}
				var body = step.querySelector( '.mcp-step-body' );
				if ( body ) {
					body.innerHTML = '<p><em>Key dismissed. Reload the page to generate a new one.</em></p>';
				}
			} );
		};
		dismissBtns.forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', cowboyMcpAdmin.ajaxUrl );
				xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
				xhr.send( 'action=cowboy_mcp_dismiss_new_key&_wpnonce=' + encodeURIComponent( cowboyMcpAdmin.dismissNonce ) );
				dismissKeySteps();
			} );
		} );
	}

	/* ── Audit log detail expand/collapse ────────────────── */
	document.querySelectorAll( '.mcp-log-row' ).forEach( function( row ) {
		row.addEventListener( 'click', function() {
			var detail = row.nextElementSibling;
			if ( ! detail || ! detail.classList.contains( 'mcp-log-detail' ) ) {
				return;
			}
			var arrow = row.querySelector( '.mcp-expand-arrow' );
			if ( detail.style.display === 'table-row' ) {
				detail.style.display = 'none';
				if ( arrow ) {
					arrow.textContent = '\u25B6';
				}
			} else {
				detail.style.display = 'table-row';
				if ( arrow ) {
					arrow.textContent = '\u25BC';
				}
			}
		} );
	} );

	/* ── Connection client sidebar ────────────────────────── */
	var connSidebar = document.querySelector( '.mcp-conn-sidebar' );
	if ( connSidebar ) {
		var connItems = Array.prototype.slice.call( connSidebar.querySelectorAll( '.mcp-conn-item' ) );

		var activateClient = function( slug, persist ) {
			connItems.forEach( function( item ) {
				var on = item.getAttribute( 'data-client' ) === slug;
				item.classList.toggle( 'mcp-conn-item--active', on );
				item.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				item.setAttribute( 'tabindex', on ? '0' : '-1' );
			} );
			document.querySelectorAll( '.mcp-client-panel' ).forEach( function( panel ) {
				panel.classList.toggle( 'mcp-client-panel--active', panel.getAttribute( 'data-client-panel' ) === slug );
			} );
			if ( persist && typeof cowboyMcpAdmin !== 'undefined' && cowboyMcpAdmin.connNonce ) {
				var xhr = new XMLHttpRequest();
				xhr.open( 'POST', cowboyMcpAdmin.ajaxUrl );
				xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
				xhr.send( 'action=cowboy_mcp_set_conn_client&client=' + encodeURIComponent( slug ) + '&_wpnonce=' + encodeURIComponent( cowboyMcpAdmin.connNonce ) );
			}
		};

		connItems.forEach( function( item ) {
			item.addEventListener( 'click', function() {
				var slug = item.getAttribute( 'data-client' );
				activateClient( slug, true );
				window.location.hash = slug;
			} );
		} );

		/* Vertical keyboard navigation (roving tabindex) */
		connSidebar.addEventListener( 'keydown', function( e ) {
			var index = connItems.indexOf( document.activeElement );
			if ( index === -1 ) {
				return;
			}
			var next = -1;
			if ( e.key === 'ArrowDown' ) {
				next = ( index + 1 ) % connItems.length;
			} else if ( e.key === 'ArrowUp' ) {
				next = ( index - 1 + connItems.length ) % connItems.length;
			} else if ( e.key === 'Home' ) {
				next = 0;
			} else if ( e.key === 'End' ) {
				next = connItems.length - 1;
			}
			if ( next !== -1 ) {
				e.preventDefault();
				connItems[ next ].focus();
				connItems[ next ].click();
			}
		} );

		/* Deep link: #<client-slug> overrides the server-selected panel */
		var connHash = window.location.hash.replace( '#', '' ).replace( /[^a-z0-9-]/g, '' );
		if ( connHash && connSidebar.querySelector( '.mcp-conn-item[data-client="' + connHash + '"]' ) ) {
			activateClient( connHash, false );
		}
	}
} )();

/* Rollback: confirm dialogs (one or two stage) for undo/restore forms. */
document.addEventListener('submit', function (e) {
	var form = e.target.closest('form[data-mcp-confirm]');
	if (!form) return;
	if (!window.confirm(form.getAttribute('data-mcp-confirm'))) { e.preventDefault(); return; }
	var second = form.getAttribute('data-mcp-confirm-2');
	if (second && !window.confirm(second)) e.preventDefault();
}, true);
