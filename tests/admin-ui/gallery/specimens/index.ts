/**
 * The specimen groups: the single list the gallery frames and the visual
 * suite iterates (SP1 Task 8). Data only, so the Node coverage test, the
 * Playwright spec and the gallery all read the same list.
 *
 * A group is captured one of two ways:
 * - `frame`: the gallery wraps the group's specimens, in DOM order, in a
 *   `[data-specimen-group="<name>"]` frame on the canvas, and the baseline is
 *   that frame;
 * - `element`: there is no frame; the visual suite opens an overlay (dialog,
 *   confirmation, palette, toast, tooltip) or targets a part of the AppShell,
 *   and the baseline is that element.
 *
 * Every specimen of a view belongs to exactly one frame, except
 * UNGROUPED_SPECIMENS (behavioral fixtures with no visual of their own).
 * The gallery unit test proves the rendered frames match this list.
 */

export const GALLERY_VIEWS = [ 'components', 'overlays' ] as const;

export type GalleryView = ( typeof GALLERY_VIEWS )[ number ];

export interface SpecimenGroup {
	/** Baseline prefix and, for a frame, its `data-specimen-group` value. */
	readonly name: string;
	/** The gallery view (`?view=`) the group renders in. */
	readonly view: GalleryView;
	readonly capture: 'frame' | 'element';
	/** Specimens a frame wraps, in DOM order; empty for an element capture. */
	readonly specimens: readonly string[];
}

export const SPECIMEN_GROUPS = [
	{
		name: 'foundations-type',
		view: 'components',
		capture: 'frame',
		specimens: [
			'foundations-headings',
			'foundations-text',
			'foundations-numeric',
		],
	},
	{
		name: 'foundations-icons',
		view: 'components',
		capture: 'frame',
		specimens: [
			'foundations-icons',
			'foundations-spinner',
			'foundations-visually-hidden',
		],
	},
	{
		name: 'buttons',
		view: 'components',
		capture: 'frame',
		specimens: [ 'button-variants', 'button-states', 'icon-button' ],
	},
	{
		name: 'text-fields',
		view: 'components',
		capture: 'frame',
		specimens: [
			'text-input-default',
			'text-input-error',
			'textarea-default',
		],
	},
	{
		name: 'secret-inputs',
		view: 'components',
		capture: 'frame',
		specimens: [ 'secret-input-saved', 'secret-input-new' ],
	},
	{
		name: 'select-and-checkboxes',
		view: 'components',
		capture: 'frame',
		specimens: [ 'select-default', 'checkbox-checked', 'checkbox-states' ],
	},
	{
		name: 'radio-groups',
		view: 'components',
		capture: 'frame',
		specimens: [ 'radio-group-list', 'radio-group-segmented' ],
	},
	{
		name: 'toggles',
		view: 'components',
		capture: 'frame',
		specimens: [ 'toggle-default', 'theme-toggle' ],
	},
	{
		name: 'cards-and-status',
		view: 'components',
		capture: 'frame',
		specimens: [ 'card-default', 'badge-tones', 'status-pill-statuses' ],
	},
	{
		name: 'notices',
		view: 'components',
		capture: 'frame',
		specimens: [ 'notice-tones' ],
	},
	{
		name: 'live-notices',
		view: 'components',
		capture: 'frame',
		specimens: [ 'notice-live', 'notice-live-error' ],
	},
	{
		name: 'empty-and-loading',
		view: 'components',
		capture: 'frame',
		specimens: [ 'empty-state', 'skeleton-group' ],
	},
	{
		name: 'progress',
		view: 'components',
		capture: 'frame',
		specimens: [ 'progress-bar', 'stepper' ],
	},
	{
		name: 'feedback-triggers',
		view: 'components',
		capture: 'frame',
		specimens: [ 'toast-trigger', 'tooltip', 'focus-target-block-end' ],
	},
	{
		name: 'toast-open',
		view: 'components',
		capture: 'element',
		specimens: [],
	},
	{
		name: 'tooltip-open',
		view: 'components',
		capture: 'element',
		specimens: [],
	},
	{
		name: 'app-shell-header',
		view: 'overlays',
		capture: 'element',
		specimens: [],
	},
	{
		name: 'app-shell-nav',
		view: 'overlays',
		capture: 'element',
		specimens: [],
	},
	{
		name: 'overlay-triggers',
		view: 'overlays',
		capture: 'frame',
		specimens: [ 'dialog', 'confirm-dialog' ],
	},
	{
		name: 'tabs',
		view: 'overlays',
		capture: 'frame',
		specimens: [ 'tabs' ],
	},
	{
		name: 'data-table',
		view: 'overlays',
		capture: 'frame',
		specimens: [ 'data-table' ],
	},
	{
		name: 'data-table-states',
		view: 'overlays',
		capture: 'frame',
		specimens: [ 'data-table-loading', 'data-table-empty' ],
	},
	{
		name: 'dialog-open',
		view: 'overlays',
		capture: 'element',
		specimens: [],
	},
	{
		name: 'confirm-dialog-pending',
		view: 'overlays',
		capture: 'element',
		specimens: [],
	},
	{
		name: 'confirm-dialog-verified',
		view: 'overlays',
		capture: 'element',
		specimens: [],
	},
	{
		name: 'command-palette',
		view: 'overlays',
		capture: 'element',
		specimens: [],
	},
] as const satisfies readonly SpecimenGroup[];

/**
 * Groups captured in light-ltr only (EXTRA_GROUP_MODE). Their frames hold
 * content of their own rather than `[data-specimen]` sections, so the
 * browser suite's fixed specimen order is unchanged.
 */
export const EXTRA_GROUPS = [
	{
		name: 'focus-forced-colors',
		view: 'components',
		capture: 'frame',
		specimens: [],
	},
	{
		name: 'data-table-compact',
		view: 'overlays',
		capture: 'frame',
		specimens: [],
	},
] as const satisfies readonly SpecimenGroup[];

/**
 * Behavioral fixtures that belong to no group, per view: `focus-scroll` is
 * the long list the sticky-header focus test tabs through.
 */
export const UNGROUPED_SPECIMENS: Readonly<
	Record< GalleryView, readonly string[] >
> = {
	components: [],
	overlays: [ 'focus-scroll' ],
};

export type FrameName = Extract<
	( typeof SPECIMEN_GROUPS )[ number ] | ( typeof EXTRA_GROUPS )[ number ],
	{ capture: 'frame' }
>[ 'name' ];
