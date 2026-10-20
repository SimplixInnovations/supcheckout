/**
 * Build-time globals.
 *
 * `@wordpress/scripts` defines `globalThis.SCRIPT_DEBUG` with webpack's
 * DefinePlugin: `false` in production builds, so development-only branches
 * (such as warnings) are dropped from the shipped bundle, as in
 * `@wordpress/warning`. Outside a build (Jest) it is undefined unless a test
 * sets it.
 */
// eslint-disable-next-line no-var -- a global must be a var to be read through globalThis.
declare var SCRIPT_DEBUG: boolean | undefined;
