/**
 * ConfirmDialog: the confirmation before a money-affecting or destructive
 * action, on Base UI AlertDialog. It is the money-safety primitive.
 *
 * Contract (AGENTS.md payment rules, Review Focus 1):
 * - It only calls the caller's `onConfirm` and renders the caller's result.
 *   It never calls a server, never infers success and never closes on its
 *   own. "Verified" appears only when the result says `verified`.
 * - `onConfirm` runs at most once per opening. A ref guard, set
 *   synchronously before the call, holds against a double click, a held
 *   Enter that auto-repeats, Enter followed by a click, and Space. A new
 *   opening (closed, then open again) starts a new guard.
 * - While the call is pending, Escape and backdrop presses are refused,
 *   Cancel is `aria-disabled` and inert, and the confirm button is `busy`
 *   (focusable, announced through its `busyLabel`, inert to activation).
 * - A rejected promise, a synchronous throw and a result that is not a
 *   well-formed ConfirmResult all become the failed result, with a generic
 *   message: the raw error never reaches the screen. A result that arrives
 *   after its opening ended is ignored.
 * - On settle, the result panel replaces the consequence: a StatusPill
 *   (verified: success, failed: danger, pending: warning) and the message,
 *   with one "Close" button that receives focus and is described by the
 *   panel, so screen readers hear the result with the focus move. No
 *   further live region is added for it.
 *
 * Copy (ux-copy): the title asks the specific question ("Refund
 * 12.500 KWD?"), `consequence` says what happens and whether it can be
 * undone, and `confirmLabel` repeats the action ("Refund 12.500 KWD"),
 * never "OK" or "Yes".
 */
import type { ReactNode } from 'react';
import { Dialog as BaseDialog } from '@base-ui/react/dialog';
import {
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Button } from '../controls/Button';
import type { ButtonVariant } from '../controls/Button';
import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { StatusPill } from '../feedback/StatusPill';
import type { StatusPillStatus } from '../feedback/StatusPill';
import { DialogFrame } from './Dialog';
import './ConfirmDialog.css';

export type ConfirmTone = 'money' | 'danger' | 'neutral';
export type ConfirmStatus = 'verified' | 'failed' | 'pending';

export interface ConfirmResult {
	/** Only `verified` may be presented as done. */
	status: ConfirmStatus;
	/** Translated message from the caller, e.g. what UPayments confirmed. */
	message: string;
}

export interface ConfirmDialogProps {
	open: boolean;
	/** Called with `false` on Cancel, Close or Escape; never while pending. */
	onOpenChange: ( open: boolean ) => void;
	/** Translated specific question, e.g. "Refund 12.500 KWD?". */
	title: string;
	/** What happens, and whether it can be undone. */
	consequence: ReactNode;
	/** Translated action, e.g. "Refund 12.500 KWD". */
	confirmLabel: string;
	/** Translated label of the safe choice; defaults to "Cancel". */
	cancelLabel?: string;
	/** Translated text announced while pending; defaults to a generic wait. */
	busyLabel?: string;
	tone: ConfirmTone;
	/** The action. Its result is the only source of the outcome. */
	onConfirm: () => Promise< ConfirmResult >;
	/** Replaces the default result body (pill and message). */
	renderResult?: ( result: ConfirmResult ) => ReactNode;
}

const STATUSES: readonly ConfirmStatus[] = [ 'verified', 'failed', 'pending' ];

const PILL_STATUS: Readonly< Record< ConfirmStatus, StatusPillStatus > > = {
	verified: 'success',
	failed: 'danger',
	pending: 'warning',
};

const CONFIRM_VARIANT: Readonly< Record< ConfirmTone, ButtonVariant > > = {
	money: 'primary',
	danger: 'danger',
	neutral: 'primary',
};

const TONE_ICON: Readonly< Record< ConfirmTone, IconName | undefined > > = {
	money: 'lock',
	danger: 'warning',
	neutral: undefined,
};

/** @return The result shown when the outcome is unknown. */
function failedResult(): ConfirmResult {
	return {
		status: 'failed',
		message: __(
			'The request did not complete. Refresh to see the current state before trying again.',
			'supcheckout'
		),
	};
}

/**
 * @param value Whatever `onConfirm` resolved with.
 * @return The value when it is a well-formed ConfirmResult; otherwise the
 *         failed result (fail closed: an unknown shape is never success).
 */
function toResult( value: unknown ): ConfirmResult {
	if ( typeof value !== 'object' || value === null ) {
		return failedResult();
	}
	const { status, message } = value as Record< string, unknown >;
	if (
		typeof status !== 'string' ||
		! ( STATUSES as readonly string[] ).includes( status ) ||
		typeof message !== 'string' ||
		message.trim() === ''
	) {
		return failedResult();
	}
	return { status: status as ConfirmStatus, message };
}

function statusLabel( status: ConfirmStatus ): string {
	switch ( status ) {
		case 'verified':
			return __( 'Verified', 'supcheckout' );
		case 'pending':
			return __( 'Awaiting confirmation', 'supcheckout' );
		default:
			return __( 'Not completed', 'supcheckout' );
	}
}

type Phase = 'idle' | 'pending' | 'settled';

export function ConfirmDialog( {
	open,
	onOpenChange,
	title,
	consequence,
	confirmLabel,
	cancelLabel,
	busyLabel,
	tone,
	onConfirm,
	renderResult,
}: ConfirmDialogProps ) {
	const [ phase, setPhase ] = useState< Phase >( 'idle' );
	const [ result, setResult ] = useState< ConfirmResult | null >( null );
	// One guard per opening: set before onConfirm runs, so no second
	// activation in the same opening can reach it, whatever React batches.
	const fired = useRef( false );
	// Increments on every opening; a settle from an older opening is dropped.
	const opening = useRef( 0 );
	const cancelRef = useRef< HTMLButtonElement >( null );
	const closeRef = useRef< HTMLButtonElement >( null );
	const resultId = useId();

	useLayoutEffect( () => {
		if ( open ) {
			opening.current += 1;
			fired.current = false;
			setPhase( 'idle' );
			setResult( null );
		}
	}, [ open ] );

	useEffect( () => {
		if ( phase === 'settled' ) {
			closeRef.current?.focus();
		}
	}, [ phase ] );

	const pending = phase === 'pending';

	const confirm = async () => {
		if ( fired.current ) {
			return;
		}
		fired.current = true;
		const current = opening.current;
		setPhase( 'pending' );
		let settled: ConfirmResult;
		try {
			settled = toResult( await onConfirm() );
		} catch {
			settled = failedResult();
		}
		if ( opening.current !== current ) {
			return;
		}
		setResult( settled );
		setPhase( 'settled' );
	};

	const cancel = () => {
		if ( ! pending ) {
			onOpenChange( false );
		}
	};

	const toneIcon = TONE_ICON[ tone ];

	return (
		<DialogFrame
			alert
			open={ open }
			size="sm"
			className={ `sup-confirm sup-confirm--${ tone }` }
			initialFocus={ cancelRef }
			onOpenChange={ ( next ) => {
				if ( ! next && pending ) {
					return false;
				}
				onOpenChange( next );
				return undefined;
			} }
		>
			<div className="sup-dialog__header sup-confirm__header">
				{ toneIcon && (
					<span className="sup-confirm__mark">
						<Icon name={ toneIcon } size={ 20 } />
					</span>
				) }
				<BaseDialog.Title className="sup-dialog__title">
					{ title }
				</BaseDialog.Title>
			</div>

			{ phase !== 'settled' && (
				<>
					<div className="sup-dialog__body sup-confirm__body">
						{ /* The tinted panel wraps the text rather than being it, so
						   axe can resolve the text's background. */ }
						<div className="sup-confirm__panel">
							<BaseDialog.Description
								render={ <div /> }
								className="sup-confirm__consequence"
							>
								{ consequence }
							</BaseDialog.Description>
						</div>
					</div>
					<div className="sup-dialog__footer">
						<Button
							ref={ cancelRef }
							aria-disabled={ pending ? true : undefined }
							onClick={ cancel }
						>
							{ cancelLabel ?? __( 'Cancel', 'supcheckout' ) }
						</Button>
						<Button
							variant={ CONFIRM_VARIANT[ tone ] }
							busy={ pending }
							busyLabel={
								busyLabel ??
								__(
									'Waiting for the result. Keep this window open.',
									'supcheckout'
								)
							}
							onClick={ () => {
								confirm();
							} }
						>
							{ confirmLabel }
						</Button>
					</div>
				</>
			) }

			{ phase === 'settled' && result && (
				<>
					<div
						id={ resultId }
						className={ `sup-dialog__body sup-confirm__result sup-confirm__result--${ result.status }` }
					>
						{ renderResult ? (
							renderResult( result )
						) : (
							<>
								<StatusPill
									status={ PILL_STATUS[ result.status ] }
								>
									{ statusLabel( result.status ) }
								</StatusPill>
								<p className="sup-confirm__message">
									{ result.message }
								</p>
							</>
						) }
					</div>
					<div className="sup-dialog__footer">
						<Button
							ref={ closeRef }
							variant="primary"
							aria-describedby={ resultId }
							onClick={ () => onOpenChange( false ) }
						>
							{ __( 'Close', 'supcheckout' ) }
						</Button>
					</div>
				</>
			) }
		</DialogFrame>
	);
}
