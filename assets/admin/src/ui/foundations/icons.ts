/**
 * The SUPCheckout icon set: our names mapped to lucide-react (ISC) glyphs.
 *
 * Each glyph is a named import from lucide-react, whose package is
 * side-effect free, so only these icons reach the bundle. Directional icons
 * are named logically (start / end) and drawn for LTR; `Icon` mirrors them in
 * RTL.
 */
import {
	ArrowLeft,
	ArrowRight,
	Check,
	ChevronDown,
	ChevronLeft,
	ChevronRight,
	CircleCheck,
	CircleDashed,
	CircleX,
	Command,
	Copy,
	Ellipsis,
	ExternalLink,
	EyeOff,
	Inbox,
	Info,
	Lock,
	Monitor,
	Moon,
	RefreshCw,
	Search,
	Sun,
	TriangleAlert,
	X,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

export type IconName =
	| 'check'
	| 'close'
	| 'info'
	| 'warning'
	| 'error'
	| 'success'
	| 'search'
	| 'chevron-start'
	| 'chevron-end'
	| 'chevron-down'
	| 'arrow-start'
	| 'arrow-end'
	| 'external'
	| 'copy'
	| 'more'
	| 'sun'
	| 'moon'
	| 'monitor'
	| 'command'
	| 'refresh'
	| 'lock'
	| 'eye-off'
	| 'idle'
	| 'inbox';

export const ICONS: Readonly< Record< IconName, LucideIcon > > = {
	check: Check,
	close: X,
	info: Info,
	warning: TriangleAlert,
	error: CircleX,
	success: CircleCheck,
	search: Search,
	'chevron-start': ChevronLeft,
	'chevron-end': ChevronRight,
	'chevron-down': ChevronDown,
	'arrow-start': ArrowLeft,
	'arrow-end': ArrowRight,
	external: ExternalLink,
	copy: Copy,
	more: Ellipsis,
	sun: Sun,
	moon: Moon,
	monitor: Monitor,
	command: Command,
	refresh: RefreshCw,
	lock: Lock,
	'eye-off': EyeOff,
	idle: CircleDashed,
	inbox: Inbox,
};

export const ICON_NAMES = Object.freeze(
	Object.keys( ICONS ) as IconName[]
) as ReadonlyArray< IconName >;

/** Icons that point along the reading direction and mirror in RTL. */
export const DIRECTIONAL_ICONS: ReadonlySet< IconName > = new Set< IconName >( [
	'chevron-start',
	'chevron-end',
	'arrow-start',
	'arrow-end',
] );
