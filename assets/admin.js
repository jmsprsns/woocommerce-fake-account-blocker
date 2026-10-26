/**
 * Content Powered > WooCommerce Fake Account Blocker page behaviour. Only loaded on that page.
 *
 * Tabs, rows that depend on a checkbox, the Spam Accounts scan and the
 * delete buttons. fake_account_blocker_admin.ajaxUrl, .nonce and .i18n are
 * passed in from PHP (see WooCommerce_Fake_Account_Blocker_Admin::enqueue_assets).
 */

/* global fake_account_blocker_admin */
( function () {
	'use strict';

	var data = window.fake_account_blocker_admin;

	// Accounts deleted per request; the server takes at most 50.
	var CHUNK = 50;

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof data === 'undefined' ) {
			return;
		}

		var form = document.querySelector( '.wcfab-form' );

		initTabs( form );
		initDepends( form );
		initScan();
		initDelete();
	} );

	// Tabs ---------------------------------------------------------------------

	function initTabs( form ) {
		var tabs = document.querySelectorAll( '[data-wcfab-tab]' );
		var panels = document.querySelectorAll( '[data-wcfab-panel]' );
		var referer = form ? form.querySelector( 'input[name="_wp_http_referer"]' ) : null;
		var refererBase = referer ? referer.value.split( '#' )[ 0 ] : '';

		function show( id ) {
			var found = false;

			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-wcfab-tab' ) === id;

				found = found || active;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );

			if ( ! found && tabs.length ) {
				show( tabs[ 0 ].getAttribute( 'data-wcfab-tab' ) );
				return;
			}

			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-wcfab-panel' ) !== id;
			} );

			// Come back to this tab after saving.
			if ( referer ) {
				referer.value = refererBase + '#' + id;
			}
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				var id = tab.getAttribute( 'data-wcfab-tab' );

				event.preventDefault();
				show( id );
				history.replaceState( null, '', '#' + id );
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			show( window.location.hash.replace( '#', '' ) );
		} );

		show( window.location.hash.replace( '#', '' ) );
	}

	// Rows that only apply while a checkbox is ticked ---------------------------

	function initDepends( form ) {
		if ( ! form ) {
			return;
		}

		var rows = form.querySelectorAll( '[data-wcfab-depends]' );

		function update() {
			rows.forEach( function ( row ) {
				var key = row.getAttribute( 'data-wcfab-depends' );
				var box = form.querySelector( 'input[type="checkbox"][name$="[' + key + ']"]' );

				row.classList.toggle( 'is-disabled', !! box && ! box.checked );
			} );
		}

		form.addEventListener( 'change', update );
		update();
	}

	// AJAX ---------------------------------------------------------------------

	function post( action, fields ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', data.nonce );
		Object.keys( fields ).forEach( function ( key ) {
			body.append( key, fields[ key ] );
		} );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( response ) {
			return response.json();
		} );
	}

	function errorText( json ) {
		return json && json.data && json.data.message ? json.data.message : data.i18n.failed;
	}

	// Reload on the Spam Accounts tab, back on its first page.
	function reloadAccounts() {
		var url = new URL( window.location.href );

		url.searchParams.delete( 'wcfab_apaged' );
		url.hash = 'accounts';

		if ( url.pathname + url.search === window.location.pathname + window.location.search ) {
			window.location.hash = 'accounts';
			window.location.reload();
		} else {
			window.location.assign( url.toString() );
		}
	}

	// Spam Accounts: scan -------------------------------------------------------

	function initScan() {
		var button = document.querySelector( '[data-wcfab-scan]' );
		var progress = document.querySelector( '[data-wcfab-progress]' );
		var fill = document.querySelector( '[data-wcfab-progress-fill]' );
		var text = document.querySelector( '[data-wcfab-progress-text]' );
		var dns = document.getElementById( 'wcfab-scan-dns' );

		if ( ! button || ! progress || ! fill || ! text ) {
			return;
		}

		function fail( json ) {
			text.textContent = errorText( json );
			progress.classList.add( 'is-error' );
			button.disabled = false;
		}

		function step( restart ) {
			post( 'fake_account_blocker_scan', {
				restart: restart ? 1 : 0,
				dns: dns && dns.checked ? 1 : 0
			} )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						fail( json );
						return;
					}

					var result = json.data;

					fill.style.width = ( result.total ? Math.min( 100, ( result.checked / result.total ) * 100 ) : 100 ) + '%';
					text.textContent = result.message;

					if ( result.done ) {
						reloadAccounts();
					} else {
						step( false );
					}
				} )
				.catch( function () {
					fail( null );
				} );
		}

		button.addEventListener( 'click', function () {
			button.disabled = true;
			progress.hidden = false;
			progress.classList.remove( 'is-error' );
			fill.style.width = '0%';
			text.textContent = data.i18n.starting;
			step( true );
		} );
	}

	// Spam Accounts: delete -----------------------------------------------------

	function initDelete() {
		var picks = Array.prototype.slice.call( document.querySelectorAll( '[data-wcfab-pick]' ) );
		var pickAll = document.querySelector( '[data-wcfab-pick-all]' );
		var pickedButton = document.querySelector( '[data-wcfab-delete="picked"]' );
		var allButton = document.querySelector( '[data-wcfab-delete="all"]' );
		var status = document.querySelector( '[data-wcfab-delete-status]' );

		if ( ! pickedButton || ! allButton || ! status ) {
			return;
		}

		function selected() {
			return picks.filter( function ( pick ) {
				return pick.checked;
			} ).map( function ( pick ) {
				return pick.value;
			} );
		}

		function update() {
			pickedButton.disabled = selected().length === 0;
		}

		function busy( on ) {
			pickedButton.disabled = on || selected().length === 0;
			allButton.disabled = on;
		}

		function showDeleted( ids ) {
			ids.forEach( function ( id ) {
				var row = document.querySelector( '[data-wcfab-user="' + parseInt( id, 10 ) + '"]' );

				if ( row ) {
					row.classList.add( 'is-deleted' );
				}
			} );
		}

		function finish( count ) {
			status.textContent = 1 === count ? data.i18n.deletedOne : data.i18n.deleted.replace( '%d', count );
			window.setTimeout( reloadAccounts, 900 );
		}

		function fail( json ) {
			status.textContent = errorText( json );
			busy( false );
		}

		picks.forEach( function ( pick ) {
			pick.addEventListener( 'change', update );
		} );

		if ( pickAll ) {
			pickAll.addEventListener( 'change', function () {
				picks.forEach( function ( pick ) {
					pick.checked = pickAll.checked;
				} );
				update();
			} );
		}

		// The picked accounts, CHUNK at a time.
		pickedButton.addEventListener( 'click', function () {
			var ids = selected();
			var done = 0;

			if ( ! ids.length || ! window.confirm( 1 === ids.length ? data.i18n.confirmOne : data.i18n.confirmPicked.replace( '%d', ids.length ) ) ) {
				return;
			}

			busy( true );

			( function next() {
				var chunk = ids.splice( 0, CHUNK );

				if ( ! chunk.length ) {
					finish( done );
					return;
				}

				post( 'fake_account_blocker_delete', { ids: chunk.join( ',' ) } )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							fail( json );
							return;
						}

						done += json.data.deleted.length;
						showDeleted( json.data.deleted );
						status.textContent = data.i18n.deleting.replace( '%d', done );
						next();
					} )
					.catch( function () {
						fail( null );
					} );
			}() );
		} );

		// Everything the scan found: the server works through its list until
		// it's empty or stops getting shorter.
		allButton.addEventListener( 'click', function () {
			var done = 0;
			var left = parseInt( allButton.getAttribute( 'data-count' ), 10 ) || 0;

			if ( ! window.confirm( 1 === left ? data.i18n.confirmOne : data.i18n.confirmAll.replace( '%d', left ) ) ) {
				return;
			}

			busy( true );

			( function next() {
				post( 'fake_account_blocker_delete', { all: 1 } )
					.then( function ( json ) {
						if ( ! json || ! json.success ) {
							fail( json );
							return;
						}

						done += json.data.deleted.length;
						showDeleted( json.data.deleted );
						status.textContent = data.i18n.deleting.replace( '%d', done );

						if ( json.data.remaining > 0 && json.data.remaining < left ) {
							left = json.data.remaining;
							next();
						} else {
							finish( done );
						}
					} )
					.catch( function () {
						fail( null );
					} );
			}() );
		} );
	}
}() );
