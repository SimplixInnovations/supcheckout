/**
 * Display components: Card, Badge, StatusPill, Notice and EmptyState
 * (SP1 Task 6).
 */
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
	Badge,
	Button,
	Card,
	EmptyState,
	LIVE_REGION_DELAY_MS,
	Notice,
	StatusPill,
} from '../../../../assets/admin/src/ui';
import type { StatusPillStatus } from '../../../../assets/admin/src/ui';

const advance = ( ms: number ) =>
	act( () => {
		jest.advanceTimersByTime( ms );
	} );

afterEach( () => {
	jest.useRealTimers();
} );

test( 'a Card section is a region labelled by its heading', () => {
	render(
		<Card title="Connection" actions={ <Button>Test connection</Button> }>
			Connected to the sandbox.
		</Card>
	);
	const region = screen.getByRole( 'region', { name: 'Connection' } );
	expect( region.tagName ).toBe( 'SECTION' );
	expect(
		within( region ).getByRole( 'heading', {
			level: 2,
			name: 'Connection',
		} )
	).toBeVisible();
	expect(
		within( region ).getByRole( 'button', { name: 'Test connection' } )
	).toBeVisible();
	expect( region ).toHaveTextContent( 'Connected to the sandbox.' );
} );

test( 'a Card takes a heading level and can be a plain div', () => {
	const { container } = render(
		<Card title="Payouts" headingLevel={ 3 } as="div">
			Next payout on Monday.
		</Card>
	);
	expect(
		screen.getByRole( 'heading', { level: 3, name: 'Payouts' } )
	).toBeVisible();
	expect( screen.queryByRole( 'region' ) ).toBeNull();
	expect( container.querySelector( '.sup-card' )?.tagName ).toBe( 'DIV' );
} );

test( 'a Card without a title is not a named region', () => {
	const { container } = render( <Card>Plain content</Card> );
	expect( screen.queryByRole( 'region' ) ).toBeNull();
	expect( container.querySelector( '.sup-card' ) ).not.toHaveAttribute(
		'aria-labelledby'
	);
} );

test( 'a Badge is neutral by default and accent on request', () => {
	render(
		<>
			<Badge>3</Badge>
			<Badge tone="accent">New</Badge>
		</>
	);
	expect( screen.getByText( '3' ) ).toHaveClass(
		'sup-badge',
		'sup-badge--neutral'
	);
	expect( screen.getByText( 'New' ) ).toHaveClass( 'sup-badge--accent' );
} );

test( 'StatusPill conveys status in text, not color alone', () => {
	const { container } = render(
		<StatusPill status="danger">Callback unreachable</StatusPill>
	);
	expect( screen.getByText( 'Callback unreachable' ) ).toBeVisible();
	const pill = container.querySelector( '.sup-status-pill' );
	expect( pill ).toHaveClass( 'sup-status-pill--danger' );
	expect( pill?.querySelector( 'svg' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
} );

test( 'every StatusPill status draws its own icon shape', () => {
	const statuses: StatusPillStatus[] = [
		'success',
		'warning',
		'danger',
		'info',
		'neutral',
	];
	const shapes = statuses.map( ( status ) => {
		const { container, unmount } = render(
			<StatusPill status={ status }>{ status }</StatusPill>
		);
		const markup = container.querySelector( 'svg' )?.innerHTML ?? '';
		unmount();
		return markup;
	} );
	expect( new Set( shapes ).size ).toBe( statuses.length );
} );

test( 'a static notice is not a live region; a live error notice is an alert', () => {
	const { unmount } = render(
		<Notice tone="error" title="Payment failed">
			The card was declined.
		</Notice>
	);
	expect( screen.getByText( 'Payment failed' ) ).toBeVisible();
	expect( screen.queryByRole( 'alert' ) ).toBeNull();
	expect( screen.queryByRole( 'status' ) ).toBeNull();
	unmount();

	render(
		<Notice tone="error" title="Payment failed" live>
			The card was declined.
		</Notice>
	);
	expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
		'Payment failedThe card was declined.'
	);
	expect( screen.queryByRole( 'status' ) ).toBeNull();
} );

test( 'a live notice of any other tone is a polite status', () => {
	jest.useFakeTimers();
	for ( const tone of [ 'info', 'success', 'warning' ] as const ) {
		const { unmount } = render(
			<Notice tone={ tone } live>
				{ `${ tone } message` }
			</Notice>
		);
		advance( LIVE_REGION_DELAY_MS );
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			`${ tone } message`
		);
		expect( screen.queryByRole( 'alert' ) ).toBeNull();
		unmount();
	}
} );

test( 'a live status notice holds its message for LIVE_REGION_DELAY_MS', () => {
	jest.useFakeTimers();
	expect( LIVE_REGION_DELAY_MS ).toBe( 100 );
	render(
		<Notice tone="success" title="Connection works" live>
			UPayments accepted the API key.
		</Notice>
	);
	const status = screen.getByRole( 'status' );
	// The empty region stays in the page long enough to reach the
	// accessibility tree before its text arrives…
	expect( status ).toHaveTextContent( '' );
	advance( LIVE_REGION_DELAY_MS - 1 );
	expect( status ).toHaveTextContent( '' );
	// …then the same region gains the message: an announced change.
	advance( 1 );
	expect( screen.getByRole( 'status' ) ).toBe( status );
	expect( status ).toHaveTextContent(
		'Connection worksUPayments accepted the API key.'
	);
} );

test( 'a live error notice is an alert that carries its message at once', () => {
	jest.useFakeTimers();
	render(
		<Notice tone="error" title="Connection failed" live>
			Check the API key.
		</Notice>
	);
	// Alerts are announced when inserted, so no delay applies.
	expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
		'Connection failedCheck the API key.'
	);
	expect( jest.getTimerCount() ).toBe( 0 );
} );

test( 'a mounted notice that becomes live holds its text for LIVE_REGION_DELAY_MS', () => {
	jest.useFakeTimers();
	const { rerender } = render( <Notice tone="info">Sync started.</Notice> );
	expect( screen.queryByRole( 'status' ) ).toBeNull();
	advance( LIVE_REGION_DELAY_MS * 3 );

	// Turning live starts a new cycle: the region shows empty first…
	rerender(
		<Notice tone="info" live>
			Sync finished.
		</Notice>
	);
	const status = screen.getByRole( 'status' );
	expect( status ).toHaveTextContent( '' );
	advance( LIVE_REGION_DELAY_MS - 1 );
	expect( status ).toHaveTextContent( '' );
	// …and gains the message only after the delay.
	advance( 1 );
	expect( status ).toHaveTextContent( 'Sync finished.' );
} );

test( 'unmounting a live notice before the delay leaves no timer', () => {
	jest.useFakeTimers();
	const { unmount } = render(
		<Notice tone="info" live>
			Sync started.
		</Notice>
	);
	expect( jest.getTimerCount() ).toBe( 1 );
	unmount();
	expect( jest.getTimerCount() ).toBe( 0 );
} );

test( 'a notice with onDismiss has a "Dismiss notice" icon button', async () => {
	const onDismiss = jest.fn();
	const { container } = render(
		<Notice tone="warning" onDismiss={ onDismiss }>
			Test mode is on.
		</Notice>
	);
	const dismiss = screen.getByRole( 'button', { name: 'Dismiss notice' } );
	expect( dismiss ).toHaveClass( 'sup-button--icon-only' );
	await userEvent.click( dismiss );
	expect( onDismiss ).toHaveBeenCalledTimes( 1 );
	expect(
		container.querySelector( '.sup-notice__icon svg, .sup-notice > svg' )
	).toHaveAttribute( 'aria-hidden', 'true' );
} );

test( 'a notice without onDismiss has no button', () => {
	render( <Notice tone="info">Payouts run daily.</Notice> );
	expect( screen.queryByRole( 'button' ) ).toBeNull();
} );

test( 'EmptyState explains the empty view and offers its action', () => {
	const { container } = render(
		<EmptyState
			icon="inbox"
			title="No payments yet"
			description="Payments appear here after your first order."
			action={ <Button variant="primary">Create a test order</Button> }
		/>
	);
	expect(
		screen.getByRole( 'heading', { level: 2, name: 'No payments yet' } )
	).toBeVisible();
	expect(
		screen.getByText( 'Payments appear here after your first order.' )
	).toBeVisible();
	expect(
		screen.getByRole( 'button', { name: 'Create a test order' } )
	).toBeVisible();
	expect( container.querySelector( 'svg' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);
} );

test( 'EmptyState takes a heading level and needs no icon or action', () => {
	const { container } = render(
		<EmptyState
			headingLevel={ 3 }
			title="No matches"
			description="Try another search."
		/>
	);
	expect(
		screen.getByRole( 'heading', { level: 3, name: 'No matches' } )
	).toBeVisible();
	expect( container.querySelector( 'svg' ) ).toBeNull();
	expect( screen.queryByRole( 'button' ) ).toBeNull();
} );
