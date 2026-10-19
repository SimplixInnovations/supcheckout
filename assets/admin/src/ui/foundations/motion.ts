/**
 * Media-query subscriptions shared by the theme and motion foundations.
 */
import { useCallback, useSyncExternalStore } from '@wordpress/element';

const REDUCED_MOTION_QUERY = '(prefers-reduced-motion: reduce)';

function mediaQueryList( query: string ): MediaQueryList | null {
	if ( typeof window === 'undefined' || ! window.matchMedia ) {
		return null;
	}
	return window.matchMedia( query );
}

/**
 * Subscribes to a media query and re-renders when it changes.
 *
 * @param query A media query such as `(prefers-color-scheme: dark)`.
 * @return Whether the query matches; false where `matchMedia` is missing.
 */
export function useMediaQuery( query: string ): boolean {
	const subscribe = useCallback(
		( onChange: () => void ) => {
			const list = mediaQueryList( query );
			if ( ! list ) {
				return () => undefined;
			}
			list.addEventListener( 'change', onChange );
			return () => list.removeEventListener( 'change', onChange );
		},
		[ query ]
	);
	const getSnapshot = () => mediaQueryList( query )?.matches ?? false;
	return useSyncExternalStore( subscribe, getSnapshot, () => false );
}

/**
 * @return Whether the user asked the system to reduce motion.
 */
export function usePrefersReducedMotion(): boolean {
	return useMediaQuery( REDUCED_MOTION_QUERY );
}
