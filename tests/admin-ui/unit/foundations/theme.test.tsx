/**
 * Theme preference, resolution and the system media queries (SP1 Task 3).
 */
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
	SupRoot,
	resolveTheme,
	usePrefersReducedMotion,
	useTheme,
} from '../../../../assets/admin/src/ui';
import { mockMatchMedia } from '../support/render';

function ThemeProbe() {
	const { preference, resolved, setPreference } = useTheme();
	return (
		<>
			<output data-testid="preference">{ preference }</output>
			<output data-testid="resolved">{ resolved }</output>
			<button type="button" onClick={ () => setPreference( 'dark' ) }>
				dark
			</button>
		</>
	);
}

const resolvedText = () => screen.getByTestId( 'resolved' ).textContent;

test( 'system preference resolves from the media query', () => {
	expect( resolveTheme( 'system', true ) ).toBe( 'dark' );
	expect( resolveTheme( 'system', false ) ).toBe( 'light' );
	expect( resolveTheme( 'light', true ) ).toBe( 'light' );
	expect( resolveTheme( 'dark', false ) ).toBe( 'dark' );
} );

test( 'SupRoot follows a system change while the preference is system', () => {
	const media = mockMatchMedia( false );
	render(
		<SupRoot preference="system">
			<ThemeProbe />
		</SupRoot>
	);
	expect( resolvedText() ).toBe( 'light' );
	act( () => media.change( true ) );
	expect( resolvedText() ).toBe( 'dark' );
} );

test( 'an explicit preference ignores system changes', () => {
	const media = mockMatchMedia( false );
	render(
		<SupRoot preference="light">
			<ThemeProbe />
		</SupRoot>
	);
	act( () => media.change( true ) );
	expect( resolvedText() ).toBe( 'light' );
} );

test( 'the default preference is match system', () => {
	mockMatchMedia( true );
	render(
		<SupRoot>
			<ThemeProbe />
		</SupRoot>
	);
	expect( screen.getByTestId( 'preference' ) ).toHaveTextContent( 'system' );
	expect( resolvedText() ).toBe( 'dark' );
} );

test( 'setPreference updates an uncontrolled root and reports the choice', async () => {
	mockMatchMedia( false );
	const onPreferenceChange = jest.fn();
	const { container } = render(
		<SupRoot onPreferenceChange={ onPreferenceChange }>
			<ThemeProbe />
		</SupRoot>
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'dark' } ) );
	expect( onPreferenceChange ).toHaveBeenCalledTimes( 1 );
	expect( onPreferenceChange ).toHaveBeenCalledWith( 'dark' );
	expect( resolvedText() ).toBe( 'dark' );
	expect( container.firstElementChild ).toHaveAttribute(
		'data-sup-theme',
		'dark'
	);
} );

test( 'a controlled root keeps the preference its owner passes', async () => {
	mockMatchMedia( false );
	const onPreferenceChange = jest.fn();
	render(
		<SupRoot preference="light" onPreferenceChange={ onPreferenceChange }>
			<ThemeProbe />
		</SupRoot>
	);
	await userEvent.click( screen.getByRole( 'button', { name: 'dark' } ) );
	expect( onPreferenceChange ).toHaveBeenCalledWith( 'dark' );
	expect( resolvedText() ).toBe( 'light' );
} );

test( 'without matchMedia the system resolves to light', () => {
	const original = window.matchMedia;
	// @ts-expect-error jsdom may lack matchMedia; the hook must not throw.
	delete window.matchMedia;
	try {
		render(
			<SupRoot>
				<ThemeProbe />
			</SupRoot>
		);
		expect( resolvedText() ).toBe( 'light' );
	} finally {
		window.matchMedia = original;
	}
} );

function MotionProbe() {
	return <output>{ usePrefersReducedMotion() ? 'reduce' : 'full' }</output>;
}

test( 'usePrefersReducedMotion follows the reduced-motion media query', () => {
	let matches = true;
	const listeners = new Set< () => void >();
	window.matchMedia = jest.fn( ( query: string ) => ( {
		get matches() {
			return query.includes( 'reduced-motion' ) && matches;
		},
		media: query,
		addEventListener: ( _type: string, listener: () => void ) =>
			listeners.add( listener ),
		removeEventListener: ( _type: string, listener: () => void ) =>
			listeners.delete( listener ),
	} ) ) as unknown as typeof window.matchMedia;
	render( <MotionProbe /> );
	expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'reduce' );
	act( () => {
		matches = false;
		listeners.forEach( ( listener ) => listener() );
	} );
	expect( screen.getByRole( 'status' ) ).toHaveTextContent( 'full' );
} );
