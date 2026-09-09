/**
 * Test runner. `npm test`.
 *
 * Why the parity check exists
 * ---------------------------
 * The same normalisation is implemented twice: utils/autofix.js runs in the MCP
 * server, plugin/includes/class-autofix.php runs in WordPress on the very same
 * content one hop later. When they disagree, the second one silently undoes the
 * first, and nothing anywhere reports it.
 *
 * That is not hypothetical. #17 made the PX_SAFE_KEYS allowlist path-aware in
 * the JS fixer only; the PHP fixer kept stripping icon.height on the way in, so
 * the merged fix did nothing at all until #20 mirrored it. Both PRs looked
 * correct in isolation, and both were.
 *
 * So: any change to one fixer must be made in the other, and this check is what
 * says so out loud.
 *
 * PHP is optional — the PHP-dependent checks skip with a notice if `php` is not
 * on PATH, so `npm test` still works for JS-only contributors. CI should install
 * PHP; skipping is a convenience, not a pass.
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const run = (cmd, args) => spawnSync(cmd, args, { cwd: here, encoding: 'utf8' });
const hasPhp = run('php', ['-v']).status === 0;

let failed = 0;
const report = (name, ok, detail) => {
  console.log(`${ok ? '  PASS' : '  FAIL'}  ${name}`);
  if (!ok) {
    failed++;
    if (detail) console.log(detail.replace(/^/gm, '        '));
  }
};

console.log('\nsyntax');
report('utils/autofix.js parses', run('node', ['--check', '../utils/autofix.js']).status === 0);
if (hasPhp) {
  for (const file of ['class-autofix.php', 'class-pages-controller.php']) {
    const r = run('php', ['-l', `../plugin/includes/${file}`]);
    report(`plugin/includes/${file} parses`, r.status === 0, r.stdout);
  }
}

console.log('\nautofix parity (JS fixer vs PHP fixer, same element)');
if (!hasPhp) {
  console.log('  SKIP  php not found on PATH');
} else {
  const js = run('node', ['autofix-parity.mjs']);
  const php = run('php', ['autofix-parity.php']);
  if (js.status !== 0 || php.status !== 0) {
    report('both fixers run', false, (js.stderr || '') + (php.stderr || ''));
  } else if (js.stdout !== php.stdout) {
    // Show the first differing line rather than two large blobs.
    const a = js.stdout.split('\n');
    const b = php.stdout.split('\n');
    const i = a.findIndex((line, n) => line !== b[n]);
    report(
      'JS and PHP fixers agree',
      false,
      `first difference at line ${i + 1}:\n  js:  ${a[i]}\n  php: ${b[i]}`
    );
  } else {
    report('JS and PHP fixers agree (preserve + normalize)', true);
  }
}

// Every tests/*.test.php file is picked up automatically. A test that has to be
// registered by hand is a test someone forgets to register — which is the same
// shape as the bug the parity check exists for.
console.log('\nPHP suites (tests/*.test.php)');
if (!hasPhp) {
  console.log('  SKIP  php not found on PATH');
} else {
  const suites = fs.readdirSync(here).filter((f) => f.endsWith('.test.php')).sort();
  if (suites.length === 0) report('at least one PHP suite exists', false);
  for (const suite of suites) {
    const r = run('php', [suite]);
    const summary = (r.stdout.trim().split('\n').pop() || '').trim();
    report(`${suite}: ${summary}`, r.status === 0, r.status === 0 ? '' : r.stdout);
  }
}

console.log(failed === 0 ? '\nall checks passed\n' : `\n${failed} check(s) failed\n`);
process.exit(failed === 0 ? 0 : 1);
