/**
 * Card: a surface that groups one topic, with an optional heading and
 * actions.
 *
 * As a `section` (the default) with a `title`, the card is a region named by
 * its heading through `aria-labelledby`, so screen-reader users can jump to
 * it. Without a title, or as a `div`, it is a plain grouping box. Cards are
 * never nested: a card inside a card is a sign the layout needs a heading
 * instead.
 */
import type { ReactNode } from 'react';
import { useId } from '@wordpress/element';

import { Heading } from '../foundations/Heading';
import './Card.css';

export type CardHeadingLevel = 2 | 3 | 4;

export interface CardProps {
	/** Translated heading text. */
	title?: string;
	/** Document-outline level of the heading; defaults to 2. */
	headingLevel?: CardHeadingLevel;
	/** Controls shown at the inline end of the header, e.g. a Button. */
	actions?: ReactNode;
	/** `section` (a named region when titled) or a plain `div`. */
	as?: 'section' | 'div';
	children: ReactNode;
}

export function Card( {
	title,
	headingLevel = 2,
	actions,
	as: Element = 'section',
	children,
}: CardProps ) {
	const headingId = useId();
	const hasHeader = title !== undefined || actions !== undefined;
	const labelled = Element === 'section' && title !== undefined;
	return (
		<Element
			className="sup-card"
			aria-labelledby={ labelled ? headingId : undefined }
		>
			{ hasHeader && (
				<div className="sup-card__header">
					{ title !== undefined && (
						<Heading
							level={ headingLevel }
							size="md"
							id={ headingId }
						>
							{ title }
						</Heading>
					) }
					{ actions !== undefined && (
						<div className="sup-card__actions">{ actions }</div>
					) }
				</div>
			) }
			<div className="sup-card__body">{ children }</div>
		</Element>
	);
}
