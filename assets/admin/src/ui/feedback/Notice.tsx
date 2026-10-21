/**
 * Notice: an inline message about the current view, in four tones.
 *
 * A Notice is static by default: it is part of the page and read in order,
 * like a WordPress admin notice printed with the screen. Set `live` only for
 * a notice that appears in response to something the user did; then an
 * `error` is an assertive `alert` and every other tone a polite `status`.
 * A status region is rendered empty and its message is inserted
 * LIVE_REGION_DELAY_MS later, so it is announced as a change; an alert is
 * announced on insertion and renders its message at once (see
 * liveRegion.ts). The dismiss control sits outside the region, so its name
 * is not part of the announcement.
 *
 * Each tone has its own icon, and the title and message are text, so the
 * tone never depends on color alone.
 *
 * Dismissing moves focus before `onDismiss` runs, so it never falls to
 * `<body>` when the caller removes the notice (WCAG 2.4.3): to
 * `focusAfterDismiss` when given, otherwise to the first focusable element
 * after the notice, otherwise to the nearest focusable ancestor (the
 * AppShell content region is one).
 *
 * Rule for screens (SP2): pass `focusAfterDismiss` whenever the task goes on
 * somewhere other than the next element, for example the control the notice
 * reports on (the "Test connection" button of a connection error).
 */
import type { ReactNode, RefObject } from 'react';
import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { IconButton } from '../controls/IconButton';
import { useLiveRegionReady } from './liveRegion';
import './Notice.css';

export type NoticeTone = 'info' | 'success' | 'warning' | 'error';

export interface NoticeProps {
	tone: NoticeTone;
	/** Translated short summary, shown in bold before the message. */
	title?: string;
	/** Announce the notice when it appears (see the module comment). */
	live?: boolean;
	/** Shows a "Dismiss notice" icon button that calls this. */
	onDismiss?: () => void;
	/** Receives focus on dismissal; see the module comment for the default. */
	focusAfterDismiss?: RefObject< HTMLElement | null >;
	/** Translated message: what happened and what to do next. */
	children: ReactNode;
}

const TONE_ICONS: Readonly< Record< NoticeTone, IconName > > = {
	info: 'info',
	success: 'success',
	warning: 'warning',
	error: 'error',
};

const FOCUSABLE = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled]):not([type="hidden"])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
	'[contenteditable="true"]',
].join( ', ' );

const UNAVAILABLE = '[inert], [hidden], [aria-hidden="true"]';

/**
 * @param notice The notice's root element.
 * @return Where focus goes when the notice is dismissed without a target:
 *         the first focusable element after it, else the nearest focusable
 *         ancestor (`tabindex`, e.g. the AppShell content), else null.
 */
function focusTargetAfter( notice: HTMLElement ): HTMLElement | null {
	const candidates =
		notice.ownerDocument.querySelectorAll< HTMLElement >( FOCUSABLE );
	for ( const candidate of Array.from( candidates ) ) {
		if (
			! notice.contains( candidate ) &&
			// eslint-disable-next-line no-bitwise -- a DOM position bit mask.
			notice.compareDocumentPosition( candidate ) &
				Node.DOCUMENT_POSITION_FOLLOWING &&
			! candidate.closest( UNAVAILABLE )
		) {
			return candidate;
		}
	}
	return notice.parentElement?.closest< HTMLElement >( '[tabindex]' ) ?? null;
}

export function Notice( {
	tone,
	title,
	live = false,
	onDismiss,
	focusAfterDismiss,
	children,
}: NoticeProps ) {
	const rootRef = useRef< HTMLDivElement >( null );
	const dismiss = () => {
		const root = rootRef.current;
		const target =
			focusAfterDismiss?.current ?? ( root && focusTargetAfter( root ) );
		target?.focus();
		onDismiss?.();
	};
	let role: 'alert' | 'status' | undefined;
	let politeness: 'assertive' | 'polite' | undefined;
	if ( live ) {
		role = tone === 'error' ? 'alert' : 'status';
		politeness = tone === 'error' ? 'assertive' : 'polite';
	}
	// Only a polite status waits; an alert is announced on insertion.
	const ready = useLiveRegionReady( role === 'status' );
	const showContent = role !== 'status' || ready;
	return (
		<div ref={ rootRef } className={ `sup-notice sup-notice--${ tone }` }>
			<span className="sup-notice__icon">
				<Icon name={ TONE_ICONS[ tone ] } size={ 20 } />
			</span>
			<div
				className="sup-notice__content"
				role={ role }
				aria-live={ politeness }
			>
				{ showContent && (
					<>
						{ title !== undefined && (
							<p className="sup-notice__title">{ title }</p>
						) }
						<div className="sup-notice__message">{ children }</div>
					</>
				) }
			</div>
			{ onDismiss && (
				<IconButton
					className="sup-notice__dismiss"
					icon="close"
					size="sm"
					label={ __( 'Dismiss notice', 'supcheckout' ) }
					onClick={ dismiss }
				/>
			) }
		</div>
	);
}
