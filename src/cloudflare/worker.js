// Simply Static -> Cloudflare Worker entry.
// Static assets answer first; only /_ss/* reaches this code (assets.run_worker_first).
//
// Bindings (all optional; the site works without them):
//   ASSETS            static assets
//   DB                D1 database with table ss_submissions (form storage)
//   TURNSTILE_SECRET  secret for Cloudflare Turnstile verification
//   WEBHOOK_URL       POSTed a JSON copy of every submission
//   MAIL_TO, MAIL_FROM, MAIL_SITE   emails each submission to the owner, through one of:
//     MAIL_SMTP_HOST + MAIL_SMTP_PASS   the owner's own mailbox over SMTP (port 465); MAIL_FROM is its login
//     EMAIL                             Cloudflare Email Service (send_email binding)

const MAX_BODY = 100 * 1024;
const MAX_FIELDS = 100;
const MAX_VALUE = 5000;

export default {
	async fetch( request, env, ctx ) {
		const url = new URL( request.url );

		if ( url.pathname.startsWith( '/_ss/form/' ) ) {
			return handleForm( request, env, ctx, url );
		}

		return env.ASSETS.fetch( request );
	},
};

function json( body, status = 200 ) {
	return new Response( JSON.stringify( body ), {
		status,
		headers: { 'content-type': 'application/json', 'cache-control': 'no-store' },
	} );
}

async function readFields( request ) {
	const type = request.headers.get( 'content-type' ) || '';
	if ( type.includes( 'application/json' ) ) {
		return await request.json();
	}

	// ponytail: file uploads are ignored (strings only); store in R2 if sites need attachments.
	const fields = {};
	for ( const [ key, value ] of ( await request.formData() ).entries() ) {
		if ( typeof value !== 'string' ) {
			continue;
		}
		fields[ key ] = key in fields ? [].concat( fields[ key ], value ) : value;
	}

	return fields;
}

async function handleForm( request, env, ctx, url ) {
	if ( request.method !== 'POST' ) {
		return json( { ok: false, error: 'method' }, 405 );
	}
	if ( ! env.DB ) {
		return json( { ok: false, error: 'forms-not-configured' }, 503 );
	}
	if ( Number( request.headers.get( 'content-length' ) || 0 ) > MAX_BODY ) {
		return json( { ok: false, error: 'too-large' }, 413 );
	}

	let fields;
	try {
		fields = await readFields( request );
	} catch ( e ) {
		return json( { ok: false, error: 'bad-body' }, 400 );
	}
	if ( ! fields || typeof fields !== 'object' || JSON.stringify( fields ).length > MAX_BODY ) {
		return json( { ok: false, error: 'bad-body' }, 400 );
	}

	// Honeypot: bots fill the hidden field; answer "ok" so they do not retry.
	if ( fields._ss_hp ) {
		return reply( request, true );
	}

	if ( env.TURNSTILE_SECRET ) {
		const token = fields[ 'cf-turnstile-response' ];
		const verdict = token ? await verifyTurnstile( env.TURNSTILE_SECRET, token, request ) : false;
		if ( ! verdict ) {
			return json( { ok: false, error: 'captcha' }, 400 );
		}
	}

	delete fields._ss_hp;
	delete fields[ 'cf-turnstile-response' ];

	const clean = {};
	for ( const key of Object.keys( fields ).slice( 0, MAX_FIELDS ) ) {
		const value = fields[ key ];
		clean[ key.slice( 0, 200 ) ] = Array.isArray( value )
			? value.map( ( v ) => String( v ).slice( 0, MAX_VALUE ) )
			: String( value ).slice( 0, MAX_VALUE );
	}

	const formId = url.pathname.slice( '/_ss/form/'.length ).replace( /[^a-z0-9_-]/gi, '' ).slice( 0, 40 );
	const page = ( request.headers.get( 'referer' ) || '' ).slice( 0, 500 );

	await env.DB.prepare( 'INSERT INTO ss_submissions (form_id, page, data) VALUES (?1, ?2, ?3)' )
		.bind( formId, page, JSON.stringify( clean ) )
		.run();

	if ( env.WEBHOOK_URL ) {
		ctx.waitUntil(
			fetch( env.WEBHOOK_URL, {
				method: 'POST',
				headers: { 'content-type': 'application/json' },
				body: JSON.stringify( { form: formId, page, fields: clean } ),
			} ).catch( () => {} )
		);
	}

	if ( ( env.EMAIL || ( env.MAIL_SMTP_HOST && env.MAIL_SMTP_PASS ) ) && env.MAIL_TO && env.MAIL_FROM ) {
		ctx.waitUntil( notify( env, page, clean ) );
	}

	return reply( request, true );
}

const EMAIL_RE = /^[^\s@<>,;"]+@[^\s@<>,;"]+\.[^\s@<>,;"]+$/;

// Mail is a courtesy: the submission is already stored, so failures are only logged.
async function notify( env, page, fields ) {
	const text = Object.entries( fields )
		.map( ( [ key, value ] ) => key + ': ' + ( Array.isArray( value ) ? value.join( ', ' ) : value ) )
		.join( '\n' );
	const replyTo = Object.values( fields ).find( ( v ) => typeof v === 'string' && EMAIL_RE.test( v.trim() ) );
	// The visitor's address is the sender's name, so the owner sees who wrote (the address itself is the site's).
	const message = {
		from: { email: env.MAIL_FROM, name: replyTo ? replyTo.trim() : env.MAIL_SITE || 'Website' },
		to: env.MAIL_TO,
		subject: 'New form message' + ( env.MAIL_SITE ? ' - ' + env.MAIL_SITE : '' ),
		text: text + '\n\n' + ( page ? 'Page: ' + page : '' ),
	};
	if ( replyTo ) {
		message.replyTo = replyTo.trim();
	}

	try {
		if ( env.MAIL_SMTP_HOST && env.MAIL_SMTP_PASS ) {
			await smtpSend( env, message );
		} else {
			await env.EMAIL.send( message );
		}
	} catch ( e ) {
		console.log( 'mail failed', e.code, e.message );
	}
}

const bytes = ( text ) => new TextEncoder().encode( text );

function base64( data ) {
	let binary = '';
	for ( const b of typeof data === 'string' ? bytes( data ) : data ) {
		binary += String.fromCharCode( b );
	}

	return btoa( binary );
}

// Header text: no line breaks or quotes (it ends up inside a quoted string), plain when ASCII, else an RFC 2047 word.
const header = ( raw ) => {
	const text = String( raw ).replace( /[\r\n"\\]/g, ' ' );

	return /^[\x20-\x7e]*$/.test( text ) ? text : '=?UTF-8?B?' + base64( text ) + '?=';
};

// Minimal SMTP submission over implicit TLS (port 465): one message, AUTH PLAIN.
// env.SMTP_CONNECT only exists for tests; the Worker uses its socket API.
async function smtpSend( env, { from, to, replyTo, subject, text } ) {
	const connect = env.SMTP_CONNECT || ( await import( 'cloudflare:sockets' ) ).connect;
	const socket = connect( { hostname: env.MAIL_SMTP_HOST, port: 465 }, { secureTransport: 'on', allowHalfOpen: false } );
	const writer = socket.writable.getWriter();
	const reader = socket.readable.getReader();
	const decoder = new TextDecoder();
	let buffer = '';

	// A reply ends at the first line with a space after the code ("250 ok"); "250-..." lines continue it.
	const read = async () => {
		for ( ;; ) {
			const lines = buffer.split( '\r\n' );
			const last = lines.findIndex( ( l, i ) => i < lines.length - 1 && /^\d{3} /.test( l ) );
			if ( last >= 0 ) {
				buffer = lines.slice( last + 1 ).join( '\r\n' );

				return lines[ last ];
			}
			const { value, done } = await reader.read();
			if ( done ) {
				throw new Error( 'smtp: connection closed' );
			}
			buffer += decoder.decode( value, { stream: true } );
		}
	};
	const step = async ( command, ok ) => {
		if ( command !== null ) {
			await writer.write( bytes( command + '\r\n' ) );
		}
		const line = await read();
		if ( ! line.startsWith( ok ) ) {
			// Never echo the command: it may carry the password.
			throw new Error( 'smtp: ' + line.slice( 0, 120 ) );
		}
	};

	const domain = from.email.split( '@' )[ 1 ];
	const body = base64( text ).replace( /.{1,76}/g, '$&\r\n' );
	const data = [
		'From: "' + header( from.name ) + '" <' + from.email + '>',
		'To: <' + to + '>',
		replyTo ? 'Reply-To: <' + replyTo + '>' : null,
		'Subject: ' + header( subject ),
		'Date: ' + new Date().toUTCString(),
		'Message-ID: <' + crypto.randomUUID() + '@' + domain + '>',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: base64',
	].filter( Boolean ).join( '\r\n' ) + '\r\n\r\n' + body;

	let timeout;
	const timer = new Promise( ( _, reject ) => {
		timeout = setTimeout( () => reject( new Error( 'smtp: timeout' ) ), 20000 );
	} );
	const session = async () => {
		await step( null, '220' );
		await step( 'EHLO ' + domain, '250' );
		await step( 'AUTH PLAIN ' + base64( '\0' + env.MAIL_FROM + '\0' + env.MAIL_SMTP_PASS ), '235' );
		await step( 'MAIL FROM:<' + from.email + '>', '250' );
		await step( 'RCPT TO:<' + to + '>', '25' );
		await step( 'DATA', '354' );
		// Base64 lines never start with ".", so no dot-stuffing is needed.
		await step( data + '.', '250' );
		await writer.write( bytes( 'QUIT\r\n' ) ).catch( () => {} );
	};

	try {
		await Promise.race( [ session(), timer ] );
	} finally {
		clearTimeout( timeout );
		socket.close().catch( () => {} );
	}
}

async function verifyTurnstile( secret, token, request ) {
	const response = await fetch( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', {
		method: 'POST',
		body: new URLSearchParams( {
			secret,
			response: String( token ),
			remoteip: request.headers.get( 'CF-Connecting-IP' ) || '',
		} ),
	} );

	return ( await response.json() ).success === true;
}

// JS callers get JSON; a plain browser submit (no JS) is sent back where it came from.
function reply( request, ok ) {
	if ( ( request.headers.get( 'accept' ) || '' ).includes( 'application/json' ) ) {
		return json( { ok } );
	}

	return Response.redirect( request.headers.get( 'referer' ) || '/', 303 );
}
