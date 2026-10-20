/**
 * Joins class names, skipping empty, false and undefined parts.
 *
 * @param parts Class names or falsy placeholders.
 * @return The space-separated class list.
 */
export function classNames(
	...parts: Array< string | false | null | undefined >
): string {
	return parts.filter( Boolean ).join( ' ' );
}

/**
 * Joins `aria-describedby` id references.
 *
 * @param ids Element ids or falsy placeholders.
 * @return The id list, or undefined when there is none (no empty attribute).
 */
export function describedBy(
	...ids: Array< string | false | null | undefined >
): string | undefined {
	const list = classNames( ...ids );
	return list === '' ? undefined : list;
}
