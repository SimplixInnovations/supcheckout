/**
 * CSS discipline checker for the SUPCheckout admin stylesheets.
 *
 * Every selector is scoped under `.supcheckout-admin` or
 * `body.supcheckout-admin-page`, custom properties are `--sup-*` only, and
 * physical (left/right) properties and values are forbidden in favor of
 * logical ones. Outside the token file, raw colors are forbidden and the only
 * raw lengths are `0`, `1px` (hairlines) and `100%`; `@media` conditions may
 * use any length. Named colors (including named-color arguments of
 * `color-mix()`) are left to stylelint's `color-named: never`, which
 * `npm run lint:css` enforces.
 *
 * A selector is scoped when a compound carrying `.supcheckout-admin` (or a
 * `body` compound carrying `.supcheckout-admin-page`) is the last compound or
 * is followed by a descendant or child combinator. A scope reached only
 * through a sibling combinator (`~`, `+`) or only inside a pseudo-class
 * argument (`:has()`, `:is()`, `:where()`, `:not()`) does not count.
 *
 * Only `@media`, `@supports`, `@container` and `@keyframes` are allowed, and
 * keyframe names start with `sup-`. Keyframe selectors (`from`, `to`, `50%`)
 * skip the scope check; their declarations are checked like any other.
 */
import postcss from 'postcss';

const TOKEN_FILE_LABEL = 'tokens';
const TOKEN_FILE_PATH = 'assets/admin/src/tokens/tokens.generated.css';
const ALLOWED_AT_RULES = new Set( [
	'media',
	'supports',
	'container',
	'keyframes',
] );
const SCOPE_CLASS = /\.supcheckout-admin(?![\w-])/;
const PAGE_SCOPE = /^body(?![\w-]).*\.supcheckout-admin-page(?![\w-])/;
const SCOPE_CONTINUES = new Set( [ '', '>' ] );
const PHYSICAL_PROPERTIES = [
	/^(?:margin|padding|inset)-(?:left|right)$/,
	/^scroll-(?:margin|padding)-(?:left|right)$/,
	/^border-(?:left|right)(?:-[a-z]+)?$/,
	/^border-(?:top|bottom)-(?:left|right)-radius$/,
	/^(?:left|right)$/,
];
const PHYSICAL_VALUE_PROPERTIES = new Set( [ 'float', 'clear', 'text-align' ] );
const HEX_COLOR = /(?<![\w#-])#[0-9a-fA-F]{3,8}(?![\w-])/g;
const COLOR_FUNCTION =
	/(?<![\w-])(?:rgba?|hsla?|hwb|lab|lch|oklab|oklch|color)\(/gi;
const LENGTH =
	/(?<![\w#.-])-?(?:\d+\.?\d*|\.\d+)(?:px|rem|em|%|vh|vw|vmin|vmax|vi|vb|svh|lvh|dvh|svw|lvw|dvw|ch|ex|cap|ic|lh|rlh|cqw|cqh|cqi|cqb|cqmin|cqmax|pt|pc|cm|mm|in|q)(?![\w-])/gi;
const ALLOWED_LENGTHS = new Set( [ '1px', '100%' ] );

/**
 * Whether a file label names the generated token stylesheet, which alone may
 * hold raw colors and lengths.
 *
 * @param {string} file File path or label.
 * @return {boolean} True only for the label `tokens` and the repository path
 *                   of `tokens.generated.css` (either slash style).
 */
function isTokenFile( file ) {
	return (
		file === TOKEN_FILE_LABEL ||
		file.split( '\\' ).join( '/' ) === TOKEN_FILE_PATH
	);
}

function keyframesParent( node ) {
	for ( let parent = node.parent; parent; parent = parent.parent ) {
		if ( parent.type === 'atrule' && /keyframes$/i.test( parent.name ) ) {
			return parent;
		}
	}
	return null;
}

/**
 * Empties every string, attribute selector and parenthesized argument, so
 * only top-level compounds and combinators remain.
 *
 * @param {string} selector One selector of a selector list.
 * @return {string} Masked selector, e.g. `body:has() #wpadminbar`.
 */
function maskSelector( selector ) {
	let out = '';
	let depth = 0;
	let quote = '';
	for ( const char of selector ) {
		if ( quote ) {
			quote = char === quote ? '' : quote;
			continue;
		}
		if ( char === '"' || char === "'" ) {
			quote = char;
		} else if ( char === '(' || char === '[' ) {
			if ( depth === 0 ) {
				out += char;
			}
			depth++;
		} else if ( char === ')' || char === ']' ) {
			depth--;
			if ( depth === 0 ) {
				out += char;
			}
		} else if ( depth === 0 ) {
			out += char;
		}
	}
	return out;
}

function isScoped( selector ) {
	// Alternating compounds and combinators: [c0, comb1, c1, comb2, c2, …].
	const parts = maskSelector( selector )
		.trim()
		.split( /(\s*[>+~]\s*|\s+)/ );
	for ( let i = 0; i < parts.length; i += 2 ) {
		const compound = parts[ i ];
		if ( ! SCOPE_CLASS.test( compound ) && ! PAGE_SCOPE.test( compound ) ) {
			continue;
		}
		const next = i + 1 < parts.length ? parts[ i + 1 ].trim() : '';
		if ( SCOPE_CONTINUES.has( next ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Extracts each color function call with its balanced arguments.
 *
 * @param {string} value Declaration value.
 * @return {string[]} Calls such as `rgb(0 0 0)`.
 */
function colorFunctions( value ) {
	const calls = [];
	for ( const match of value.matchAll( COLOR_FUNCTION ) ) {
		let depth = 0;
		for (
			let i = match.index + match[ 0 ].length - 1;
			i < value.length;
			i++
		) {
			if ( value[ i ] === '(' ) {
				depth++;
			} else if ( value[ i ] === ')' && --depth === 0 ) {
				calls.push( value.slice( match.index, i + 1 ) );
				break;
			}
		}
	}
	return calls;
}

function rawLengths( value ) {
	return [ ...value.matchAll( LENGTH ) ]
		.map( ( match ) => match[ 0 ] )
		.filter(
			( length ) =>
				parseFloat( length ) !== 0 && ! ALLOWED_LENGTHS.has( length )
		);
}

function declarationViolations( decl, tokenFile ) {
	const prop = decl.prop.toLowerCase();
	const value = decl.value;
	const found = [];
	// Case-sensitive on purpose: `--Sup-x` is a different custom property.
	if ( decl.prop.startsWith( '--' ) && ! decl.prop.startsWith( '--sup-' ) ) {
		found.push( `custom property ${ decl.prop } is not --sup-*` );
	}
	if ( PHYSICAL_PROPERTIES.some( ( pattern ) => pattern.test( prop ) ) ) {
		found.push( `physical property ${ prop }` );
	}
	if (
		PHYSICAL_VALUE_PROPERTIES.has( prop ) &&
		/^(?:left|right)$/i.test( value.trim() )
	) {
		found.push(
			`physical value ${ prop }: ${ value.trim().toLowerCase() }`
		);
	}
	if ( tokenFile ) {
		return found;
	}
	for ( const color of [
		...( value.match( HEX_COLOR ) ?? [] ),
		...colorFunctions( value ),
	] ) {
		found.push( `raw color ${ color } in ${ decl.prop }` );
	}
	for ( const length of rawLengths( value ) ) {
		found.push( `raw length ${ length } in ${ decl.prop }` );
	}
	return found;
}

function atRuleViolations( atRule ) {
	const name = atRule.name.toLowerCase();
	if ( ! ALLOWED_AT_RULES.has( name ) ) {
		return [ `at-rule @${ atRule.name } is not allowed` ];
	}
	if ( name === 'keyframes' && ! atRule.params.trim().startsWith( 'sup-' ) ) {
		return [ `keyframes name ${ atRule.params.trim() } is not sup-*` ];
	}
	return [];
}

function ruleViolations( rule ) {
	if ( keyframesParent( rule ) ) {
		return [];
	}
	return rule.selectors
		.filter( ( selector ) => ! isScoped( selector ) )
		.map( ( selector ) => `unscoped selector ${ selector }` );
}

/**
 * Lists CSS discipline violations.
 *
 * @param {string} css  Stylesheet source.
 * @param {string} file Label used as the message prefix; the label `tokens`
 *                      or the path assets/admin/src/tokens/tokens.generated.css
 *                      marks the token file.
 * @return {string[]} Messages such as `x: physical property margin-left`.
 */
export function findCssViolations( css, file ) {
	const tokenFile = isTokenFile( file );
	const violations = [];
	postcss.parse( css, { from: undefined } ).walk( ( node ) => {
		let messages = [];
		if ( node.type === 'atrule' ) {
			messages = atRuleViolations( node );
		} else if ( node.type === 'rule' ) {
			messages = ruleViolations( node );
		} else if ( node.type === 'decl' ) {
			messages = declarationViolations( node, tokenFile );
		}
		for ( const message of messages ) {
			violations.push( `${ file }: ${ message }` );
		}
	} );
	return violations;
}
