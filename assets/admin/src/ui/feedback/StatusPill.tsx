/**
 * StatusPill: a state such as "Connected" or "Callback unreachable".
 *
 * The visible text names the state and a decorative icon with a distinct
 * shape per status backs it up, so color is never the only signal (WCAG
 * 1.4.1). The text is a plain translated string, which keeps the pill short
 * and lets screens reuse it as a table cell.
 */
import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import './StatusPill.css';

export type StatusPillStatus =
	'success' | 'warning' | 'danger' | 'info' | 'neutral';

export interface StatusPillProps {
	status: StatusPillStatus;
	/** Translated state text. */
	children: string;
}

const STATUS_ICONS: Readonly< Record< StatusPillStatus, IconName > > = {
	success: 'success',
	warning: 'warning',
	danger: 'error',
	info: 'info',
	neutral: 'idle',
};

export function StatusPill( { status, children }: StatusPillProps ) {
	return (
		<span className={ `sup-status-pill sup-status-pill--${ status }` }>
			<Icon name={ STATUS_ICONS[ status ] } size={ 16 } />
			<span className="sup-status-pill__text">{ children }</span>
		</span>
	);
}
