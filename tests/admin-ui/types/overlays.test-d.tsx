/**
 * Compile-time API contracts of the overlays, navigation, AppShell and the
 * command palette (SP1 Task 7).
 *
 * Type-checked, never run: `npm run typecheck` includes this file through
 * tsconfig.tests.json. Each `@ts-expect-error` must meet a real error, so a
 * contract that stops being enforced fails the typecheck as an unused
 * directive (TS2578).
 */
import { createRef } from 'react';

import {
	AppShell,
	CommandPalette,
	ConfirmDialog,
	DataTable,
	Dialog,
	normalizeForSearch,
	PALETTE_SHORTCUT,
	Tabs,
} from '../../../assets/admin/src/ui';
import type {
	Column,
	Command,
	ConfirmResult,
	NavItem,
} from '../../../assets/admin/src/ui';

const noop = () => undefined;
const confirm = async (): Promise< ConfirmResult > => ( {
	status: 'verified',
	message: 'UPayments confirmed it.',
} );
const focusRef = createRef< HTMLButtonElement >();

interface Row {
	id: string;
	amount: string;
}
const columns: Column< Row >[] = [
	{ id: 'id', header: 'Order', cell: ( row ) => row.id, sortable: true },
	{
		id: 'amount',
		header: 'Amount',
		cell: ( row ) => row.amount,
		numeric: true,
		align: 'end',
	},
];
const nav: NavItem[] = [
	{ id: 'a', label: 'Overview', href: '#a', icon: 'inbox' },
];
const commands: Command[] = [
	{
		id: 'a',
		label: 'Open',
		group: 'Go to',
		keywords: [ 'x' ],
		perform: noop,
	},
];

export const searchKey: string = normalizeForSearch( 'Café' );
export const shortcut: string = PALETTE_SHORTCUT.ariaKeyShortcuts;

// Usages the contracts allow.
export const allowed = [
	<Dialog
		key="a"
		open
		onOpenChange={ noop }
		title="Edit"
		description="Details"
		size="lg"
		dismissible={ false }
		initialFocus={ focusRef }
		footer="x"
	>
		Body
	</Dialog>,
	<ConfirmDialog
		key="b"
		open
		onOpenChange={ noop }
		title="Refund?"
		consequence={ <strong>Cannot be undone.</strong> }
		confirmLabel="Refund"
		cancelLabel="Keep"
		tone="money"
		onConfirm={ confirm }
		renderResult={ ( result ) => result.message }
	/>,
	<Tabs
		key="c"
		label="Sections"
		tabs={ [ { id: 'a', label: 'A', badge: '2', panel: 'A' } ] }
		selectedId="a"
		onSelect={ noop }
		activation="manual"
	/>,
	<DataTable< Row >
		key="d"
		caption="Payments"
		captionHidden
		columns={ columns }
		rows={ [] }
		rowKey={ ( row ) => row.id }
		loading
		skeletonRows={ 3 }
		empty="None"
		sort={ { columnId: 'id', direction: 'descending' } }
		onSortChange={ noop }
		density="compact"
	/>,
	<AppShell key="e" title="SUPCheckout" nav={ nav } currentNavId="a">
		Body
	</AppShell>,
	<CommandPalette key="f" commands={ commands } />,
];

// Usages the contracts reject.
export const rejected = [
	// @ts-expect-error dialog sizes are sm, md and lg
	<Dialog key="a" open onOpenChange={ noop } title="x" size="xl">
		Body
	</Dialog>,
	// @ts-expect-error a Dialog needs a title
	<Dialog key="b" open onOpenChange={ noop }>
		Body
	</Dialog>,
	<ConfirmDialog
		key="c"
		open
		onOpenChange={ noop }
		title="x"
		consequence="x"
		confirmLabel="x"
		// @ts-expect-error tones are money, danger and neutral
		tone="success"
		onConfirm={ confirm }
	/>,
	<ConfirmDialog
		key="d"
		open
		onOpenChange={ noop }
		title="x"
		consequence="x"
		confirmLabel="x"
		tone="money"
		// @ts-expect-error onConfirm returns the caller's result, not a boolean
		onConfirm={ async () => true }
	/>,
	<ConfirmDialog
		key="e"
		open
		onOpenChange={ noop }
		title="x"
		consequence="x"
		confirmLabel="x"
		tone="money"
		// @ts-expect-error a result status is verified, failed or pending
		onConfirm={ async () => ( { status: 'paid', message: 'x' } ) }
	/>,
	<Tabs
		key="f"
		label="x"
		tabs={ [] }
		// @ts-expect-error activation is automatic or manual
		activation="hover"
	/>,
	<DataTable< Row >
		key="g"
		caption="x"
		columns={ columns }
		rows={ [] }
		rowKey={ ( row ) => row.id }
		// @ts-expect-error sort directions are ascending and descending
		sort={ { columnId: 'id', direction: 'up' } }
	/>,
	// @ts-expect-error a DataTable needs a caption
	<DataTable< Row >
		key="h"
		columns={ columns }
		rows={ [] }
		rowKey={ ( row ) => row.id }
	/>,
	// @ts-expect-error an AppShell needs its nav, even an empty one
	<AppShell key="i" title="x">
		Body
	</AppShell>,
	<AppShell
		key="j"
		title="x"
		// @ts-expect-error nav icons come from the SUPCheckout icon set
		nav={ [ { id: 'a', label: 'A', href: '#', icon: 'rocket' } ] }
	>
		Body
	</AppShell>,
	// @ts-expect-error a command needs perform
	<CommandPalette key="k" commands={ [ { id: 'a', label: 'A' } ] } />,
];
