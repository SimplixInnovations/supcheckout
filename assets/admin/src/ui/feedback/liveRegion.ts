/**
 * Live-region timing shared by Notice and SkeletonGroup.
 *
 * Screen readers announce a polite `status` region reliably only when its
 * content changes after the region is already in the accessibility tree; a
 * region inserted together with its text, or filled in the same frame, may
 * stay silent. The browser builds the accessibility tree asynchronously, so
 * one React commit or one animation frame is not enough. Components
 * therefore render the region empty, and this hook reports `true` only
 * LIVE_REGION_DELAY_MS later (the react-aria announcer pattern), when they
 * insert the text. The timer is cleared on unmount.
 *
 * `role="alert"` regions do not use the delay: assistive technology
 * announces an alert when it is inserted, with its content.
 *
 * Every SUPCheckout region also sets an explicit `aria-live` (`polite` on a
 * status, `assertive` on an alert). Behind an open modal Base UI hides
 * everything except `[aria-live]` regions and their ancestors, so a
 * role-only region would be silenced (see Dialog.tsx).
 */
import { useEffect, useState } from '@wordpress/element';

/** Time an empty polite region stays empty before its text is inserted. */
export const LIVE_REGION_DELAY_MS = 100;

/**
 * Each time `enabled` turns true (on mount, or when a region becomes live
 * later) a new cycle starts: readiness drops at once, in the same render,
 * and returns LIVE_REGION_DELAY_MS later.
 *
 * @param enabled Whether to wait; false reports `true` at once (alerts).
 * @return False until LIVE_REGION_DELAY_MS after `enabled` last turned
 *         true, then true; always true while `enabled` is false.
 */
export function useLiveRegionReady( enabled: boolean ): boolean {
	// The `enabled` value the region became ready for; null while waiting.
	const [ readyFor, setReadyFor ] = useState< boolean | null >(
		enabled ? null : false
	);
	useEffect( () => {
		if ( ! enabled ) {
			setReadyFor( false );
			return undefined;
		}
		setReadyFor( null );
		const timer = setTimeout(
			() => setReadyFor( true ),
			LIVE_REGION_DELAY_MS
		);
		return () => clearTimeout( timer );
	}, [ enabled ] );
	// Derived, not stored: a region that just turned live is never ready
	// in its first render, even before the effect above runs.
	return enabled ? readyFor === true : true;
}
