/**
 * The frame of a specimen group (SP1 Task 8): a `[data-specimen-group]`
 * element on the canvas tone that wraps the group's specimens, so the visual
 * suite captures the group as one element with its own background and never
 * the wp-admin page behind it. The name must be a frame group of
 * SPECIMEN_GROUPS or EXTRA_GROUPS (./index.ts).
 */
import type { ReactNode } from 'react';

import type { FrameName } from './index';

interface SpecimenFrameProps {
	name: FrameName;
	children: ReactNode;
}

export function SpecimenFrame( { name, children }: SpecimenFrameProps ) {
	return (
		<div
			data-specimen-group={ name }
			style={ {
				display: 'grid',
				gap: 'var(--sup-space-6)',
				justifyItems: 'start',
				padding: 'var(--sup-space-4)',
				backgroundColor: 'var(--sup-color-bg-canvas)',
			} }
		>
			{ children }
		</div>
	);
}
