/**
 * Loading and progress: Skeleton, SkeletonGroup, ProgressBar and Stepper
 * (SP1 Task 6).
 */
import { render, screen, within } from '@testing-library/react';
import { useLayoutEffect } from '@wordpress/element';

import {
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

function StatusProbe( { seen }: { seen: string[] } ) {
	useLayoutEffect( () => {
		const regions = document.querySelectorAll( '[role="status"]' );
		seen.push(
			...Array.from(
				regions,
				( region ) => `region:${ region.textContent ?? '' }`
			)
		);
	}, [ seen ] );
	return null;
}

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
	const { container } = render(
		<SkeletonGroup label="payments">
			<Skeleton height="title" width="40%" />
			<Skeleton />
			<Skeleton />
		</SkeletonGroup>
	);
	expect( container.querySelector( '[aria-busy="true"]' ) ).not.toBeNull();
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

test( 'SkeletonGroup mounts its status empty, then inserts the text', () => {
	const seen: string[] = [];
	render(
		<>
			<SkeletonGroup label="payments">
				<Skeleton />
			</SkeletonGroup>
			<StatusProbe seen={ seen } />
		</>
	);
	expect( seen ).toEqual( [ 'region:' ] );
	expect( screen.getByRole( 'status' ) ).toHaveTextContent(
		'Loading payments…'
	);
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
	const items = within( list ).getAllByRole( 'listitem' );
	expect( items ).toHaveLength( 4 );

	expect( items[ 0 ] ).toHaveTextContent( 'Connect account' );
	expect( items[ 0 ] ).toHaveTextContent( 'Completed' );
	expect( items[ 1 ] ).toHaveTextContent( 'Completed' );
	expect( items[ 2 ] ).toHaveAttribute( 'aria-current', 'step' );
	expect( items[ 2 ] ).toHaveTextContent( 'Current step' );
	expect( items[ 3 ] ).toHaveTextContent( 'Not started' );
	for ( const index of [ 0, 1, 3 ] ) {
		expect( items[ index ] ).not.toHaveAttribute( 'aria-current' );
	}
	// The state words are for assistive technology; the marker shows them.
	for ( const word of [ 'Completed', 'Current step', 'Not started' ] ) {
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
	expect( items[ 1 ] ).toHaveTextContent( 'Current step' );
	expect( items[ 1 ] ).not.toHaveTextContent( 'Completed' );
} );
