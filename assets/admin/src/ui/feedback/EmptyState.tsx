/**
 * EmptyState: what a view will hold, why it is empty and how to fill it.
 *
 * Copy pattern (ux-copy): the title says what is missing ("No payments
 * yet"), the description says why and what happens next, and the optional
 * action is the one step that fills the view. The icon is decorative.
 */
import type { ReactNode } from 'react';

import { Heading } from '../foundations/Heading';
import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import './EmptyState.css';

export type EmptyStateHeadingLevel = 2 | 3;

export interface EmptyStateProps {
	/** Decorative icon above the title. */
	icon?: IconName;
	/** Translated title. */
	title: string;
	/** Translated explanation and next step. */
	description: string;
	/** Document-outline level of the title; defaults to 2. */
	headingLevel?: EmptyStateHeadingLevel;
	/** One call to action, e.g. a primary Button. */
	action?: ReactNode;
}

export function EmptyState( {
	icon,
	title,
	description,
	headingLevel = 2,
	action,
}: EmptyStateProps ) {
	return (
		<div className="sup-empty-state">
			{ icon && (
				<span className="sup-empty-state__icon">
					<Icon name={ icon } size={ 24 } />
				</span>
			) }
			<Heading level={ headingLevel } size="md">
				{ title }
			</Heading>
			<p className="sup-empty-state__description">{ description }</p>
			{ action !== undefined && (
				<div className="sup-empty-state__action">{ action }</div>
			) }
		</div>
	);
}
