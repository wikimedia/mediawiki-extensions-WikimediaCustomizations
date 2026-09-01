'use strict';

// For a detailed explanation regarding each configuration property, visit:
// https://jestjs.io/docs/en/configuration.html

module.exports = {
	testEnvironmentOptions: {
		customExportConditions: [ 'node', 'node-addons' ]
	},

	transform: {
		'^.+\\.vue$': '<rootDir>/node_modules/@vue/vue3-jest'
	},

	// Automatically clear mock calls and instances between every test
	clearMocks: true,

	// Indicates whether the coverage information should be collected while executing the test
	collectCoverage: true,

	// An array of glob patterns indicating a set of files fo
	//  which coverage information should be collected
	collectCoverageFrom: [
		'modules/DonorIdentification/**/*.(js|vue)'
	],

	// The directory where Jest should output its coverage files
	coverageDirectory: 'coverage',

	// An array of regexp pattern strings used to skip coverage collection
	coveragePathIgnorePatterns: [
		'/node_modules/',
		// Ignore ConfirmationDialog.vue since it's for a completed experiment. If this code is used
		// in the future tests should be written for it.
		'ext.wikimediaCustomizations.donorDelightBadge/ConfirmationDialog.vue'
	],

	// An object that configures minimum threshold enforcement for coverage results
	coverageThreshold: {
		global: {
			// Line 161 of the donor badge module has an unreachable branch:
			// the `total === 1` fallback in launchBurst() is dead code because
			// all burst definitions always contain more than one heart.
			branches: 80,
			functions: 90,
			lines: 95,
			statements: 95
		}
	},

	// A set of global variables that need to be available in all test environments
	globals: {
		'vue-jest': {
			babelConfig: false,
			hideStyleWarn: true,
			experimentalCSSCompile: true
		}
	},

	// An array of file extensions your modules use
	moduleFileExtensions: [
		'js',
		'json',
		'vue'
	],

	moduleNameMapper: {
		'codex\\.js$': '@wikimedia/codex'
	},

	testEnvironment: 'jsdom'
};
