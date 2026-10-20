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
 */
import { useEffect, useState } from '@wordpress/element';

/** Time an empty polite region stays empty before its text is inserted. */
export const LIVE_REGION_DELAY_MS = 100;

/**
 * @param enabled Whether to wait; false reports `true` at once (alerts).
 * @return False until LIVE_REGION_DELAY_MS after mount, then true.
 */
export function useLiveRegionReady( enabled: boolean ): boolean {
	const [ ready, setReady ] = useState( ! enabled );
	useEffect( () => {
		if ( ! enabled ) {
			setReady( true );
			return undefined;
		}
		const timer = setTimeout(
			() => setReady( true ),
			LIVE_REGION_DELAY_MS
		);
		return () => clearTimeout( timer );
	}, [ enabled ] );
	return ready;
}
