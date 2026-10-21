/**
 * AppShell: the frame of every SUPCheckout screen inside wp-admin.
 *
 * - A "Skip to content" link first, to `#supcheckout-content`.
 * - A sticky `<header>` below the WordPress admin bar with the screen's
 *   `<h1>`, the screen actions, the command palette trigger and the
 *   ThemeToggle. On narrow screens (where WordPress lets its own admin bar
 *   scroll away) the header scrolls with the page.
 * - `<nav aria-label="SUPCheckout sections">` with the current section
 *   marked `aria-current="page"`; left out when there are no sections. A
 *   nav `href` must be relative (`admin.php?page=…`, `#section`) or use
 *   `http:` / `https:`; any other scheme (`javascript:`, `data:`, also
 *   disguised by case, spaces, tabs or control characters) renders the
 *   item as plain text, with a development warning (SCRIPT_DEBUG).
 * - The content in `<div id="supcheckout-content" tabIndex={-1}>`. It is
 *   not a `main` element and has no `role`: wp-admin already provides the
 *   one main landmark (`#wpbody[role=main]`).
 * - Focus scrolling (Tab, the skip link, `focus()`) leaves every element
 *   clear of the admin bar and the sticky header (WCAG 2.4.11): the shell
 *   sets `scroll-margin-block-start` on its content. The header's real
 *   height (it wraps on narrow screens or with long translations) is
 *   measured with a ResizeObserver into `--sup-app-shell-header-height`;
 *   the 64px token is the fallback until it is measured.
 */
import type { ReactNode } from 'react';
import { useEffect, useLayoutEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ThemeToggle } from '../controls/ThemeToggle';
import { classNames } from '../controls/classNames';
import { Heading } from '../foundations/Heading';
import { Icon } from '../foundations/Icon';
import type { IconName } from '../foundations/icons';
import { CommandPalette } from './CommandPalette';
import type { Command } from './CommandPalette';
import './AppShell.css';

export const CONTENT_ID = 'supcheckout-content';

export interface NavItem {
	id: string;
	/** Translated section name. */
	label: string;
	href: string;
	icon?: IconName;
}

export interface AppShellProps {
	/** Translated screen title, the page's `<h1>`. */
	title: string;
	/** Sections of the SUPCheckout admin; may be empty. */
	nav: readonly NavItem[];
	currentNavId?: string;
	/** Screen actions, e.g. a primary Button, before the palette trigger. */
	actions?: ReactNode;
	/** The palette registry; empty by default. */
	commands?: readonly Command[];
	children: ReactNode;
}

const NO_COMMANDS: readonly Command[] = [];

/** The only URL schemes a nav link may use; relative URLs have none. */
const SAFE_SCHEMES: ReadonlySet< string > = new Set( [ 'http', 'https' ] );

/**
 * Browsers drop leading C0 controls and spaces and every tab and newline
 * before they read a URL's scheme, so `\tjava\nscript:` still runs script.
 * Every such character is removed before the scheme is read.
 */
const URL_NOISE = /[\u0000- \u007f]+/g;
const SCHEME = /^([a-z][a-z\d+.-]*):/i;

/**
 * @param href A nav item's href.
 * @return Whether it is relative or uses http: or https:.
 */
function isSafeNavHref( href: string ): boolean {
	const scheme = SCHEME.exec( href.replace( URL_NOISE, '' ) );
	return scheme === null || SAFE_SCHEMES.has( scheme[ 1 ].toLowerCase() );
}

const HEADER_HEIGHT_PROPERTY = '--sup-app-shell-header-height';

/**
 * Keeps `--sup-app-shell-header-height` on the shell equal to the header's
 * rendered height, so focus scrolling clears a header that wraps.
 *
 * @return Refs for the shell and its header.
 */
function useMeasuredHeader() {
	const shellRef = useRef< HTMLDivElement >( null );
	const headerRef = useRef< HTMLElement >( null );
	useLayoutEffect( () => {
		const shell = shellRef.current;
		const header = headerRef.current;
		if ( ! shell || ! header || typeof ResizeObserver === 'undefined' ) {
			return undefined;
		}
		const measure = () => {
			const height = Math.ceil( header.getBoundingClientRect().height );
			if ( height > 0 ) {
				shell.style.setProperty(
					HEADER_HEIGHT_PROPERTY,
					`${ height }px`
				);
			}
		};
		const observer = new ResizeObserver( measure );
		observer.observe( header );
		measure();
		return () => {
			observer.disconnect();
			shell.style.removeProperty( HEADER_HEIGHT_PROPERTY );
		};
	}, [] );
	return { shellRef, headerRef };
}

export function AppShell( {
	title,
	nav,
	currentNavId,
	actions,
	commands = NO_COMMANDS,
	children,
}: AppShellProps ) {
	const { shellRef, headerRef } = useMeasuredHeader();
	useEffect( () => {
		if ( ! globalThis.SCRIPT_DEBUG ) {
			return;
		}
		for ( const item of nav ) {
			if ( ! isSafeNavHref( item.href ) ) {
				// eslint-disable-next-line no-console -- development-only guidance, dropped from production builds.
				console.warn(
					`SUPCheckout AppShell: the nav item "${ item.id }" has an href that is not relative, http: or https:, so it renders as text.`
				);
			}
		}
	}, [ nav ] );
	return (
		<div className="sup-app-shell" ref={ shellRef }>
			<a className="sup-skip-link" href={ `#${ CONTENT_ID }` }>
				{ __( 'Skip to content', 'supcheckout' ) }
			</a>
			<header className="sup-app-shell__header" ref={ headerRef }>
				<div className="sup-app-shell__title">
					<Heading level={ 1 } size="lg">
						{ title }
					</Heading>
				</div>
				<div className="sup-app-shell__tools">
					{ actions !== undefined && (
						<div className="sup-app-shell__actions">
							{ actions }
						</div>
					) }
					<CommandPalette commands={ commands } />
					<div className="sup-app-shell__theme">
						<ThemeToggle />
					</div>
				</div>
			</header>
			<div className="sup-app-shell__body">
				{ nav.length > 0 && (
					<nav
						className="sup-app-shell__nav"
						aria-label={ __(
							'SUPCheckout sections',
							'supcheckout'
						) }
					>
						<ul className="sup-app-shell__nav-list">
							{ nav.map( ( item ) => {
								const current = item.id === currentNavId;
								if ( ! isSafeNavHref( item.href ) ) {
									return (
										<li key={ item.id }>
											<span className="sup-app-shell__nav-link sup-app-shell__nav-link--text">
												{ item.icon && (
													<Icon
														name={ item.icon }
														size={ 16 }
													/>
												) }
												<span>{ item.label }</span>
											</span>
										</li>
									);
								}
								return (
									<li key={ item.id }>
										<a
											className={ classNames(
												'sup-app-shell__nav-link',
												current &&
													'sup-app-shell__nav-link--current'
											) }
											href={ item.href }
											aria-current={
												current ? 'page' : undefined
											}
										>
											{ item.icon && (
												<Icon
													name={ item.icon }
													size={ 16 }
												/>
											) }
											<span>{ item.label }</span>
										</a>
									</li>
								);
							} ) }
						</ul>
					</nav>
				) }
				<div
					id={ CONTENT_ID }
					className="sup-app-shell__content"
					tabIndex={ -1 }
				>
					{ children }
				</div>
			</div>
		</div>
	);
}
