import js from '@eslint/js';
import { defineConfig } from 'eslint/config';
import pluginVue from 'eslint-plugin-vue';
import globals from 'globals';
import tseslint from 'typescript-eslint';

// 型の正しさは vue-tsc に任せ、ここでは type-aware rule を使わない。
export default defineConfig(
    { ignores: ['dist/', 'coverage/'] },
    js.configs.recommended,
    tseslint.configs.recommended,
    pluginVue.configs['flat/recommended'],
    {
        files: ['**/*.vue'],
        languageOptions: {
            parserOptions: { parser: tseslint.parser },
        },
        // .editorconfig（indent_size = 4）に合わせる
        rules: { 'vue/html-indent': ['warn', 4] },
    },
    {
        files: ['src/**/*.{ts,vue}'],
        languageOptions: { globals: globals.browser },
    },
    {
        files: ['*.config.{js,ts}'],
        languageOptions: { globals: globals.node },
    },
);
