/**
 * Display and feedback specimens (SP1 Task 6). Each specimen is a
 * `[data-specimen]` element in a fixed DOM order after the controls, so the
 * browser suite can check live regions, the toast viewport, tooltips,
 * reduced motion and RTL, and the visual suite (Task 8) can screenshot it.
 *
 * Interactive specimens hold their own state; nothing here calls a server.
 * `?pin=focus-target-block-end` pins the last specimen's button to the
 * window corner where the toast stack appears, for the focus-obscured test.
 * `?labels=long` gives the info toast's action a long German label, for the
 * 320 px wrap test.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	Badge,
	Button,
	Card,
	EmptyState,
	IconButton,
	Notice,
	ProgressBar,
	Skeleton,
	SkeletonGroup,
	StatusPill,
	Stepper,
	Text,
	Tooltip,
	useToast,
} from '../../../../assets/admin/src/ui';
import { Specimen } from './foundations';
import { SpecimenFrame } from './frame';

/** Specimen names in DOM order (asserted by the browser suite). */
export const FEEDBACK_SPECIMENS = [
	'card-default',
	'badge-tones',
	'status-pill-statuses',
	'notice-tones',
	'notice-live',
	'notice-live-error',
	'empty-state',
	'skeleton-group',
	'progress-bar',
	'stepper',
	'toast-trigger',
	'tooltip',
	'focus-target-block-end',
] as const;

const PIN_QUERY = 'focus-target-block-end';

const row = {
	display: 'flex',
	flexWrap: 'wrap',
	alignItems: 'center',
	gap: 'var(--sup-space-3)',
} as const;

const stack = { display: 'grid', gap: 'var(--sup-space-4)' } as const;

function DismissibleNotice() {
	const [ shown, setShown ] = useState( true );
	if ( ! shown ) {
		return (
			<Button size="sm" onClick={ () => setShown( true ) }>
				{ __( 'Show the test mode notice', 'supcheckout' ) }
			</Button>
		);
	}
	return (
		<Notice
			tone="warning"
			title={ __( 'Test mode is on', 'supcheckout' ) }
			onDismiss={ () => setShown( false ) }
		>
			{ __(
				'Payments go to the UPayments sandbox. No real money moves.',
				'supcheckout'
			) }
		</Notice>
	);
}

function LiveNoticeSpecimen() {
	const [ attempts, setAttempts ] = useState( 0 );
	return (
		<div style={ stack }>
			<div>
				<Button onClick={ () => setAttempts( attempts + 1 ) }>
					{ __( 'Test connection', 'supcheckout' ) }
				</Button>
			</div>
			{ attempts > 0 && (
				<Notice
					key={ attempts }
					tone="success"
					live
					title={ __( 'Connection works', 'supcheckout' ) }
				>
					{ __(
						'UPayments accepted the API key. Customers can pay with UPayments.',
						'supcheckout'
					) }
				</Notice>
			) }
		</div>
	);
}

function LiveErrorNoticeSpecimen() {
	const [ attempts, setAttempts ] = useState( 0 );
	return (
		<div style={ stack }>
			<div>
				<Button onClick={ () => setAttempts( attempts + 1 ) }>
					{ __( 'Test failing connection', 'supcheckout' ) }
				</Button>
			</div>
			{ attempts > 0 && (
				<Notice
					key={ attempts }
					tone="error"
					live
					title={ __( 'Connection failed', 'supcheckout' ) }
				>
					{ __(
						'UPayments rejected the API key. Copy the key from your UPayments dashboard, save it, then test again.',
						'supcheckout'
					) }
				</Notice>
			) }
		</div>
	);
}

/** A long German action label (a test fixture) under `?labels=long`. */
const LONG_ACTION_LABEL =
	'Archivierung rückgängig machen und Bestellung wiederherstellen';

function ToastTriggers() {
	const toast = useToast();
	const longLabels =
		typeof window !== 'undefined' &&
		new URLSearchParams( window.location.search ).get( 'labels' ) ===
			'long';
	return (
		<div style={ row }>
			<Button
				onClick={ () =>
					toast.show( {
						tone: 'success',
						title: __( 'Settings saved', 'supcheckout' ),
						description: __(
							'New checkouts use these settings.',
							'supcheckout'
						),
					} )
				}
			>
				{ __( 'Show success toast', 'supcheckout' ) }
			</Button>
			<Button
				onClick={ () =>
					toast.show( {
						tone: 'info',
						title: __( 'Order archived', 'supcheckout' ),
						action: {
							label: longLabels
								? LONG_ACTION_LABEL
								: __( 'Undo', 'supcheckout' ),
							onClick: () => undefined,
						},
					} )
				}
			>
				{ __( 'Show info toast', 'supcheckout' ) }
			</Button>
			<Button
				onClick={ () =>
					toast.show( {
						tone: 'error',
						title: __( 'Sync failed', 'supcheckout' ),
						description: __(
							'Orders were not updated. Try again in a minute.',
							'supcheckout'
						),
					} )
				}
			>
				{ __( 'Show error toast', 'supcheckout' ) }
			</Button>
		</div>
	);
}

function FocusTarget() {
	const toast = useToast();
	const pinned =
		typeof window !== 'undefined' &&
		new URLSearchParams( window.location.search ).get( 'pin' ) ===
			PIN_QUERY;
	return (
		<div
			style={
				pinned
					? {
							position: 'fixed',
							insetBlockEnd: 'var(--sup-space-7)',
							insetInlineEnd: 'var(--sup-space-7)',
							zIndex: 1,
						}
					: undefined
			}
		>
			<Button
				onClick={ () =>
					toast.show( {
						tone: 'info',
						title: __( 'Focus stays visible', 'supcheckout' ),
						description: __(
							'This toast moved so it does not cover the focused button.',
							'supcheckout'
						),
					} )
				}
			>
				{ __( 'Show a toast here', 'supcheckout' ) }
			</Button>
		</div>
	);
}

export function FeedbackSpecimens() {
	return (
		<>
			<SpecimenFrame name="cards-and-status">
				<Specimen
					name="card-default"
					title={ __( 'Card', 'supcheckout' ) }
				>
					<Card
						title={ __( 'Connection', 'supcheckout' ) }
						actions={
							<Button size="sm">
								{ __( 'Test connection', 'supcheckout' ) }
							</Button>
						}
					>
						<div style={ stack }>
							<div>
								<StatusPill status="success">
									{ __( 'Connected', 'supcheckout' ) }
								</StatusPill>
							</div>
							<Text variant="muted">
								{ __(
									'Payments go to the UPayments sandbox until you switch to live mode.',
									'supcheckout'
								) }
							</Text>
						</div>
					</Card>
				</Specimen>

				<Specimen
					name="badge-tones"
					title={ __( 'Badges', 'supcheckout' ) }
				>
					<div style={ row }>
						<Badge>{ __( 'Sandbox', 'supcheckout' ) }</Badge>
						<Badge>12</Badge>
						<Badge tone="accent">
							{ __( 'New', 'supcheckout' ) }
						</Badge>
					</div>
				</Specimen>

				<Specimen
					name="status-pill-statuses"
					title={ __( 'Status pills', 'supcheckout' ) }
				>
					<div style={ row }>
						<StatusPill status="success">
							{ __( 'Connected', 'supcheckout' ) }
						</StatusPill>
						<StatusPill status="warning">
							{ __( 'Webhook delayed', 'supcheckout' ) }
						</StatusPill>
						<StatusPill status="danger">
							{ __( 'Callback unreachable', 'supcheckout' ) }
						</StatusPill>
						<StatusPill status="info">
							{ __( 'Syncing', 'supcheckout' ) }
						</StatusPill>
						<StatusPill status="neutral">
							{ __( 'Not configured', 'supcheckout' ) }
						</StatusPill>
					</div>
				</Specimen>
			</SpecimenFrame>

			<SpecimenFrame name="notices">
				<Specimen
					name="notice-tones"
					title={ __( 'Notices', 'supcheckout' ) }
				>
					<div style={ stack }>
						<Notice tone="info">
							{ __(
								'UPayments sends payouts to your bank account every working day.',
								'supcheckout'
							) }
						</Notice>
						<Notice
							tone="success"
							title={ __( 'API key saved', 'supcheckout' ) }
						>
							{ __(
								'New payments use the new key.',
								'supcheckout'
							) }
						</Notice>
						<DismissibleNotice />
						<Notice
							tone="error"
							title={ __(
								'Callback unreachable',
								'supcheckout'
							) }
						>
							{ __(
								'UPayments cannot reach your store, so orders stay pending. Check that your site is public, then test the connection.',
								'supcheckout'
							) }
						</Notice>
					</div>
				</Specimen>
			</SpecimenFrame>

			<SpecimenFrame name="live-notices">
				<Specimen
					name="notice-live"
					title={ __( 'Live notice', 'supcheckout' ) }
				>
					<LiveNoticeSpecimen />
				</Specimen>

				<Specimen
					name="notice-live-error"
					title={ __( 'Live error notice', 'supcheckout' ) }
				>
					<LiveErrorNoticeSpecimen />
				</Specimen>
			</SpecimenFrame>

			<SpecimenFrame name="empty-and-loading">
				<Specimen
					name="empty-state"
					title={ __( 'Empty state', 'supcheckout' ) }
				>
					<EmptyState
						icon="inbox"
						title={ __( 'No payments yet', 'supcheckout' ) }
						description={ __(
							'Payments appear here after a customer pays with UPayments.',
							'supcheckout'
						) }
						action={
							<Button variant="primary">
								{ __(
									'Open checkout settings',
									'supcheckout'
								) }
							</Button>
						}
					/>
				</Specimen>

				<Specimen
					name="skeleton-group"
					title={ __( 'Loading skeleton', 'supcheckout' ) }
				>
					<SkeletonGroup label={ __( 'payments', 'supcheckout' ) }>
						<Skeleton height="title" width="40%" />
						<div style={ row }>
							<Skeleton shape="circle" height="control" />
							<div style={ { ...stack, flex: 1 } }>
								<Skeleton width="70%" />
								<Skeleton width="45%" />
							</div>
						</div>
						<Skeleton shape="block" height="4rem" />
					</SkeletonGroup>
				</Specimen>
			</SpecimenFrame>

			<SpecimenFrame name="progress">
				<Specimen
					name="progress-bar"
					title={ __( 'Progress bars', 'supcheckout' ) }
				>
					<div style={ stack }>
						<ProgressBar
							label={ __( 'Setup progress', 'supcheckout' ) }
							value={ 2 }
							max={ 4 }
							valueText={ __( '2 of 4 steps', 'supcheckout' ) }
						/>
						<ProgressBar
							label={ __( 'Checking connection', 'supcheckout' ) }
						/>
					</div>
				</Specimen>

				<Specimen
					name="stepper"
					title={ __( 'Stepper', 'supcheckout' ) }
				>
					<Stepper
						label={ __( 'Setup steps', 'supcheckout' ) }
						steps={ [
							{
								id: 'connect',
								label: __( 'Connect account', 'supcheckout' ),
							},
							{
								id: 'keys',
								label: __( 'Add API keys', 'supcheckout' ),
							},
							{
								id: 'test',
								label: __( 'Test payment', 'supcheckout' ),
							},
							{
								id: 'live',
								label: __( 'Go live', 'supcheckout' ),
							},
						] }
						currentId="test"
						completedIds={ [ 'connect', 'keys' ] }
					/>
				</Specimen>
			</SpecimenFrame>

			<SpecimenFrame name="feedback-triggers">
				<Specimen
					name="toast-trigger"
					title={ __( 'Toasts', 'supcheckout' ) }
				>
					<ToastTriggers />
				</Specimen>

				<Specimen
					name="tooltip"
					title={ __( 'Tooltips', 'supcheckout' ) }
				>
					<div style={ row }>
						<Tooltip
							content={ __(
								'UPayments sends payment updates to this address.',
								'supcheckout'
							) }
						>
							<Button>
								{ __( 'Webhook URL', 'supcheckout' ) }
							</Button>
						</Tooltip>
						<IconButton
							icon="copy"
							variant="secondary"
							label={ __( 'Copy webhook URL', 'supcheckout' ) }
							showTooltip
						/>
						<IconButton
							icon="refresh"
							label={ __( 'Refresh status', 'supcheckout' ) }
							showTooltip
						/>
					</div>
				</Specimen>

				<Specimen
					name="focus-target-block-end"
					title={ __(
						'Focus target at the block end',
						'supcheckout'
					) }
				>
					<FocusTarget />
				</Specimen>
			</SpecimenFrame>
		</>
	);
}
