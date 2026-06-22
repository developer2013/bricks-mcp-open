/**
 * css-classify — decide whether a raw CSS string is "infra" (legitimately fine in
 * plugin-private page-assets) or "editable" (human-editable layout/responsive/visual
 * CSS that belongs in a Bricks-NATIVE channel so it stays editable in the builder).
 *
 * WHY: `_bab_page_assets.css` is injected at `wp_head` priority 9997 — BEFORE element
 * `_cssCustom` (9999) and global CSS (9998) — and has NO Bricks editing UI. Writing
 * editable CSS there silently overrides what the builder shows ("the builder lies"):
 * a human (or a future agent) who opens Bricks to fix a responsive issue can't find
 * the rule and can't override it. See memory feedback-agent-css-must-stay-bricks-editable
 * and bricks-mcp/sessions/2026-06-22-css-editability-enforcement-plan.md.
 *
 * This is a heuristic, NOT a real CSS parser. It leans toward 'editable' on
 * uncertainty so the guard warns rather than misses — warning is non-destructive
 * (zero-breakage). Shared by the MCP guard (tools/pages.js) and is the reference
 * shape for the future PHP port in the bridge (Layer 3).
 *
 * Classification rules (per top-level construct):
 *   infra    → @font-face, @keyframes, @import, @charset, and selector blocks whose
 *              declarations are EXCLUSIVELY CSS custom properties (`--token: …`,
 *              e.g. `:root{--brand:#fff}`). @media/@supports/@layer/@container wrapping
 *              only-infra content stays infra.
 *   editable → any normal selector block with at least one non-custom-property
 *              declaration (layout/box/typography/color/etc.), and any @media (and
 *              friends) that wraps editable content — the responsive case is the
 *              canonical reason this guard exists.
 */

/**
 * Split CSS into top-level constructs, respecting nested braces (so @keyframes /
 * @media bodies stay attached to their header).
 * @param {string} css
 * @returns {string[]}
 */
function topLevelSegments(css) {
  const segs = [];
  let depth = 0;
  let start = 0;
  for (let i = 0; i < css.length; i++) {
    const c = css[i];
    if (c === '{') {
      depth++;
    } else if (c === '}') {
      depth--;
      if (depth <= 0) {
        depth = 0;
        const s = css.slice(start, i + 1).trim();
        if (s) segs.push(s);
        start = i + 1;
      }
    } else if (c === ';' && depth === 0) {
      // top-level statement with no block, e.g. `@import url(…);`
      const s = css.slice(start, i + 1).trim();
      if (s) segs.push(s);
      start = i + 1;
    }
  }
  const tail = css.slice(start).trim();
  if (tail) segs.push(tail);
  return segs;
}

/**
 * True when a declaration body contains only CSS custom-property definitions
 * (or nothing) — i.e. `--x: …; --y: …`. These are design tokens = infra.
 * @param {string} body declarations between the outermost braces
 */
function declarationsAreCustomPropsOnly(body) {
  const decls = body.split(';').map((d) => d.trim()).filter(Boolean);
  if (decls.length === 0) return true; // empty block has no visible effect
  for (const d of decls) {
    const colon = d.indexOf(':');
    if (colon === -1) continue; // not a real declaration; ignore
    const key = d.slice(0, colon).trim();
    if (key && !key.startsWith('--')) return false;
  }
  return true;
}

/** Short human-readable label for a segment (its prelude / first chars). */
function label(seg) {
  const s = seg.trim().replace(/\s+/g, ' ');
  const brace = s.indexOf('{');
  const head = (brace === -1 ? s : s.slice(0, brace)).trim();
  return (head || s).slice(0, 60);
}

/**
 * @param {string} seg a single top-level construct
 * @returns {'infra'|'editable'|null} null = neutral/ignored (no visible effect)
 */
function classifySegment(seg) {
  const s = seg.trim();
  if (!s) return null;
  const brace = s.indexOf('{');
  if (brace === -1) {
    // statement with no block
    if (/^@import\b/i.test(s)) return 'infra';
    if (/^@charset\b/i.test(s)) return 'infra';
    return null; // stray top-level declaration — ignore
  }
  const prelude = s.slice(0, brace).trim();
  const body = s.slice(brace + 1, s.lastIndexOf('}'));

  if (/^@font-face\b/i.test(prelude)) return 'infra';
  if (/^@(-[a-z]+-)?keyframes\b/i.test(prelude)) return 'infra';

  if (/^@(media|supports|layer|container)\b/i.test(prelude)) {
    // Recurse: a query wrapping ONLY infra stays infra; otherwise it's editable
    // (responsive layout/visual overrides are the canonical editable case).
    const inner = classifyCss(body);
    return inner.kind === 'editable' || inner.kind === 'mixed' ? 'editable' : 'infra';
  }

  if (prelude.startsWith('@')) {
    // Other at-rules (@page, @property, …) — be conservative, treat as editable.
    return 'editable';
  }

  // Normal selector block: editable unless it is custom-properties only.
  return declarationsAreCustomPropsOnly(body) ? 'infra' : 'editable';
}

/**
 * Classify a raw CSS string (no <style> wrapper).
 * @param {string} css
 * @returns {{kind:'empty'|'infra'|'editable'|'mixed', editable:string[], infra:string[]}}
 */
export function classifyCss(css) {
  if (typeof css !== 'string' || !css.trim()) {
    return { kind: 'empty', editable: [], infra: [] };
  }
  const clean = css.replace(/\/\*[\s\S]*?\*\//g, ''); // strip comments
  const editable = [];
  const infra = [];
  for (const seg of topLevelSegments(clean)) {
    const t = classifySegment(seg);
    if (t === 'editable') editable.push(label(seg));
    else if (t === 'infra') infra.push(label(seg));
  }
  let kind;
  if (editable.length && infra.length) kind = 'mixed';
  else if (editable.length) kind = 'editable';
  else if (infra.length) kind = 'infra';
  else kind = 'empty';
  return { kind, editable, infra };
}

/**
 * Build the advisory/blocking message for a flagged CSS payload.
 * @param {ReturnType<typeof classifyCss>} cls
 * @returns {string}
 */
export function formatEditableCssWarning(cls) {
  const sample = cls.editable.slice(0, 4).map((s) => `"${s}"`).join(', ');
  const more = cls.editable.length > 4 ? ` (+${cls.editable.length - 4} more)` : '';
  return (
    `⚠️ EDITABILITY: this CSS is stored in _bab_page_assets (injected at wp_head 9997, ` +
    `BEFORE element CSS) — it is INVISIBLE and not editable in the Bricks builder and ` +
    `silently overrides the UI. Editable rules detected: ${sample}${more}. → Move ` +
    `layout/responsive/visual CSS to a Bricks-NATIVE channel: element _cssCustom ` +
    `(bricks_patch_page), OR page customCss (bricks_update_page_settings ` +
    `{settings:{customCss:"…"}}), OR global CSS/classes. Reserve this field for true ` +
    `infra (@font-face / :root{--vars} / @keyframes / critical) — use css_critical/` +
    `css_deferred for that. (To hard-block instead of warn: BRICKS_MCP_BLOCK_EDITABLE_CSS=1)`
  );
}
