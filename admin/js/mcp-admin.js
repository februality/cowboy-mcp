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

	/* ── Connection Doctor ───────────────────────────────── */
	function doctorInit() {
		var run = document.getElementById( 'cowboy-doctor-run' );
		if ( ! run || typeof cowboyMcpDoctor === 'undefined' ) {
			return;
		}
		var resultsEl = document.getElementById( 'cowboy-doctor-results' );
		var copyBtn   = document.getElementById( 'cowboy-doctor-copy' );
		var reportText = '';

		run.addEventListener( 'click', function() {
			run.disabled = true;
			resultsEl.textContent = 'Running checks...';
			var data = new URLSearchParams( { action: 'cowboy_mcp_doctor', _ajax_nonce: cowboyMcpDoctor.nonce } );
			fetch( cowboyMcpDoctor.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function( r ) { return r.json(); } )
				.then( function( json ) {
					if ( ! json.success ) { throw new Error( 'AJAX error' ); }
					reportText = json.data.report;
					renderChecks( json.data.results.checks, 'Server-side checks' );
					return browserProbes( json.data.probes, json.data.fingerprints );
				} )
				.then( function( probeChecks ) {
					renderChecks( probeChecks, 'From your browser (outside the server)' );
					reportText += '\n--- From your browser (outside the server) ---\n' +
						'Note: your browser IP is not a datacenter IP - a pass here can still be blocked for cloud AI clients.\n' +
						probeChecks.map( function( c ) {
							return '[' + c.status.toUpperCase() + '] ' + c.label + ( c.detail ? ' - ' + c.detail : '' ) + ( c.fix ? '\n       Fix: ' + c.fix : '' );
						} ).join( '\n' );
					copyBtn.hidden = false;
					run.disabled = false;
				} )
				.catch( function( err ) {
					resultsEl.textContent = 'Doctor failed to run: ' + err.message;
					run.disabled = false;
				} );
		} );

		copyBtn.addEventListener( 'click', function() {
			copyToClipboard( reportText, function() { copyBtn.textContent = 'Copied!'; }, function() {} );
		} );

		function renderChecks( checks, heading ) {
			if ( resultsEl.textContent === 'Running checks...' ) { resultsEl.textContent = ''; }
			var h = document.createElement( 'div' );
			h.className = 'cowboy-doctor-group';
			h.textContent = heading;
			resultsEl.appendChild( h );
			checks.forEach( function( c ) {
				var el = document.createElement( 'div' );
				el.className = 'cowboy-doctor-check is-' + c.status;
				el.textContent = '[' + c.status.toUpperCase() + '] ' + c.label + ( c.detail ? ' - ' + c.detail : '' );
				if ( c.fix ) {
					var fix = document.createElement( 'span' );
					fix.className = 'fix';
					fix.textContent = 'Fix: ' + c.fix;
					el.appendChild( fix );
				}
				resultsEl.appendChild( el );
			} );
		}
	}

	/** Probe the public endpoint from the admin's browser. Same-origin, no CORS needed. */
	function browserProbes( probes, fingerprints ) {
		var jobs = [];
		jobs.push( probeOne( 'GET', probes.endpoint, null, 'GET MCP endpoint', fingerprints ) );
		jobs.push( probeOne( 'POST', probes.endpoint, JSON.stringify( { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'cowboy-doctor-browser', version: '0' } } } ), 'POST MCP endpoint', fingerprints ) );
		probes.well_known.forEach( function( url ) {
			jobs.push( probeOne( 'GET', url, null, 'OAuth discovery ' + url.split( '/.well-known' )[ 1 ], fingerprints ) );
		} );
		return Promise.all( jobs );
	}

	function probeOne( method, url, body, label, fingerprints ) {
		var opts = { method: method, credentials: 'omit', headers: { Accept: 'application/json, text/event-stream' } };
		if ( body ) {
			opts.headers[ 'Content-Type' ] = 'application/json';
			opts.body = body;
		}
		return fetch( url, opts ).then( function( r ) {
			return r.text().then( function( text ) {
				var isWellKnown = url.indexOf( '.well-known' ) !== -1;
				var okStatus    = isWellKnown ? r.status === 200 : r.status === 401;
				var isJson      = true;
				try { JSON.parse( text ); } catch ( e ) { isJson = false; }
				var ok = okStatus && isJson;
				var fp = ok ? null : matchFingerprint( r, text, fingerprints );
				return {
					label: label, status: ok ? 'pass' : 'fail',
					detail: ok ? '' : 'HTTP ' + r.status + ( isJson ? '' : ', non-JSON body' ),
					fix: fp ? fp.fix : ( ok ? null : 'See the server-side result for this URL; if that passed, the block is at your network edge (CDN/WAF).' )
				};
			} );
		} ).catch( function( err ) {
			return { label: label, status: 'fail', detail: 'Network error: ' + err.message, fix: 'Your browser could not reach the site at all (DNS, TLS, or connection refused). Remote AI clients will hit the same wall.' };
		} );
	}

	function matchFingerprint( response, bodyText, fingerprints ) {
		for ( var i = 0; i < fingerprints.length; i++ ) {
			var fp = fingerprints[ i ];
			if ( fp.status && fp.status.indexOf( response.status ) === -1 ) { continue; }
			if ( fp.header ) {
				var val = response.headers.get( fp.header[ 0 ] ) || '';
				if ( ! new RegExp( fp.header[ 1 ].replace( /^\/|\/i?$/g, '' ), 'i' ).test( val ) ) { continue; }
			}
			if ( fp.body_regex && ! new RegExp( fp.body_regex.replace( /^\/|\/i?$/g, '' ), 'i' ).test( bodyText ) ) { continue; }
			return fp;
		}
		return null;
	}

	doctorInit();
} )();

/* Rollback: confirm dialogs (one or two stage) for undo/restore forms. */
document.addEventListener('submit', function (e) {
	var form = e.target.closest('form[data-mcp-confirm]');
	if (!form) return;
	if (!window.confirm(form.getAttribute('data-mcp-confirm'))) { e.preventDefault(); return; }
	var second = form.getAttribute('data-mcp-confirm-2');
	if (second && !window.confirm(second)) e.preventDefault();
}, true);

/* ── Per-key scope editor ─────────────────────────────────── */
(function () {
	'use strict';

	function ensureChecklist(wrap, slot) {
		if (slot.firstElementChild) {
			return;
		}
		var tpl = document.getElementById('mcp-scope-checklist-template');
		if (!tpl) {
			return;
		}
		slot.appendChild(tpl.content.cloneNode(true));
		var preset = [];
		try {
			preset = JSON.parse(wrap.dataset.scopeTools || '[]');
		} catch (e) {
			preset = [];
		}
		preset.forEach(function (name) {
			var cb = slot.querySelector('input[name="allowed_tools[]"][value="' + name + '"]');
			if (cb) {
				cb.checked = true;
			}
		});
	}

	document.addEventListener('change', function (e) {
		// Radio: reveal/hide the custom checklist.
		if (e.target.matches('.mcp-scope-select input[type="radio"]')) {
			var wrap = e.target.closest('.mcp-scope-select');
			var slot = wrap.querySelector('.mcp-scope-custom-slot');
			var isCustom = wrap.querySelector('input[value="custom"]').checked;
			slot.hidden = !isCustom;
			if (isCustom) {
				ensureChecklist(wrap, slot);
			}
		}
		// Category select-all is handled entirely in the click listener below
		// (preventDefault() there stops the checkbox's default toggle, so it
		// never fires a native 'change' event for us to catch here).
	});

	document.addEventListener('click', function (e) {
		// Toggle the inline editor row under a key row.
		if (e.target.matches('.mcp-edit-scope')) {
			var editorRow = e.target.closest('tr').nextElementSibling;
			if (editorRow && editorRow.classList.contains('mcp-scope-editor-row')) {
				editorRow.hidden = !editorRow.hidden;
				e.target.setAttribute('aria-expanded', String(!editorRow.hidden));
				if (!editorRow.hidden) {
					// Pre-open the checklist for custom-scoped keys.
					var wrap = editorRow.querySelector('.mcp-scope-select');
					var slot = wrap.querySelector('.mcp-scope-custom-slot');
					if (wrap.querySelector('input[value="custom"]').checked) {
						ensureChecklist(wrap, slot);
						slot.hidden = false;
					}
				}
			}
		}
		// Category select-all checkbox lives inside <summary>, so a native click
		// also toggles the parent <details> — that's <summary>'s default action,
		// not propagation, so stopPropagation() alone can't stop it. preventDefault()
		// stops the <details> toggle, but the checkbox's own toggle already ran as
		// part of the click's *pre-click* activation step (before this handler even
		// sees the event), and preventDefault() also cancels that: the browser's
		// "canceled activation steps" revert checkbox.checked back to its pre-click
		// value immediately after dispatch finishes — after this handler returns,
		// so any assignment made in here gets clobbered synchronously. Read the
		// already-toggled value now (it's the state the click intended), apply it
		// to the children immediately, then reapply it to the checkbox itself on
		// the next tick, once the browser's revert has already happened.
		if (e.target.matches('.mcp-scope-cat-all')) {
			var summary = e.target.closest('summary');
			if (summary) {
				e.preventDefault();
				var cb = e.target;
				var desired = cb.checked;
				var det = cb.closest('.mcp-scope-cat');
				det.querySelectorAll('input[name="allowed_tools[]"]').forEach(function (c) {
					c.checked = desired;
				});
				setTimeout(function () {
					cb.checked = desired;
				}, 0);
			}
		}
	});
})();
