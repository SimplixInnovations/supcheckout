/**
 * Search normalization for the command palette.
 *
 * Merchants type without diacritics, in either script, and often without
 * matching case: "cafe" must find "Café" and "تطبيق" must find "تَطْبِيـق".
 * Both the query and each candidate pass through `normalizeForSearch`, and
 * a candidate matches when its normalized text contains the normalized
 * query.
 */

/**
 * Combining marks left by NFKD (Latin accents and every other script's
 * combining marks), the Arabic tashkeel U+064B–U+065F and the superscript
 * alef U+0670, and the tatweel U+0640 (a stretch with no meaning).
 */
const IGNORED = /[\p{M}ً-ٰٟـ]/gu;

/**
 * @param text Any user-visible text or query.
 * @return The text decomposed (NFKD, so compatibility forms such as `ﬁ` or
 *         full-width letters fold too), without combining marks, tashkeel
 *         and tatweel, lowercased.
 */
export function normalizeForSearch( text: string ): string {
	return text.normalize( 'NFKD' ).replace( IGNORED, '' ).toLowerCase();
}

/**
 * @param haystacks The candidate's label and keywords.
 * @param query     What the user typed.
 * @return Whether any haystack contains the query, ignoring diacritics and
 *         case; an empty query matches everything.
 */
export function matchesSearch(
	haystacks: readonly string[],
	query: string
): boolean {
	const needle = normalizeForSearch( query.trim() );
	if ( needle === '' ) {
		return true;
	}
	return haystacks.some( ( text ) =>
		normalizeForSearch( text ).includes( needle )
	);
}
