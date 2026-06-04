( function() {
	'use strict';

	/* ── Helpers ─────────────────────────────────────────── */

	/**
	 * Activate a tab by name across ALL tab groups on the page.
	 */
	function activateTab( tabName ) {
		document.querySelectorAll( '.mcp-tabs' ).forEach( function( tabs ) {
			var btn = tabs.querySelector( '.mcp-tab-btn[data-tab="' + tabName + '"]' );
			if ( ! btn ) {
				return;
			}
			var buttons = tabs.querySelectorAll( '.mcp-tab-btn' );
			buttons.forEach( function( b ) {
				b.classList.remove( 'mcp-tab-btn--active' );
				b.setAttribute( 'aria-selected', 'false' );
				b.setAttribute( 'tabindex', '-1' );
			} );
			tabs.querySelectorAll( '.mcp-tab-panel' ).forEach( function( p ) {
				p.classList.remove( 'mcp-tab-panel--active' );
			} );
			btn.classList.add( 'mcp-tab-btn--active' );
			btn.setAttribute( 'aria-selected', 'true' );
			btn.setAttribute( 'tabindex', '0' );
			var panel = tabs.querySelector( '[data-panel="' + tabName + '"]' );
			if ( panel ) {
				panel.classList.add( 'mcp-tab-panel--active' );
			}
		} );
		window.location.hash = tabName;
	}

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

	/* ── Tab switching ────────────────────────────────────── */
	document.querySelectorAll( '.mcp-tabs' ).forEach( function( tabs ) {
		tabs.querySelectorAll( '.mcp-tab-btn' ).forEach( function( btn ) {
			btn.addEventListener( 'click', function() {
				activateTab( btn.getAttribute( 'data-tab' ) );
			} );
		} );

		/* ── Keyboard navigation (roving tabindex) ────────── */
		var nav = tabs.querySelector( '.mcp-tabs-nav' );
		if ( nav ) {
			nav.addEventListener( 'keydown', function( e ) {
				var buttons = Array.prototype.slice.call( nav.querySelectorAll( '.mcp-tab-btn' ) );
				var index = buttons.indexOf( document.activeElement );
				if ( index === -1 ) {
					return;
				}
				var next = -1;
				if ( e.key === 'ArrowRight' ) {
					next = ( index + 1 ) % buttons.length;
				} else if ( e.key === 'ArrowLeft' ) {
					next = ( index - 1 + buttons.length ) % buttons.length;
				} else if ( e.key === 'Home' ) {
					next = 0;
				} else if ( e.key === 'End' ) {
					next = buttons.length - 1;
				}
				if ( next !== -1 ) {
					e.preventDefault();
					buttons[ next ].focus();
					activateTab( buttons[ next ].getAttribute( 'data-tab' ) );
				}
			} );
		}
	} );

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

	/* ── "I've copied my key" dismiss button ─────────────── */
	var dismissBtn = document.getElementById( 'mcp-dismiss-key' );
	if ( dismissBtn && typeof cowboyMcpAdmin !== 'undefined' ) {
		dismissBtn.addEventListener( 'click', function() {
			var xhr = new XMLHttpRequest();
			xhr.open( 'POST', cowboyMcpAdmin.ajaxUrl );
			xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
			xhr.send( 'action=cowboy_mcp_dismiss_new_key&_wpnonce=' + encodeURIComponent( cowboyMcpAdmin.dismissNonce ) );
			var step = dismissBtn.closest( '.mcp-step' );
			if ( step ) {
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
			}
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

	/* ── Restore tab state from URL hash ─────────────────── */
	var hash = window.location.hash.replace( '#', '' );
	if ( hash ) {
		var match = document.querySelector( '.mcp-tab-btn[data-tab="' + hash + '"]' );
		if ( match ) {
			activateTab( hash );
		}
	}
} )();
