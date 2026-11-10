// Simply Static -> Cloudflare Worker entry.
// Static assets answer first; only /_ss/* reaches this code (assets.run_worker_first).
//
// Bindings (all optional; the site works without them):
//   ASSETS            static assets
//   DB                D1 database with table ss_submissions (form storage)
//   TURNSTILE_SECRET  secret for Cloudflare Turnstile verification
//   WEBHOOK_URL       POSTed a JSON copy of every submission

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

	return reply( request, true );
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
