/**
 * Test-only UI gallery entry (never shipped).
 *
 * The mu-plugin page `admin.php?page=supcheckout-ui-gallery&theme=light|dark|system`
 * renders `#supcheckout-ui-gallery-root` with the validated preference in
 * `data-theme`. Every specimen renders inside one SupRoot, so it carries the
 * same scope, theme, direction and portal layer as the real app.
 *
 * `&view=overlays` renders the overlay specimens instead (SP1 Task 7): they
 * live inside an AppShell, a whole-page frame with a sticky header, so they
 * get their own view and the other specimens keep their order and layout.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot, useState } from '@wordpress/element';

import '../../../assets/admin/src/tokens/tokens.generated.css';
import { SupRoot } from '../../../assets/admin/src/ui';
import type { ThemePreference } from '../../../assets/admin/src/ui';
import { ControlsSpecimens } from './specimens/controls';
import { FeedbackSpecimens } from './specimens/feedback';
import { FoundationsSpecimens } from './specimens/foundations';
import { OverlaysSpecimens } from './specimens/overlays';

const ROOT_ID = 'supcheckout-ui-gallery-root';
const PREFERENCES: readonly ThemePreference[] = [ 'light', 'dark', 'system' ];

type View = 'components' | 'overlays';

function preferenceOf( element: HTMLElement ): ThemePreference {
	const requested = element.dataset.theme;
	return (
		PREFERENCES.find( ( preference ) => preference === requested ) ??
		'system'
	);
}

function viewOf(): View {
	return new URLSearchParams( window.location.search ).get( 'view' ) ===
		'overlays'
		? 'overlays'
		: 'components';
}

interface GalleryProps {
	preference: ThemePreference;
	view: View;
}

function Gallery( { preference: initial, view }: GalleryProps ) {
	// The theme query sets the first preference; ThemeToggle then changes it,
	// as a screen persisting the choice would.
	const [ preference, setPreference ] = useState( initial );
	return (
		<SupRoot preference={ preference } onPreferenceChange={ setPreference }>
			{ view === 'overlays' ? (
				<div data-gallery-ready="true">
					<OverlaysSpecimens />
				</div>
			) : (
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
				</div>
			) }
		</SupRoot>
	);
}

domReady( () => {
	const container = document.getElementById( ROOT_ID );
	if ( ! container ) {
		return;
	}
	createRoot( container ).render(
		<Gallery preference={ preferenceOf( container ) } view={ viewOf() } />
	);
} );
