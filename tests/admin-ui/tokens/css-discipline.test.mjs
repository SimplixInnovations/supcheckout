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
