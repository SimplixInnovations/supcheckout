/**
 * Loading and progress: Skeleton, SkeletonGroup, ProgressBar and Stepper
 * (SP1 Task 6).
 */
import { act, render, screen, within } from '@testing-library/react';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';

import {
	LIVE_REGION_DELAY_MS,
	ProgressBar,
	Skeleton,
	SkeletonGroup,
	Stepper,
} from '../../../../assets/admin/src/ui';

const STEPS = [
	{ id: 'connect', label: 'Connect account' },
	{ id: 'keys', label: 'Add API keys' },
	{ id: 'test', label: 'Run a test payment' },
	{ id: 'live', label: 'Go live' },
];

const advance = ( ms: number ) =>
	act( () => {
		jest.advanceTimersByTime( ms );
	} );

afterEach( () => {
	jest.useRealTimers();
} );

test( 'a Skeleton is hidden from assistive technology in every shape', () => {
	const { container } = render(
		<>
			<Skeleton />
			<Skeleton shape="block" height="control" />
			<Skeleton shape="circle" height="title" />
			<Skeleton width="60%" height="4rem" />
		</>
	);
	const shapes = Array.from( container.querySelectorAll( '.sup-skeleton' ) );
	expect( shapes ).toHaveLength( 4 );
	for ( const shape of shapes ) {
		expect( shape ).toHaveAttribute( 'aria-hidden', 'true' );
	}
	expect( shapes[ 0 ] ).toHaveClass(
		'sup-skeleton--line',
		'sup-skeleton--height-text'
	);
	expect( shapes[ 1 ] ).toHaveClass(
		'sup-skeleton--block',
		'sup-skeleton--height-control'
	);
	expect( shapes[ 2 ] ).toHaveClass(
		'sup-skeleton--circle',
		'sup-skeleton--height-title'
	);
	// Free sizes go inline as logical properties.
	expect( shapes[ 3 ] ).toHaveStyle( {
		inlineSize: '60%',
		blockSize: '4rem',
	} );
} );

test( 'SkeletonGroup is busy and announces loading once', () => {
	jest.useFakeTimers();
	const { container } = render(
		<SkeletonGroup label="payments">
			<Skeleton height="title" width="40%" />
			<Skeleton />
			<Skeleton />
		</SkeletonGroup>
	);
	expect( container.querySelector( '[aria-busy="true"]' ) ).not.toBeNull();
	advance( LIVE_REGION_DELAY_MS );
	const statuses = screen.getAllByRole( 'status' );
	expect( statuses ).toHaveLength( 1 );
	expect( statuses[ 0 ] ).toHaveTextContent( 'Loading payments…' );
	expect( statuses[ 0 ] ).toHaveClass( 'sup-visually-hidden' );
	// The status sits outside the busy subtree, which AT may hold back.
	expect( statuses[ 0 ].closest( '[aria-busy="true"]' ) ).toBeNull();
	for ( const shape of container.querySelectorAll( '.sup-skeleton' ) ) {
		expect( shape ).toHaveAttribute( 'aria-hidden', 'true' );
	}
} );

test( 'SkeletonGroup holds its loading text for LIVE_REGION_DELAY_MS', () => {
	jest.useFakeTimers();
	render(
		<SkeletonGroup label="payments">
			<Skeleton />
		</SkeletonGroup>
	);
	const status = screen.getByRole( 'status' );
	expect( status ).toHaveTextContent( '' );
	advance( LIVE_REGION_DELAY_MS - 1 );
	expect( status ).toHaveTextContent( '' );
	advance( 1 );
	expect( screen.getByRole( 'status' ) ).toBe( status );
	expect( status ).toHaveTextContent( 'Loading payments…' );
} );

test( 'unmounting a SkeletonGroup before the delay leaves no timer', () => {
	jest.useFakeTimers();
	const { unmount } = render(
		<SkeletonGroup label="payments">
			<Skeleton />
		</SkeletonGroup>
	);
	expect( jest.getTimerCount() ).toBe( 1 );
	unmount();
	expect( jest.getTimerCount() ).toBe( 0 );
} );

test( 'ProgressBar without a value is indeterminate with an accessible name', () => {
	render( <ProgressBar label="Checking connection" /> );
	const bar = screen.getByRole( 'progressbar', {
		name: 'Checking connection',
	} );
	expect( bar ).not.toHaveAttribute( 'aria-valuenow' );
	// No English default text from the primitive leaks into the name or value.
	expect( bar ).not.toHaveAttribute( 'aria-valuetext' );
	expect( bar ).toHaveAttribute( 'data-indeterminate' );
	expect( screen.getByText( 'Checking connection' ) ).toBeVisible();
} );

test( 'a determinate ProgressBar exposes its value, maximum and value text', () => {
	render(
		<ProgressBar
			label="Setup progress"
			value={ 2 }
			max={ 4 }
			valueText="2 of 4 steps"
		/>
	);
	const bar = screen.getByRole( 'progressbar', { name: 'Setup progress' } );
	expect( bar ).toHaveAttribute( 'aria-valuenow', '2' );
	expect( bar ).toHaveAttribute( 'aria-valuemax', '4' );
	expect( bar ).toHaveAttribute( 'aria-valuetext', '2 of 4 steps' );
	expect( screen.getByText( '2 of 4 steps' ) ).toBeVisible();
} );

test( 'a determinate ProgressBar without value text shows a percentage', () => {
	const { container } = render( <ProgressBar label="Import" value={ 30 } /> );
	const bar = screen.getByRole( 'progressbar', { name: 'Import' } );
	expect( bar ).toHaveAttribute( 'aria-valuenow', '30' );
	expect( bar.getAttribute( 'aria-valuetext' ) ).toMatch( /30/ );
	expect(
		container.querySelector( '.sup-progress__value' )?.textContent
	).toMatch( /30/ );
} );

test( 'Stepper marks the current step and announces completed steps', () => {
	render(
		<Stepper
			label="Setup steps"
			steps={ STEPS }
			currentId="test"
			completedIds={ [ 'connect', 'keys' ] }
		/>
	);
	const list = screen.getByRole( 'list', { name: 'Setup steps' } );
	expect( list.tagName ).toBe( 'OL' );
	// Explicit: WebKit drops list semantics when list-style is none.
	expect( list ).toHaveAttribute( 'role', 'list' );
	const items = within( list ).getAllByRole( 'listitem' );
	expect( items ).toHaveLength( 4 );

	expect( items[ 0 ] ).toHaveTextContent( 'Connect account' );
	expect( items[ 0 ] ).toHaveTextContent( 'Completed' );
	expect( items[ 1 ] ).toHaveTextContent( 'Completed' );
	// aria-current already says "current step"; no hidden text repeats it.
	expect( items[ 2 ] ).toHaveAttribute( 'aria-current', 'step' );
	expect( items[ 2 ] ).toHaveTextContent( 'Run a test payment' );
	expect( items[ 2 ] ).not.toHaveTextContent( 'Current step' );
	expect( items[ 2 ] ).not.toHaveTextContent( 'Completed' );
	expect( items[ 2 ] ).not.toHaveTextContent( 'Not started' );
	expect( items[ 3 ] ).toHaveTextContent( 'Not started' );
	for ( const index of [ 0, 1, 3 ] ) {
		expect( items[ index ] ).not.toHaveAttribute( 'aria-current' );
	}
	// The state words are for assistive technology; the marker shows them.
	for ( const word of [ 'Completed', 'Not started' ] ) {
		for ( const node of screen.getAllByText( word ) ) {
			expect( node ).toHaveClass( 'sup-visually-hidden' );
		}
	}
} );

test( 'a completed current step is announced as current', () => {
	render(
		<Stepper
			label="Setup steps"
			steps={ STEPS }
			currentId="keys"
			completedIds={ [ 'connect', 'keys' ] }
		/>
	);
	const items = screen.getAllByRole( 'listitem' );
	expect( items[ 1 ] ).toHaveAttribute( 'aria-current', 'step' );
	expect( items[ 1 ] ).not.toHaveTextContent( 'Current step' );
	expect( items[ 1 ] ).not.toHaveTextContent( 'Completed' );
} );

test( 'step states are translated with the "setup step state" context', () => {
	const contextual = {
		'': { domain: 'supcheckout' },
		'setup step stateCompleted': [ 'Done (state)' ],
		'setup step stateNot started': [ 'Waiting (state)' ],
		// A bare msgid must not be what the Stepper looks up.
		Completed: [ 'Done (bare)' ],
		'Not started': [ 'Waiting (bare)' ],
	};
	setLocaleData( contextual, 'supcheckout' );
	try {
		render(
			<Stepper
				label="Setup steps"
				steps={ STEPS }
				currentId="test"
				completedIds={ [ 'connect', 'keys' ] }
			/>
		);
		const items = screen.getAllByRole( 'listitem' );
		expect( items[ 0 ] ).toHaveTextContent( 'Done (state)' );
		expect( items[ 3 ] ).toHaveTextContent( 'Waiting (state)' );
		expect( document.body ).not.toHaveTextContent( '(bare)' );
	} finally {
		resetLocaleData( undefined, 'supcheckout' );
	}
} );
