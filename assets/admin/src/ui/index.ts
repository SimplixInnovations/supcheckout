/**
 * The SUPCheckout admin UI library: the only import surface for screens.
 *
 * Screens import from here, never from Base UI, lucide-react or
 * `@wordpress/components` directly (ESLint `no-restricted-imports`).
 */
export { SupRoot } from './foundations/SupRoot';
export type { Density, SupRootProps } from './foundations/SupRoot';
export { resolveTheme, useTheme } from './foundations/theme';
export type {
	ResolvedTheme,
	ThemeContextValue,
	ThemePreference,
} from './foundations/theme';
export { useDirection } from './foundations/direction';
export type { Direction } from './foundations/direction';
export { usePortalContainer } from './foundations/portal';
export { usePrefersReducedMotion } from './foundations/motion';
export { Icon } from './foundations/Icon';
export type { IconProps, IconSize } from './foundations/Icon';
export { DIRECTIONAL_ICONS, ICON_NAMES } from './foundations/icons';
export type { IconName } from './foundations/icons';
export { VisuallyHidden } from './foundations/VisuallyHidden';
export type { VisuallyHiddenProps } from './foundations/VisuallyHidden';
export { Heading } from './foundations/Heading';
export type {
	HeadingLevel,
	HeadingProps,
	HeadingSize,
} from './foundations/Heading';
export { Text } from './foundations/Text';
export type { TextProps, TextVariant } from './foundations/Text';
export { Numeric } from './foundations/Numeric';
export type { NumericKind, NumericProps } from './foundations/Numeric';
export { Spinner } from './foundations/Spinner';
export type { SpinnerProps, SpinnerSize } from './foundations/Spinner';
