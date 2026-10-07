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
		var prev = document.activeElement; // select() moves focus into the throwaway textarea
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
		if ( prev && prev.focus ) {
			prev.focus();
		}
	}

	var l10n = window.cowboyMcpAdmin || {};

	/** Screen-reader announcement through one shared polite live region. */
	function announce( text ) {
		var live = document.getElementById( 'cmcp-live' );
		if ( ! live ) {
			live = document.createElement( 'span' );
			live.id = 'cmcp-live';
			live.className = 'screen-reader-text';
			live.setAttribute( 'aria-live', 'polite' );
			( document.querySelector( '.wrap.cmcp' ) || document.body ).appendChild( live );
		}
		live.textContent = '';
		setTimeout( function() { live.textContent = text; }, 50 );
	}

	/**
	 * Show copy feedback on a button for 2 s, then restore it. Icon buttons keep their SVG
	 * (a class carries the state); text buttons swap their label.
	 */
	function copyFeedback( btn, ok ) {
		var text = ok ? ( l10n.copied || 'Copied!' ) : ( l10n.copyFailed || 'Failed to copy' );
		announce( text );
		if ( btn.hasAttribute( 'data-cmcp-icon' ) ) {
			btn.classList.add( ok ? 'is-copied' : 'is-failed' );
			setTimeout( function() { btn.classList.remove( 'is-copied', 'is-failed' ); }, 2000 );
			return;
		}
		if ( ! btn.hasAttribute( 'data-cmcp-label' ) ) {
			btn.setAttribute( 'data-cmcp-label', btn.textContent );
		}
		clearTimeout( btn._cmcpCopyTimer );
		btn.textContent = text;
		btn.classList.toggle( 'mcp-copy-btn--copied', ok );
		btn._cmcpCopyTimer = setTimeout( function() {
			btn.textContent = btn.getAttribute( 'data-cmcp-label' );
			btn.classList.remove( 'mcp-copy-btn--copied' );
		}, 2000 );
	}

	/* ── Copy-to-clipboard buttons ────────────────────────── */
	document.querySelectorAll( '.mcp-copy-btn' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			var el = document.getElementById( btn.getAttribute( 'data-copy-target' ) || '' );
			if ( ! el ) {
				return;
			}
			copyToClipboard( el.textContent, function() { copyFeedback( btn, true ); }, function() { copyFeedback( btn, false ); } );
		} );
	} );

	/* ── "I've saved my key" dismiss buttons (one per client panel) ── */
	var dismissBtns = document.querySelectorAll( '.mcp-dismiss-key' );
	if ( dismissBtns.length && typeof cowboyMcpAdmin !== 'undefined' ) {
		var dismissKeySteps = function() {
			document.querySelectorAll( '.cmcp-keybox' ).forEach( function( box ) {
				box.remove();
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

	/* ── Log rows: caret toggles the detail row ── */
	document.querySelectorAll( '.cmcp-caret' ).forEach( function( btn ) {
		btn.addEventListener( 'click', function() {
			var detail = document.getElementById( btn.getAttribute( 'aria-controls' ) );
			var open = btn.getAttribute( 'aria-expanded' ) !== 'true';
			btn.setAttribute( 'aria-expanded', String( open ) );
			btn.closest( 'tr' ).classList.toggle( 'is-open', open );
			if ( detail ) {
				detail.hidden = ! open;
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
		var t         = cowboyMcpDoctor.i18n;
		var reportText = '';

		run.addEventListener( 'click', function() {
			run.disabled = true;
			counts = {};
			resultsEl.dataset.state = 'running';
			resultsEl.textContent = t.running;
			var data = new URLSearchParams( { action: 'cowboy_mcp_doctor', _ajax_nonce: cowboyMcpDoctor.nonce } );
			fetch( cowboyMcpDoctor.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data } )
				.then( function( r ) { return r.json(); } )
				.then( function( json ) {
					if ( ! json.success ) { throw new Error( 'AJAX error' ); }
					reportText = json.data.report;
					renderChecks( json.data.results.checks, t.serverChecks );
					return browserProbes( json.data.probes, json.data.fingerprints, t );
				} )
				.then( function( probeChecks ) {
					renderChecks( probeChecks, t.browserChecks );
					renderSummary();
					reportText += '\n--- From your browser (outside the server) ---\n' +
						'Note: your browser IP is not a datacenter IP - a pass here can still be blocked for cloud AI clients.\n' +
						probeChecks.map( function( c ) {
							return '[' + c.status.toUpperCase() + '] ' + c.label + ( c.detail ? ' - ' + c.detail : '' ) + ( c.fix ? '\n       Fix: ' + c.fix : '' );
						} ).join( '\n' );
					copyBtn.hidden = false;
					run.disabled = false;
				} )
				.catch( function( err ) {
					resultsEl.textContent = fmt( t.failed, err.message );
					run.disabled = false;
				} );
		} );

		copyBtn.addEventListener( 'click', function() {
			copyToClipboard( reportText, function() { copyFeedback( copyBtn, true ); }, function() { copyFeedback( copyBtn, false ); } );
		} );

		var counts = {};
		function tally( checks ) {
			checks.forEach( function( c ) { counts[ c.status ] = ( counts[ c.status ] || 0 ) + 1; } );
		}
		function renderSummary() {
			var parts = [ 'fail', 'error', 'warn', 'pass', 'skip' ].filter( function( k ) { return counts[ k ]; } ).map( function( k ) { return fmt( t.summary[ k ], counts[ k ] ); } );
			var p = document.createElement( 'p' );
			p.className = 'cmcp-doctor-summary';
			p.textContent = parts.join( ' · ' );
			resultsEl.appendChild( p );
		}
		function renderChecks( checks, heading ) {
			if ( resultsEl.dataset.state === 'running' ) {
				resultsEl.textContent = '';
				resultsEl.dataset.state = '';
			}
			tally( checks );
			var h = document.createElement( 'p' ), ul = document.createElement( 'ul' );
			h.className = 'cmcp-checks-group';
			h.textContent = heading;
			ul.className = 'cmcp-checks';
			checks.forEach( function( raw ) {
				var c = raw.ui ? Object.assign( {}, raw, raw.ui ) : raw;
				var li = document.createElement( 'li' ), dot = document.createElement( 'span' ), label = document.createElement( 'span' ), res = document.createElement( 'span' );
				var tone = { pass: '', warn: ' cmcp-dot--warn', fail: ' cmcp-dot--bad', error: ' cmcp-dot--bad', skip: ' cmcp-dot--off' }[ c.status ] || ' cmcp-dot--off';
				dot.className = 'cmcp-dot' + tone;
				dot.setAttribute( 'aria-hidden', 'true' );
				label.className = 'cmcp-check-label';
				label.textContent = c.label;
				res.className = 'cmcp-res cmcp-res--' + c.status;
				res.textContent = t.status[ c.status ] || c.status;
				li.append( dot, label, res );
				if ( c.detail && c.status !== 'pass' ) {
					var det = document.createElement( 'span' );
					det.className = 'cmcp-det';
					det.textContent = c.detail;
					li.appendChild( det );
				}
				if ( c.fix && ( c.status === 'warn' || c.status === 'fail' || c.status === 'error' ) ) {
					var fix = document.createElement( 'span' );
					fix.className = 'cmcp-fix';
					fix.textContent = fmt( t.fix, c.fix );
					li.appendChild( fix );
				}
				ul.appendChild( li );
			} );
			resultsEl.append( h, ul );
		}
	}

	/** Replace the first %s / %d in a localized string. */
	function fmt( str, value ) {
		// A replacer function: a string replacement would expand $& / $' inside the value.
		return String( str ).replace( /%[sd]/, function() { return String( value ); } );
	}

	/** Probe the public endpoint from the admin's browser. Same-origin, no CORS needed. */
	function browserProbes( probes, fingerprints, t ) {
		var jobs = [];
		jobs.push( probeOne( 'GET', probes.endpoint, null, [ 'GET MCP endpoint', t.probeGet ], fingerprints, t ) );
		jobs.push( probeOne( 'POST', probes.endpoint, JSON.stringify( { jsonrpc: '2.0', id: 1, method: 'initialize', params: { protocolVersion: '2025-06-18', capabilities: {}, clientInfo: { name: 'cowboy-doctor-browser', version: '0' } } } ), [ 'POST MCP endpoint', t.probePost ], fingerprints, t ) );
		probes.well_known.forEach( function( url ) {
			var path = url.split( '/.well-known' )[ 1 ];
			jobs.push( probeOne( 'GET', url, null, [ 'OAuth discovery ' + path, fmt( t.probeOauth, path ) ], fingerprints, t ) );
		} );
		return Promise.all( jobs );
	}

	/**
	 * One browser probe. The top-level label/detail/fix are English (they go into the
	 * copied report); `ui` holds the localized text shown on screen. labels = [ en, localized ].
	 */
	function probeOne( method, url, body, labels, fingerprints, t ) {
		var label = labels[ 0 ];
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
				var edgeFix = ok ? null : 'See the server-side result for this URL; if that passed, the block is at your network edge (CDN/WAF).';
				return {
					label: label, status: ok ? 'pass' : 'fail',
					detail: ok ? '' : 'HTTP ' + r.status + ( isJson ? '' : ', non-JSON body' ),
					fix: fp ? fp.fix : edgeFix,
					ui: {
						label: labels[ 1 ],
						detail: ok ? '' : fmt( isJson ? t.probeHttp : t.probeNonJson, r.status ),
						fix: fp ? fp.fix : ( ok ? null : t.probeEdgeFix )
					}
				};
			} );
		} ).catch( function( err ) {
			return {
				label: label, status: 'fail', detail: 'Network error: ' + err.message, fix: 'Your browser could not reach the site at all (DNS, TLS, or connection refused). Remote AI clients will hit the same wall.',
				ui: { label: labels[ 1 ], detail: fmt( t.probeNetwork, err.message ), fix: t.probeNetFix }
			};
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
		var missing = null;
		preset.forEach(function (name) {
			var cb = Array.prototype.find.call(slot.querySelectorAll('input[name="allowed_tools[]"]'), function (c) { return c.value === name; });
			if (cb) {
				cb.checked = true;
				return;
			}
			// Stored tool that is not registered right now (inactive plugin, classic theme, abilities off):
			// keep it in the scope instead of silently dropping it on save, but show it so it can be removed.
			if (!missing) {
				missing = document.createElement('div');
				missing.className = 'cmcp-scope-missing';
				var h = document.createElement('p');
				h.className = 'cmcp-scope-missing-h';
				h.textContent = (window.cowboyMcpAdmin && cowboyMcpAdmin.scopeMissing) || 'Not currently available (kept in this scope until you untick them)';
				missing.appendChild(h);
				var list = slot.querySelector('.cmcp-scope-checklist') || slot;
				list.insertBefore(missing, list.querySelector(':scope > p.description'));
			}
			var label = document.createElement('label'), input = document.createElement('input'), code = document.createElement('code');
			label.className = 'mcp-scope-tool';
			input.type = 'checkbox';
			input.name = 'allowed_tools[]';
			input.value = name;
			input.checked = true;
			code.textContent = name;
			label.append(input, ' ', code);
			missing.appendChild(label);
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
	/* ── "New connections" countdown (Connections tab) ─────── */

	( function() {
		var els = document.querySelectorAll( '[data-mcp-lock-until]' );
		if ( ! els.length ) {
			return;
		}
		var first = els[ 0 ].textContent.split( ':' ),
			skew  = Math.floor( Date.now() / 1000 ) - ( parseInt( els[ 0 ].getAttribute( 'data-mcp-lock-until' ), 10 ) - parseInt( first[ 0 ], 10 ) * 60 - parseInt( first[ 1 ], 10 ) );
		function expire( el ) {
			var gate = el.closest( '.mcp-conn-gate' ), l10n = window.cowboyMcpAdmin || {};
			if ( ! gate ) {
				return;
			}
			gate.classList.remove( 'mcp-conn-gate--on' );
			gate.classList.add( 'mcp-conn-gate--off' );
			var label = gate.querySelector( '.mcp-conn-gate-label' ), timer = gate.querySelector( '.mcp-conn-gate-timer' ), btn = gate.querySelector( 'button[name="cowboy_mcp_toggle_connections"]' );
			if ( label ) { label.textContent = l10n.gateOff || 'New connections: disabled'; }
			if ( timer ) { timer.remove(); }
			if ( btn ) { btn.value = 'enable'; btn.textContent = l10n.gateEnable || 'Enable for 30 minutes'; btn.classList.add( 'cmcp-btn--primary' ); }
		}
		function tick() {
			var now = Math.floor( Date.now() / 1000 ) - skew, live = false;
			Array.prototype.forEach.call( els, function( el ) {
				var left = parseInt( el.getAttribute( 'data-mcp-lock-until' ), 10 ) - now;
				if ( left > 0 ) {
					el.textContent = ( '0' + Math.floor( left / 60 ) ).slice( -2 ) + ':' + ( '0' + ( left % 60 ) ).slice( -2 ); // mm:ss, as the server renders it
					live = true;
				} else if ( ! el.hasAttribute( 'data-mcp-expired' ) ) {
					el.setAttribute( 'data-mcp-expired', '1' );
					expire( el );
				}
			} );
			if ( live ) {
				setTimeout( tick, 1000 );
			}
		}
		setTimeout( tick, 1000 );
	} )();
})();
/* ── In-page confirmations: <form data-cmcp-confirm="…"> (replaces window.confirm) ── */
( function() {
	'use strict';
	var l10n = window.cowboyMcpAdmin || {};

	function close( form ) {
		if ( form._cmcpConfirm ) {
			form._cmcpConfirm.remove();
			form._cmcpConfirm = null;
		}
	}

	function place( form, box ) {
		var row = form.closest( 'tr' );
		if ( row && row.parentNode ) {
			var tr = document.createElement( 'tr' ), td = document.createElement( 'td' );
			tr.className = 'cmcp-panel-row';
			td.colSpan = row.cells.length;
			td.appendChild( box );
			tr.appendChild( td );
			row.after( tr );
			form._cmcpConfirm = tr;
		} else {
			form.after( box );
			form._cmcpConfirm = box;
		}
		var menu = form.closest( 'details' );
		if ( menu ) {
			menu.open = false;
		}
		// Destructive confirms start on Cancel so Enter-Enter cannot revoke/clear by accident.
		var tone = form.getAttribute( 'data-cmcp-confirm-tone' );
		box.querySelector( tone === 'danger' ? '[data-cmcp-cancel]' : '[data-cmcp-go]' ).focus();
	}

	/** Return focus to the control that opened the confirm — or its menu toggle, if that menu is now closed. */
	function refocus( submitter ) {
		if ( ! submitter ) {
			return;
		}
		var menu = submitter.closest( 'details' );
		if ( menu && ! menu.open ) {
			menu.querySelector( 'summary' ).focus();
			return;
		}
		submitter.focus();
	}

	function build( form, submitter, step ) {
		var msg   = form.getAttribute( step === 2 ? 'data-cmcp-confirm-2' : 'data-cmcp-confirm' );
		var tone  = form.getAttribute( 'data-cmcp-confirm-tone' ) || 'plain';
		var label = form.getAttribute( 'data-cmcp-confirm-label' ) || ( submitter && submitter.textContent.trim().replace( /…$/, '' ) ) || l10n.confirm || 'Confirm';
		var box = document.createElement( 'div' ), text = document.createElement( 'span' ), cancel = document.createElement( 'button' ), go = document.createElement( 'button' );
		box.className = 'cmcp-inline-confirm cmcp-inline-confirm--' + tone;
		box.setAttribute( 'role', 'alert' );
		text.className = 'cmcp-msg';
		text.textContent = msg;
		cancel.type = 'button';
		cancel.className = 'cmcp-btn cmcp-btn--sm';
		cancel.setAttribute( 'data-cmcp-cancel', '' );
		cancel.textContent = l10n.cancel || 'Cancel';
		go.type = 'button';
		go.className = 'cmcp-btn cmcp-btn--sm ' + ( tone === 'danger' ? 'cmcp-btn--danger-solid' : 'cmcp-btn--primary' );
		go.setAttribute( 'data-cmcp-go', '' );
		go.textContent = label;
		box.append( text, cancel, go );
		cancel.addEventListener( 'click', function() {
			close( form );
			refocus( submitter );
		} );
		go.addEventListener( 'click', function() {
			close( form );
			if ( step === 1 && form.hasAttribute( 'data-cmcp-confirm-2' ) ) {
				place( form, build( form, submitter, 2 ) );
				return;
			}
			form.dataset.cmcpConfirmed = '1';
			form.requestSubmit( submitter || undefined );
			// requestSubmit dispatches synchronously; if validation blocked it, the flag is still set
			// and would let the next submit skip the confirm.
			delete form.dataset.cmcpConfirmed;
		} );
		return box;
	}

	// Escape = Cancel on the confirm that holds focus (Cancel restores focus to the opener).
	document.addEventListener( 'keydown', function( e ) {
		var box = e.key === 'Escape' && document.activeElement && document.activeElement.closest( '.cmcp-inline-confirm' );
		if ( box ) {
			e.preventDefault();
			box.querySelector( '[data-cmcp-cancel]' ).click();
		}
	} );

	// "Add to Claude" posts into a new tab (which then goes on to claude.ai). Re-load this
	// page with a GET so the "New connections" status shows the window it just opened.
	document.addEventListener( 'submit', function( e ) {
		if ( e.target.matches && e.target.matches( 'form[data-cmcp-refresh-after-submit]' ) ) {
			window.setTimeout( function() { window.location.assign( window.location.href.split( '#' )[ 0 ] ); }, 1500 );
		}
	} );

	document.addEventListener( 'submit', function( e ) {
		var form = e.target;
		if ( ! form.matches || ! form.matches( 'form[data-cmcp-confirm]' ) ) {
			return;
		}
		if ( form.dataset.cmcpConfirmed === '1' ) {
			delete form.dataset.cmcpConfirmed;
			return;
		}
		e.preventDefault();
		close( form );
		place( form, build( form, e.submitter || null, 1 ) );
	}, true );
} )();

/* ── Credential menus + access editor (Connections) ── */
( function() {
	'use strict';
	document.addEventListener( 'keydown', function( e ) {
		if ( e.key !== 'Escape' ) {
			return;
		}
		document.querySelectorAll( 'details.cmcp-menu[open]' ).forEach( function( d ) {
			d.open = false;
			if ( d.contains( document.activeElement ) ) {
				d.querySelector( 'summary' ).focus();
			}
		} );
	} );
	document.addEventListener( 'click', function( e ) {
		document.querySelectorAll( 'details.cmcp-menu[open]' ).forEach( function( d ) {
			if ( ! d.contains( e.target ) ) {
				d.open = false;
			}
		} );
		var edit = e.target.closest( '[data-cmcp-edit-scope]' );
		if ( edit ) {
			// Skip an in-page confirm row (revoke) that may sit between the credential and its editor.
			var row = edit.closest( 'tr' ).nextElementSibling;
			while ( row && ! row.hasAttribute( 'data-cmcp-scope-row' ) && row.classList.contains( 'cmcp-panel-row' ) ) {
				row = row.nextElementSibling;
			}
			edit.closest( 'details' ).open = false;
			if ( row && row.hasAttribute( 'data-cmcp-scope-row' ) ) {
				row.hidden = false;
				var wrap = row.querySelector( '.mcp-scope-select' );
				if ( wrap.querySelector( 'input[value="custom"]' ).checked ) {
					wrap.querySelector( 'input[value="custom"]' ).dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
				row.querySelector( 'input[type="radio"]:checked' ).focus();
			}
		}
		var cancel = e.target.closest( '[data-cmcp-scope-cancel]' );
		if ( cancel ) {
			// Throw away unsaved edits: back to the stored mode, and drop the cloned checklist
			// (it is rebuilt from data-scope-tools the next time the editor opens).
			var form = cancel.closest( 'form' ), sel = form.querySelector( '.mcp-scope-select' ), slot = sel.querySelector( '.mcp-scope-custom-slot' );
			form.reset();
			slot.textContent = '';
			slot.hidden = ! sel.querySelector( 'input[value="custom"]' ).checked;
			cancel.closest( 'tr' ).hidden = true;
			var owner = cancel.closest( 'tr' ).previousElementSibling;
			while ( owner && owner.classList.contains( 'cmcp-panel-row' ) ) {
				owner = owner.previousElementSibling;
			}
			if ( owner && owner.querySelector( 'summary.cmcp-kebab' ) ) {
				owner.querySelector( 'summary.cmcp-kebab' ).focus();
			}
		}
	} );
} )();

/* ── Journal: pair highlight + auto-submitting filters (Activity) ── */
( function() {
	'use strict';
	document.querySelectorAll( '.cmcp-nojs' ).forEach( function( b ) { b.hidden = true; } );
	// A mouse pick submits at once. Arrow keys on a closed select also fire `change`
	// (Windows/Linux), so a keyboard change waits for Enter or the revealed Filter button.
	document.querySelectorAll( 'select[data-cmcp-autosubmit]' ).forEach( function( s ) {
		var keyboard = false;
		s.addEventListener( 'pointerdown', function() { keyboard = false; } );
		s.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				s.form.requestSubmit();
				return;
			}
			keyboard = true;
		} );
		s.addEventListener( 'change', function() {
			if ( ! keyboard ) {
				s.form.requestSubmit();
				return;
			}
			s.form.querySelectorAll( '.cmcp-nojs' ).forEach( function( b ) { b.hidden = false; } );
		} );
	} );
	function pairOf( row ) {
		var id = row.getAttribute( 'data-cmcp-pair' );
		return id ? document.getElementById( 'cmcp-change-' + id ) : null;
	}
	document.querySelectorAll( '.cmcp-journal tr[data-cmcp-pair]' ).forEach( function( row ) {
		row.addEventListener( 'mouseenter', function() {
			var other = pairOf( row );
			row.classList.add( 'is-hl' );
			if ( other ) { other.classList.add( 'is-hl' ); }
		} );
		row.addEventListener( 'mouseleave', function() {
			var other = pairOf( row );
			row.classList.remove( 'is-hl' );
			if ( other ) { other.classList.remove( 'is-hl' ); }
		} );
	} );
} )();

/* ── Settings: save bar + Power-mode typed confirm ── */
( function() {
	'use strict';
	var form = document.querySelector( 'form.cmcp-settings-form' );
	if ( ! form ) {
		return;
	}
	var bar = form.querySelector( '.cmcp-savebar' ), msg = bar.querySelector( '.cmcp-savebar-msg' ), discard = bar.querySelector( '[data-cmcp-discard]' ), save = bar.querySelector( 'button[type="submit"]' );
	var snapshot = function() { return new URLSearchParams( new FormData( form ) ).toString(); };
	var initial = snapshot();
	var power = form.querySelector( '#cmcp-power-mode' ), panel = form.querySelector( '[data-cmcp-power-confirm]' );
	var hostIn = panel && panel.querySelector( '#cmcp-power-host' ), go = panel && panel.querySelector( '[data-cmcp-power-go]' );

	var submitting = false;
	form.addEventListener( 'submit', function() { submitting = true; } );
	window.addEventListener( 'beforeunload', function( e ) {
		if ( ! submitting && snapshot() !== initial ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	function refresh() {
		var dirty = snapshot() !== initial;
		bar.classList.toggle( 'is-dirty', dirty );
		msg.textContent = dirty ? bar.getAttribute( 'data-dirty' ) : bar.getAttribute( 'data-clean' );
		discard.hidden = ! dirty;
	}
	function closePower() {
		if ( panel ) {
			panel.hidden = true;
			hostIn.value = '';
			go.disabled = true;
		}
	}
	form.addEventListener( 'input', refresh );
	form.addEventListener( 'change', refresh );
	discard.addEventListener( 'click', function() {
		form.reset();
		closePower();
		refresh();
		save.focus(); // Discard just hid itself
	} );
	if ( power && panel ) {
		power.addEventListener( 'click', function( e ) {
			if ( power.checked && power.dataset.cmcpArmed !== '1' ) {
				e.preventDefault();
				panel.hidden = false;
				hostIn.focus();
			}
			delete power.dataset.cmcpArmed;
		} );
		hostIn.addEventListener( 'input', function() {
			go.disabled = hostIn.value.trim().toLowerCase() !== panel.getAttribute( 'data-host' ).toLowerCase();
		} );
		hostIn.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				if ( ! go.disabled ) {
					go.click();
				}
			}
		} );
		// Closing the panel hides the control that has focus: hand it back to the switch.
		go.addEventListener( 'click', function() {
			power.dataset.cmcpArmed = '1';
			power.click();
			closePower();
			refresh();
			power.focus();
		} );
		panel.querySelector( '[data-cmcp-power-cancel]' ).addEventListener( 'click', function() {
			closePower();
			power.focus();
		} );
		panel.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				closePower();
				power.focus();
			}
		} );
	}
	refresh();
} )();

document.addEventListener( 'click', function( e ) {
	var x = e.target.closest && e.target.closest( '.cmcp-blocked-x' );
	if ( ! x ) { return; }
	var box = x.closest( '[data-cmcp-blocked]' );
	var body = new URLSearchParams( { action: 'cowboy_mcp_dismiss_blocked', _ajax_nonce: cowboyMcpAdmin.blockedNonce } );
	fetch( cowboyMcpAdmin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } );
	if ( box ) { box.remove(); }
} );

// "Open ChatGPT": copy the connection link during the click (user gesture), then let the form open the new tab.
document.addEventListener( 'submit', function( e ) {
	var form = e.target;
	if ( ! form.matches || ! form.matches( 'form[data-cmcp-copy]' ) ) { return; }
	var note = form.parentNode.querySelector( '[data-cmcp-copy-note]' );
	if ( navigator.clipboard && window.isSecureContext !== false ) {
		navigator.clipboard.writeText( form.getAttribute( 'data-cmcp-copy' ) ).then( function() {
			if ( note ) { note.hidden = false; }
		}, function() {} );
	}
} );
