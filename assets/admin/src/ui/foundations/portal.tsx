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
 * @return The portal layer of the nearest SupRoot, from its first render on;
 *         null only outside a SupRoot.
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

function createLayer(): HTMLElement | null {
	if ( typeof document === 'undefined' ) {
		return null;
	}
	const element = document.createElement( 'div' );
	element.className = PORTAL_LAYER_CLASS;
	return element;
}

/**
 * Owns the layer: one element per SupRoot, created on the first render (so
 * `usePortalContainer()` is never null inside a mounted SupRoot), attached to
 * `document.body` while mounted, with its attributes kept equal to
 * `attributes`, and detached on unmount.
 *
 * Layout effects attach it and set its attributes before the first paint.
 * Under StrictMode the effects run, clean up and run again on the same
 * element, so exactly one layer stays attached.
 *
 * @param attributes The app root's scope attributes.
 * @return The layer element; null only where there is no document.
 */
export function usePortalLayer(
	attributes: ScopeAttributes
): HTMLElement | null {
	const [ layer ] = useState( createLayer );

	useLayoutEffect( () => {
		if ( ! layer ) {
			return undefined;
		}
		document.body.appendChild( layer );
		return () => {
			layer.remove();
		};
	}, [ layer ] );

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
