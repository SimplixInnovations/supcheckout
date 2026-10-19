/**
 * SupRoot: the scoped app root and its body-level portal layer (SP1 Task 3,
 * Review Focus 2).
 */
import { render, screen } from '@testing-library/react';
import { StrictMode, createPortal, useLayoutEffect } from '@wordpress/element';

import {
	Heading,
	Icon,
	Numeric,
	Spinner,
	SupRoot,
	Text,
	VisuallyHidden,
	usePortalContainer,
} from '../../../../assets/admin/src/ui';
import { mockIsRTL, mockMatchMedia, renderInModes } from '../support/render';

const root = ( container: HTMLElement ) =>
	container.firstElementChild as HTMLElement;
const layers = () =>
	document.body.querySelectorAll( ':scope > .sup-portal-layer' );
const layer = () => layers()[ 0 ] as HTMLElement | undefined;

beforeEach( () => {
	mockMatchMedia( false );
} );

test( 'SupRoot renders the scoped root with direction, language and density', () => {
	document.documentElement.lang = 'en-US';
	const { container } = render( <SupRoot>x</SupRoot> );
	expect( root( container ) ).toHaveClass( 'supcheckout-admin' );
	expect( root( container ) ).toHaveAttribute( 'dir', 'ltr' );
	expect( root( container ) ).toHaveAttribute( 'lang', 'en-US' );
	expect( root( container ) ).toHaveAttribute(
		'data-sup-density',
		'comfortable'
	);
} );

test( 'SupRoot sets data-sup-theme only for an explicit preference', () => {
	const { container, rerender } = render(
		<SupRoot preference="system">x</SupRoot>
	);
	expect( root( container ) ).not.toHaveAttribute( 'data-sup-theme' );
	rerender( <SupRoot preference="dark">x</SupRoot> );
	expect( root( container ) ).toHaveAttribute( 'data-sup-theme', 'dark' );
	rerender( <SupRoot preference="light">x</SupRoot> );
	expect( root( container ) ).toHaveAttribute( 'data-sup-theme', 'light' );
} );

test( 'SupRoot mirrors theme, dir and lang onto the portal layer', () => {
	mockIsRTL( true );
	document.documentElement.lang = 'ar';
	render( <SupRoot preference="dark">x</SupRoot> );
	expect( layers() ).toHaveLength( 1 );
	expect( layer() ).toHaveClass( 'supcheckout-admin' );
	expect( layer() ).toHaveAttribute( 'data-sup-theme', 'dark' );
	expect( layer() ).toHaveAttribute( 'dir', 'rtl' );
	expect( layer() ).toHaveAttribute( 'lang', 'ar' );
	expect( layer() ).toHaveAttribute( 'data-sup-density', 'comfortable' );
} );

test( 'the portal layer stays in sync with the root', () => {
	const { rerender } = render(
		<SupRoot preference="dark" density="compact">
			x
		</SupRoot>
	);
	expect( layer() ).toHaveAttribute( 'data-sup-density', 'compact' );
	rerender( <SupRoot preference="system">x</SupRoot> );
	expect( layers() ).toHaveLength( 1 );
	expect( layer() ).not.toHaveAttribute( 'data-sup-theme' );
	expect( layer() ).toHaveAttribute( 'data-sup-density', 'comfortable' );
} );

test( 'the portal layer is removed on unmount', () => {
	const { unmount } = render( <SupRoot>x</SupRoot> );
	expect( layers() ).toHaveLength( 1 );
	unmount();
	expect( layers() ).toHaveLength( 0 );
} );

function PortalProbe() {
	const container = usePortalContainer();
	return container
		? createPortal( <p>Portaled content</p>, container )
		: null;
}

test( 'usePortalContainer returns the layer so overlays render inside the token scope', () => {
	render(
		<SupRoot preference="dark">
			<PortalProbe />
		</SupRoot>
	);
	const portaled = screen.getByText( 'Portaled content' );
	expect( portaled.parentElement ).toBe( layer() );
	expect( portaled.closest( '.supcheckout-admin' ) ).toBe( layer() );
} );

test( 'usePortalContainer is null outside SupRoot', () => {
	let seen: HTMLElement | null | undefined;
	function Probe() {
		seen = usePortalContainer();
		return null;
	}
	render( <Probe /> );
	expect( seen ).toBeNull();
} );

test( 'usePortalContainer is the layer on the first render inside SupRoot', () => {
	const seen: Array< HTMLElement | null > = [];
	function Probe() {
		seen.push( usePortalContainer() );
		return null;
	}
	render(
		<SupRoot preference="dark">
			<Probe />
		</SupRoot>
	);
	expect( seen[ 0 ] ).not.toBeNull();
	expect( seen[ 0 ] ).toHaveClass( 'supcheckout-admin', 'sup-portal-layer' );
	expect( seen[ 0 ] ).toBe( layer() );
	expect( new Set( seen ).size ).toBe( 1 );
} );

type LayerSnapshot = { connected: boolean; theme: string | null };

function LayoutEffectProbe( {
	seen,
}: {
	seen: Array< LayerSnapshot | null >;
} ) {
	const container = usePortalContainer();
	useLayoutEffect( () => {
		seen.push(
			container
				? {
						connected: container.isConnected,
						theme: container.getAttribute( 'data-sup-theme' ),
					}
				: null
		);
	}, [ container, seen ] );
	return null;
}

test( 'the layer is attached and synced before a child’s first layout effect', () => {
	const seen: Array< LayerSnapshot | null > = [];
	render(
		<SupRoot preference="dark">
			<div>
				<LayoutEffectProbe seen={ seen } />
			</div>
		</SupRoot>
	);
	expect( seen ).toEqual( [ { connected: true, theme: 'dark' } ] );
} );

test( 'under StrictMode every child layout effect run sees an attached layer', () => {
	const seen: Array< LayerSnapshot | null > = [];
	render(
		<StrictMode>
			<SupRoot preference="dark">
				<LayoutEffectProbe seen={ seen } />
			</SupRoot>
		</StrictMode>
	);
	expect( seen.length ).toBeGreaterThan( 0 );
	expect( seen ).toEqual(
		seen.map( () => ( { connected: true, theme: 'dark' } ) )
	);
	expect( layers() ).toHaveLength( 1 );
} );

test( 'StrictMode keeps exactly one synced layer and removes it on unmount', () => {
	const { rerender, unmount } = render(
		<StrictMode>
			<SupRoot preference="dark">x</SupRoot>
		</StrictMode>
	);
	expect( layers() ).toHaveLength( 1 );
	expect( layer() ).toHaveAttribute( 'data-sup-theme', 'dark' );
	rerender(
		<StrictMode>
			<SupRoot preference="light" density="compact">
				x
			</SupRoot>
		</StrictMode>
	);
	expect( layers() ).toHaveLength( 1 );
	expect( layer() ).toHaveAttribute( 'data-sup-theme', 'light' );
	expect( layer() ).toHaveAttribute( 'data-sup-density', 'compact' );
	unmount();
	expect( layers() ).toHaveLength( 0 );
} );

test( 'two roots own two layers and unmounting one keeps the other', () => {
	const first = render( <SupRoot preference="dark">a</SupRoot> );
	const second = render( <SupRoot preference="light">b</SupRoot> );
	expect( layers() ).toHaveLength( 2 );
	first.unmount();
	expect( layers() ).toHaveLength( 1 );
	expect( layer() ).toHaveAttribute( 'data-sup-theme', 'light' );
	second.unmount();
	expect( layers() ).toHaveLength( 0 );
} );

test( 'the system color-scheme listener is removed on unmount', () => {
	const media = mockMatchMedia( false );
	const { unmount } = render( <SupRoot>x</SupRoot> );
	expect( media.listenerCount() ).toBeGreaterThan( 0 );
	unmount();
	expect( media.listenerCount() ).toBe( 0 );
} );

function FoundationsSpecimen() {
	return (
		<div>
			<Heading level={ 1 }>Payments</Heading>
			<Heading level={ 2 } size="md">
				Recent activity
			</Heading>
			<Text>
				Order <Numeric kind="id">#10234</Numeric> was charged{ ' ' }
				<Numeric kind="amount">12.500 KWD</Numeric>.
			</Text>
			<Text variant="muted" as="span">
				Updated <Numeric kind="count">3</Numeric> minutes ago
			</Text>
			<Icon name="success" label="Verified" />
			<Icon name="chevron-end" />
			<Spinner label="Loading payments" />
			<Spinner />
			<VisuallyHidden>Hidden helper text</VisuallyHidden>
		</div>
	);
}

test( 'foundations have no axe violations in four modes', () =>
	renderInModes( <FoundationsSpecimen /> ) );
