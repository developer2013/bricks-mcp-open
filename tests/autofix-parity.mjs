/**
 * JS half of the autofix parity check.
 *
 * Prints the fixed settings of tests/fixtures/autofix-element.json as canonical
 * JSON. tests/autofix-parity.php prints the same thing from the PHP fixer, and
 * tests/run.mjs compares the two. See that file for why this exists.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { autofix } from '../utils/autofix.js';

const here = path.dirname(fileURLToPath(import.meta.url));
const fixture = () => JSON.parse(fs.readFileSync(path.join(here, 'fixtures/autofix-element.json'), 'utf8'));

// Key order is an artefact of each language's object handling, not behaviour.
const sortDeep = (v) => {
  if (Array.isArray(v)) return v.map(sortDeep);
  if (v && typeof v === 'object') {
    const out = {};
    for (const k of Object.keys(v).sort()) out[k] = sortDeep(v[k]);
    return out;
  }
  return v;
};

const out = {};
for (const mode of ['preserve', 'normalize']) {
  const settings = {};
  for (const el of autofix(fixture(), { mode }).content) settings[el.id] = sortDeep(el.settings);
  out[mode] = Object.fromEntries(Object.keys(settings).sort().map((k) => [k, settings[k]]));
}

console.log(JSON.stringify(out, null, 4));
