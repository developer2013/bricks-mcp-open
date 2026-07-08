/**
 * Bricks Builder JSON Validator
 * Validates element structure, IDs, and settings before saving.
 * Returns errors (blocking), warnings (non-blocking), and info (hints).
 *
 * Supports structured patch validation via validatePatch() — inspired by
 * agent-to-bricks JSON schema (insert/replace/append/delete modes).
 */

// ─── Patch Schema Validation ─────────────────────────────────────────────────

const VALID_PATCH_MODES = ['insert', 'replace', 'append', 'delete'];

const VALID_ELEMENT_NAMES = new Set([
  // Layout
  'section', 'container', 'block', 'div',
  // Text
  'heading', 'text-basic', 'text', 'text-link', 'list',
  // Buttons / Icons
  'button', 'icon', 'icon-box', 'social-icons',
  // Media
  'image', 'image-gallery', 'video', 'audio', 'svg', 'logo', 'slider', 'slider-nested', 'lottie', 'map',
  // Tabs / Accordion
  'tabs', 'tabs-nested', 'accordion', 'accordion-nested',
  // Navigation
  'nav-menu', 'nav-nested', 'nav-menu-mobile-toggle', 'breadcrumbs', 'search', 'offcanvas', 'toggle', 'sidebar',
  // UI
  'divider', 'alert', 'progress-bar', 'counter', 'countdown',
  // Code / Embeds
  'code', 'html', 'shortcode', 'template',
  // Forms
  'form', 'form-field', 'form-submit',
  // Post / Loop
  'post-title', 'post-content', 'post-excerpt', 'post-meta', 'post-toc',
  'post-comments', 'post-comments-form', 'post-navigation', 'post-sharing',
  'post-reading-time', 'post-taxonomy', 'post-author', 'post-related',
  'post-featured-image', 'pagination', 'posts', 'related-posts',
]);

/**
 * Validate a structured element patch before applying.
 *
 * Patch format:
 * {
 *   patchMode: 'insert' | 'replace' | 'append' | 'delete',
 *   targetParent: string | 0,        // parent element ID (0 = root)
 *   targetIndex?: number,             // position for insert mode
 *   targetElementIds?: string[],      // IDs for replace/delete modes
 *   elements: Array<BricksElement>,   // elements to insert/replace/append
 * }
 *
 * @param {object} patch - Structured patch object
 * @param {Array} [existingElements] - Current page elements (for collision checks)
 * @returns {{ valid: boolean, errors: string[], warnings: string[] }}
 */
function validatePatch(patch, existingElements = []) {
  const errors = [];
  const warnings = [];

  if (!patch || typeof patch !== 'object') {
    return { valid: false, errors: ['Patch must be an object'], warnings: [] };
  }

  // Validate patchMode
  const mode = patch.patchMode;
  if (!mode) {
    errors.push('Missing required field: patchMode');
  } else if (!VALID_PATCH_MODES.includes(mode)) {
    errors.push(`Invalid patchMode: "${mode}". Must be one of: ${VALID_PATCH_MODES.join(', ')}`);
  }

  // Validate targetParent (required for insert/append)
  if (mode === 'insert' || mode === 'append') {
    if (patch.targetParent === undefined || patch.targetParent === null) {
      errors.push(`${mode} mode requires targetParent (element ID or 0 for root)`);
    } else if (patch.targetParent !== 0) {
      if (typeof patch.targetParent !== 'string') {
        errors.push(`targetParent must be a string (element ID) or 0 (root)`);
      } else if (existingElements.length > 0) {
        const parentExists = existingElements.some(el => el.id === patch.targetParent);
        if (!parentExists) {
          errors.push(`targetParent "${patch.targetParent}" not found in existing page elements`);
        }
      }
    }
  }

  // Validate targetIndex (optional, for insert mode)
  if (mode === 'insert' && patch.targetIndex !== undefined) {
    if (typeof patch.targetIndex !== 'number' || patch.targetIndex < 0) {
      errors.push('targetIndex must be a non-negative number');
    }
  }

  // Validate targetElementIds (required for replace/delete)
  if (mode === 'replace' || mode === 'delete') {
    if (!Array.isArray(patch.targetElementIds) || patch.targetElementIds.length === 0) {
      errors.push(`${mode} mode requires targetElementIds array with at least one ID`);
    } else {
      for (const id of patch.targetElementIds) {
        if (typeof id !== 'string' || !isValidBricksId(id)) {
          errors.push(`Invalid target element ID: "${id}"`);
        }
        if (existingElements.length > 0) {
          const exists = existingElements.some(el => el.id === id);
          if (!exists) {
            warnings.push(`Target element "${id}" not found in current page — may have been already removed`);
          }
        }
      }
    }
  }

  // Validate elements (required for insert/replace/append, forbidden for delete)
  if (mode === 'delete') {
    if (patch.elements && patch.elements.length > 0) {
      warnings.push('delete mode ignores the elements array — only targetElementIds are used');
    }
  } else if (mode && mode !== 'delete') {
    if (!Array.isArray(patch.elements) || patch.elements.length === 0) {
      errors.push(`${mode} mode requires elements array with at least one element`);
    } else {
      // Run standard element validation on the patch elements
      const elementValidation = validateContent(patch.elements);
      errors.push(...elementValidation.errors);
      warnings.push(...elementValidation.warnings);

      // Check for ID collisions with existing page elements
      if (existingElements.length > 0 && mode !== 'replace') {
        const existingIds = new Set(existingElements.map(el => el.id));
        for (const el of patch.elements) {
          if (el.id && existingIds.has(el.id)) {
            errors.push(`Element ID "${el.id}" already exists on this page — will cause duplicate`);
          }
        }
      }

      // Validate element names against known types
      for (const el of patch.elements) {
        if (el.name && !VALID_ELEMENT_NAMES.has(el.name)) {
          warnings.push(`Element "${el.id || '?'}": Unknown element type "${el.name}" — may be a custom element or typo`);
        }
      }
    }
  }

  return { valid: errors.length === 0, errors, warnings };
}

function isValidBricksId(id) {
  if (typeof id !== 'string') return false;
  if (id.length !== 6) return false;
  if (!/^[a-z0-9]+$/.test(id)) return false;
  // NOTE: no digit requirement. Bricks accepts 6-char lowercase-alphanumeric IDs
  // whether or not they contain a digit — cloned/legacy sites routinely have
  // all-letter IDs (e.g. "wmhqxu") that are live and valid. Requiring a digit
  // here only rejected those IDs and forced a destructive ID regeneration on
  // save (see issue #13). Our own generator still mints digit-bearing IDs.
  return true;
}

const PX_SAFE_KEYS = new Set([
  'content', '_cssCustom', 'url', 'name', 'label', 'tag',
  'link', 'href', 'src', 'alt', 'placeholder', 'icon', 'class',
  'text', 'title', 'description',
]);

function findPxValues(obj, path = '', currentKey = '') {
  if (PX_SAFE_KEYS.has(currentKey)) return [];

  const results = [];

  if (obj === null || obj === undefined) return results;

  if (typeof obj === 'string') {
    if (/^\d+px$/.test(obj)) {
      results.push(path);
    }
    return results;
  }

  if (Array.isArray(obj)) {
    obj.forEach((item, index) => {
      results.push(...findPxValues(item, `${path}[${index}]`, currentKey));
    });
    return results;
  }

  if (typeof obj === 'object') {
    for (const [key, value] of Object.entries(obj)) {
      results.push(...findPxValues(value, path ? `${path}.${key}` : key, key));
    }
    return results;
  }

  return results;
}

/**
 * Check whether an element's settings contain any responsive breakpoint keys.
 */
function hasResponsiveKeys(settings) {
  if (!settings || typeof settings !== 'object') return false;
  return Object.keys(settings).some(key =>
    key.includes(':tablet_portrait') ||
    key.includes(':mobile_landscape') ||
    key.includes(':mobile_portrait')
  );
}

function validateContent(content) {
  const errors = [];
  const warnings = [];
  const info = [];

  if (!Array.isArray(content)) {
    return { valid: false, errors: ['Content must be an array of elements'], warnings: [], info: [] };
  }

  if (content.length === 0) {
    return { valid: false, errors: ['Content array is empty'], warnings: [], info: [] };
  }

  const ids = new Set();
  const parentIds = new Set();

  const idCounts = new Map();
  content.forEach(el => {
    if (el && el.id) {
      ids.add(el.id);
      idCounts.set(el.id, (idCounts.get(el.id) || 0) + 1);
    }
  });

  // ── Per-element structural checks (errors) ──

  content.forEach((element, index) => {
    const prefix = `Element [${index}]`;

    if (!element || typeof element !== 'object') {
      errors.push(`${prefix}: Not a valid object`);
      return;
    }

    if (!element.id) {
      errors.push(`${prefix}: Missing 'id' field`);
    } else if (!isValidBricksId(element.id)) {
      errors.push(`${prefix} (${element.id}): Invalid Bricks ID format (must be 6 lowercase alphanumeric characters)`);
    }

    if (!element.name) {
      errors.push(`${prefix} (${element.id || '?'}): Missing 'name' field`);
    }

    // NOTE: `div` IS a valid Bricks layout element (unstyled div) — do not reject it.
    // It is distinct from `block` (full-width flex div); see issue #13, Bug 1.

    if (element.id && idCounts.get(element.id) > 1) {
      errors.push(`${prefix} (${element.id}): Duplicate ID found`);
    }

    if (element.parent) {
      parentIds.add(element.parent);
      if (!ids.has(element.parent)) {
        errors.push(`${prefix} (${element.id || '?'}): Parent '${element.parent}' does not exist in the element tree`);
      }
    }

    if (element.settings) {
      const pxPaths = findPxValues(element.settings, `${prefix}.settings`);
      pxPaths.forEach(p => {
        errors.push(`${p}: Bare px value found. Use a unitless number (Bricks adds px automatically).`);
      });
    }

    // Missing children array.
    if (!Array.isArray(element.children)) {
      errors.push(`${prefix} (${element.id || '?'}): Missing "children" array. Every element must have children: [] (empty for leaf nodes).`);
    }

    // Root section parent must be 0 (integer).
    if (element.name === 'section') {
      if (element.parent === undefined || element.parent === null || element.parent === '0' || element.parent === '') {
        if (element.parent !== 0) {
          errors.push(`${prefix} (${element.id || '?'}): Root section has parent=${JSON.stringify(element.parent)} instead of 0 (integer).`);
        }
      }
    }
  });

  // ── Smart checks — Warnings ──

  content.forEach(element => {
    if (!element || typeof element !== 'object') return;
    const id = element.id || '?';
    const settings = element.settings || {};

    // Warning: "block" with flex properties → use "container".
    if (element.name === 'block' && settings) {
      const flexProps = ['_direction', '_alignItems', '_justifyContent'];
      for (const prop of flexProps) {
        if (settings[prop] !== undefined) {
          warnings.push(`Element "${id}": "block" with flex property "${prop}". Use "container" instead — block has no native flex support.`);
          break;
        }
      }
    }

    // Warning: _display: "grid" triggers brx-grid bug.
    if (settings._display === 'grid') {
      warnings.push(`Element "${id}": _display: "grid" in settings triggers the brx-grid bug. Use _cssCustom for CSS Grid instead.`);
    }

    // Warning: Backslash in _cssCustom — WordPress strips them.
    if (typeof settings._cssCustom === 'string' && settings._cssCustom.includes('\\')) {
      warnings.push(`Element "${id}": Backslash in _cssCustom. WordPress strips backslashes via wp_unslash() — use the actual Unicode character instead.`);
    }

    // Warning: Container row + many children without flex-wrap.
    if (element.name === 'container' && settings._direction === 'row') {
      const childCount = Array.isArray(element.children) ? element.children.length : 0;
      if (childCount > 4) {
        const hasWrap = settings._flexWrap ||
          (typeof settings._cssCustom === 'string' && settings._cssCustom.includes('flex-wrap'));
        if (!hasWrap) {
          warnings.push(`Element "${id}": Container with direction: row and ${childCount} children but no flex-wrap. This will likely overflow on mobile.`);
        }
      }
    }

    // Warning: Fixed width in px without max-width.
    if (typeof settings._cssCustom === 'string') {
      if (/\bwidth\s*:\s*\d+px/.test(settings._cssCustom) && !settings._cssCustom.includes('max-width')) {
        warnings.push(`Element "${id}": Fixed width in px without max-width. This may cause overflow on smaller screens.`);
      }
    }
  });

  // Warning: Heading hierarchy gaps.
  const headingLevels = [];
  content.forEach(el => {
    if (!el || el.name !== 'heading') return;
    const tag = el.settings?.tag || 'h2';
    const match = tag.match(/^h([1-6])$/);
    if (match) headingLevels.push(parseInt(match[1]));
  });
  if (headingLevels.length > 0) {
    const unique = [...new Set(headingLevels)].sort((a, b) => a - b);
    for (let i = 0; i < unique.length - 1; i++) {
      if (unique[i + 1] - unique[i] > 1) {
        warnings.push(`Heading hierarchy gap: H${unique[i]} jumps to H${unique[i + 1]} (missing H${unique[i] + 1}).`);
      }
    }
  }

  // ── Smart checks — Info ──

  content.forEach(element => {
    if (!element || typeof element !== 'object') return;
    const id = element.id || '?';
    const settings = element.settings || {};

    // Info: CSS transform may conflict with GSAP.
    if (typeof settings._cssCustom === 'string' && /\btransform\s*:/.test(settings._cssCustom)) {
      info.push(`Element "${id}": CSS transform in _cssCustom may conflict with GSAP. Consider individual properties (rotate, scale, translate) instead.`);
    }

    // Info: Large font-size without responsive overrides.
    if (settings._typography && settings._typography['font-size']) {
      const size = parseInt(settings._typography['font-size']);
      if (size > 48 && !hasResponsiveKeys(settings)) {
        info.push(`Element "${id}": Font size ${size}px without responsive overrides. Consider adding tablet/mobile sizes.`);
      }
    }

    // Info: Large padding without responsive overrides.
    if (settings._padding && typeof settings._padding === 'object') {
      const hasLarge = Object.values(settings._padding).some(v => {
        const n = parseInt(v);
        return !isNaN(n) && n > 60;
      });
      if (hasLarge && !hasResponsiveKeys(settings)) {
        info.push(`Element "${id}": Padding > 60px without responsive overrides. Consider reducing for smaller screens.`);
      }
    }

    // Info: Image without _objectFit.
    if (element.name === 'image' && !settings._objectFit) {
      info.push(`Element "${id}": Image without _objectFit. Consider setting "cover" or "contain" to prevent distortion.`);
    }

    // Info: position: fixed/absolute without z-index.
    if (typeof settings._cssCustom === 'string') {
      if (/position\s*:\s*(fixed|absolute)/.test(settings._cssCustom) && !settings._cssCustom.includes('z-index')) {
        info.push(`Element "${id}": position: fixed/absolute in _cssCustom without z-index. Elements may overlap unexpectedly.`);
      }
    }

    // Info: Empty container not rendered by Bricks.
    if (element.name === 'container') {
      if (!Array.isArray(element.children) || element.children.length === 0) {
        info.push(`Element "${id}": Empty container. Bricks does not render containers without children.`);
      }
    }
  });

  return {
    valid: errors.length === 0,
    errors,
    warnings,
    info,
  };
}

export { isValidBricksId, findPxValues, validateContent, validatePatch, VALID_ELEMENT_NAMES, VALID_PATCH_MODES };
