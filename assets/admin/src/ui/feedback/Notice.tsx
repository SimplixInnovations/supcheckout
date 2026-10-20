/**
 * Notice: an inline message about the current view, in four tones.
 *
 * A Notice is static by default: it is part of the page and read in order,
 * like a WordPress admin notice printed with the screen. Set `live` only for
 * a notice that appears in response to something the user did; then an
 * `error` is an assertive `alert` and every other tone a polite `status`.
 * The live region is rendered empty on the first commit and the message is
 * inserted one frame later, so it is announced as a change (see
 * liveRegion.ts). The dismiss control sits outside the region, so its name
 * is not part of the announcement.
 *
 * Each tone has its own icon, and the title and message are text, so the
 * tone never depends on color alone.
 */
import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';

import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { IconButton } from '../controls/IconButton';
import { useAfterFirstCommit } from './liveRegion';
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
	/** Translated message: what happened and what to do next. */
	children: ReactNode;
}

const TONE_ICONS: Readonly< Record< NoticeTone, IconName > > = {
	info: 'info',
	success: 'success',
	warning: 'warning',
	error: 'error',
};

export function Notice( {
	tone,
	title,
	live = false,
	onDismiss,
	children,
}: NoticeProps ) {
	const committed = useAfterFirstCommit();
	const showContent = ! live || committed;
	let role: 'alert' | 'status' | undefined;
	if ( live ) {
		role = tone === 'error' ? 'alert' : 'status';
	}
	return (
		<div className={ `sup-notice sup-notice--${ tone }` }>
			<span className="sup-notice__icon">
				<Icon name={ TONE_ICONS[ tone ] } size={ 20 } />
			</span>
			<div className="sup-notice__content" role={ role }>
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
					onClick={ onDismiss }
				/>
			) }
		</div>
	);
}
