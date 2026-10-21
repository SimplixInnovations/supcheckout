/**
 * Policy behind `npm run audit:lock-age`: which locked packages a change adds,
 * the 14-day release-age floor, and the reviewed, expiring exceptions file,
 * tested with a fake registry and fake lockfiles (no network, no git).
 */
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	baseRevision,
	changedPackages,
	evaluateLockAge,
	lockedKeys,
	validateExceptions,
} from '../../../tools/admin-ui/audit/lock-age-policy.mjs';

const ROOT = join(
	dirname( fileURLToPath( import.meta.url ) ),
	'..',
	'..',
	'..'
);

const NOW = Date.parse( '2026-10-21T12:00:00Z' );
const DAY_MS = 24 * 60 * 60 * 1000;
const ago = ( days ) => new Date( NOW - days * DAY_MS ).toISOString();

function lock( packages ) {
	return {
		name: 'supcheckout',
		lockfileVersion: 3,
		packages: { '': { name: 'supcheckout' }, ...packages },
	};
}

const BASE = lock( {
	'node_modules/markdown-it': { version: '14.3.2' },
	'node_modules/braces': { version: '3.0.3' },
	'node_modules/react-19': { name: 'react', version: '19.3.0' },
} );

const EXCEPTION = Object.freeze( {
	name: 'markdown-it',
	version: '14.3.3',
	reason: 'Owner-approved security fix younger than the floor.',
	expires: '2026-10-30',
} );

function evaluate( changes ) {
	return evaluateLockAge( {
		changed: [ { name: 'markdown-it', version: '14.3.3' } ],
		publishTimes: { 'markdown-it@14.3.3': ago( 5 ) },
		exceptions: [],
		locked: [ 'markdown-it@14.3.3' ],
		now: NOW,
		...changes,
	} );
}

describe( 'changedPackages', () => {
	test( 'reports only name@version pairs the head lockfile adds', () => {
		const head = lock( {
			'node_modules/markdown-it': { version: '14.3.3' },
			'node_modules/braces': { version: '3.0.3' },
			'node_modules/jest/node_modules/braces': { version: '3.0.3' },
			'node_modules/react-19': { name: 'react', version: '19.3.0' },
			'node_modules/linkify-it': { version: '5.0.1' },
		} );
		expect( changedPackages( BASE, head ) ).toEqual( [
			{ name: 'linkify-it', version: '5.0.1' },
			{ name: 'markdown-it', version: '14.3.3' },
		] );
	} );

	test( 'an npm alias is keyed by its real package name', () => {
		const head = lock( {
			...BASE.packages,
			'node_modules/react-19': { name: 'react', version: '19.4.0' },
		} );
		expect( changedPackages( BASE, head ) ).toEqual( [
			{ name: 'react', version: '19.4.0' },
		] );
		expect( lockedKeys( head ) ).toContain( 'react@19.4.0' );
		expect( lockedKeys( head ) ).not.toContain( 'react-19@19.4.0' );
	} );

	test( 'an unchanged or shrinking lockfile adds nothing', () => {
		expect( changedPackages( BASE, BASE ) ).toEqual( [] );
		expect( changedPackages( BASE, lock( {} ) ) ).toEqual( [] );
	} );

	test( 'a base without a lockfile treats every head package as added', () => {
		expect( changedPackages( null, BASE ) ).toHaveLength( 3 );
	} );

	test( 'linked entries are not registry packages', () => {
		const head = lock( {
			'node_modules/local': { link: true, resolved: 'packages/local' },
		} );
		expect( lockedKeys( head ) ).toEqual( [] );
	} );
} );

describe( 'baseRevision', () => {
	test( 'a branch compares against its merge-base with origin/main', () => {
		expect( baseRevision( { head: 'aaa', mergeBase: 'bbb' } ) ).toBe(
			'bbb'
		);
	} );

	test( 'a commit already on main (a push to main) compares against HEAD^', () => {
		expect( baseRevision( { head: 'aaa', mergeBase: 'aaa' } ) ).toBe(
			'HEAD^'
		);
	} );
} );

describe( 'evaluateLockAge', () => {
	test( 'RED: a package published 5 days ago fails the 14-day floor', () => {
		expect( evaluate() ).toEqual( [
			'markdown-it@14.3.3 was published 2026-10-16 (5 days ago), younger than the 14-day release-age floor',
		] );
	} );

	test( 'a package exactly 14 days old passes', () => {
		expect(
			evaluate( { publishTimes: { 'markdown-it@14.3.3': ago( 14 ) } } )
		).toEqual( [] );
	} );

	test( 'a package 13.9 days old still fails', () => {
		expect(
			evaluate( {
				publishTimes: { 'markdown-it@14.3.3': ago( 13.9 ) },
			} )
		).toHaveLength( 1 );
	} );

	test( 'a package with no registry publish time fails closed', () => {
		expect( evaluate( { publishTimes: {} } ) ).toEqual( [
			'markdown-it@14.3.3 has no registry publish time',
		] );
		expect(
			evaluate( { publishTimes: { 'markdown-it@14.3.3': 'not a date' } } )
		).toEqual( [ 'markdown-it@14.3.3 has no registry publish time' ] );
	} );

	test( 'a valid exception lets a young package through', () => {
		expect( evaluate( { exceptions: [ EXCEPTION ] } ) ).toEqual( [] );
	} );

	test( 'an exception for another version does not cover the package', () => {
		expect(
			evaluate( { exceptions: [ { ...EXCEPTION, version: '14.3.2' } ] } )
		).toEqual(
			expect.arrayContaining( [
				expect.stringContaining( 'younger than the 14-day' ),
				'stale lock-age exception markdown-it@14.3.2: not in package-lock.json, remove it',
			] )
		);
	} );

	test( 'an exception for a package no longer locked is stale', () => {
		expect(
			evaluate( {
				changed: [],
				locked: [ 'markdown-it@14.3.4' ],
				exceptions: [ EXCEPTION ],
			} )
		).toEqual( [
			'stale lock-age exception markdown-it@14.3.3: not in package-lock.json, remove it',
		] );
	} );

	test( 'an invalid exceptions file fails before any package is judged', () => {
		expect(
			evaluate( {
				exceptions: [ { ...EXCEPTION, expires: '2026-10-20' } ],
			} )
		).toEqual( [
			'lock-age exception "markdown-it@14.3.3": expires 2026-10-20 is in the past',
		] );
	} );
} );

describe( 'validateExceptions', () => {
	const TODAY = '2026-10-21';

	test( 'the file must be an array', () => {
		expect( validateExceptions( {}, TODAY ) ).toEqual( [
			'lock-age exceptions must be a JSON array',
		] );
	} );

	test( 'every field is required and checked', () => {
		expect(
			validateExceptions(
				[
					{
						name: '',
						version: '^1.0.0',
						reason: ' ',
						expires: '2026-02-30',
					},
				],
				TODAY
			)
		).toEqual( [
			'lock-age exception "@^1.0.0": name must be a non-empty string',
			'lock-age exception "@^1.0.0": version must be an exact version',
			'lock-age exception "@^1.0.0": reason must be a non-empty string',
			'lock-age exception "@^1.0.0": expires must be a real YYYY-MM-DD date, got "2026-02-30"',
		] );
	} );

	test( 'an expiry more than 90 days away is rejected', () => {
		expect(
			validateExceptions(
				[ { ...EXCEPTION, expires: '2027-01-20' } ],
				TODAY
			)
		).toEqual( [
			'lock-age exception "markdown-it@14.3.3": expires 2027-01-20 is more than 90 days after 2026-10-21',
		] );
	} );

	test( 'unknown keys and duplicates are rejected', () => {
		expect(
			validateExceptions(
				[ EXCEPTION, { ...EXCEPTION, extra: 1 } ],
				TODAY
			)
		).toEqual( [
			'lock-age exception "markdown-it@14.3.3": unexpected key extra',
			'duplicate lock-age exception markdown-it@14.3.3',
		] );
	} );

	test( 'the committed exceptions file is a valid list', () => {
		// markdown-it 14.3.3 was adopted through #208 before this check
		// existed, so main needs no exception for it; the file starts empty.
		const committed = JSON.parse(
			readFileSync(
				join( ROOT, 'tools/admin-ui/audit/lock-age-exceptions.json' ),
				'utf8'
			)
		);
		expect( Array.isArray( committed ) ).toBe( true );
		expect( validateExceptions( committed, TODAY ) ).toEqual( [] );
	} );
} );

describe( 'wiring', () => {
	test( 'audit:lock-age runs the gate script', () => {
		const pkg = JSON.parse(
			readFileSync( join( ROOT, 'package.json' ), 'utf8' )
		);
		expect( pkg.scripts[ 'audit:lock-age' ] ).toBe(
			'node tools/admin-ui/audit/lock-age.mjs'
		);
	} );
} );
