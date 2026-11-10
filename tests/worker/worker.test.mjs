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
	assert.deepEqual( sent[ 0 ].from, { email: 'noreply@example.com', name: 'Mi sitio' } );
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
