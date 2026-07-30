import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,
  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
  {
    rules: {
      // Mount-time initializers (reading the persisted locale from localStorage,
      // kicking off the /me auth load) are intentional, SSR-safe effects: they
      // render the default on the server and first client paint, then update
      // after mount to avoid a hydration mismatch. This heuristic over-flags
      // them, so keep it a warning rather than a build-blocking error.
      "react-hooks/set-state-in-effect": "warn",
    },
  },
]);

export default eslintConfig;
