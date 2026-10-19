/**
 * Text: body copy in one of four variants.
 */
import type { ReactNode } from 'react';

export type TextVariant = 'body' | 'muted' | 'caption' | 'strong';

export interface TextProps {
	variant?: TextVariant;
	as?: 'p' | 'span' | 'div';
	children: ReactNode;
}

export function Text( {
	variant = 'body',
	as: Element = 'p',
	children,
}: TextProps ) {
	return (
		<Element className={ `sup-text sup-text--${ variant }` }>
			{ children }
		</Element>
	);
}
