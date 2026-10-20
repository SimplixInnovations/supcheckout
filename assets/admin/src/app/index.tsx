/**
 * SUPCheckout admin app entry.
 *
 * Mounts `SupRoot` (the scoped root and its body-level portal layer) around
 * the AppShell. Sub-project 1 ships no screen yet, so the shell holds an
 * empty state and the command palette an empty registry; Sub-project 2 adds
 * the sections, the screens and their commands. The palette panel is the
 * lazy `command-palette` chunk, which keeps the chunk runtime
 * (`supCheckoutAdminChunks`, `publicPath: 'auto'`) and the `app.chunks.json`
 * manifest exercised.
 */
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import '../tokens/tokens.generated.css';
import { AppShell, EmptyState, SupRoot } from '../ui';
import type { Command, NavItem } from '../ui';
import './app.css';

const ROOT_ID = 'supcheckout-admin-root';
const NAV: readonly NavItem[] = [];
const COMMANDS: readonly Command[] = [];

function App() {
	return (
		<SupRoot>
			<AppShell
				title={ __( 'SUPCheckout', 'supcheckout' ) }
				nav={ NAV }
				commands={ COMMANDS }
			>
				<EmptyState
					icon="inbox"
					title={ __( 'Nothing to manage here yet', 'supcheckout' ) }
					description={ __(
						'The SUPCheckout dashboard arrives in a later update. Until then, manage UPayments in the WooCommerce payment settings.',
						'supcheckout'
					) }
				/>
			</AppShell>
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
