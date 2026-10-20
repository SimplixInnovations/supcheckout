// Types for the parts of jest-axe 11 the component tests use.
//
// jest-axe ships no declarations, and `@types/jest-axe` pins axe-core 3.x;
// these reuse the axe-core types already in the tree (jest-axe's own axe-core).

declare module 'jest-axe' {
	type AxeResults = import( 'axe-core' ).AxeResults;
	type RunOptions = import( 'axe-core' ).RunOptions;

	export interface JestAxeConfigureOptions extends RunOptions {
		globalOptions?: import( 'axe-core' ).Spec;
		impactLevels?: Array< 'minor' | 'moderate' | 'serious' | 'critical' >;
	}

	export type JestAxe = (
		html: Element | string,
		options?: RunOptions
	) => Promise< AxeResults >;

	export const configureAxe: ( options?: JestAxeConfigureOptions ) => JestAxe;

	export const axe: JestAxe;

	export const toHaveNoViolations: {
		toHaveNoViolations: ( results: AxeResults ) => {
			pass: boolean;
			message: () => string;
		};
	};
}

declare namespace jest {
	interface Matchers< R > {
		toHaveNoViolations: () => R;
	}
}
