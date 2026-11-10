// Simply Static -> client-side search. Loads /ss-search/index.json once and filters in the browser.
// Index rows: [ title, path, text, type ].
( function () {
	var root = document.getElementById( 'ss-search' );
	if ( ! root ) {
		return;
	}

	var input = root.querySelector( 'input[name=s]' );
	var out = root.querySelector( '.ss-search-results' );
	var strings = root.dataset;

	function norm( text ) {
		return String( text ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
	}

	function snippet( text, terms ) {
		var flat = norm( text );
		var at = -1;
		for ( var i = 0; i < terms.length && at < 0; i++ ) {
			at = flat.indexOf( terms[ i ] );
		}
		var start = Math.max( 0, at - 60 );
		return ( start > 0 ? '… ' : '' ) + text.slice( start, start + 200 ) + ( text.length > start + 200 ? ' …' : '' );
	}

	// Wraps matches in <mark>, building nodes so the text is never parsed as HTML.
	function highlight( node, text, terms ) {
		var flat = norm( text );
		var marks = [];
		terms.forEach( function ( term ) {
			for ( var i = flat.indexOf( term ); i >= 0; i = flat.indexOf( term, i + term.length ) ) {
				marks.push( [ i, i + term.length ] );
			}
		} );
		marks.sort( function ( a, b ) {
			return a[ 0 ] - b[ 0 ];
		} );

		var pos = 0;
		marks.forEach( function ( m ) {
			if ( m[ 0 ] < pos ) {
				return;
			}
			node.appendChild( document.createTextNode( text.slice( pos, m[ 0 ] ) ) );
			var mark = document.createElement( 'mark' );
			mark.textContent = text.slice( m[ 0 ], m[ 1 ] );
			node.appendChild( mark );
			pos = m[ 1 ];
		} );
		node.appendChild( document.createTextNode( text.slice( pos ) ) );
	}

	function search( rows, terms ) {
		var hits = [];
		rows.forEach( function ( row ) {
			var title = norm( row[ 0 ] );
			var body = norm( row[ 2 ] );
			var score = 0;
			for ( var i = 0; i < terms.length; i++ ) {
				var inTitle = title.indexOf( terms[ i ] ) >= 0;
				var inBody = body.indexOf( terms[ i ] ) >= 0;
				if ( ! inTitle && ! inBody ) {
					return; // every word must match
				}
				score += ( inTitle ? 10 : 0 ) + ( inBody ? 2 : 0 );
			}
			hits.push( { row: row, score: score } );
		} );

		return hits.sort( function ( a, b ) {
			return b.score - a.score;
		} ).slice( 0, 50 );
	}

	function render( hits, terms ) {
		out.textContent = '';
		if ( ! hits.length ) {
			var none = document.createElement( 'p' );
			none.textContent = strings.none;
			out.appendChild( none );
			return;
		}
		var list = document.createElement( 'ol' );
		list.className = 'ss-search-list';
		hits.forEach( function ( hit ) {
			var item = document.createElement( 'li' );
			var link = document.createElement( 'a' );
			link.href = hit.row[ 1 ];
			highlight( link, hit.row[ 0 ], terms );
			var excerpt = document.createElement( 'p' );
			highlight( excerpt, snippet( hit.row[ 2 ], terms ), terms );
			item.appendChild( link );
			item.appendChild( excerpt );
			list.appendChild( item );
		} );
		out.appendChild( list );
	}

	var params = new URLSearchParams( location.search );
	var query = ( params.get( 's' ) || params.get( 'q' ) || '' ).trim();
	input.value = query;
	if ( ! query ) {
		return;
	}

	var terms = norm( query ).split( /\s+/ ).filter( Boolean );
	out.textContent = strings.loading;
	fetch( strings.index )
		.then( function ( response ) {
			return response.json();
		} )
		.then( function ( rows ) {
			render( search( rows, terms ), terms );
		} )
		.catch( function () {
			out.textContent = strings.error;
		} );
}() );
