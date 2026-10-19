/**
 * SUPCheckout admin app entry.
 *
 * Mounts `SupRoot` (the scoped root and its body-level portal layer) around a
 * placeholder body. Later Sub-project 1 tasks replace the placeholder with
 * `<AppShell />`. The placeholder body is a lazy chunk so the build already
 * exercises the chunk runtime (`supCheckoutAdminChunks`, `publicPath: 'auto'`)
 * and the `app.chunks.json` manifest.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot, lazy, Suspense } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import '../tokens/tokens.generated.css';
import { SupRoot } from '../ui';
import './app.css';

const ROOT_ID = 'supcheckout-admin-root';

const Placeholder = lazy( () => import( './Placeholder' ) );

function App() {
	return (
		<SupRoot>
			<Suspense fallback={ null }>
				<Placeholder title={ __( 'SUPCheckout', 'supcheckout' ) } />
			</Suspense>
		</SupRoot>
	);
}

domReady( () => {
	const container = document.getElementById( ROOT_ID );
	if ( ! container ) {
		return;
	}
	createRoot( container ).render( <App /> );
} );
