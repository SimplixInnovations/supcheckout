/**
 * Overlays, navigation, AppShell and command palette specimens (SP1 Task 7).
 *
 * They render as their own gallery view (`?view=overlays`): the AppShell is
 * a whole-page frame with a sticky header, so it cannot sit among the other
 * specimens. Each specimen is a `[data-specimen]` element in a fixed DOM
 * order inside the shell's content, so the browser suite can open each
 * overlay, check the portal layer, the sticky header and the palette chunk,
 * and the visual suite (Task 8) can screenshot it.
 *
 * Nothing here calls a server: the confirmation outcomes are local promises
 * that settle after RESULT_DELAY_MS, so the pending state is observable.
 * `?confirm=hold` (visual suite) stretches that to HELD_RESULT_DELAY_MS, so
 * the pending state stays on screen for a screenshot without a timing race.
 * `open` opens one overlay on mount, for the unit axe suite.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	AppShell,
	Button,
	ConfirmDialog,
	DataTable,
	Dialog,
	EmptyState,
	StatusPill,
	Tabs,
	Text,
	TextInput,
	Tooltip,
	useTheme,
} from '../../../../assets/admin/src/ui';
import type {
	Column,
	Command,
	ConfirmResult,
	NavItem,
	TableSort,
} from '../../../../assets/admin/src/ui';
import { Specimen, SPECIMEN_STYLE } from './foundations';
import { SpecimenFrame } from './frame';

/** Specimen names in DOM order (asserted by the browser suite). */
export const OVERLAY_SPECIMENS = [
	'dialog',
	'confirm-dialog',
	'tabs',
	'data-table',
	'data-table-loading',
	'data-table-empty',
	'focus-scroll',
] as const;

export type OverlayName = 'dialog' | 'confirm' | 'palette';

/** How long a specimen confirmation stays pending. */
export const RESULT_DELAY_MS = 800;

/** How long it stays pending under `?confirm=hold`: the promise still settles. */
export const HELD_RESULT_DELAY_MS = 10 * 60 * 1000;

const CONFIRM_QUERY = 'confirm';

function resultDelay(): number {
	return typeof window !== 'undefined' &&
		new URLSearchParams( window.location.search ).get( CONFIRM_QUERY ) ===
			'hold'
		? HELD_RESULT_DELAY_MS
		: RESULT_DELAY_MS;
}

/** Rows in the long specimen that the sticky-header focus test tabs through. */
const FOCUS_ROWS = 24;

const row = {
	display: 'flex',
	flexWrap: 'wrap',
	alignItems: 'center',
	gap: 'var(--sup-space-3)',
} as const;

const stack = { display: 'grid', gap: 'var(--sup-space-4)' } as const;

/** The content column: frames shrink-wrap, as in the components view. */
const frameStack = { ...stack, justifyItems: 'start' } as const;

const later = < T, >( value: () => T ): Promise< T > =>
	new Promise( ( resolve ) =>
		setTimeout( () => resolve( value() ), resultDelay() )
	);

interface Payment {
	order: string;
	customer: string;
	status: 'paid' | 'pending' | 'failed';
	amount: string;
	cents: number;
}

const PAYMENTS: Payment[] = [
	{
		order: '#10234',
		customer: 'Fatima Al-Sabah',
		status: 'paid',
		amount: '12.500 KWD',
		cents: 12500,
	},
	{
		order: '#10233',
		customer: 'Omar Haddad',
		status: 'pending',
		amount: '4.000 KWD',
		cents: 4000,
	},
	{
		order: '#10232',
		customer: 'Layla Mansour',
		status: 'failed',
		amount: '27.750 KWD',
		cents: 27750,
	},
];

function statusPill( status: Payment[ 'status' ] ) {
	switch ( status ) {
		case 'paid':
			return (
				<StatusPill status="success">
					{ __( 'Verified', 'supcheckout' ) }
				</StatusPill>
			);
		case 'pending':
			return (
				<StatusPill status="warning">
					{ __( 'Awaiting confirmation', 'supcheckout' ) }
				</StatusPill>
			);
		default:
			return (
				<StatusPill status="danger">
					{ __( 'Not completed', 'supcheckout' ) }
				</StatusPill>
			);
	}
}

function paymentColumns(): Column< Payment >[] {
	return [
		{
			id: 'order',
			header: __( 'Order', 'supcheckout' ),
			cell: ( payment ) => payment.order,
			numeric: true,
			align: 'start',
			sortable: true,
		},
		{
			id: 'customer',
			header: __( 'Customer', 'supcheckout' ),
			cell: ( payment ) => payment.customer,
		},
		{
			id: 'status',
			header: __( 'Status', 'supcheckout' ),
			cell: ( payment ) => statusPill( payment.status ),
		},
		{
			id: 'amount',
			header: __( 'Amount', 'supcheckout' ),
			cell: ( payment ) => payment.amount,
			numeric: true,
			sortable: true,
		},
	];
}

function sortPayments( sort: TableSort ): Payment[] {
	const sign = sort.direction === 'ascending' ? 1 : -1;
	return [ ...PAYMENTS ].sort( ( a, b ) =>
		sort.columnId === 'amount'
			? ( a.cents - b.cents ) * sign
			: a.order.localeCompare( b.order ) * sign
	);
}

/**
 * The light-ltr-only `data-table-compact` frame (visual suite): the payments
 * table at compact density. Not a `[data-specimen]`, so the browser suite's
 * overlay specimen order is unchanged.
 */
function CompactTableFrame() {
	const columns = useMemo( paymentColumns, [] );
	return (
		<SpecimenFrame name="data-table-compact">
			<section
				aria-label={ __( 'Compact data table', 'supcheckout' ) }
				style={ SPECIMEN_STYLE }
			>
				<DataTable< Payment >
					caption={ __( 'Recent payments, compact', 'supcheckout' ) }
					columns={ columns }
					rows={ PAYMENTS }
					rowKey={ ( payment ) => payment.order }
					density="compact"
				/>
			</section>
		</SpecimenFrame>
	);
}

function SortableTable() {
	const [ sort, setSort ] = useState< TableSort >( {
		columnId: 'order',
		direction: 'descending',
	} );
	const columns = useMemo( paymentColumns, [] );
	return (
		<DataTable< Payment >
			caption={ __( 'Recent payments', 'supcheckout' ) }
			columns={ columns }
			rows={ sortPayments( sort ) }
			rowKey={ ( payment ) => payment.order }
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

interface DialogSpecimenProps {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
}

function WebhookDialog( { open, onOpenChange }: DialogSpecimenProps ) {
	return (
		<Dialog
			open={ open }
			onOpenChange={ onOpenChange }
			title={ __( 'Edit webhook', 'supcheckout' ) }
			description={ __(
				'UPayments sends payment updates to this address.',
				'supcheckout'
			) }
			footer={
				<>
					<Button onClick={ () => onOpenChange( false ) }>
						{ __( 'Cancel', 'supcheckout' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ () => onOpenChange( false ) }
					>
						{ __( 'Save webhook', 'supcheckout' ) }
					</Button>
				</>
			}
		>
			<div style={ stack }>
				<TextInput
					aria-label={ __( 'Webhook URL', 'supcheckout' ) }
					defaultValue="https://shop.example/wc-api/wc_upayments"
					dir="ltr"
				/>
				<div style={ row }>
					<Tooltip
						content={ __(
							'Copies the address to the clipboard.',
							'supcheckout'
						) }
					>
						<Button size="sm">
							{ __( 'Copy address', 'supcheckout' ) }
						</Button>
					</Tooltip>
					<Text variant="muted">
						{ __( 'Only HTTPS addresses work.', 'supcheckout' ) }
					</Text>
				</div>
			</div>
		</Dialog>
	);
}

type Scenario = 'refund' | 'capture' | 'link';

function ConfirmScenarios( { initial }: { initial?: Scenario } ) {
	const [ open, setOpen ] = useState< Scenario | null >( initial ?? null );
	const close = ( next: boolean ) => {
		if ( ! next ) {
			setOpen( null );
		}
	};
	return (
		<div style={ row }>
			<Button variant="primary" onClick={ () => setOpen( 'refund' ) }>
				{ __( 'Refund order #10234', 'supcheckout' ) }
			</Button>
			<Button onClick={ () => setOpen( 'capture' ) }>
				{ __( 'Capture order #10233', 'supcheckout' ) }
			</Button>
			<Button onClick={ () => setOpen( 'link' ) }>
				{ __( 'Send a pay link', 'supcheckout' ) }
			</Button>
			<ConfirmDialog
				open={ open === 'refund' }
				onOpenChange={ close }
				tone="money"
				title={ __( 'Refund 12.500 KWD?', 'supcheckout' ) }
				consequence={ __(
					'UPayments returns 12.500 KWD to the card that paid for order #10234. This cannot be undone.',
					'supcheckout'
				) }
				confirmLabel={ __( 'Refund 12.500 KWD', 'supcheckout' ) }
				onConfirm={ () =>
					later< ConfirmResult >( () => ( {
						status: 'verified',
						message: __(
							'UPayments confirmed the refund of 12.500 KWD.',
							'supcheckout'
						),
					} ) )
				}
			/>
			<ConfirmDialog
				open={ open === 'capture' }
				onOpenChange={ close }
				tone="money"
				title={ __( 'Capture 4.000 KWD?', 'supcheckout' ) }
				consequence={ __(
					'UPayments charges the authorized 4.000 KWD for order #10233.',
					'supcheckout'
				) }
				confirmLabel={ __( 'Capture 4.000 KWD', 'supcheckout' ) }
				onConfirm={ () =>
					new Promise< ConfirmResult >( ( _resolve, reject ) =>
						setTimeout(
							() => reject( new Error( 'network' ) ),
							resultDelay()
						)
					)
				}
			/>
			<ConfirmDialog
				open={ open === 'link' }
				onOpenChange={ close }
				tone="neutral"
				title={ __( 'Send a pay link to Omar Haddad?', 'supcheckout' ) }
				consequence={ __(
					'The customer receives an email with a link to pay for order #10233.',
					'supcheckout'
				) }
				confirmLabel={ __( 'Send pay link', 'supcheckout' ) }
				onConfirm={ () =>
					later< ConfirmResult >( () => ( {
						status: 'pending',
						message: __(
							'UPayments accepted the request and has not confirmed delivery yet.',
							'supcheckout'
						),
					} ) )
				}
			/>
		</div>
	);
}

function OpenPaletteOnMount() {
	useEffect( () => {
		document.body.dispatchEvent(
			new KeyboardEvent( 'keydown', {
				key: 'k',
				ctrlKey: true,
				bubbles: true,
			} )
		);
	}, [] );
	return null;
}

interface OverlaysSpecimensProps {
	/** Opens one overlay on mount (unit axe suite). */
	open?: OverlayName;
}

export function OverlaysSpecimens( { open }: OverlaysSpecimensProps ) {
	const [ dialogOpen, setDialogOpen ] = useState( open === 'dialog' );
	const [ refund, setRefund ] = useState( 0 );
	const { setPreference } = useTheme();

	const nav: NavItem[] = [
		{
			id: 'overview',
			label: __( 'Overview', 'supcheckout' ),
			href: '#overview',
		},
		{
			id: 'payments',
			label: __( 'Payments', 'supcheckout' ),
			href: '#payments',
			icon: 'inbox',
		},
		{
			id: 'settings',
			label: __( 'Settings', 'supcheckout' ),
			href: '#settings',
		},
	];

	const commands: Command[] = [
		{
			id: 'go-payments',
			label: __( 'Go to payments', 'supcheckout' ),
			group: __( 'Go to', 'supcheckout' ),
			keywords: [ __( 'orders', 'supcheckout' ) ],
			perform: () => window.location.assign( '#payments' ),
		},
		{
			id: 'go-settings',
			label: __( 'Go to settings', 'supcheckout' ),
			group: __( 'Go to', 'supcheckout' ),
			perform: () => window.location.assign( '#settings' ),
		},
		{
			id: 'edit-webhook',
			label: __( 'Edit the webhook', 'supcheckout' ),
			group: __( 'Actions', 'supcheckout' ),
			perform: () => setDialogOpen( true ),
		},
		{
			id: 'refund',
			label: __( 'Refund order #10234', 'supcheckout' ),
			group: __( 'Actions', 'supcheckout' ),
			keywords: [ __( 'money back', 'supcheckout' ) ],
			perform: () => setRefund( ( n ) => n + 1 ),
		},
		{
			id: 'dark',
			label: __( 'Use the dark appearance', 'supcheckout' ),
			group: __( 'Appearance', 'supcheckout' ),
			keywords: [ __( 'theme', 'supcheckout' ) ],
			perform: () => setPreference( 'dark' ),
		},
	];

	return (
		<AppShell
			title={ __( 'Payments', 'supcheckout' ) }
			nav={ nav }
			currentNavId="payments"
			commands={ commands }
			actions={ <Button>{ __( 'Export CSV', 'supcheckout' ) }</Button> }
		>
			{ open === 'palette' && <OpenPaletteOnMount /> }
			<div style={ frameStack } data-overlays-ready="true">
				<SpecimenFrame name="overlay-triggers">
					<Specimen
						name="dialog"
						title={ __( 'Dialog', 'supcheckout' ) }
					>
						<div>
							<Button onClick={ () => setDialogOpen( true ) }>
								{ __( 'Edit webhook', 'supcheckout' ) }
							</Button>
						</div>
						<WebhookDialog
							open={ dialogOpen }
							onOpenChange={ setDialogOpen }
						/>
					</Specimen>

					<Specimen
						name="confirm-dialog"
						title={ __( 'Confirmation', 'supcheckout' ) }
					>
						<ConfirmScenarios
							key={ refund }
							initial={
								open === 'confirm' || refund > 0
									? 'refund'
									: undefined
							}
						/>
					</Specimen>
				</SpecimenFrame>

				<SpecimenFrame name="tabs">
					<Specimen name="tabs" title={ __( 'Tabs', 'supcheckout' ) }>
						<Tabs
							label={ __( 'Payment views', 'supcheckout' ) }
							tabs={ [
								{
									id: 'all',
									label: __( 'All payments', 'supcheckout' ),
									panel: (
										<Text variant="muted">
											{ __(
												'Every payment UPayments reported for this store.',
												'supcheckout'
											) }
										</Text>
									),
								},
								{
									id: 'attention',
									label: __(
										'Needs attention',
										'supcheckout'
									),
									badge: '2',
									panel: (
										<Text variant="muted">
											{ __(
												'Payments that UPayments has not confirmed yet.',
												'supcheckout'
											) }
										</Text>
									),
								},
								{
									id: 'refunds',
									label: __( 'Refunds', 'supcheckout' ),
									panel: (
										<Text variant="muted">
											{ __(
												'Refunds and their UPayments confirmation.',
												'supcheckout'
											) }
										</Text>
									),
								},
							] }
						/>
					</Specimen>
				</SpecimenFrame>

				<SpecimenFrame name="data-table">
					<Specimen
						name="data-table"
						title={ __( 'Data table', 'supcheckout' ) }
					>
						<SortableTable />
					</Specimen>
				</SpecimenFrame>

				<SpecimenFrame name="data-table-states">
					<Specimen
						name="data-table-loading"
						title={ __( 'Loading data table', 'supcheckout' ) }
					>
						<DataTable< Payment >
							caption={ __( 'Payments loading', 'supcheckout' ) }
							columns={ paymentColumns() }
							rows={ [] }
							rowKey={ ( payment ) => payment.order }
							loading
							skeletonRows={ 3 }
						/>
					</Specimen>

					<Specimen
						name="data-table-empty"
						title={ __( 'Empty data table', 'supcheckout' ) }
					>
						<DataTable< Payment >
							caption={ __( 'Refunds', 'supcheckout' ) }
							columns={ paymentColumns() }
							rows={ [] }
							rowKey={ ( payment ) => payment.order }
							density="compact"
							empty={
								<EmptyState
									icon="inbox"
									headingLevel={ 3 }
									title={ __(
										'No refunds yet',
										'supcheckout'
									) }
									description={ __(
										'Refunds appear here after you refund an order from WooCommerce.',
										'supcheckout'
									) }
								/>
							}
						/>
					</Specimen>
				</SpecimenFrame>

				<Specimen
					name="focus-scroll"
					title={ __( 'Long list', 'supcheckout' ) }
				>
					<ul style={ { ...stack, margin: 0, padding: 0 } }>
						{ Array.from( { length: FOCUS_ROWS }, ( _, i ) => (
							<li key={ i } style={ { listStyle: 'none' } }>
								<Button size="sm">
									{ sprintf(
										/* translators: %s: an order number, e.g. "#10200". */
										__( 'Open order %s', 'supcheckout' ),
										`#${ 10200 + i }`
									) }
								</Button>
							</li>
						) ) }
					</ul>
				</Specimen>

				<CompactTableFrame />
			</div>
		</AppShell>
	);
}
