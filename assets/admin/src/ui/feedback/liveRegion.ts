/**
 * Live-region timing shared by Notice and SkeletonGroup.
 *
 * Screen readers announce a `status` or `alert` region reliably only when its
 * content changes after the region is already in the accessibility tree; a
 * region inserted together with its text may stay silent. Components render
 * the region empty on their first commit and insert the text once this hook
 * reports `true`, one passive effect (one frame) later.
 */
import { useEffect, useState } from '@wordpress/element';

/** Time an empty polite region stays empty before its text is inserted. */
export const LIVE_REGION_DELAY_MS = 100;

/**
 * @return False on the first commit, true from the next render on.
 */
export function useAfterFirstCommit(): boolean {
	const [ committed, setCommitted ] = useState( false );
	useEffect( () => {
		setCommitted( true );
	}, [] );
	return committed;
}
