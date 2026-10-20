/**
 * The four visual-regression modes: color mode x direction. The Playwright
 * config generates one `visual-<mode>` project per entry, the visual spec
 * names every baseline `<group>--<mode>.png`, and the coverage test expects
 * one baseline per group and mode.
 */
export const VISUAL_MODES = [
	'light-ltr',
	'dark-ltr',
	'light-rtl',
	'dark-rtl',
] as const;

export type VisualMode = ( typeof VISUAL_MODES )[ number ];

/** The only mode the light-ltr-only groups (EXTRA_GROUPS) run in. */
export const EXTRA_GROUP_MODE: VisualMode = 'light-ltr';
