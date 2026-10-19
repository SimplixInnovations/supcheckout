/**
 * Theme preference (Light / Dark / Match system) and its resolution.
 *
 * "Match system" needs no JavaScript for styling: the token CSS follows
 * `prefers-color-scheme` unless `data-sup-theme` is set. JavaScript only
 * reports the resolved mode to components that need it.
 */
import { createContext, useContext } from '@wordpress/element';

import { useMediaQuery } from './motion';

export type ThemePreference = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

export interface ThemeContextValue {
	preference: ThemePreference;
	resolved: ResolvedTheme;
	setPreference: ( preference: ThemePreference ) => void;
}

const DARK_QUERY = '(prefers-color-scheme: dark)';

/**
 * @param preference        The manual preference.
 * @param systemPrefersDark Whether `prefers-color-scheme: dark` matches.
 * @return The mode the UI renders in.
 */
export function resolveTheme(
	preference: ThemePreference,
	systemPrefersDark: boolean
): ResolvedTheme {
	if ( preference === 'system' ) {
		return systemPrefersDark ? 'dark' : 'light';
	}
	return preference;
}

/**
 * @return Whether the system color scheme is dark, kept in sync.
 */
export function useSystemPrefersDark(): boolean {
	return useMediaQuery( DARK_QUERY );
}

export const ThemeContext = createContext< ThemeContextValue >( {
	preference: 'system',
	resolved: 'light',
	setPreference: () => undefined,
} );

/**
 * @return The preference, the resolved mode and a setter, from the nearest SupRoot.
 */
export function useTheme(): ThemeContextValue {
	return useContext( ThemeContext );
}
