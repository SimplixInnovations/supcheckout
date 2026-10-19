/**
 * SupRoot: the scoped root of every SUPCheckout admin surface.
 *
 * Renders `<div class="supcheckout-admin">` with `dir`, `lang`,
 * `data-sup-density` and, for an explicit Light or Dark choice only,
 * `data-sup-theme`. It owns the body-level portal layer, which mirrors those
 * attributes, and gives Base UI the same direction through
 * `DirectionProvider`.
 */
import type { ReactNode } from 'react';
import { DirectionProvider } from '@base-ui/react/direction-provider';
import { useCallback, useMemo, useState } from '@wordpress/element';

import { useDirection } from './direction';
import { PortalContext, usePortalLayer } from './portal';
import type { ScopeAttributes } from './portal';
import { ThemeContext, resolveTheme, useSystemPrefersDark } from './theme';
import type { ThemeContextValue, ThemePreference } from './theme';
import './foundations.css';

export type Density = 'comfortable' | 'compact';

export interface SupRootProps {
	/** The theme preference. Omit it to let SupRoot hold the preference itself. */
	preference?: ThemePreference;
	/** Called with every preference a component sets through `useTheme()`. */
	onPreferenceChange?: ( preference: ThemePreference ) => void;
	/** Control and table-row density; never a user setting. */
	density?: Density;
	children: ReactNode;
}

function documentLanguage(): string | undefined {
	if ( typeof document === 'undefined' ) {
		return undefined;
	}
	return document.documentElement.lang || undefined;
}

export function SupRoot( {
	preference: controlledPreference,
	onPreferenceChange,
	density = 'comfortable',
	children,
}: SupRootProps ) {
	const [ ownPreference, setOwnPreference ] =
		useState< ThemePreference >( 'system' );
	const preference = controlledPreference ?? ownPreference;
	const isControlled = controlledPreference !== undefined;
	const systemPrefersDark = useSystemPrefersDark();
	const resolved = resolveTheme( preference, systemPrefersDark );
	const direction = useDirection();
	const lang = documentLanguage();

	const setPreference = useCallback(
		( next: ThemePreference ) => {
			if ( ! isControlled ) {
				setOwnPreference( next );
			}
			onPreferenceChange?.( next );
		},
		[ isControlled, onPreferenceChange ]
	);

	const theme = useMemo< ThemeContextValue >(
		() => ( { preference, resolved, setPreference } ),
		[ preference, resolved, setPreference ]
	);

	const attributes: ScopeAttributes = {
		'data-sup-theme': preference === 'system' ? undefined : preference,
		dir: direction,
		lang,
		'data-sup-density': density,
	};
	const layer = usePortalLayer( attributes );

	return (
		<ThemeContext.Provider value={ theme }>
			<DirectionProvider direction={ direction }>
				<PortalContext.Provider value={ layer }>
					<div className="supcheckout-admin" { ...attributes }>
						{ children }
					</div>
				</PortalContext.Provider>
			</DirectionProvider>
		</ThemeContext.Provider>
	);
}
