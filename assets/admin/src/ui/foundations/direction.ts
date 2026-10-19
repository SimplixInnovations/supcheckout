/**
 * Text direction of the admin locale.
 */
import { isRTL } from '@wordpress/i18n';

export type Direction = 'ltr' | 'rtl';

/**
 * @return `rtl` when the loaded WordPress locale is right-to-left.
 */
export function useDirection(): Direction {
	return isRTL() ? 'rtl' : 'ltr';
}
