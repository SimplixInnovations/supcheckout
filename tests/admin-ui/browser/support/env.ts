/**
 * Environment of the real-WordPress browser lane.
 *
 * Defaults match scripts/install-wp-test-environment.sh (site URL and the
 * `cert-admin` test administrator) and the `sup-ar` test user the
 * admin-ui-certification workflow creates. All are test-only values for a
 * throwaway CI site on 127.0.0.1.
 */
import { resolve } from 'node:path';

export const BASE_URL = process.env.SUP_UI_BASE_URL ?? 'http://127.0.0.1:8080';

export const USERS = {
	admin: {
		login: process.env.SUP_UI_ADMIN_USER ?? 'cert-admin',
		password:
			process.env.SUP_UI_ADMIN_PASSWORD ?? 'cert-password-not-production',
	},
	arabic: {
		login: process.env.SUP_UI_AR_USER ?? 'sup-ar',
		password:
			process.env.SUP_UI_AR_PASSWORD ?? 'sup-ar-password-not-production',
	},
} as const;

export type UserKey = keyof typeof USERS;

const AUTH_DIR = resolve( __dirname, '..', '..', 'artifacts', '.auth' );

export const AUTH_FILES: Readonly< Record< UserKey, string > > = {
	admin: resolve( AUTH_DIR, 'admin.json' ),
	arabic: resolve( AUTH_DIR, 'arabic.json' ),
};

/** WordPress version of the site under test, from the CI matrix. */
export const WP_VERSION = process.env.SUP_UI_WP_VERSION ?? '';

export const SHELL = '/wp-admin/admin.php?page=supcheckout-ui-shell';

/**
 * @param theme Gallery theme preference.
 * @param extra Extra query parameters.
 * @return The gallery page path.
 */
export function galleryUrl(
	theme: 'light' | 'dark' | 'system' = 'system',
	extra: Record< string, string > = {}
): string {
	const query = new URLSearchParams( {
		page: 'supcheckout-ui-gallery',
		theme,
		...extra,
	} );
	return `/wp-admin/admin.php?${ query.toString() }`;
}
