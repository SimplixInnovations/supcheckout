/**
 * Computed-style probes shared by the browser specs.
 */
import type { Page } from '@playwright/test';

/**
 * @param page     The page.
 * @param selector Element to read.
 * @param property A CSS property in kebab case, e.g. `min-height`.
 * @return The computed value of `property` on the first match.
 */
export function cssVar(
	page: Page,
	selector: string,
	property: string
): Promise< string > {
	return page.evaluate(
		( [ target, name ] ) => {
			const element = document.querySelector( target );
			if ( ! element ) {
				throw new Error( `no element matches ${ target }` );
			}
			return getComputedStyle( element ).getPropertyValue( name ).trim();
		},
		[ selector, property ] as const
	);
}

/**
 * @param page  The page.
 * @param name  A custom property such as `--sup-size-control-md`.
 * @param scope Element whose value is read (default: the app root).
 * @return The token's computed value.
 */
export function token(
	page: Page,
	name: string,
	scope = '#wpbody .supcheckout-admin'
): Promise< string > {
	return cssVar( page, scope, name );
}
