/**
 * CSS discipline checker for the SUPCheckout admin stylesheets.
 *
 * Every selector is scoped under `.supcheckout-admin` or
 * `body.supcheckout-admin-page`, custom properties are `--sup-*` only, and
 * physical (left/right) properties and values are forbidden in favor of
 * logical ones. Outside the token file, raw colors are forbidden and the only
 * raw lengths are `0`, `1px` (hairlines) and `100%`; `@media` conditions may
 * use any length.
 */
import postcss from 'postcss';

const SCOPED = [
	/(?:^|[\s>+~])\.supcheckout-admin(?![\w-])/,
	/(?:^|[\s>+~])body\.supcheckout-admin-page(?![\w-])/,
];
const PHYSICAL_PROPERTIES = [
	/^(?:margin|padding|inset)-(?:left|right)$/,
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
 * @return {boolean} True for `tokens` and `…/tokens.generated.css`.
 */
function isTokenFile( file ) {
	return /(?:^|[\\/])tokens(?:\.generated\.css)?$/.test( file );
}

function insideKeyframes( node ) {
	for ( let parent = node.parent; parent; parent = parent.parent ) {
		if ( parent.type === 'atrule' && /keyframes$/i.test( parent.name ) ) {
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
	if ( prop.startsWith( '--' ) && ! prop.startsWith( '--sup-' ) ) {
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
		found.push( `raw color ${ color } in ${ prop }` );
	}
	for ( const length of rawLengths( value ) ) {
		found.push( `raw length ${ length } in ${ prop }` );
	}
	return found;
}

/**
 * Lists CSS discipline violations.
 *
 * @param {string} css  Stylesheet source.
 * @param {string} file Label used as the message prefix; `tokens` or a path
 *                      ending in `tokens.generated.css` marks the token file.
 * @return {string[]} Messages such as `x: physical property margin-left`.
 */
export function findCssViolations( css, file ) {
	const tokenFile = isTokenFile( file );
	const violations = [];
	postcss.parse( css, { from: undefined } ).walk( ( node ) => {
		if ( insideKeyframes( node ) ) {
			return;
		}
		if ( node.type === 'rule' ) {
			for ( const selector of node.selectors ) {
				if (
					! SCOPED.some( ( pattern ) => pattern.test( selector ) )
				) {
					violations.push(
						`${ file }: unscoped selector ${ selector }`
					);
				}
			}
		} else if ( node.type === 'decl' ) {
			for ( const message of declarationViolations( node, tokenFile ) ) {
				violations.push( `${ file }: ${ message }` );
			}
		}
	} );
	return violations;
}
