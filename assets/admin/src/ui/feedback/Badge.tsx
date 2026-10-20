/**
 * Badge: a short, quiet label or count, such as "New" or "3".
 *
 * A Badge carries no status meaning; a payment, connection or health state
 * is a StatusPill, which adds an icon so color is never the only signal.
 * Its content is an isolated run in tabular figures, so a count keeps its
 * order inside RTL text.
 */
import type { ReactNode } from 'react';

import './Badge.css';

export type BadgeTone = 'neutral' | 'accent';

export interface BadgeProps {
	/** `neutral` (default) or the admin accent for a highlight. */
	tone?: BadgeTone;
	/** Translated text or a number. */
	children: ReactNode;
}

export function Badge( { tone = 'neutral', children }: BadgeProps ) {
	return (
		<span className={ `sup-badge sup-badge--${ tone }` }>{ children }</span>
	);
}
