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
					if ( btn.hasAttribute( 'data-cmcp-icon' ) ) {
						btn.classList.add( 'is-copied' );
						setTimeout( function() { btn.classList.remove( 'is-copied' ); }, 2000 );
						return;
					}
					btn.textContent = ( window.cowboyMcpAdmin && cowboyMcpAdmin.copied ) || 'Copied!';
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
		var reportText = '';

		run.addEventListener( 'click', function() {
			run.disabled = true;
			counts = {};
			resultsEl.dataset.state = 'running';
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
					resultsEl.textContent = 'Doctor failed to run: ' + err.message;
					run.disabled = false;
				} );
		} );

		copyBtn.addEventListener( 'click', function() {
			copyToClipboard( reportText, function() { copyBtn.textContent = 'Copied!'; }, function() {} );
		} );

		var counts = {};
		function tally( checks ) {
			checks.forEach( function( c ) { counts[ c.status ] = ( counts[ c.status ] || 0 ) + 1; } );
		}
		function renderSummary() {
			var order = [ [ 'fail', 'failed' ], [ 'error', 'errors' ], [ 'warn', 'warnings' ], [ 'pass', 'passed' ], [ 'skip', 'skipped' ] ];
			var parts = order.filter( function( o ) { return counts[ o[ 0 ] ]; } ).map( function( o ) { return counts[ o[ 0 ] ] + ' ' + o[ 1 ]; } );
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
			checks.forEach( function( c ) {
				var li = document.createElement( 'li' ), dot = document.createElement( 'span' ), label = document.createElement( 'span' ), res = document.createElement( 'span' );
				var tone = { pass: '', warn: ' cmcp-dot--warn', fail: ' cmcp-dot--bad', error: ' cmcp-dot--bad', skip: ' cmcp-dot--off' }[ c.status ] || ' cmcp-dot--off';
				dot.className = 'cmcp-dot' + tone;
				dot.setAttribute( 'aria-hidden', 'true' );
				label.className = 'cmcp-check-label';
				label.textContent = c.label;
				res.className = 'cmcp-res cmcp-res--' + c.status;
				res.textContent = c.status;
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
					fix.textContent = 'Fix: ' + c.fix;
					li.appendChild( fix );
				}
				ul.appendChild( li );
			} );
			resultsEl.append( h, ul );
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
			var cb = Array.prototype.find.call(slot.querySelectorAll('input[name="allowed_tools[]"]'), function (c) { return c.value === name; });
			if (cb) {
				cb.checked = true;
				return;
			}
			// Stored tool that is not registered right now (inactive plugin, classic theme, abilities off):
			// keep it in the scope instead of silently dropping it on save.
			var keep = document.createElement('input');
			keep.type = 'hidden';
			keep.name = 'allowed_tools[]';
			keep.value = name;
			slot.appendChild(keep);
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
	/* ── "New connections" countdown (Connection tab) ─────── */

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
			if ( btn ) { btn.value = 'enable'; btn.textContent = l10n.gateEnable || 'Enable for 30 minutes'; btn.classList.add( 'button-primary' ); }
		}
		function tick() {
			var now = Math.floor( Date.now() / 1000 ) - skew, live = false;
			Array.prototype.forEach.call( els, function( el ) {
				var left = parseInt( el.getAttribute( 'data-mcp-lock-until' ), 10 ) - now;
				if ( left > 0 ) {
					el.textContent = Math.floor( left / 60 ) + ':' + ( '0' + ( left % 60 ) ).slice( -2 );
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
		box.querySelector( '[data-cmcp-go]' ).focus();
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
		cancel.textContent = l10n.cancel || 'Cancel';
		go.type = 'button';
		go.className = 'cmcp-btn cmcp-btn--sm ' + ( tone === 'danger' ? 'cmcp-btn--danger-solid' : 'cmcp-btn--primary' );
		go.setAttribute( 'data-cmcp-go', '' );
		go.textContent = label;
		box.append( text, cancel, go );
		cancel.addEventListener( 'click', function() {
			close( form );
			if ( submitter ) {
				submitter.focus();
			}
		} );
		go.addEventListener( 'click', function() {
			close( form );
			if ( step === 1 && form.hasAttribute( 'data-cmcp-confirm-2' ) ) {
				place( form, build( form, submitter, 2 ) );
				return;
			}
			form.dataset.cmcpConfirmed = '1';
			form.requestSubmit( submitter || undefined );
		} );
		return box;
	}

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
	document.addEventListener( 'click', function( e ) {
		document.querySelectorAll( 'details.cmcp-menu[open]' ).forEach( function( d ) {
			if ( ! d.contains( e.target ) ) {
				d.open = false;
			}
		} );
		var edit = e.target.closest( '[data-cmcp-edit-scope]' );
		if ( edit ) {
			var row = edit.closest( 'tr' ).nextElementSibling;
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
			cancel.closest( 'tr' ).hidden = true;
		}
	} );
} )();

/* ── Journal: pair highlight + auto-submitting filters (Activity) ── */
( function() {
	'use strict';
	document.querySelectorAll( 'select[data-cmcp-autosubmit]' ).forEach( function( s ) {
		s.addEventListener( 'change', function() { s.form.requestSubmit(); } );
	} );
	document.querySelectorAll( '.cmcp-nojs' ).forEach( function( b ) { b.hidden = true; } );
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
	var bar = form.querySelector( '.cmcp-savebar' ), msg = bar.querySelector( '.cmcp-savebar-msg' ), discard = bar.querySelector( '[data-cmcp-discard]' );
	var snapshot = function() { return new URLSearchParams( new FormData( form ) ).toString(); };
	var initial = snapshot();
	var power = form.querySelector( '#cmcp-power-mode' ), panel = form.querySelector( '[data-cmcp-power-confirm]' );
	var hostIn = panel && panel.querySelector( '#cmcp-power-host' ), go = panel && panel.querySelector( '[data-cmcp-power-go]' );

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
			go.disabled = hostIn.value.trim() !== panel.getAttribute( 'data-host' );
		} );
		hostIn.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				if ( ! go.disabled ) {
					go.click();
				}
			}
		} );
		go.addEventListener( 'click', function() {
			power.dataset.cmcpArmed = '1';
			power.click();
			closePower();
			refresh();
		} );
		panel.querySelector( '[data-cmcp-power-cancel]' ).addEventListener( 'click', closePower );
	}
	refresh();
} )();
