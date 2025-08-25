/**
 * ESLint Configuration
 * Description: Configuration file for ESLint.
 * https://eslint.org/docs/latest/use/configure/configuration-files
 * https://eslint.org/docs/latest/use/configure/migration-guide
 */

import globals from "globals";
// import eslintConfigPrettier from "eslint-config-prettier";
import eslintPluginPrettierRecommended from "eslint-plugin-prettier/recommended";

export default [
    {
        ignores: ["**/*.min.js", "**/*.config.js"],
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            "no-unused-expressions": "error",
            "no-unused-vars": "error",
            "prettier/prettier": [
                "error",
                {
                    endOfLine: "auto",
                },
            ],
        },
    },
    eslintPluginPrettierRecommended,
];
