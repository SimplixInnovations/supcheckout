/**
 * Icon: one SVG glyph from the SUPCheckout set.
 *
 * Decorative by default (`aria-hidden`, not focusable). With a `label` it is
 * an image with that accessible name; the label must already be translated.
 * Directional icons mirror in RTL only.
 */
import type { SVGProps } from 'react';

import { useDirection } from './direction';
import { DIRECTIONAL_ICONS, ICONS } from './icons';
import type { IconName } from './icons';

export type IconSize = 16 | 20 | 24;

export interface IconProps {
	name: IconName;
	/** CSS pixel size of the square glyph. */
	size?: IconSize;
	/** Translated accessible name; omit for a decorative icon. */
	label?: string;
}

export function Icon( { name, size = 20, label }: IconProps ) {
	const direction = useDirection();
	const Glyph = ICONS[ name ];
	const mirrored = direction === 'rtl' && DIRECTIONAL_ICONS.has( name );
	const className = [
		'sup-icon',
		`sup-icon--${ size }`,
		mirrored ? 'sup-icon--mirrored' : '',
	]
		.filter( Boolean )
		.join( ' ' );
	const a11y: SVGProps< SVGSVGElement > = label
		? { role: 'img', 'aria-label': label }
		: { 'aria-hidden': true, focusable: false };
	return <Glyph size={ size } className={ className } { ...a11y } />;
}
