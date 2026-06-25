const defaultConfig = require('@wordpress/scripts/config/jest-unit.config');

// jest-junit only activates during report runs (orchestrator sets the env var),
// so a normal `npm run test:js` stays clean. jest-junit reads JEST_JUNIT_*
// env vars for its output location.
const reporters = ['default'];
if (process.env.JEST_JUNIT_OUTPUT_DIR) {
    reporters.push('jest-junit');
}

module.exports = {
    ...defaultConfig,
    testEnvironment: 'jsdom',
    roots: ['<rootDir>/tests/js'],
    setupFiles: ['<rootDir>/tests/js/setup-globals.js'],
    reporters,
};
