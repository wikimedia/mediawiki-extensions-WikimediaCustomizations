'use strict';

// For a detailed explanation regarding each configuration property, visit:
// https://jestjs.io/docs/en/configuration.html

module.exports = {
	// Automatically clear mock calls and instances between every test
	clearMocks: true,

	// Indicates whether the coverage information should be collected while executing the test
	collectCoverage: true,

	// An array of glob patterns indicating a set of files fo
	//  which coverage information should be collected
	collectCoverageFrom: [
		'modules/DonorIdentification/**/*.(js)'
	],

	// The directory where Jest should output its coverage files
	coverageDirectory: 'coverage',

	// An array of regexp pattern strings used to skip coverage collection
	coveragePathIgnorePatterns: [
		'/node_modules/'
	],

	// An object that configures minimum threshold enforcement for coverage results
	coverageThreshold: {
		global: {
			// Line 161 of the donor badge module has an unreachable branch:
			// the `total === 1` fallback in launchBurst() is dead code because
			// all burst definitions always contain more than one heart.
			branches: 95,
			functions: 100,
			lines: 100,
			statements: 100
		}
	},

	// An array of file extensions your modules use
	moduleFileExtensions: [
		'js',
		'json'
	],

	testEnvironment: 'jsdom'
};
