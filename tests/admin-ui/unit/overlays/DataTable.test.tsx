/**
 * DataTable: a semantic, captioned table in a focusable scroll region, with
 * sortable headers, bidi-isolated numeric cells and a loading state (SP1
 * Task 7, Review Focus 4).
 */
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from '@wordpress/element';

import {
	DataTable,
	LIVE_REGION_DELAY_MS,
	SupRoot,
} from '../../../../assets/admin/src/ui';
import type {
	Column,
	DataTableProps,
	TableSort,
} from '../../../../assets/admin/src/ui';

interface Payment {
	id: string;
	customer: string;
	amount: string;
}

const ROWS: Payment[] = [
	{ id: '#10234', customer: 'Fatima Al-Sabah', amount: '12.500 KWD' },
	{ id: '#10235', customer: 'Omar Haddad', amount: '4.000 KWD' },
];

const COLUMNS: Column< Payment >[] = [
	{
		id: 'order',
		header: 'Order',
		cell: ( row ) => row.id,
		numeric: true,
		sortable: true,
	},
	{ id: 'customer', header: 'Customer', cell: ( row ) => row.customer },
	{
		id: 'amount',
		header: 'Amount',
		cell: ( row ) => row.amount,
		numeric: true,
		sortable: true,
	},
];

function renderTable( props: Partial< DataTableProps< Payment > > = {} ) {
	return render(
		<SupRoot>
			<DataTable< Payment >
				caption="Recent payments"
				columns={ COLUMNS }
				rows={ ROWS }
				rowKey={ ( row ) => row.id }
				{ ...props }
			/>
		</SupRoot>
	);
}

test( 'DataTable renders a captioned table with column headers and aria-sort', async () => {
	const onSortChange = jest.fn();
	renderTable( {
		sort: { columnId: 'amount', direction: 'ascending' },
		onSortChange,
	} );
	const table = screen.getByRole( 'table', { name: 'Recent payments' } );
	const headers = within( table ).getAllByRole( 'columnheader' );
	expect( headers.map( ( h ) => h.textContent ) ).toEqual( [
		'Order',
		'Customer',
		'Amount',
	] );
	expect( headers[ 2 ] ).toHaveAttribute( 'aria-sort', 'ascending' );
	expect( headers[ 0 ] ).not.toHaveAttribute( 'aria-sort' );
	expect( headers[ 1 ] ).not.toHaveAttribute( 'aria-sort' );
	// Only sortable headers are buttons.
	expect( within( headers[ 1 ] ).queryByRole( 'button' ) ).toBeNull();
	await userEvent.click(
		within( headers[ 0 ] ).getByRole( 'button', { name: 'Order' } )
	);
	expect( onSortChange ).toHaveBeenCalledWith( 'order' );
	expect( within( table ).getAllByRole( 'row' ) ).toHaveLength( 3 );
	for ( const header of headers ) {
		expect( header ).toHaveAttribute( 'scope', 'col' );
	}
} );

test( 'a descending sort is announced on the header', () => {
	renderTable( { sort: { columnId: 'order', direction: 'descending' } } );
	expect(
		screen.getByRole( 'columnheader', { name: 'Order' } )
	).toHaveAttribute( 'aria-sort', 'descending' );
} );

test( 'numeric cells are end-aligned and bidi-isolated', () => {
	renderTable();
	const cell = screen.getByText( '12.500 KWD' ).closest( 'td' );
	expect( cell ).toHaveClass( 'sup-table__cell--end' );
	const bdi = cell?.querySelector( 'bdi[dir="ltr"]' );
	expect( bdi ).toHaveTextContent( '12.500 KWD' );
	expect( bdi ).toHaveClass( 'sup-numeric' );
	expect(
		screen.getByRole( 'columnheader', { name: 'Amount' } )
	).toHaveClass( 'sup-table__cell--end' );
	const text = screen.getByText( 'Omar Haddad' ).closest( 'td' );
	expect( text ).not.toHaveClass( 'sup-table__cell--end' );
} );

test( 'loading shows skeleton rows and aria-busy', async () => {
	jest.useFakeTimers();
	try {
		renderTable( { loading: true } );
		const table = screen.getByRole( 'table', { name: 'Recent payments' } );
		expect( table ).toHaveAttribute( 'aria-busy', 'true' );
		const body = table.querySelector( 'tbody' ) as HTMLElement;
		const rows = body.querySelectorAll( 'tr' );
		expect( rows ).toHaveLength( 5 );
		for ( const row of rows ) {
			expect( row ).toHaveAttribute( 'aria-hidden', 'true' );
			expect( row.querySelectorAll( '.sup-skeleton' ) ).toHaveLength( 3 );
		}
		expect( screen.queryByText( 'Omar Haddad' ) ).toBeNull();
		// The status exists before its text and sits outside the busy table.
		const status = screen.getByRole( 'status' );
		expect( table.contains( status ) ).toBe( false );
		expect( status ).toBeEmptyDOMElement();
		act( () => {
			jest.advanceTimersByTime( LIVE_REGION_DELAY_MS );
		} );
		expect( status ).toHaveTextContent( 'Loading: Recent payments' );
	} finally {
		jest.useRealTimers();
	}
} );

test( 'skeletonRows sets the number of placeholder rows', () => {
	renderTable( { loading: true, skeletonRows: 2 } );
	const body = screen.getByRole( 'table' ).querySelector( 'tbody' );
	expect( body?.querySelectorAll( 'tr' ) ).toHaveLength( 2 );
} );

test( 'an empty table shows the empty content across every column', () => {
	renderTable( { rows: [], empty: <p>No payments yet.</p> } );
	const cell = screen.getByText( 'No payments yet.' ).closest( 'td' );
	expect( cell ).toHaveAttribute( 'colspan', '3' );
	expect( screen.getByRole( 'table' ) ).not.toHaveAttribute( 'aria-busy' );
} );

test( 'the scroll region is focusable and labelled by the caption', () => {
	renderTable( { captionHidden: true, density: 'compact' } );
	const region = screen.getByRole( 'region', { name: 'Recent payments' } );
	expect( region ).toHaveAttribute( 'tabindex', '0' );
	expect( region ).toHaveAttribute( 'data-sup-density', 'compact' );
	const caption = region.querySelector( 'caption' );
	expect( caption ).toHaveClass( 'sup-visually-hidden' );
	expect( region.getAttribute( 'aria-labelledby' ) ).toBe( caption?.id );
} );

test( 'without a density prop the table sets no density and inherits the root', () => {
	render(
		<SupRoot density="compact">
			<DataTable< Payment >
				caption="Recent payments"
				columns={ COLUMNS }
				rows={ ROWS }
				rowKey={ ( row ) => row.id }
			/>
		</SupRoot>
	);
	const region = screen.getByRole( 'region', { name: 'Recent payments' } );
	expect( region ).not.toHaveAttribute( 'data-sup-density' );
	expect(
		region.parentElement?.closest( '[data-sup-density]' )
	).toHaveAttribute( 'data-sup-density', 'compact' );
} );

test( 'an explicit comfortable density is set on the table, so it overrides a compact root', () => {
	render(
		<SupRoot density="compact">
			<DataTable< Payment >
				caption="Recent payments"
				columns={ COLUMNS }
				rows={ ROWS }
				rowKey={ ( row ) => row.id }
				density="comfortable"
			/>
		</SupRoot>
	);
	expect(
		screen.getByRole( 'region', { name: 'Recent payments' } )
	).toHaveAttribute( 'data-sup-density', 'comfortable' );
} );

function SortedTable() {
	const [ sort, setSort ] = useState< TableSort >( {
		columnId: 'order',
		direction: 'descending',
	} );
	return (
		<DataTable< Payment >
			caption="Recent payments"
			columns={ COLUMNS }
			rows={ ROWS }
			rowKey={ ( row ) => row.id }
			sort={ sort }
			onSortChange={ ( columnId ) =>
				setSort( ( current ) => ( {
					columnId,
					direction:
						current.columnId === columnId &&
						current.direction === 'ascending'
							? 'descending'
							: 'ascending',
				} ) )
			}
		/>
	);
}

test( 'a sort the user asks for is announced in the polite status, never on mount', async () => {
	render(
		<SupRoot>
			<SortedTable />
		</SupRoot>
	);
	const status = screen.getByRole( 'status' );
	expect( status ).toHaveAttribute( 'aria-live', 'polite' );
	await act( async () => {
		await new Promise( ( resolve ) =>
			setTimeout( resolve, LIVE_REGION_DELAY_MS + 10 )
		);
	} );
	// The initial sort is part of the page, not a change.
	expect( status ).toHaveTextContent( /^$/ );

	await userEvent.click( screen.getByRole( 'button', { name: 'Amount' } ) );
	await waitFor( () =>
		expect( status ).toHaveTextContent( 'Sorted by Amount, ascending' )
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Amount' } ) );
	await waitFor( () =>
		expect( status ).toHaveTextContent( 'Sorted by Amount, descending' )
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'Order' } ) );
	await waitFor( () =>
		expect( status ).toHaveTextContent( 'Sorted by Order, ascending' )
	);
} );
