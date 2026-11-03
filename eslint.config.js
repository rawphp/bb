import tseslint from 'typescript-eslint';
import vueParser from 'vue-eslint-parser';
import noBusinessRules from './eslint/no-business-rules/index.js';

// Only the project's own CAP-001 rule runs; there is no style rule set.
export default [
    {
        files: ['resources/js/**/*.{ts,vue}'],
        ignores: ['resources/js/**/*.test.ts'],
        languageOptions: {
            parser: vueParser,
            parserOptions: { parser: tseslint.parser, ecmaVersion: 2022, sourceType: 'module', extraFileExtensions: ['.vue'] },
        },
        plugins: { bb: { rules: { 'no-business-rules': noBusinessRules } } },
        rules: { 'bb/no-business-rules': 'error' },
    },
    {
        files: ['resources/js/features/**/*.{ts,vue}'],
        ignores: ['resources/js/**/*.test.ts'],
        rules: { 'bb/no-business-rules': ['error', { payloadArithmetic: true }] },
    },
    {
        // The transport: the one place allowed to call fetch (CAP-004).
        files: ['resources/js/app/call.ts'],
        rules: { 'bb/no-business-rules': 'off' },
    },
    {
        // The chat's transport to the package's routes (AGT-021).
        files: ['resources/js/app/chatTransport.ts'],
        rules: { 'bb/no-business-rules': 'off' },
    },
];
