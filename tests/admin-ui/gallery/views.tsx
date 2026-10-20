/**
 * The two gallery views (test-only, never shipped), rendered inside the
 * gallery's SupRoot. `components` stacks the foundations, controls and
 * feedback specimens; `overlays` is the AppShell with the overlay specimens.
 * Each view ends with its light-ltr-only visual frame (EXTRA_GROUPS).
 * Exported so the gallery unit test renders exactly what the page renders.
 */
import { ControlsSpecimens } from './specimens/controls';
import { FeedbackSpecimens } from './specimens/feedback';
import { FocusForcedColorsFrame } from './specimens/focus';
import { FoundationsSpecimens } from './specimens/foundations';
import type { GalleryView } from './specimens/index';
import { OverlaysSpecimens } from './specimens/overlays';

interface GalleryContentProps {
	view: GalleryView;
}

export function GalleryContent( { view }: GalleryContentProps ) {
	if ( view === 'overlays' ) {
		return (
			<div data-gallery-ready="true">
				<OverlaysSpecimens />
			</div>
		);
	}
	return (
		<div
			data-gallery-ready="true"
			style={ {
				display: 'grid',
				gap: 'var(--sup-space-6)',
				padding: 'var(--sup-space-6)',
				justifyItems: 'start',
			} }
		>
			<FoundationsSpecimens />
			<ControlsSpecimens />
			<FeedbackSpecimens />
			<FocusForcedColorsFrame />
		</div>
	);
}
