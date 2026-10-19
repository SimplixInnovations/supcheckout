/**
 * CSS discipline: scoped selectors, `--sup-*` custom properties only, no raw
 * colors or lengths outside the token file, logical properties only
 * (spec section 4.1, SP1 Task 2).
 */
import { findCssViolations } from '../../../tools/admin-ui/tokens/css-discipline.mjs';
import {
	loadTokens,
	renderCss,
} from '../../../tools/admin-ui/tokens/resolve.mjs';
import { fixtureCss, listFiles, read } from './support.mjs';

const generatedCss = renderCss( loadTokens() );

test( 'only --sup-* properties are defined and every selector is scoped', () => {
	expect( findCssViolations( generatedCss, 'tokens' ) ).toEqual( [] );
	expect(
		findCssViolations(
			read( 'assets/admin/src/tokens/tokens.generated.css' ),
			'assets/admin/src/tokens/tokens.generated.css'
		)
	).toEqual( [] );
} );

test( 'the CSS checker flags raw colors and physical properties', () => {
	expect( findCssViolations( fixtureCss( 'raw-color' ), 'x' ) ).toEqual( [
		'x: raw color #ff0000 in color',
	] );
	expect( findCssViolations( fixtureCss( 'physical' ), 'x' ) ).toEqual( [
		'x: physical property margin-left',
	] );
} );

test( 'the CSS checker flags every raw color notation outside the token file', () => {
	const css = [
		'.supcheckout-admin .sup-a { color: rgb(0 0 0); }',
		'.supcheckout-admin .sup-b { background: hsl(0 0% 0%); }',
		'.supcheckout-admin .sup-c { border-color: oklch(0.5 0.1 200); }',
		'.supcheckout-admin .sup-d { outline-color: rgba(0, 0, 0, 0.5); }',
		'.supcheckout-admin .sup-e { box-shadow: 0 0 0 1px #abc; }',
	].join( '\n' );
	expect( findCssViolations( css, 'x' ) ).toEqual( [
		'x: raw color rgb(0 0 0) in color',
		'x: raw color hsl(0 0% 0%) in background',
		'x: raw color oklch(0.5 0.1 200) in border-color',
		'x: raw color rgba(0, 0, 0, 0.5) in outline-color',
		'x: raw color #abc in box-shadow',
	] );
} );

test( 'the CSS checker allows only 0, 1px and 100% as raw lengths outside media queries', () => {
	const css = [
		'.supcheckout-admin .sup-a { margin-block: 0; border-block-end: 1px solid var(--sup-color-border-default); inline-size: 100%; }',
		'.supcheckout-admin .sup-b { padding-inline: 13px; }',
		'.supcheckout-admin .sup-c { gap: 0.5rem; }',
		'.supcheckout-admin .sup-d { inline-size: 50%; }',
		'.supcheckout-admin .sup-e { flex: 1; opacity: 0.5; transition-duration: var(--sup-motion-duration-fast); }',
		'@media (min-width: 782px) { .supcheckout-admin .sup-f { gap: var(--sup-space-2); } }',
		'.supcheckout-admin .sup-g { padding-block: calc(var(--sup-space-2) - 1px); }',
	].join( '\n' );
	expect( findCssViolations( css, 'x' ) ).toEqual( [
		'x: raw length 13px in padding-inline',
		'x: raw length 0.5rem in gap',
		'x: raw length 50% in inline-size',
	] );
} );

test( 'the CSS checker flags every physical property and value', () => {
	const css = [
		'.supcheckout-admin .sup-a { padding-right: 0; border-left: 0; border-right-color: var(--sup-color-border-default); }',
		'.supcheckout-admin .sup-b { left: 0; right: 0; inset-left: 0; }',
		'.supcheckout-admin .sup-c { float: left; text-align: right; clear: left; }',
		'.supcheckout-admin .sup-d { float: inline-start; text-align: start; }',
	].join( '\n' );
	expect( findCssViolations( css, 'x' ) ).toEqual( [
		'x: physical property padding-right',
		'x: physical property border-left',
		'x: physical property border-right-color',
		'x: physical property left',
		'x: physical property right',
		'x: physical property inset-left',
		'x: physical value float: left',
		'x: physical value text-align: right',
		'x: physical value clear: left',
	] );
} );

test( 'the CSS checker flags unscoped selectors and foreign custom properties', () => {
	const css = [
		'.sup-button { color: var(--sup-color-fg-default); }',
		'.supcheckout-admin .sup-a, .wrap .sup-b { color: var(--sup-color-fg-default); }',
		'body.supcheckout-admin-page #wpbody-content { padding-block: 0; }',
		'.supcheckout-adminx .sup-c { color: var(--sup-color-fg-default); }',
		'.supcheckout-admin .sup-d { --wp-admin-theme-color: var(--sup-color-accent-bg); --sup-ok: 0; }',
		'@keyframes sup-spin { from { rotate: 0deg; } to { rotate: 360deg; } }',
	].join( '\n' );
	expect( findCssViolations( css, 'x' ) ).toEqual( [
		'x: unscoped selector .sup-button',
		'x: unscoped selector .wrap .sup-b',
		'x: unscoped selector .supcheckout-adminx .sup-c',
		'x: custom property --wp-admin-theme-color is not --sup-*',
	] );
} );

test( 'the token file may hold raw values but is still scoped and logical', () => {
	const css = [
		'.supcheckout-admin { --sup-palette-x: #123456; --sup-space-x: 13px; }',
		'.wrap { --sup-y: 0; }',
		'.supcheckout-admin { margin-left: 0; }',
	].join( '\n' );
	expect(
		findCssViolations( css, 'assets/admin/src/tokens/tokens.generated.css' )
	).toEqual( [
		'assets/admin/src/tokens/tokens.generated.css: unscoped selector .wrap',
		'assets/admin/src/tokens/tokens.generated.css: physical property margin-left',
	] );
} );

test( 'component CSS has no violations', () => {
	for ( const f of listFiles( 'assets/admin/src/ui', '.css' ) ) {
		expect( findCssViolations( read( f ), f ) ).toEqual( [] );
	}
} );

test( 'the app CSS has no violations', () => {
	const files = listFiles( 'assets/admin/src/app', '.css' );
	expect( files ).toContain( 'assets/admin/src/app/app.css' );
	for ( const f of files ) {
		expect( findCssViolations( read( f ), f ) ).toEqual( [] );
	}
} );

const fg = '{ color: var(--sup-color-fg-default); }';

test( 'a scope followed by a sibling combinator or only inside a pseudo-class argument is unscoped', () => {
	const selectors = [
		'.supcheckout-admin ~ div',
		'.supcheckout-admin + *',
		'body:has( .supcheckout-admin) #wpadminbar',
		':is(.supcheckout-admin) a',
		':where(.supcheckout-admin) a',
		'div:not(.supcheckout-admin) a',
		'body.supcheckout-admin-page + div',
	];
	const css = selectors.map( ( s ) => `${ s } ${ fg }` ).join( '\n' );
	expect( findCssViolations( css, 'x' ) ).toEqual(
		selectors.map( ( s ) => `x: unscoped selector ${ s }` )
	);
} );

test( 'descendant, child and argument selectors inside the scope stay allowed', () => {
	const css = [
		'.supcheckout-admin > .sup-x',
		'.supcheckout-admin .sup-x:has(> svg)',
		'.supcheckout-admin .sup-a ~ .sup-b',
		'.supcheckout-admin:not([data-sup-theme="light"]) .sup-x',
		'body.admin-color-blue .supcheckout-admin[data-sup-x="a + b"]',
		'.supcheckout-admin:lang(ar) .sup-x',
		'.supcheckout-admin',
	]
		.map( ( s ) => `${ s } ${ fg }` )
		.join( '\n' );
	expect( findCssViolations( css, 'x' ) ).toEqual( [] );
} );

test( 'declarations inside keyframes are still checked and keyframe names need sup-', () => {
	const css = [
		'@keyframes sup-pulse { from { color: #fff; } 50% { margin-left: 0; } to { opacity: 1; } }',
		'@keyframes spin { to { rotate: 360deg; } }',
	].join( '\n' );
	expect( findCssViolations( css, 'x' ) ).toEqual( [
		'x: raw color #fff in color',
		'x: physical property margin-left',
		'x: keyframes name spin is not sup-*',
	] );
} );

test( 'only the exact token file is exempt from raw values', () => {
	const css = '.supcheckout-admin { --sup-x: #123456; }';
	for ( const file of [
		'tokens',
		'assets/admin/src/tokens/tokens.generated.css',
		'assets\\admin\\src\\tokens\\tokens.generated.css',
	] ) {
		expect( findCssViolations( css, file ) ).toEqual( [] );
	}
	for ( const file of [
		'assets/admin/src/ui/x/tokens.generated.css',
		'assets/admin/src/ui/tokens',
		'x/tokens',
	] ) {
		expect( findCssViolations( css, file ) ).toEqual( [
			`${ file }: raw color #123456 in --sup-x`,
		] );
	}
} );

test( 'the custom-property prefix check is case-sensitive', () => {
	expect(
		findCssViolations( '.supcheckout-admin { --Sup-x: 0; }', 'x' )
	).toEqual( [ 'x: custom property --Sup-x is not --sup-*' ] );
} );

test( 'physical scroll margins and paddings are flagged', () => {
	const css =
		'.supcheckout-admin .sup-a { scroll-margin-left: 0; scroll-margin-right: 0; scroll-padding-left: 0; scroll-padding-right: 0; scroll-margin-inline-start: 0; }';
	expect( findCssViolations( css, 'x' ) ).toEqual( [
		'x: physical property scroll-margin-left',
		'x: physical property scroll-margin-right',
		'x: physical property scroll-padding-left',
		'x: physical property scroll-padding-right',
	] );
} );

test( 'only @media, @supports, @keyframes and @container at-rules are allowed', () => {
	const css = [
		'@import url(x.css);',
		'@font-face { font-family: x; }',
		`@layer base { .supcheckout-admin ${ fg } }`,
		'@page { color: inherit; }',
		`@media (min-width: 782px) { .supcheckout-admin ${ fg } }`,
		'@supports (display: grid) { .supcheckout-admin { display: grid; } }',
		'@container (min-width: 1px) { .supcheckout-admin { display: grid; } }',
	].join( '\n' );
	expect( findCssViolations( css, 'x' ) ).toEqual( [
		'x: at-rule @import is not allowed',
		'x: at-rule @font-face is not allowed',
		'x: at-rule @layer is not allowed',
		'x: at-rule @page is not allowed',
	] );
} );
