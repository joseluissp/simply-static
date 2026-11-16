// Run: node --test tests/worker/
import test from 'node:test';
import assert from 'node:assert/strict';
import worker from '../../src/cloudflare/worker.js';

const ctx = { waitUntil: ( p ) => p };

function fakeDb() {
	const rows = [];
	return {
		rows,
		prepare: () => ( { bind: ( ...args ) => ( { run: async () => rows.push( args ) } ) } ),
	};
}

function post( body, headers = {}, path = '/_ss/form/abc123' ) {
	return new Request( 'https://site.test' + path, {
		method: 'POST',
		headers: { accept: 'application/json', ...headers },
		body,
	} );
}

test( 'non-form requests fall through to static assets', async () => {
	const env = { ASSETS: { fetch: async () => new Response( 'asset' ) } };
	const res = await worker.fetch( new Request( 'https://site.test/index.html' ), env, ctx );

	assert.equal( await res.text(), 'asset' );
} );

test( 'stores a submission and drops control fields', async () => {
	const DB = fakeDb();
	const body = new URLSearchParams( { name: 'Ana', msg: 'hola', _ss_hp: '' } );
	const res = await worker.fetch( post( body, { referer: 'https://site.test/contacto/' } ), { DB }, ctx );

	assert.deepEqual( await res.json(), { ok: true } );
	assert.equal( DB.rows.length, 1 );
	assert.deepEqual( DB.rows[ 0 ].slice( 0, 2 ), [ 'abc123', 'https://site.test/contacto/' ] );
	assert.deepEqual( JSON.parse( DB.rows[ 0 ][ 2 ] ), { name: 'Ana', msg: 'hola' } );
} );

test( 'honeypot answers ok but stores nothing', async () => {
	const DB = fakeDb();
	const res = await worker.fetch( post( new URLSearchParams( { _ss_hp: 'bot' } ) ), { DB }, ctx );

	assert.deepEqual( await res.json(), { ok: true } );
	assert.equal( DB.rows.length, 0 );
} );

test( 'turnstile: missing token is rejected before storing', async () => {
	const DB = fakeDb();
	const res = await worker.fetch( post( new URLSearchParams( { name: 'x' } ) ), { DB, TURNSTILE_SECRET: 's' }, ctx );

	assert.equal( res.status, 400 );
	assert.equal( ( await res.json() ).error, 'captcha' );
	assert.equal( DB.rows.length, 0 );
} );

test( 'turnstile: valid token is accepted and not stored', async () => {
	const realFetch = globalThis.fetch;
	globalThis.fetch = async () => new Response( JSON.stringify( { success: true } ) );
	try {
		const DB = fakeDb();
		const body = new URLSearchParams( { name: 'x', 'cf-turnstile-response': 'tok' } );
		const res = await worker.fetch( post( body ), { DB, TURNSTILE_SECRET: 's' }, ctx );

		assert.equal( res.status, 200 );
		assert.deepEqual( JSON.parse( DB.rows[ 0 ][ 2 ] ), { name: 'x' } );
	} finally {
		globalThis.fetch = realFetch;
	}
} );

test( 'without a database binding forms answer 503, GET is 405', async () => {
	assert.equal( ( await worker.fetch( post( 'a=b', { 'content-type': 'application/x-www-form-urlencoded' } ), {}, ctx ) ).status, 503 );
	assert.equal( ( await worker.fetch( new Request( 'https://site.test/_ss/form/x' ), { DB: fakeDb() }, ctx ) ).status, 405 );
} );

test( 'plain browser submit is redirected back (303)', async () => {
	const req = new Request( 'https://site.test/_ss/form/abc', {
		method: 'POST',
		headers: { referer: 'https://site.test/contacto/' },
		body: new URLSearchParams( { a: '1' } ),
	} );
	const res = await worker.fetch( req, { DB: fakeDb() }, ctx );

	assert.equal( res.status, 303 );
	assert.equal( res.headers.get( 'location' ), 'https://site.test/contacto/' );
} );

function mailCtx() {
	const pending = [];

	return { pending, waitUntil: ( p ) => pending.push( p ) };
}

const MAIL = { MAIL_TO: 'owner@example.org', MAIL_FROM: 'noreply@example.com', MAIL_SITE: 'Mi sitio' };

test( 'mail: a stored submission is emailed with reply-to from the visitor address', async () => {
	const sent = [];
	const c = mailCtx();
	const env = { DB: fakeDb(), EMAIL: { send: async ( m ) => sent.push( m ) }, ...MAIL };
	await worker.fetch( post( new URLSearchParams( { name: 'Ana', email: ' ana@mail.com ', msg: 'hola' } ), { referer: 'https://site.test/contacto/' } ), env, c );
	await Promise.all( c.pending );

	assert.equal( sent.length, 1 );
	assert.equal( sent[ 0 ].to, 'owner@example.org' );
	assert.deepEqual( sent[ 0 ].from, { email: 'noreply@example.com', name: 'ana@mail.com' }, 'the visitor address is the sender name' );
	assert.equal( sent[ 0 ].replyTo, 'ana@mail.com' );
	assert.equal( sent[ 0 ].subject, 'New form message - Mi sitio' );
	assert.match( sent[ 0 ].text, /name: Ana/ );
	assert.match( sent[ 0 ].text, /Page: https:\/\/site\.test\/contacto\// );
} );

test( 'mail: no reply-to when no field looks like an email', async () => {
	const sent = [];
	const c = mailCtx();
	const env = { DB: fakeDb(), EMAIL: { send: async ( m ) => sent.push( m ) }, ...MAIL };
	await worker.fetch( post( new URLSearchParams( { msg: 'hola' } ) ), env, c );
	await Promise.all( c.pending );

	assert.equal( sent.length, 1 );
	assert.equal( 'replyTo' in sent[ 0 ], false );
	assert.deepEqual( sent[ 0 ].from, { email: 'noreply@example.com', name: 'Mi sitio' }, 'no visitor address: the site name' );
} );

test( 'mail: nothing is sent without the email bindings, honeypot or captcha failures', async () => {
	const sent = [];
	const EMAIL = { send: async ( m ) => sent.push( m ) };

	let c = mailCtx();
	await worker.fetch( post( new URLSearchParams( { msg: 'x' } ) ), { DB: fakeDb(), EMAIL }, c );
	c = mailCtx();
	await worker.fetch( post( new URLSearchParams( { _ss_hp: 'bot' } ) ), { DB: fakeDb(), EMAIL, ...MAIL }, c );
	c = mailCtx();
	await worker.fetch( post( new URLSearchParams( { msg: 'x' } ) ), { DB: fakeDb(), EMAIL, ...MAIL, TURNSTILE_SECRET: 's' }, c );
	await Promise.all( c.pending );

	assert.equal( sent.length, 0 );
} );

test( 'mail: a failing send never breaks the submission', async () => {
	const c = mailCtx();
	const DB = fakeDb();
	const env = { DB, EMAIL: { send: async () => { throw Object.assign( new Error( 'boom' ), { code: 'E_X' } ); } }, ...MAIL };
	const res = await worker.fetch( post( new URLSearchParams( { msg: 'hola' } ) ), env, c );
	await Promise.all( c.pending );

	assert.deepEqual( await res.json(), { ok: true } );
	assert.equal( DB.rows.length, 1 );
} );

// ---- SMTP (the owner's own mailbox) ----

// A scripted SMTP server behind the socket API the Worker uses: replies per command, records everything it receives.
function fakeSmtp( { auth = '235 ok' } = {} ) {
	const received = [];
	const enc = new TextEncoder();
	const dec = new TextDecoder();
	let controller;
	let inData = false;
	let data = '';
	let closed = false;
	const readable = new ReadableStream( { start( c ) { controller = c; c.enqueue( enc.encode( '220 mx ready\r\n' ) ); } } );
	const say = ( text ) => controller.enqueue( enc.encode( text + '\r\n' ) );
	const writable = new WritableStream( {
		write( chunk ) {
			const text = dec.decode( chunk );
			if ( inData ) {
				data += text;
				if ( data.endsWith( '\r\n.\r\n' ) ) {
					inData = false;
					received.push( data );
					say( '250 queued' );
				}

				return;
			}
			received.push( text.trimEnd() );
			if ( text.startsWith( 'EHLO' ) ) {
				controller.enqueue( enc.encode( '250-mx.test\r\n250-SIZE 100\r\n250 AUTH PLAIN LOGIN\r\n' ) );
			} else if ( text.startsWith( 'AUTH' ) ) {
				say( auth );
			} else if ( text.startsWith( 'DATA' ) ) {
				inData = true;
				say( '354 go' );
			} else if ( text.startsWith( 'QUIT' ) ) {
				say( '221 bye' );
			} else {
				say( '250 ok' );
			}
		},
	} );
	const calls = [];
	const connect = ( address, options ) => {
		calls.push( { address, options } );

		return { readable, writable, close: async () => { closed = true; } };
	};

	return { received, calls, connect, isClosed: () => closed };
}

const SMTP = { MAIL_TO: 'info@example.com', MAIL_FROM: 'info@example.com', MAIL_SITE: 'Mi sitio', MAIL_SMTP_HOST: 'smtp.example.com', MAIL_SMTP_PASS: 's3cret' };

test( 'smtp: the message goes through the owner mailbox, from the site address, named after the visitor', async () => {
	const smtp = fakeSmtp();
	const c = mailCtx();
	const env = { DB: fakeDb(), SMTP_CONNECT: smtp.connect, ...SMTP };
	await worker.fetch( post( new URLSearchParams( { name: 'Ñandú', email: 'ana@mail.com', msg: 'hola\n.\nmundo' } ), { referer: 'https://site.test/contacto/' } ), env, c );
	await Promise.all( c.pending );

	assert.deepEqual( smtp.calls[ 0 ].address, { hostname: 'smtp.example.com', port: 465 } );
	assert.equal( smtp.calls[ 0 ].options.secureTransport, 'on' );
	assert.equal( smtp.received[ 0 ], 'EHLO example.com' );
	assert.equal( smtp.received[ 1 ], 'AUTH PLAIN ' + Buffer.from( '\0info@example.com\0s3cret' ).toString( 'base64' ) );
	assert.equal( smtp.received[ 2 ], 'MAIL FROM:<info@example.com>' );
	assert.equal( smtp.received[ 3 ], 'RCPT TO:<info@example.com>' );
	assert.equal( smtp.received[ 4 ], 'DATA' );

	const mail = smtp.received[ 5 ];
	assert.match( mail, /^From: "ana@mail\.com" <info@example\.com>\r\n/ );
	assert.match( mail, /\r\nTo: <info@example\.com>\r\n/ );
	assert.match( mail, /\r\nReply-To: <ana@mail\.com>\r\n/ );
	assert.match( mail, /\r\nSubject: New form message - Mi sitio\r\n/ );
	assert.match( mail, /\r\nContent-Transfer-Encoding: base64\r\n/ );
	const body = Buffer.from( mail.split( '\r\n\r\n' )[ 1 ].replace( /\r\n/g, '' ), 'base64' ).toString( 'utf8' );
	assert.match( body, /name: Ñandú/ );
	assert.match( body, /msg: hola\n\.\nmundo/, 'a lone dot in the text cannot end the message' );
	assert.match( body, /Page: https:\/\/site\.test\/contacto\//);
	assert.ok( smtp.received.includes( 'QUIT' ) );
	assert.ok( smtp.isClosed() );
} );

test( 'smtp: a refused login is only logged and never breaks the submission, nor leaks the password', async () => {
	const smtp = fakeSmtp( { auth: '535 5.7.8 bad credentials' } );
	const logs = [];
	const original = console.log;
	console.log = ( ...args ) => logs.push( args.join( ' ' ) );
	const DB = fakeDb();
	const c = mailCtx();
	let res;
	try {
		res = await worker.fetch( post( new URLSearchParams( { msg: 'hola' } ) ), { DB, SMTP_CONNECT: smtp.connect, ...SMTP }, c );
		await Promise.all( c.pending );
	} finally {
		console.log = original;
	}

	assert.deepEqual( await res.json(), { ok: true } );
	assert.equal( DB.rows.length, 1 );
	assert.match( logs.join( '\n' ), /535/ );
	assert.doesNotMatch( logs.join( '\n' ), /s3cret|AUTH/ );
	assert.equal( smtp.received.some( ( l ) => l === 'DATA' ), false, 'no message after a failed login' );
	assert.ok( smtp.isClosed() );
} );

test( 'smtp: needs its password and recipient, otherwise nothing is sent', async () => {
	const smtp = fakeSmtp();
	let c = mailCtx();
	await worker.fetch( post( new URLSearchParams( { msg: 'x' } ) ), { DB: fakeDb(), SMTP_CONNECT: smtp.connect, ...SMTP, MAIL_SMTP_PASS: undefined }, c );
	c = mailCtx();
	await worker.fetch( post( new URLSearchParams( { msg: 'x' } ) ), { DB: fakeDb(), SMTP_CONNECT: smtp.connect, ...SMTP, MAIL_TO: undefined }, c );
	await Promise.all( c.pending );

	assert.equal( smtp.calls.length, 0 );
} );

test( 'smtp: header injection through the visitor address or the site name is not possible', async () => {
	const smtp = fakeSmtp();
	const c = mailCtx();
	const env = { DB: fakeDb(), SMTP_CONNECT: smtp.connect, ...SMTP, MAIL_SITE: 'Mi "sitio"\r\nBcc: x@evil.test' };
	await worker.fetch( post( new URLSearchParams( { email: 'a@b.co\r\nBcc: x@evil.test', msg: 'hola' } ) ), env, c );
	await Promise.all( c.pending );

	const mail = smtp.received[ 5 ];
	assert.doesNotMatch( mail, /\r\nBcc:/ );
	assert.match( mail, /\r\nSubject: New form message - Mi  sitio   Bcc: x@evil\.test\r\n/, 'line breaks and quotes become spaces' );
} );

// ---- Hostinger Agentic Mail API ----

const HM = { MAIL_TO: 'info@example.com', MAIL_FROM: 'info@example.com', MAIL_SITE: 'Mi sitio', MAIL_API_TOKEN: 'tok-secret' };

// Fake API: GET /me lists the mailbox, POST .../send answers 204 (or `sendStatus`); records every call.
function withHostinger( { sendStatus = 204, mailboxes = [ { resourceId: 'AC1', address: 'Info@Example.com' } ] } = {} ) {
	const calls = [];
	const realFetch = globalThis.fetch;
	globalThis.fetch = async ( url, init = {} ) => {
		calls.push( { url, init } );
		if ( url.endsWith( '/me' ) ) {
			return new Response( JSON.stringify( { data: { mailboxes } } ) );
		}

		return new Response( sendStatus === 204 ? null : 'denied', { status: sendStatus } );
	};

	return { calls, restore: () => ( globalThis.fetch = realFetch ) };
}

test( 'hostinger api: sends from the mailbox over HTTPS, named after the visitor, with a mailto link', async () => {
	const api = withHostinger();
	try {
		const c = mailCtx();
		await worker.fetch( post( new URLSearchParams( { email: 'ana@mail.com', msg: '<b>hola</b>' } ) ), { DB: fakeDb(), ...HM }, c );
		await Promise.all( c.pending );

		assert.equal( api.calls.length, 2 );
		assert.equal( api.calls[ 0 ].url, 'https://api.mail.hostinger.com/api/v1/me' );
		assert.equal( api.calls[ 1 ].url, 'https://api.mail.hostinger.com/api/v1/mailboxes/AC1/send' );
		assert.equal( api.calls[ 1 ].init.headers.authorization, 'Bearer tok-secret' );
		const body = JSON.parse( api.calls[ 1 ].init.body );
		assert.deepEqual( body.to, [ 'info@example.com' ] );
		assert.equal( body.displayName, 'ana@mail.com' );
		assert.equal( body.subject, 'New form message - Mi sitio' );
		assert.match( body.text, /^Reply to: ana@mail\.com\n\nemail: ana@mail\.com/ );
		assert.match( body.html, /href="mailto:ana@mail\.com"/ );
		assert.match( body.html, /&#60;b&#62;hola&#60;\/b&#62;/, 'the visitor text is escaped in the html' );
	} finally {
		api.restore();
	}
} );

test( 'hostinger api: the email leaves out plugin fields ("_*") and empty ones, D1 keeps them all', async () => {
	const api = withHostinger();
	try {
		const db = fakeDb();
		const c = mailCtx();
		await worker.fetch(
			post( new URLSearchParams( { _wpcf7: '924', _wpcf7_posted_data_hash: '', 'your-email': 'ana@mail.com', note: '  ' } ) ),
			{ DB: db, ...HM },
			c
		);
		await Promise.all( c.pending );

		const body = JSON.parse( api.calls[ api.calls.length - 1 ].init.body );
		assert.equal( body.text, 'Reply to: ana@mail.com\n\nyour-email: ana@mail.com\n\n' );
		assert.doesNotMatch( body.html, /_wpcf7|note/ );
		assert.match( JSON.stringify( db.rows ), /_wpcf7/, 'the stored submission is untouched' );
	} finally {
		api.restore();
	}
} );

test( 'hostinger api: a second submission reuses the mailbox id', async () => {
	const api = withHostinger( { mailboxes: [ { resourceId: 'AC9', address: 'cache@example.com' } ] } );
	try {
		for ( let i = 0; i < 2; i++ ) {
			const c = mailCtx();
			await worker.fetch( post( new URLSearchParams( { msg: 'x' + i } ) ), { DB: fakeDb(), ...HM, MAIL_FROM: 'cache@example.com' }, c );
			await Promise.all( c.pending );
		}

		assert.equal( api.calls.filter( ( x ) => x.url.endsWith( '/me' ) ).length, 1 );
		assert.equal( api.calls.filter( ( x ) => x.url.endsWith( '/AC9/send' ) ).length, 2 );
	} finally {
		api.restore();
	}
} );

test( 'hostinger api: a rejected token or a foreign mailbox is only logged, the submission is kept, the token never leaks', async () => {
	const api = withHostinger( { sendStatus: 403 } );
	const logs = [];
	const realLog = console.log;
	console.log = ( ...a ) => logs.push( a.join( ' ' ) );
	try {
		const c = mailCtx();
		const DB = fakeDb();
		const res = await worker.fetch( post( new URLSearchParams( { msg: 'hola' } ) ), { DB, ...HM, MAIL_FROM: 'info@example.com' }, c );
		await Promise.all( c.pending );

		assert.deepEqual( await res.json(), { ok: true } );
		assert.equal( DB.rows.length, 1 );
		assert.ok( logs.some( ( l ) => /mail failed/.test( l ) && /403/.test( l ) ), logs.join( '|' ) );
		assert.ok( ! logs.join( '' ).includes( 'tok-secret' ) );

		// Another sender address that the token does not own.
		const other = mailCtx();
		await worker.fetch( post( new URLSearchParams( { msg: 'hola' } ) ), { DB: fakeDb(), ...HM, MAIL_FROM: 'otra@example.com' }, other );
		await Promise.all( other.pending );
		assert.ok( logs.some( ( l ) => /is not in this token/.test( l ) ), logs.join( '|' ) );
		assert.equal( api.calls.filter( ( x ) => x.url.endsWith( '/send' ) ).length, 1, 'nothing is sent for a mailbox the token does not own' );
	} finally {
		console.log = realLog;
		api.restore();
	}
} );

test( 'hostinger api: wins over SMTP and Email Routing; without recipient nothing is sent', async () => {
	const api = withHostinger();
	try {
		const smtp = fakeSmtp();
		const sent = [];
		const c = mailCtx();
		await worker.fetch( post( new URLSearchParams( { msg: 'x' } ) ), { DB: fakeDb(), ...HM, ...SMTP, EMAIL: { send: async ( m ) => sent.push( m ) }, SMTP_CONNECT: smtp.connect }, c );
		const none = mailCtx();
		await worker.fetch( post( new URLSearchParams( { msg: 'x' } ) ), { DB: fakeDb(), ...HM, MAIL_TO: undefined }, none );
		await Promise.all( [ ...c.pending, ...none.pending ] );

		assert.equal( smtp.calls.length, 0 );
		assert.equal( sent.length, 0 );
		assert.equal( api.calls.filter( ( x ) => x.url.endsWith( '/send' ) ).length, 1 );
	} finally {
		api.restore();
	}
} );
