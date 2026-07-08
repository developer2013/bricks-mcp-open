/**
 * Bricks Builder JSON Auto-Fixer
 *
 * Repairs common structural issues in Bricks element arrays before validation.
 * 10 fix passes run in order — each is idempotent, so valid JSON passes through unchanged.
 *
 * Pass 10 (learnings): Applies stored CSS fixes from the learning system.
 * Requires learnings to be passed in — autofix itself is pure (no API calls).
 */

import { isValidBricksId } from './validator.js';

/**
 * Lenient ID check for autofix — accepts any lowercase alphanumeric string (3-12 chars).
 * The strict 6-char check is in the validator. Many existing presets use 5-char or
 * 7-char IDs that work fine in Bricks.
 *
 * NOTE: no digit requirement. All-letter IDs (e.g. "wmhqxu") are valid in Bricks and
 * common on cloned/legacy sites. Regenerating them on save orphaned #brxe-<id> CSS
 * selectors and queryId/filterQueryId references (issue #13). We now keep any
 * structurally-valid ID and only regenerate genuinely broken ones (missing / wrong
 * charset / out of range) — and when we do, rewriteIdReferences() fixes every reference.
 */
function isAcceptableBricksId(id) {
  if (typeof id !== 'string') return false;
  if (id.length < 3 || id.length > 12) return false;
  if (!/^[a-z0-9]+$/.test(id)) return false;
  return true;
}

/**
 * Keys whose string values should never be stripped of "px".
 */
const PX_SAFE_KEYS = new Set([
  'content', '_cssCustom', 'url', 'name', 'label', 'tag',
  'link', 'href', 'src', 'alt', 'placeholder', 'icon', 'class',
  'text', 'title', 'description',
]);

/**
 * Flex-related settings that indicate a block should be a container.
 */
const FLEX_PROPERTIES = new Set([
  '_direction', '_alignItems', '_justifyContent', '_gap',
  '_flexWrap', '_alignContent',
]);

/**
 * Generate a random 6-char lowercase alphanumeric ID with at least one digit.
 */
function generateBricksId(existingIds) {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const digits = '0123456789';
  let id;
  let attempts = 0;
  do {
    id = '';
    for (let i = 0; i < 6; i++) {
      id += chars[Math.floor(Math.random() * chars.length)];
    }
    // Ensure at least one digit
    if (!/[0-9]/.test(id)) {
      const pos = Math.floor(Math.random() * 6);
      id = id.substring(0, pos) + digits[Math.floor(Math.random() * 10)] + id.substring(pos + 1);
    }
    attempts++;
  } while (existingIds.has(id) && attempts < 100);
  existingIds.add(id);
  return id;
}

/**
 * Settings keys whose string value is a raw element-ID reference (not a `brxe-` selector).
 * When an element ID is regenerated, these must be rewritten too or the loop/filter/
 * pagination linkage breaks silently (issue #13).
 */
const ID_REF_KEYS = new Set(['queryId', 'filterQueryId', '_cssId']);

function escapeRegExp(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * Recursively rewrite one occurrence of an element ID inside a settings value.
 * Covers two shapes:
 *   1. `brxe-<oldId>` tokens in any string (e.g. `#brxe-<id>` / `.brxe-<id>` in _cssCustom).
 *   2. Exact ID-reference fields (queryId, filterQueryId, _cssId) whose value === oldId.
 * A negative lookahead on the token guards against clobbering a longer ID that shares a prefix.
 */
function rewriteIdInValue(value, key, oldId, newId, brxeRe) {
  if (typeof value === 'string') {
    let out = value;
    if (out.indexOf('brxe-' + oldId) !== -1) out = out.replace(brxeRe, 'brxe-' + newId);
    if (ID_REF_KEYS.has(key) && out === oldId) out = newId;
    return out;
  }
  if (Array.isArray(value)) {
    return value.map((v) => rewriteIdInValue(v, key, oldId, newId, brxeRe));
  }
  if (value && typeof value === 'object') {
    const res = {};
    for (const [k, v] of Object.entries(value)) res[k] = rewriteIdInValue(v, k, oldId, newId, brxeRe);
    return res;
  }
  return value;
}

/**
 * Rewrite EVERY reference to an old element ID after it is regenerated:
 * structural refs (parent / children) plus all in-settings references —
 * `brxe-<id>` selectors/classes, queryId/filterQueryId/_cssId fields, and
 * the same inside component-instance `properties`. Prevents orphaned CSS and
 * broken query/filter linkage (issue #13). Call BEFORE assigning the new ID.
 */
function rewriteIdReferences(content, oldId, newId) {
  const brxeRe = new RegExp('brxe-' + escapeRegExp(oldId) + '(?![a-z0-9])', 'g');
  for (const el of content) {
    if (!el) continue;
    if (el.parent === oldId) el.parent = newId;
    if (Array.isArray(el.children)) {
      const idx = el.children.indexOf(oldId);
      if (idx !== -1) el.children[idx] = newId;
    }
    if (el.settings && typeof el.settings === 'object') {
      el.settings = rewriteIdInValue(el.settings, '', oldId, newId, brxeRe);
    }
    if (el.properties && typeof el.properties === 'object') {
      el.properties = rewriteIdInValue(el.properties, '', oldId, newId, brxeRe);
    }
  }
}

/**
 * Recursively strip bare "px" values from settings.
 * "80px" → "80", but leaves _cssCustom, content, text, etc. untouched.
 */
function stripPxValues(obj, currentKey = '', log, elementId) {
  if (obj === null || obj === undefined) return obj;

  if (typeof obj === 'string') {
    if (!PX_SAFE_KEYS.has(currentKey) && /^\d+px$/.test(obj)) {
      const fixed = obj.replace(/px$/, '');
      log.push(`Stripped px: "${obj}" → "${fixed}" on element ${elementId}, key "${currentKey}"`);
      return fixed;
    }
    return obj;
  }

  if (Array.isArray(obj)) {
    return obj.map((item, i) => stripPxValues(item, currentKey, log, elementId));
  }

  if (typeof obj === 'object') {
    const result = {};
    for (const [key, value] of Object.entries(obj)) {
      result[key] = stripPxValues(value, key, log, elementId);
    }
    return result;
  }

  return obj;
}

/**
 * Known CSS fixes for common Bricks issues — applied when no learnings are available.
 * These are the hard-won fixes from 12+ rebuilds documented in CLAUDE.md.
 */
/**
 * Builtin CSS fixes with condition functions.
 * Each fix only applies when its `when()` function returns true for the element.
 * This prevents blind injection of CSS on every element of that type.
 */
const BUILTIN_CSS_FIXES = {
  'container:horizontal_overflow': {
    css: 'max-width: 100% !important; width: 100% !important; min-width: 0 !important;',
    note: 'Container as grid child overflows parent',
    when: (el, content) => {
      // Only apply when parent uses CSS Grid
      const parent = content.find(p => p && p.id === el.parent);
      if (!parent) return false;
      const parentCss = parent.settings?._cssCustom || '';
      return parentCss.includes('display: grid') || parentCss.includes('display:grid');
    },
  },
  'section:section_no_padding': {
    classes: ['ds-section-md'],
    note: 'Section missing spacing class',
    when: (el) => {
      // Only if section has no padding and no spacing classes
      const s = el.settings || {};
      const hasPadding = s._padding && (s._padding.top || s._padding.bottom);
      const hasClasses = (s._cssGlobalClasses || []).some(c => c.startsWith('ds-section'));
      const hasCssPadding = (s._cssCustom || '').includes('padding');
      return !hasPadding && !hasClasses && !hasCssPadding;
    },
  },
  'container:flex_no_gap': {
    classes: ['ds-gap-md'],
    note: 'Flex container without gap',
    when: (el) => {
      // Only if container uses flex, has children, but no gap set
      const s = el.settings || {};
      const hasFlex = s._direction || s._alignItems || s._justifyContent;
      const hasGap = s._gap || (s._cssCustom || '').includes('gap');
      const hasGapClass = (s._cssGlobalClasses || []).some(c => c.startsWith('ds-gap'));
      const hasChildren = (el.children || []).length > 1;
      return hasFlex && hasChildren && !hasGap && !hasGapClass;
    },
  },
  'heading:line_height_bug': {
    css: 'line-height: 1.2 !important;',
    note: 'Heading with broken line-height (px stripped to unitless multiplier)',
    when: (el) => {
      // Only if line-height is a large number (>5 = clearly a bug, e.g. "64")
      const lh = el.settings?._typography?.['line-height'];
      if (!lh) return false;
      const val = parseFloat(lh);
      return !isNaN(val) && val > 5;
    },
  },
  'container:grid_child_overflow': {
    css: 'min-width: 0 !important;',
    note: 'CSS Grid child expanding beyond fr track',
    when: (el, content) => {
      // Only when parent uses CSS Grid (same check as horizontal_overflow)
      const parent = content.find(p => p && p.id === el.parent);
      if (!parent) return false;
      const parentCss = parent.settings?._cssCustom || '';
      const alreadyHas = (el.settings?._cssCustom || '').includes('min-width: 0');
      return !alreadyHas && (parentCss.includes('display: grid') || parentCss.includes('display:grid'));
    },
  },
  'heading:fit_content_width': {
    css: 'width: 100% !important;',
    note: 'Heading with width:fit-content blocks justify-content',
    when: (el) => {
      const css = el.settings?._cssCustom || '';
      return css.includes('justify-content') && !css.includes('width: 100%');
    },
  },
  // Sections with absolute-positioned children (decorative blobs, aurora
  // gradients) overflow without overflow:hidden.
  'section:absolute_child_overflow': {
    css: 'overflow: hidden !important;',
    note: 'Section with absolute-positioned children needs overflow:hidden',
    when: (el, content) => {
      if (el.name !== 'section') return false;
      const css = el.settings?._cssCustom || '';
      // Skip if already has overflow
      if (css.includes('overflow: hidden') || css.includes('overflow:hidden')) return false;
      // Check if any direct children use position: absolute
      const children = content.filter(c => c && c.parent === el.id);
      return children.some(child => {
        const childCss = child.settings?._cssCustom || '';
        return childCss.includes('position: absolute') || childCss.includes('position:absolute');
      });
    },
  },
};

/**
 * Apply CSS fix to an element's _cssCustom, avoiding duplicates.
 *
 * @param {Object} el - Bricks element
 * @param {string} cssFix - CSS rules to inject
 * @param {string[]} log - Log array
 * @returns {boolean} Whether a fix was applied
 */
function applyCssFix(el, cssFix, log) {
  if (!cssFix || !el || !el.settings) return false;

  const existing = el.settings._cssCustom || '';

  // Check if fix is already present (avoid duplicates)
  const fixRules = cssFix.split(';').map(r => r.trim()).filter(Boolean);
  const allPresent = fixRules.every(rule => existing.includes(rule.split(':')[0]?.trim()));
  if (allPresent && fixRules.length > 0) return false;

  // Inject into %root% block
  if (existing.includes('%root%')) {
    // Append rules inside existing %root% block
    el.settings._cssCustom = existing.replace(
      /(%root%\s*\{[^}]*)/,
      `$1 ${cssFix}`
    );
  } else {
    // Create new %root% block
    el.settings._cssCustom = `%root% { ${cssFix} } ${existing}`.trim();
  }

  log.push(`Applied CSS fix on ${el.name} ${el.id}: ${cssFix.substring(0, 80)}`);
  return true;
}

/**
 * Apply class-based corrections to an element.
 *
 * @param {Object} el - Bricks element
 * @param {string[]} classes - Classes to add
 * @param {string[]} log - Log array
 * @returns {boolean} Whether classes were added
 */
function applyClassFix(el, classes, log) {
  if (!classes || classes.length === 0 || !el || !el.settings) return false;

  const existing = el.settings._cssGlobalClasses || [];
  const toAdd = classes.filter(c => !existing.includes(c));
  if (toAdd.length === 0) return false;

  el.settings._cssGlobalClasses = [...existing, ...toAdd];
  log.push(`Applied class fix on ${el.name} ${el.id}: +${toAdd.join(', ')}`);
  return true;
}

/**
 * Run all 10 fix passes on a Bricks content array.
 *
 * @param {Array}  content   - Array of Bricks elements (may be mutated).
 * @param {Object} [options] - Optional config
 * @param {Object} [options.learnings] - Learnings from bricks_get_learnings API
 *                                       Format: { "element_type:issue_type": { correction: { css_fix, add_classes, ... } } }
 * @param {'normalize'|'preserve'} [options.mode='normalize'] - 'preserve' is for saving
 *   EXISTING page/template content: element types and IDs are kept verbatim (no
 *   div→block / block→container reclassification). 'normalize' (default) is for
 *   new/imported/generated content and keeps the aggressive structural repairs.
 *   ID regeneration of genuinely-broken IDs, reference rewriting, px-strip and the
 *   other structural passes run in BOTH modes. See issue #13.
 * @returns {{ content: Array, log: string[], fixed: boolean }}
 */
function autofix(content, options = {}) {
  const log = [];
  const mode = options.mode === 'preserve' ? 'preserve' : 'normalize';

  if (!Array.isArray(content)) {
    return { content, log: ['Content is not an array — cannot autofix'], fixed: false };
  }

  if (content.length === 0) {
    return { content, log: [], fixed: false };
  }

  // Collect existing IDs for uniqueness checks
  const existingIds = new Set();
  for (const el of content) {
    if (el && el.id) existingIds.add(el.id);
  }

  // === Pass 1: Strip bare px values ===
  for (const el of content) {
    if (el && el.settings && typeof el.settings === 'object') {
      el.settings = stripPxValues(el.settings, '', log, el.id || '?');
    }
  }

  // === Pass 2: (removed) "div" → "block" reclassification ===
  // `div` is a valid Bricks layout element (unstyled div, no default width/display) and
  // is distinct from `block` (full-width flex div) — see docs element-catalog. Rewriting
  // it silently changed layout on every save (issue #13, Bug 1). We now preserve the
  // submitted `name`. The HTML→Bricks converter still chooses div-vs-block at import time.

  // === Pass 3: Fix missing/genuinely-broken IDs ===
  // Runs in BOTH modes, but only regenerates IDs that are missing or structurally
  // invalid (wrong charset/length) — NOT merely digit-less. When an ID is regenerated,
  // rewriteIdReferences() rewrites every reference to it (parent/children, brxe-<id>
  // selectors, queryId/filterQueryId/_cssId, component properties) so nothing orphans.
  for (const el of content) {
    if (!el) continue;
    if (!el.id || !isAcceptableBricksId(el.id)) {
      const oldId = el.id;
      const newId = generateBricksId(existingIds);
      if (oldId) rewriteIdReferences(content, oldId, newId);
      log.push(`Fixed ID: "${oldId || '(missing)'}" → "${newId}"`);
      el.id = newId;
    }
  }

  // === Pass 4: Ensure settings object exists ===
  for (const el of content) {
    if (!el) continue;
    if (!el.settings || typeof el.settings !== 'object' || Array.isArray(el.settings)) {
      log.push(`Added missing settings on element ${el.id}`);
      el.settings = el.settings && typeof el.settings === 'object' ? el.settings : {};
    }
  }

  // === Pass 5: Promote block → container when flex properties present (normalize only) ===
  // Reclassifies the submitted type, so skip it on 'preserve' saves of existing content.
  if (mode === 'normalize') {
    for (const el of content) {
      if (!el || el.name !== 'block') continue;
      const hasFlexProp = Object.keys(el.settings || {}).some(k => FLEX_PROPERTIES.has(k));
      if (hasFlexProp) {
        log.push(`Promoted "block" → "container" on element ${el.id} (has flex properties)`);
        el.name = 'container';
      }
    }
  }

  // === Pass 6: Force root sections parent: 0 (integer) ===
  for (const el of content) {
    if (!el || el.name !== 'section') continue;
    // Root section = no parent, or parent is 0, "0", or missing
    if (el.parent === undefined || el.parent === null || el.parent === '0' || el.parent === 0 || el.parent === '') {
      if (el.parent !== 0) {
        log.push(`Fixed parent: ${JSON.stringify(el.parent)} → 0 (integer) on section ${el.id}`);
        el.parent = 0;
      }
    }
  }

  // === Pass 7: Ensure every element has children array ===
  for (const el of content) {
    if (!el) continue;
    if (!Array.isArray(el.children)) {
      log.push(`Added missing children[] on element ${el.id}`);
      el.children = [];
    }
  }

  // === Pass 8: Rebuild children arrays from parent refs ===
  // Build parent→children map from parent references
  const parentToChildren = new Map();
  for (const el of content) {
    if (!el || !el.parent || el.parent === 0) continue;
    const parentId = el.parent;
    if (!parentToChildren.has(parentId)) parentToChildren.set(parentId, []);
    parentToChildren.get(parentId).push(el.id);
  }
  // Sync children arrays with parent refs
  for (const el of content) {
    if (!el) continue;
    const childrenFromRefs = parentToChildren.get(el.id) || [];
    // Merge: keep existing order but add missing, remove orphans
    const refSet = new Set(childrenFromRefs);
    const existingChildren = el.children || [];
    // Keep existing order for children that are still referenced
    const merged = existingChildren.filter(cid => refSet.has(cid));
    // Add any new children not in existing array
    for (const cid of childrenFromRefs) {
      if (!merged.includes(cid)) merged.push(cid);
    }
    if (JSON.stringify(merged) !== JSON.stringify(existingChildren)) {
      log.push(`Rebuilt children[] on element ${el.id}: [${existingChildren.join(',')}] → [${merged.join(',')}]`);
      el.children = merged;
    }
  }

  // === Pass 9: Add spacer to empty containers ===
  // Collect spacers in a separate array to avoid mutating content during iteration.
  const spacersToAdd = [];
  for (const el of content) {
    if (!el) continue;
    // Only containers that have no children and are leaf containers
    if (el.name === 'container' && Array.isArray(el.children) && el.children.length === 0) {
      // Check if any element claims this as parent (edge case from pass 8)
      const hasChildRef = content.some(other => other && other.parent === el.id);
      if (!hasChildRef) {
        const spacerId = generateBricksId(existingIds);
        const spacer = {
          id: spacerId,
          name: 'text-basic',
          parent: el.id,
          children: [],
          settings: {
            text: '&nbsp;',
            _cssCustom: '%root% { position: absolute; opacity: 0; pointer-events: none; }',
          },
          label: 'Spacer',
        };
        spacersToAdd.push(spacer);
        el.children.push(spacerId);
        log.push(`Added spacer child ${spacerId} to empty container ${el.id}`);
      }
    }
  }
  content.push(...spacersToAdd);

  // === Pass 10: Apply learnings (CSS fixes + class corrections) ===
  // v2: Only apply learnings with state "active" (confidence >= 60).
  // v1 fallback: Apply all learnings (backward-compatible when v2 not available).
  // Builtin fixes (conditional — only apply when `when()` detects the problem)
  const { learnings } = options;
  const appliedLearnings = []; // Track for effectiveness reporting.

  for (const el of content) {
    if (!el || !el.name) continue;

    // Apply API learnings with confidence filtering.
    if (learnings) {
      // Detect v2 format (array with state/confidence) vs v1 (keyed object).
      const isV2 = Array.isArray(learnings);
      const entries = isV2
        ? learnings.map(l => [l.pattern?.element_type + ':' + l.pattern?.issue_type, l])
        : Object.entries(learnings);

      for (const [key, learning] of entries) {
        const [elType] = key.split(':');
        if (el.name !== elType) continue;

        // v2: Only apply "active" learnings (confidence >= 60).
        if (isV2 && learning.state && learning.state !== 'active') {
          if (learning.state === 'validated') {
            log.push(`[info] Skipped validated learning ${learning.id || key} for ${el.id} (confidence ${learning.confidence || '?'})`);
          }
          continue;
        }

        const correction = learning.correction || learning;
        if (correction.css_fix) {
          applyCssFix(el, correction.css_fix, log);
          appliedLearnings.push({ id: learning.id || key, element: el.id });
        }
        if (correction.add_classes) {
          applyClassFix(el, correction.add_classes, log);
          appliedLearnings.push({ id: learning.id || key, element: el.id });
        }
      }
    }

    // Apply builtin fixes (only when condition is met)
    for (const [key, fix] of Object.entries(BUILTIN_CSS_FIXES)) {
      const [elType] = key.split(':');
      if (el.name !== elType) continue;
      // Skip if condition not met
      if (fix.when && !fix.when(el, content)) continue;
      if (fix.css) applyCssFix(el, fix.css, log);
      if (fix.classes) applyClassFix(el, fix.classes, log);
    }
  }

  return {
    content,
    log,
    fixed: log.length > 0,
    appliedLearnings, // v2: Track which learnings were applied for effectiveness.
  };
}

export { autofix, generateBricksId, stripPxValues, isAcceptableBricksId, rewriteIdReferences, applyCssFix, applyClassFix };
