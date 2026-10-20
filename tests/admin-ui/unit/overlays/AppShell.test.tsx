/**
 * AppShell: the frame of every SUPCheckout screen inside wp-admin (SP1
 * Task 7, Review Focus 5). WordPress owns `role=main`, so the shell adds no
 * main landmark; it adds a skip link, a sticky header and a labelled nav.
 */
import { act, render, screen, within } from '@testing-library/react';

import { AppShell, Button, SupRoot } from '../../../../assets/admin/src/ui';
import type { NavItem } from '../../../../assets/admin/src/ui';

const NAV: NavItem[] = [
	{ id: 'overview', label: 'Overview', href: '#overview' },
	{ id: 'payments', label: 'Payments', href: '#payments', icon: 'inbox' },
	{ id: 'settings', label: 'Settings', href: '#settings' },
];

function renderShell( nav: NavItem[] = NAV ) {
	return render(
		<SupRoot>
			<AppShell
				title="Payments"
				nav={ nav }
				currentNavId="payments"
				actions={ <Button>Export</Button> }
			>
				<p>Body</p>
			</AppShell>
		</SupRoot>
	);
}

test( 'AppShell has a skip link, a labelled nav with aria-current and no main landmark', () => {
	renderShell();
	expect( screen.queryByRole( 'main' ) ).toBeNull();

	const skip = screen.getByRole( 'link', { name: 'Skip to content' } );
	expect( skip ).toHaveAttribute( 'href', '#supcheckout-content' );

	const nav = screen.getByRole( 'navigation', {
		name: 'SUPCheckout sections',
	} );
	const current = within( nav ).getByRole( 'link', { name: 'Payments' } );
	expect( current ).toHaveAttribute( 'aria-current', 'page' );
	expect( current ).toHaveAttribute( 'href', '#payments' );
	for ( const name of [ 'Overview', 'Settings' ] ) {
		expect(
			within( nav ).getByRole( 'link', { name } )
		).not.toHaveAttribute( 'aria-current' );
	}

	const content = document.getElementById( 'supcheckout-content' );
	expect( content ).toHaveTextContent( 'Body' );
	expect( content ).toHaveAttribute( 'tabindex', '-1' );
	expect( content?.tagName ).toBe( 'DIV' );
	expect( content ).not.toHaveAttribute( 'role' );
} );

test( 'the header holds the title, the actions, the palette trigger and the theme toggle', () => {
	renderShell();
	const header = document.querySelector( 'header.sup-app-shell__header' );
	expect( header ).not.toBeNull();
	const scope = within( header as HTMLElement );
	expect(
		scope.getByRole( 'heading', { level: 1, name: 'Payments' } )
	).toBeInTheDocument();
	expect(
		scope.getByRole( 'button', { name: 'Export' } )
	).toBeInTheDocument();
	const trigger = scope.getByRole( 'button', { name: /^Search commands/ } );
	expect( trigger ).toHaveAttribute( 'aria-keyshortcuts', 'Control+K' );
	expect( trigger ).toHaveAttribute( 'aria-haspopup', 'dialog' );
	expect(
		scope.getByRole( 'group', { name: 'Appearance' } )
	).toBeInTheDocument();
} );

test( 'the skip link comes first and the nav is left out when there are no sections', () => {
	renderShell( [] );
	expect( screen.queryByRole( 'navigation' ) ).toBeNull();
	const shell = document.querySelector( '.sup-app-shell' ) as HTMLElement;
	expect( shell.querySelector( 'a' ) ).toHaveTextContent( 'Skip to content' );
	expect( shell.firstElementChild ).toHaveClass( 'sup-skip-link' );
} );

test( 'the measured header height feeds the focus clearance, also when the header wraps', () => {
	const observers: Array< {
		callback: ResizeObserverCallback;
		targets: Element[];
		disconnected: boolean;
	} > = [];
	const original = globalThis.ResizeObserver;
	globalThis.ResizeObserver = class {
		private entry;
		constructor( callback: ResizeObserverCallback ) {
			this.entry = {
				callback,
				targets: [] as Element[],
				disconnected: false,
			};
			observers.push( this.entry );
		}
		observe( target: Element ) {
			this.entry.targets.push( target );
		}
		unobserve() {}
		disconnect() {
			this.entry.disconnected = true;
		}
	} as unknown as typeof ResizeObserver;
	try {
		const { unmount } = renderShell();
		const header = document.querySelector(
			'header.sup-app-shell__header'
		) as HTMLElement;
		const shell = document.querySelector( '.sup-app-shell' ) as HTMLElement;
		const observer = observers.find( ( o ) =>
			o.targets.includes( header )
		);
		expect( observer ).toBeDefined();
		// The tools wrap onto a second row: the header grows to 121.5px.
		header.getBoundingClientRect = () => ( { height: 121.5 } ) as DOMRect;
		act( () => {
			observer?.callback( [], {} as ResizeObserver );
		} );
		expect(
			shell.style.getPropertyValue( '--sup-app-shell-header-height' )
		).toBe( '122px' );
		unmount();
		expect( observer?.disconnected ).toBe( true );
	} finally {
		globalThis.ResizeObserver = original;
	}
} );
