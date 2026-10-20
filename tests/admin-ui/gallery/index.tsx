/**
 * Test-only UI gallery entry (never shipped).
 *
 * The mu-plugin page `admin.php?page=supcheckout-ui-gallery&theme=light|dark|system`
 * renders `#supcheckout-ui-gallery-root` with the validated preference in
 * `data-theme`. Every specimen renders inside one SupRoot, so it carries the
 * same scope, theme, direction and portal layer as the real app.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot, useState } from '@wordpress/element';

import '../../../assets/admin/src/tokens/tokens.generated.css';
import { SupRoot } from '../../../assets/admin/src/ui';
import type { ThemePreference } from '../../../assets/admin/src/ui';
import { ControlsSpecimens } from './specimens/controls';
import { FeedbackSpecimens } from './specimens/feedback';
import { FoundationsSpecimens } from './specimens/foundations';

const ROOT_ID = 'supcheckout-ui-gallery-root';
const PREFERENCES: readonly ThemePreference[] = [ 'light', 'dark', 'system' ];

function preferenceOf( element: HTMLElement ): ThemePreference {
	const requested = element.dataset.theme;
	return (
		PREFERENCES.find( ( preference ) => preference === requested ) ??
		'system'
	);
}

interface GalleryProps {
	preference: ThemePreference;
}

function Gallery( { preference: initial }: GalleryProps ) {
	// The theme query sets the first preference; ThemeToggle then changes it,
	// as a screen persisting the choice would.
	const [ preference, setPreference ] = useState( initial );
	return (
		<SupRoot preference={ preference } onPreferenceChange={ setPreference }>
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
		</SupRoot>
	);
}

domReady( () => {
	const container = document.getElementById( ROOT_ID );
	if ( ! container ) {
		return;
	}
	createRoot( container ).render(
		<Gallery preference={ preferenceOf( container ) } />
	);
} );
