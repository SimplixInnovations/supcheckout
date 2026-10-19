/**
 * The body-level portal layer.
 *
 * Dialogs, toasts, tooltips and the palette render in `document.body`, outside
 * the app root. The layer is itself a scoped root (`.supcheckout-admin`) that
 * mirrors the app root's theme, direction, language and density, so portaled
 * overlays keep every token, dark mode, RTL and the Arabic stack. Its z-index
 * (`--sup-z-portal`) puts it above the WordPress admin bar (99999).
 */
import {
	createContext,
	useContext,
	useLayoutEffect,
	useState,
} from '@wordpress/element';

export const PORTAL_LAYER_CLASS = 'supcheckout-admin sup-portal-layer';

/** Attributes the layer copies from the app root; `undefined` removes one. */
export interface ScopeAttributes {
	'data-sup-theme'?: string;
	dir: string;
	lang?: string;
	'data-sup-density': string;
}

export const PortalContext = createContext< HTMLElement | null >( null );

/**
 * @return The portal layer of the nearest SupRoot, or null outside one (or
 *         before it mounts).
 */
export function usePortalContainer(): HTMLElement | null {
	return useContext( PortalContext );
}

function applyAttributes( element: HTMLElement, attributes: ScopeAttributes ) {
	for ( const [ name, value ] of Object.entries( attributes ) ) {
		if ( value === undefined ) {
			element.removeAttribute( name );
		} else {
			element.setAttribute( name, value );
		}
	}
}

/**
 * Creates the layer in `document.body`, keeps its attributes equal to
 * `attributes` and removes it on unmount.
 *
 * @param attributes The app root's scope attributes.
 * @return The layer element once mounted, else null.
 */
export function usePortalLayer(
	attributes: ScopeAttributes
): HTMLElement | null {
	const [ layer, setLayer ] = useState< HTMLElement | null >( null );

	// Layout effects: the layer and its attributes exist before the first
	// paint (setLayer in a layout effect re-renders synchronously).
	useLayoutEffect( () => {
		const element = document.createElement( 'div' );
		element.className = PORTAL_LAYER_CLASS;
		document.body.appendChild( element );
		setLayer( element );
		return () => {
			element.remove();
		};
	}, [] );

	const { dir, lang } = attributes;
	const theme = attributes[ 'data-sup-theme' ];
	const density = attributes[ 'data-sup-density' ];
	useLayoutEffect( () => {
		if ( layer ) {
			applyAttributes( layer, {
				'data-sup-theme': theme,
				dir,
				lang,
				'data-sup-density': density,
			} );
		}
	}, [ layer, theme, dir, lang, density ] );

	return layer;
}
