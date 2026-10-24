/**
 * Policy behind `npm run audit:full`: allow-list validation and the
 * full-tree high/critical decision, tested without touching the registry.
 */
import { readFileSync } from 'node:fs';

import {
	advisoriesOf,
	evaluateAudit,
	validateAllowlist,
} from '../../../tools/admin-ui/audit/audit-policy.mjs';

const TODAY = '2026-10-19';

const ENTRY = Object.freeze( {
	id: 'GHSA-vfj7-8cjw-p6xm',
	package: 'braces',
	severity: 'high',
	expires: '2027-01-17',
	reason: 'Dev-only, no patched release.',
} );

const BRACES = Object.freeze( {
	id: 'GHSA-vfj7-8cjw-p6xm',
	package: 'braces',
	severity: 'high',
	title: 'braces stack exhaustion',
} );

function withEntry( changes ) {
	return [ { ...ENTRY, ...changes } ];
}

function evaluate( overrides = {} ) {
	return evaluateAudit( {
		full: [ BRACES ],
		production: [],
		allowlist: [ ENTRY ],
		bundledNames: [],
		today: TODAY,
		...overrides,
	} );
}

describe( 'validateAllowlist', () => {
	test( 'accepts a well-formed entry exactly 90 days out', () => {
		expect( validateAllowlist( [ ENTRY ], TODAY ) ).toEqual( [] );
	} );

	test( 'accepts an empty allow-list', () => {
		expect( validateAllowlist( [], TODAY ) ).toEqual( [] );
		expect( evaluate( { full: [], allowlist: [] } ) ).toEqual( [] );
	} );

	test( 'accepts the committed allow-list file', () => {
		const committed = JSON.parse(
			readFileSync(
				new URL(
					'../../../tools/admin-ui/audit/audit-allowlist.json',
					import.meta.url
				),
				'utf8'
			)
		);
		expect(
			validateAllowlist(
				committed,
				new Date().toISOString().slice( 0, 10 )
			)
		).toEqual( [] );
	} );

	test( 'rejects a non-array allow-list', () => {
		expect( validateAllowlist( { ENTRY }, TODAY ) ).not.toEqual( [] );
	} );

	test.each( [
		[ 'a CVE id', 'CVE-2026-12345' ],
		[ 'a GHSA id with a disallowed character', 'GHSA-vfj7-8cjw-p6x1' ],
		[ 'a short GHSA id', 'GHSA-vfj7-8cjw' ],
		[ 'a missing id', undefined ],
	] )( 'rejects %s', ( _label, id ) => {
		expect( validateAllowlist( withEntry( { id } ), TODAY ) ).toEqual( [
			expect.stringMatching( /id/ ),
		] );
	} );

	test.each( [
		[ 'an empty package', { package: '' } ],
		[ 'a missing package', { package: undefined } ],
		[ 'an empty reason', { reason: '  ' } ],
		[ 'a missing reason', { reason: undefined } ],
		[ 'a missing severity', { severity: undefined } ],
		[ 'an unknown severity', { severity: 'severe' } ],
	] )( 'rejects %s', ( _label, changes ) => {
		expect( validateAllowlist( withEntry( changes ), TODAY ) ).toHaveLength(
			1
		);
	} );

	test.each( [
		[ 'a missing expiry', undefined ],
		[ 'a malformed date', '2027-1-17' ],
		[ 'an impossible calendar date', '2027-02-30' ],
		[ 'a past date', '2026-10-18' ],
		[ 'a date more than 90 days out', '2027-01-18' ],
	] )( 'rejects %s', ( _label, expires ) => {
		expect( validateAllowlist( withEntry( { expires } ), TODAY ) ).toEqual(
			[ expect.stringMatching( /expires/ ) ]
		);
	} );

	test( 'rejects a duplicate id and package pair', () => {
		expect( validateAllowlist( [ ENTRY, { ...ENTRY } ], TODAY ) ).toEqual( [
			'duplicate allow-list entry GHSA-vfj7-8cjw-p6xm braces',
		] );
	} );

	test( 'accepts an entry that expires today', () => {
		expect(
			validateAllowlist( withEntry( { expires: TODAY } ), TODAY )
		).toEqual( [] );
	} );
} );

describe( 'evaluateAudit', () => {
	test( 'passes the allow-listed, still-reported, dev-only advisory', () => {
		expect( evaluate() ).toEqual( [] );
	} );

	test( 'fails a high advisory that is not allow-listed', () => {
		expect( evaluate( { allowlist: [] } ) ).toEqual( [
			expect.stringMatching( /GHSA-vfj7-8cjw-p6xm in braces/ ),
		] );
	} );

	test.each( [ 'moderate', 'low' ] )(
		'fails a %s advisory that is not allow-listed',
		( severity ) => {
			const advisory = {
				...BRACES,
				id: 'GHSA-2222-3333-4444',
				package: 'smol-toml',
				severity,
			};
			expect( evaluate( { full: [ BRACES, advisory ] } ) ).toEqual( [
				expect.stringMatching(
					new RegExp(
						`^${ severity } GHSA-2222-3333-4444 in smol-toml`
					)
				),
			] );
		}
	);

	test( 'ignores informational advisories', () => {
		const info = {
			...BRACES,
			id: 'GHSA-2222-3333-4444',
			severity: 'info',
		};
		expect( evaluate( { full: [ BRACES, info ] } ) ).toEqual( [] );
	} );

	test( 'fails when the live severity is higher than the recorded one', () => {
		expect(
			evaluate( { full: [ { ...BRACES, severity: 'critical' } ] } )
		).toEqual( [ expect.stringMatching( /critical.*recorded high/ ) ] );
	} );

	test( 'fails a stale entry that is no longer reported', () => {
		expect( evaluate( { full: [] } ) ).toEqual( [
			expect.stringMatching( /stale/ ),
		] );
	} );

	test( 'fails when the advisory reaches the production tree', () => {
		expect( evaluate( { production: [ BRACES ] } ) ).toEqual( [
			expect.stringMatching( /production/ ),
		] );
	} );

	test( 'fails when an allow-listed package is bundled', () => {
		expect( evaluate( { bundledNames: [ 'braces' ] } ) ).toEqual( [
			expect.stringMatching( /bundled/ ),
		] );
	} );

	test( 'fails an invalid allow-list entry even when it matches', () => {
		expect(
			evaluate( { allowlist: withEntry( { expires: '2027-04-01' } ) } )
		).toEqual( [ expect.stringMatching( /expires/ ) ] );
	} );
} );

describe( 'advisoriesOf', () => {
	test( 'flattens npm audit JSON into unique advisories', () => {
		const report = {
			vulnerabilities: {
				braces: {
					via: [
						{
							name: 'braces',
							url: 'https://github.com/advisories/GHSA-vfj7-8cjw-p6xm',
							severity: 'high',
							title: 'braces stack exhaustion',
						},
					],
				},
				micromatch: { via: [ 'braces' ] },
			},
		};
		expect( advisoriesOf( report ) ).toEqual( [ BRACES ] );
	} );
} );
