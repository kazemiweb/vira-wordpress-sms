/*
 * The sign-in form's three steps.
 *
 * No dependency beyond `fetch`, and no reload: every step posts to admin-ajax.php, shows its own
 * button as busy while it waits, and moves the visitor to the next step or shows the reason the
 * step did not work. Only the button that was pressed shows the spinner.
 */

( function () {
	'use strict';

	var root = document.querySelector( '[data-vira-sms]' );
	var config = window.viraSmsData;

	if ( ! root || ! config || ! window.fetch || ! window.FormData ) {
		return;
	}

	var strings = config.strings || {};
	var form = root.querySelector( '.vira-sms__form' );
	var messageBox = root.querySelector( '[data-message]' );
	var sentBox = root.querySelector( '[data-sent-to]' );
	var verifiedBox = root.querySelector( '[data-verified]' );
	var resendButton = root.querySelector( '[data-action="resend"]' );
	var backButton = root.querySelector( '[data-action="back"]' );

	var steps = {
		mobile: root.querySelector( '[data-step="mobile"]' ),
		code: root.querySelector( '[data-step="code"]' ),
		profile: root.querySelector( '[data-step="profile"]' )
	};

	var fields = {
		mobile: root.querySelector( '.vira-sms__input--mobile' ),
		code: root.querySelector( '.vira-sms__input--code' ),
		first: root.querySelector( '#vira-sms-first' ),
		last: root.querySelector( '#vira-sms-last' )
	};

	var state = {
		step: 'mobile',
		mobile: '',
		ticket: '',
		nonce: config.nonce,
		busy: false,
		timer: null,
		resendLeft: 0
	};

	/* ---------------------------------------------------------------- helpers */

	function toAsciiDigits( value ) {
		return String( value )
			.replace( /[\u06F0-\u06F9]/g, function ( d ) {
				return String( d.charCodeAt( 0 ) - 0x06f0 );
			} )
			.replace( /[\u0660-\u0669]/g, function ( d ) {
				return String( d.charCodeAt( 0 ) - 0x0660 );
			} );
	}

	function digitsOnly( value ) {
		return toAsciiDigits( value ).replace( /\D/g, '' );
	}

	function say( text, kind ) {
		messageBox.textContent = text || '';
		messageBox.className = 'vira-sms__message' + ( kind ? ' is-' + kind : '' );
	}

	function show( name ) {
		state.step = name;

		Object.keys( steps ).forEach( function ( key ) {
			if ( steps[ key ] ) {
				steps[ key ].hidden = key !== name;
			}
		} );

		// Move the caret where the visitor expects it, without stealing focus on load.
		var target = name === 'mobile' ? fields.mobile : name === 'code' ? fields.code : fields.first;

		if ( target && document.activeElement !== target ) {
			target.focus();
		}
	}

	function setBusy( button, busy, label ) {
		if ( ! button ) {
			return;
		}

		button.classList.toggle( 'is-loading', !! busy );
		button.disabled = !! busy;
		form.setAttribute( 'aria-busy', busy ? 'true' : 'false' );

		var text = button.querySelector( '.vira-sms__button-text' );

		if ( text ) {
			if ( busy && label ) {
				if ( ! text.dataset.rest ) {
					text.dataset.rest = text.textContent;
				}

				text.textContent = label;
			} else if ( text.dataset.rest ) {
				text.textContent = text.dataset.rest;
			}
		}
	}

	/**
	 * Run a step with its button busy.
	 *
	 * A step that comes back in a few milliseconds - checking a code, making an account - would
	 * otherwise leave the spinner on screen for less time than the eye can catch, and the button
	 * would look as if it had not been pressed at all. Every step therefore keeps its button busy
	 * for at least this long, so the first press and the last behave the same way.
	 */
	var MIN_BUSY = 400;

	function run( button, label, work ) {
		setBusy( button, true, label );

		var started = Date.now();

		return work()
			.then( function ( result ) {
				var left = MIN_BUSY - ( Date.now() - started );

				if ( left <= 0 ) {
					return result;
				}

				return new Promise( function ( done ) {
					window.setTimeout( function () {
						done( result );
					}, left );
				} );
			} )
			.then( function ( result ) {
				setBusy( button, false );
				return result;
			} );
	}

	function refreshNonce() {
		var body = new FormData();

		body.append( 'action', 'vira_sms_nonce' );

		return window
			.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( result && result.success && result.data && result.data.nonce ) {
					state.nonce = result.data.nonce;
				}
			} )
			.catch( function () {} );
	}

	function post( action, payload ) {
		var body = new FormData();

		body.append( 'action', action );
		body.append( 'nonce', state.nonce );

		Object.keys( payload || {} ).forEach( function ( key ) {
			body.append( key, payload[ key ] );
		} );

		return window
			.fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.catch( function () {
				return { success: false, data: { code: 'vira_sms_network', message: strings.networkError } };
			} );
	}

	/**
	 * Post one step, asking for a fresh form token once if the page's own token has expired.
	 */
	function request( action, payload, retried ) {
		return post( action, payload ).then( function ( result ) {
			var expired =
				result &&
				result.success === false &&
				result.data &&
				result.data.code === 'vira_sms_bad_nonce';

			if ( expired && ! retried ) {
				return refreshNonce().then( function () {
					return request( action, payload, true );
				} );
			}

			return result;
		} );
	}

	function redirect( url ) {
		window.setTimeout( function () {
			window.location.href = url;
		}, 700 );
	}

	/* ------------------------------------------------------------- countdown */

	function stopCountdown() {
		if ( state.timer ) {
			window.clearInterval( state.timer );
			state.timer = null;
		}
	}

	function startCountdown( seconds ) {
		stopCountdown();

		if ( ! resendButton ) {
			return;
		}

		state.resendLeft = Math.max( 0, parseInt( seconds, 10 ) || 0 );

		function paint() {
			if ( state.resendLeft <= 0 ) {
				resendButton.disabled = false;
				resendButton.textContent = strings.resend;
				stopCountdown();
				return;
			}

			resendButton.disabled = true;
			resendButton.textContent = ( strings.resendIn || '%s' ).replace( '%s', state.resendLeft );
			state.resendLeft -= 1;
		}

		paint();
		state.timer = window.setInterval( paint, 1000 );
	}

	/* ----------------------------------------------------------------- steps */

	function sendCode( button ) {
		var mobile = digitsOnly( fields.mobile.value );

		if ( mobile.length < 10 ) {
			say( 'شماره موبایل را کامل وارد کنید.', 'error' );
			fields.mobile.focus();
			return;
		}

		say( '' );

		run( button, strings.sending, function () {
			return request( 'vira_sms_send_code', { mobile: mobile } );
		} ).then( function ( result ) {
			if ( ! result.success ) {
				say( result.data && result.data.message ? result.data.message : strings.networkError, 'error' );
				return;
			}

			state.mobile = mobile;
			fields.code.value = '';
			fields.code.maxLength = result.data.length || config.codeLength;
			sentBox.textContent = ( strings.codeSentTo || '%s' ).replace( '%s', result.data.masked );
			say( '' );
			show( 'code' );
			startCountdown( result.data.resend_after );
		} );
	}

	function verifyCode( button ) {
		var code = digitsOnly( fields.code.value );

		if ( ! code ) {
			say( 'کد پیامک‌شده را وارد کنید.', 'error' );
			fields.code.focus();
			return;
		}

		say( '' );

		run( button, strings.verifying, function () {
			return request( 'vira_sms_verify_code', { mobile: state.mobile, code: code } );
		} ).then( function ( result ) {
			if ( ! result.success ) {
				say( result.data && result.data.message ? result.data.message : strings.networkError, 'error' );

				// A code that is gone or used up should not keep the visitor waiting for a new one.
				if (
					result.data &&
					[ 'vira_sms_expired', 'vira_sms_no_code', 'vira_sms_too_many' ].indexOf( result.data.code ) !== -1
				) {
					startCountdown( 0 );
				}

				fields.code.focus();
				fields.code.select();
				return;
			}

			if ( result.data.state === 'register' ) {
				state.ticket = result.data.ticket;
				verifiedBox.textContent = result.data.name || '';
				say( '' );
				show( 'profile' );
				return;
			}

			stopCountdown();
			say( result.data.message, 'success' );
			redirect( result.data.redirect );
		} );
	}

	function register( button ) {
		var first = fields.first.value.trim();
		var last = fields.last.value.trim();

		if ( ! first || ! last ) {
			say( 'نام و نام خانوادگی را وارد کنید.', 'error' );
			( first ? fields.last : fields.first ).focus();
			return;
		}

		say( '' );

		run( button, strings.registering, function () {
			return request( 'vira_sms_register', { ticket: state.ticket, first_name: first, last_name: last } );
		} ).then( function ( result ) {
			if ( ! result.success ) {
				say( result.data && result.data.message ? result.data.message : strings.networkError, 'error' );

				// The ticket is what expires; when it does, the visitor starts again.
				if ( result.data && result.data.code === 'vira_sms_no_ticket' ) {
					show( 'mobile' );
					startCountdown( 0 );
				}

				return;
			}

			say( result.data.message, 'success' );
			redirect( result.data.redirect );
		} );
	}

	/* ------------------------------------------------------------------ wire */

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		var button = event.submitter || form.querySelector( '[data-step]:not([hidden]) .vira-sms__button' );

		if ( 'code' === state.step ) {
			verifyCode( button );
		} else if ( 'profile' === state.step ) {
			register( button );
		} else {
			sendCode( button );
		}
	} );

	if ( resendButton ) {
		resendButton.addEventListener( 'click', function () {
			sendCode( form.querySelector( '[data-step="code"] .vira-sms__button' ) );
		} );
	}

	if ( backButton ) {
		backButton.addEventListener( 'click', function () {
			stopCountdown();
			state.ticket = '';
			fields.code.value = '';
			fields.mobile.value = state.mobile ? '0' + state.mobile : fields.mobile.value;
			say( '' );
			show( 'mobile' );
		} );
	}

	// Keep the number fields to digits, in either alphabet.
	[ fields.mobile, fields.code ].forEach( function ( input ) {
		if ( ! input ) {
			return;
		}

		input.addEventListener( 'input', function () {
			var clean = digitsOnly( input.value );
			var limit = parseInt( input.getAttribute( 'maxlength' ), 10 ) || 0;

			if ( limit ) {
				clean = clean.slice( 0, limit );
			}

			if ( clean !== input.value ) {
				input.value = clean;
			}
		} );
	} );

	// A cached page carries an old token; ask for a live one before the visitor needs it.
	refreshNonce();
} )();
