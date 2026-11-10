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
