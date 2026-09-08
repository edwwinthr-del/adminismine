/*
 * Every literal key passed to `t()`, checked against the dictionary.
 *
 * Two failures this catches, both of which shipped before it existed:
 *
 *   - a key used in a component but never added, which renders on screen as
 *     `common.remove` — the fallback in `t()` is the key itself, so a missing
 *     translation is invisible in code review and obvious to the user;
 *   - a key added to `en` and forgotten in `sr` or `tr`, which silently falls
 *     back to English for everyone else.
 *
 * Template keys with an interpolation (`t(\`nav.${x}\`)`) are dynamic and
 * skipped — the composed-label helpers in `lib/labels.ts` and `lib/audit.ts`
 * check those themselves and fall back to a humanised value.
 */
import { readFileSync, readdirSync } from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const root = path.join(import.meta.dirname, '..', 'src');
const dictionary = readFileSync(path.join(root, 'lib/i18n/dictionaries.ts'), 'utf8');

const slice = (from, to) =>
  dictionary.slice(dictionary.indexOf(from), to ? dictionary.indexOf(to) : undefined);

const keysIn = (block) => new Set([...block.matchAll(/"([a-zA-Z0-9_.]+)":/g)].map((m) => m[1]));

const locales = {
  en: keysIn(slice('  en: {', '  sr: {')),
  sr: keysIn(slice('  sr: {', '  tr: {')),
  tr: keysIn(slice('  tr: {')),
};

function walk(dir, out = []) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, entry.name);
    if (entry.isDirectory()) walk(p, out);
    else if (/\.tsx?$/.test(entry.name) && !p.includes('dictionaries')) out.push(p);
  }
  return out;
}

const used = new Map();

for (const file of walk(root)) {
  for (const m of readFileSync(file, 'utf8').matchAll(/\bt\(\s*["'`]([a-zA-Z0-9_.]+)["'`]/g)) {
    if (!used.has(m[1])) used.set(m[1], path.relative(root, file).replace(/\\/g, '/'));
  }
}

const missing = [...used.keys()].filter((key) => !locales.en.has(key)).sort();
const partial = [...locales.en]
  .filter((key) => !locales.sr.has(key) || !locales.tr.has(key))
  .sort();

for (const key of missing) {
  console.error(`missing key   ${key}  (used in ${used.get(key)})`);
}

for (const key of partial) {
  const absent = ['sr', 'tr'].filter((l) => !locales[l].has(key));
  console.error(`untranslated  ${key}  (no ${absent.join(', ')})`);
}

if (missing.length || partial.length) {
  console.error(`\n${missing.length} missing, ${partial.length} untranslated.`);
  process.exit(1);
}

console.log(
  `i18n ok — ${used.size} literal keys used, `
  + `${locales.en.size} defined in each of en/sr/tr.`,
);
