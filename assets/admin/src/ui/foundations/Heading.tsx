/**
 * Heading: a document-outline level with an independent visual size.
 */
import type { ReactNode } from 'react';

export type HeadingLevel = 1 | 2 | 3 | 4;
export type HeadingSize = 'xl' | 'lg' | 'md' | 'sm';

export interface HeadingProps {
	level: HeadingLevel;
	/** Visual size; defaults to the size that matches the level. */
	size?: HeadingSize;
	children: ReactNode;
}

const DEFAULT_SIZE: Readonly< Record< HeadingLevel, HeadingSize > > = {
	1: 'xl',
	2: 'lg',
	3: 'md',
	4: 'sm',
};

export function Heading( { level, size, children }: HeadingProps ) {
	const Element = `h${ level }` as const;
	const visual = size ?? DEFAULT_SIZE[ level ];
	return (
		<Element className={ `sup-heading sup-heading--${ visual }` }>
			{ children }
		</Element>
	);
}
