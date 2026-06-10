/**
 * Startup banner for the Bricks MCP server.
 *
 * IMPORTANT: everything is written to stderr (process.stderr) — stdout is
 * reserved for the JSON-RPC stdio protocol and must NEVER contain decoration.
 *
 * Two modes (chosen automatically):
 *   - TTY (real terminal: `node index.js`, inspector) → box animates in
 *   - No TTY (MCP client log capture, e.g. Claude Code) → static box, instant
 *
 * Override with the BRICKS_BANNER env var: "anim" | "static" | "off".
 */

const sleep = (ms) => new Promise((res) => setTimeout(res, ms));

// ANSI helpers
const ESC = '\x1b[';
const RESET = `${ESC}0m`;

// Accent colour — 256-colour code 191 is a bright yellow-green.
const BRAND = `${ESC}38;5;191m`;
const DIM = `${ESC}2m`;

// Figlet logo + the side label that sits next to each row.
const ART = [
  ' ____  ____  ___ ____ _  ______',
  '| __ )|  _ \\|_ _/ ___| |/ / ___|',
  '|  _ \\| |_) || | |   | \' /\\___ \\',
  '| |_) |  _ < | | |___| . \\ ___) |',
  '|____/|_| \\_\\___\\____|_|\\_\\____/',
];
const LABELS = ['', 'BRICKS', '', 'MCP', ''];

/**
 * Build the bordered box. Every line is padded to one common width, so
 * alignment is guaranteed regardless of label or version length.
 * (Version is shown in the status line below, not inside the box.)
 */
function buildBox() {
  const PAD = 1; // spaces between art and border
  const artW = Math.max(...ART.map((l) => l.length));
  const labelW = Math.max(...LABELS.map((l) => l.length));
  const inner = PAD + artW + 2 + labelW + PAD; // art + gap + label

  const body = ART.map((art, i) => {
    const row = `${' '.repeat(PAD)}${art.padEnd(artW)}  ${LABELS[i].padEnd(labelW)}${' '.repeat(PAD)}`;
    return `█${row.padEnd(inner)}█`;
  });
  const top = `▟${'█'.repeat(inner)}▙`;
  const bottom = `▜${'▀'.repeat(inner)}▛`;
  return [top, ...body, bottom];
}

const BOX = buildBox();

function statusLine(meta) {
  return `${DIM}  v${meta.version} · ${meta.toolCount} tools · ${meta.moduleCount ?? 17} modules · ● ${meta.site}${RESET}`;
}

/** Static box (always safe, also without a TTY). */
function printStatic(meta) {
  process.stderr.write('\n');
  for (const line of BOX) process.stderr.write(`${BRAND}${line}${RESET}\n`);
  process.stderr.write(`${statusLine(meta)}\n\n`);
}

/** Animated box: lines are "laid" like bricks (top-to-bottom reveal). */
async function printAnimated(meta) {
  process.stderr.write('\n');
  for (const line of BOX) {
    process.stderr.write(`${BRAND}${line}${RESET}\n`);
    await sleep(55);
  }
  process.stderr.write(`${statusLine(meta)}`);
  await sleep(90);
  process.stderr.write('\n\n');
}

/**
 * Public API. Detects a TTY and picks the matching mode.
 *
 * @param {{version:string, toolCount:number, moduleCount?:number, site:string}} meta
 */
export async function printBanner(meta) {
  const mode = process.env.BRICKS_BANNER
    ?? (process.stderr.isTTY ? 'anim' : 'static');

  if (mode === 'off') return;
  if (mode === 'anim') return printAnimated(meta);
  return printStatic(meta);
}
