/**
 * The nested Arabic-run rule in foundations.css (SP1 Task 8) re-applies the
 * scope's family, line height and letter spacing on a `lang="ar|fa|ur"` run.
 * It must never outrank a component rule (`.supcheckout-admin .sup-x` is
 * (0,2,0)), so its specificity is that of the scope class alone: (0,1,0).
 */
import postcss from 'postcss';

import { read } from './support.mjs';

/**
 * Splits a selector list on top-level commas.
 *
 * @param {string} list Selector list.
 * @return {string[]} Selectors.
 */
function splitList( list ) {
	const parts = [];
	let depth = 0;
	let start = 0;
	for ( let i = 0; i < list.length; i++ ) {
		const char = list[ i ];
		if ( char === '(' || char === '[' ) {
			depth++;
		} else if ( char === ')' || char === ']' ) {
			depth--;
		} else if ( char === ',' && depth === 0 ) {
			parts.push( list.slice( start, i ).trim() );
			start = i + 1;
		}
	}
	parts.push( list.slice( start ).trim() );
	return parts;
}

function closing( selector, open ) {
	let depth = 0;
	for ( let i = open; i < selector.length; i++ ) {
		if ( selector[ i ] === '(' ) {
			depth++;
		} else if ( selector[ i ] === ')' && --depth === 0 ) {
			return i;
		}
	}
	throw new Error( `unbalanced selector: ${ selector }` );
}

const add = ( a, b ) => a.map( ( value, i ) => value + b[ i ] );
const max = ( list ) =>
	list.reduce(
		( best, next ) => {
			for ( let i = 0; i < 3; i++ ) {
				if ( next[ i ] !== best[ i ] ) {
					return next[ i ] > best[ i ] ? next : best;
				}
			}
			return best;
		},
		[ 0, 0, 0 ]
	);

/**
 * Selectors Level 4 specificity of one complex selector, for the grammar
 * this stylesheet uses: ids, classes, attributes, pseudo-classes (`:is`,
 * `:not` and `:has` take their most specific argument, `:where` counts
 * nothing), pseudo-elements and type selectors.
 *
 * @param {string} selector One selector.
 * @return {number[]} [ids, classes, types].
 */
function specificity( selector ) {
	let total = [ 0, 0, 0 ];
	let i = 0;
	while ( i < selector.length ) {
		const char = selector[ i ];
		if ( char === '#' ) {
			total = add( total, [ 1, 0, 0 ] );
			i = skipName( selector, i + 1 );
		} else if ( char === '.' ) {
			total = add( total, [ 0, 1, 0 ] );
			i = skipName( selector, i + 1 );
		} else if ( char === '[' ) {
			total = add( total, [ 0, 1, 0 ] );
			i = selector.indexOf( ']', i ) + 1;
		} else if ( selector.startsWith( '::', i ) ) {
			total = add( total, [ 0, 0, 1 ] );
			i = skipName( selector, i + 2 );
		} else if ( char === ':' ) {
			const end = skipName( selector, i + 1 );
			const name = selector.slice( i + 1, end );
			if ( selector[ end ] === '(' ) {
				const close = closing( selector, end );
				const inner = selector.slice( end + 1, close );
				if ( [ 'is', 'not', 'has' ].includes( name ) ) {
					total = add(
						total,
						max( splitList( inner ).map( specificity ) )
					);
				} else if ( name !== 'where' ) {
					total = add( total, [ 0, 1, 0 ] );
				}
				i = close + 1;
			} else {
				total = add( total, [ 0, 1, 0 ] );
				i = end;
			}
		} else if ( /[a-z*]/i.test( char ) ) {
			if ( char !== '*' ) {
				total = add( total, [ 0, 0, 1 ] );
			}
			i = skipName( selector, i + 1 );
		} else {
			i++;
		}
	}
	return total;
}

function skipName( selector, from ) {
	let i = from;
	while ( i < selector.length && /[\w-]/.test( selector[ i ] ) ) {
		i++;
	}
	return i;
}

function arabicRunRules() {
	const rules = [];
	postcss
		.parse( read( 'assets/admin/src/ui/foundations/foundations.css' ) )
		.walkRules( ( rule ) => {
			if ( /\[lang\]/.test( rule.selector ) ) {
				rules.push( rule );
			}
		} );
	return rules;
}

test( 'the specificity helper counts :where as nothing and :is as its argument', () => {
	expect( specificity( '.supcheckout-admin .sup-button' ) ).toEqual( [
		0, 2, 0,
	] );
	expect( specificity( '.a :where([lang]:lang(ar))' ) ).toEqual( [
		0, 1, 0,
	] );
	expect( specificity( '.a [lang]:is(:lang(ar), :lang(fa))' ) ).toEqual( [
		0, 3, 0,
	] );
	expect( specificity( '#x h1::before' ) ).toEqual( [ 1, 0, 2 ] );
} );

test( 'the nested Arabic-run rule has the scope class specificity (0,1,0) only', () => {
	const rules = arabicRunRules();
	expect( rules ).toHaveLength( 1 );
	const selectors = splitList( rules[ 0 ].selector );
	expect( selectors.length ).toBeGreaterThan( 0 );
	for ( const selector of selectors ) {
		expect( selector.startsWith( '.supcheckout-admin ' ) ).toBe( true );
		expect( specificity( selector ) ).toEqual( [ 0, 1, 0 ] );
	}
	for ( const lang of [ 'ar', 'fa', 'ur' ] ) {
		expect( rules[ 0 ].selector ).toContain( `:lang(${ lang })` );
	}
	const declared = rules[ 0 ].nodes
		.filter( ( node ) => node.type === 'decl' )
		.map( ( node ) => `${ node.prop }: ${ node.value }` );
	expect( declared ).toEqual( [
		'font-family: var(--sup-font-family-sans)',
		'line-height: var(--sup-line-height-body)',
		'letter-spacing: var(--sup-letter-spacing-normal)',
	] );
} );
