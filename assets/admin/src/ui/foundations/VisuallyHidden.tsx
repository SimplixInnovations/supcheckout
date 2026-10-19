/**
 * VisuallyHidden: text for assistive technology only.
 */
import type { ReactNode } from 'react';

export interface VisuallyHiddenProps {
	children: ReactNode;
	as?: 'span' | 'div';
}

export function VisuallyHidden( {
	children,
	as: Element = 'span',
}: VisuallyHiddenProps ) {
	return <Element className="sup-visually-hidden">{ children }</Element>;
}
