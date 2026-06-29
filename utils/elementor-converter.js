/**
 * Elementor → Bricks Builder Converter (structural, no HTML/rendering)
 *
 * Reads an Elementor element tree (from a .json template/page export, or from
 * _elementor_data post meta) and translates it into a flat Bricks element array.
 *
 * Elementor model:  nested tree
 *   { id, elType: 'section'|'column'|'container'|'widget', widgetType?, settings, elements[] }
 * Bricks model:     flat array
 *   { id, name, parent: 0|'parentId', children: ['id'...], settings }
 *
 * The two hard sub-problems are kept separate on purpose:
 *   1. STRUCTURE   — flatten the tree, wire parent/children, generate IDs. (done here)
 *   2. SEMANTICS   — which widget maps to which Bricks element + what content/styles
 *                    carry over. That lives in WIDGET_MAP + translateCommonStyles,
 *                    and is where domain knowledge matters most.
 *
 * NOTE: Exact Bricks setting keys (e.g. icon/image/video shapes) should be
 * verified against a live Bricks page before trusting them in bulk — per the
 * project rule "exakte JSON-Keys vor Verlass live verifizieren".
 */

// ─── ID Generator (6-char lowercase alnum, >=1 digit — Bricks contract) ───────

function generateId(usedIds) {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const digits = '0123456789';
  let id;
  let attempts = 0;
  do {
    id = '';
    for (let i = 0; i < 6; i++) id += chars[Math.floor(Math.random() * chars.length)];
    if (!/[0-9]/.test(id)) {
      const pos = Math.floor(Math.random() * 6);
      id = id.substring(0, pos) + digits[Math.floor(Math.random() * 10)] + id.substring(pos + 1);
    }
    attempts++;
  } while (usedIds.has(id) && attempts < 100);
  usedIds.add(id);
  return id;
}

// ─── Small value helpers ──────────────────────────────────────────────────────

/** Elementor sizes are objects: { unit:'px', size:32, sizes:[] }. Pull a CSS string. */
function elSize(v) {
  if (v == null) return null;
  if (typeof v === 'number') return String(v);
  if (typeof v === 'string') return v.trim() || null;
  if (typeof v === 'object' && v.size !== '' && v.size != null) {
    const unit = v.unit && v.unit !== 'custom' ? v.unit : '';
    return `${v.size}${unit}`;
  }
  return null;
}

const round3 = (n) => String(Math.round(n * 1000) / 1000);

/** Numeric px font-size from an Elementor size object/string, else null. */
function fontSizePx(raw) {
  if (raw == null) return null;
  if (typeof raw === 'object') {
    if ((raw.unit === 'px' || !raw.unit) && raw.size != null && raw.size !== '') {
      const n = parseFloat(raw.size);
      return Number.isFinite(n) ? n : null;
    }
    return null;
  }
  if (typeof raw === 'string' && /px$/.test(raw.trim())) {
    const n = parseFloat(raw);
    return Number.isFinite(n) ? n : null;
  }
  return null;
}

/**
 * line-height MUST be unitless in Bricks — and a bare number is read as a
 * MULTIPLIER (Bricks strips units). So a px line-height has to be converted to
 * a ratio (lineHeight ÷ fontSize), NOT just de-unitised. em/% are already ratios.
 * If we can't resolve a ratio safely (px line-height, unknown font-size), we
 * return null and let the theme default apply rather than emit a 56× bug.
 *
 * @param {*} lhRaw - Elementor typography_line_height
 * @param {*} fsRaw - Elementor typography_font_size (for px→ratio conversion)
 */
function unitlessLineHeight(lhRaw, fsRaw) {
  if (lhRaw == null) return null;
  if (typeof lhRaw === 'number') return String(lhRaw);
  if (typeof lhRaw === 'string') {
    const n = parseFloat(lhRaw);
    return Number.isFinite(n) ? String(n) : null;
  }
  if (typeof lhRaw === 'object' && lhRaw.size != null && lhRaw.size !== '') {
    const size = parseFloat(lhRaw.size);
    if (!Number.isFinite(size)) return null;
    const unit = lhRaw.unit || '';
    if (unit === 'em' || unit === '' || unit === 'custom') return String(size); // already a ratio
    if (unit === '%') return round3(size / 100);
    if (unit === 'px') {
      const fsPx = fontSizePx(fsRaw);
      return fsPx ? round3(size / fsPx) : null; // px→ratio, or skip (avoid Nx multiplier bug)
    }
  }
  return null;
}

/** Elementor 4-side box: { unit, top, right, bottom, left, isLinked }. */
function elBox(v) {
  if (!v || typeof v !== 'object') return null;
  const { top, right, bottom, left } = v;
  if ([top, right, bottom, left].every((x) => x === '' || x == null)) return null;
  return { top: String(top ?? ''), right: String(right ?? ''), bottom: String(bottom ?? ''), left: String(left ?? '') };
}

const HEADING_TAG = { small: 'h6', default: 'h2', large: 'h1', xl: 'h1', xxl: 'h1', h1: 'h1', h2: 'h2', h3: 'h3', h4: 'h4', h5: 'h5', h6: 'h6' };

// ─── Common style translation (the pragmatic 80%) ─────────────────────────────

/**
 * Translate the style keys that are roughly universal across Elementor widgets
 * (advanced tab spacing, typography, colors) into native Bricks settings.
 * Anything not handled here is simply dropped — extend as needed.
 *
 * @param {object} s - Elementor widget settings
 * @param {object} [colorKeys] - per-widget overrides, e.g. { text: 'title_color' }
 * @returns {object} partial Bricks settings
 */
function translateCommonStyles(s, colorKeys = {}) {
  if (!s || typeof s !== 'object') return {};
  const out = {};

  // Spacing (advanced tab — universal)
  const pad = elBox(s._padding);
  if (pad) out._padding = pad;
  const mar = elBox(s._margin);
  if (mar) out._margin = mar;

  // Background (classic color only; gradients/images need _cssCustom — out of MVP scope)
  if (s._background_background === 'classic' && s._background_color) {
    out._background = { color: { raw: s._background_color } };
  }

  // Typography
  const typo = {};
  const fs = elSize(s.typography_font_size);
  if (fs) typo['font-size'] = fs;
  if (s.typography_font_family) typo['font-family'] = s.typography_font_family;
  if (s.typography_font_weight) typo['font-weight'] = String(s.typography_font_weight);
  const lh = unitlessLineHeight(s.typography_line_height, s.typography_font_size);
  if (lh) typo['line-height'] = lh;
  const ls = elSize(s.typography_letter_spacing);
  if (ls) typo['letter-spacing'] = ls;
  if (s.align || s.text_align) typo['text-align'] = s.align || s.text_align;
  const colorKey = colorKeys.text;
  if (colorKey && s[colorKey]) typo.color = { raw: s[colorKey] };
  if (Object.keys(typo).length) out._typography = typo;

  // Width
  const w = elSize(s.width || s._element_width || s._width);
  if (w) out._width = w;

  return out;
}

// ─── WIDGET MAP — semantic translation (the heart of the converter) ───────────

/**
 * Each entry: elementorWidgetType -> (settings) => { name, settings }
 *   name     = Bricks element name
 *   settings = Bricks settings (content keys; styles are merged in by the engine)
 *
 * This is the seeded core. Extend it with the widgets YOUR migration users
 * actually rely on. Keys not present here fall through to handleUnsupportedWidget.
 */
const WIDGET_MAP = {
  heading: (s) => ({
    name: 'heading',
    settings: {
      text: s.title ?? '',
      tag: HEADING_TAG[s.header_size] || 'h2',
      ...translateCommonStyles(s, { text: 'title_color' }),
    },
  }),

  'text-editor': (s) => ({
    name: 'text-basic',
    settings: {
      text: s.editor ?? '',
      ...translateCommonStyles(s, { text: 'text_color' }),
    },
  }),

  button: (s) => ({
    name: 'button',
    settings: {
      text: s.text ?? s.button_text ?? 'Button',
      link: s.link?.url ? { type: 'external', url: s.link.url } : undefined,
      ...translateCommonStyles(s, { text: 'button_text_color' }),
    },
  }),

  image: (s) => ({
    name: 'image',
    settings: {
      // Bricks resolves <img> from image.id; keep url as fallback. Verify live.
      image: { id: s.image?.id, url: s.image?.url, alt: s.image?.alt },
      ...translateCommonStyles(s),
    },
  }),

  icon: (s) => ({
    name: 'icon',
    settings: {
      // Elementor: selected_icon = { value:'fas fa-star', library:'fa-solid' }
      icon: s.selected_icon?.value
        ? { library: 'fontawesome', icon: s.selected_icon.value }
        : undefined,
      ...translateCommonStyles(s, { text: 'primary_color' }),
    },
  }),

  divider: (s) => ({ name: 'divider', settings: { ...translateCommonStyles(s) } }),

  spacer: (s) => ({
    name: 'block',
    settings: { _height: elSize(s.space) || '50px', ...translateCommonStyles(s) },
  }),
};

// ─── Structural element-type mapping (section/column/container) ───────────────

function structuralBricksName(node) {
  switch (node.elType) {
    case 'section': return 'section';
    case 'column': return 'container';
    case 'container': return 'container'; // Elementor flexbox container
    default: return 'block';
  }
}

// ─── Unsupported-widget policy ────────────────────────────────────────────────
// NOTE: implemented as a clear seam so the data-integrity behaviour is one
// deliberate decision rather than scattered through the recursion.

/** Best-effort short text preview of a widget's content, for the placeholder label. */
function widgetPreview(s) {
  if (!s || typeof s !== 'object') return '';
  const cand =
    s.title || s.editor || s.text || s.heading || s.description ||
    s.caption || s.button_text || s.testimonial_content ||
    (s.image && s.image.url ? '[image]' : '');
  if (!cand) return '';
  const txt = String(cand).replace(/<[^>]*>/g, '').trim();
  if (!txt) return '';
  return txt.length > 40 ? `${txt.slice(0, 37)}…` : txt;
}

/**
 * POLICY: option A — loud placeholder. When a widget type has no WIDGET_MAP
 * entry we emit an empty, dashed, clearly-labelled block carrying a preview of
 * the original content. Nothing is lost silently: the migrator sees exactly
 * WHERE the gap is AND WHAT belonged there, right in the Bricks structure panel.
 *
 * @param {object} node - the Elementor widget node
 * @param {object} ctx  - { usedIds, parentId, report }
 * @returns {object|null} a Bricks element (pushed) or null to skip
 */
function handleUnsupportedWidget(node, ctx) {
  const type = node.widgetType || 'unknown';
  const preview = widgetPreview(node.settings);
  ctx.report.unsupported.push({ id: node.id, widgetType: type, preview: preview || undefined });
  return {
    id: generateId(ctx.usedIds),
    name: 'block',
    parent: ctx.parentId,
    children: [],
    settings: {
      _cssCustom: `%root%{min-height:40px;outline:1px dashed #f0a;}`,
      // surfaced as the Bricks element label in the structure panel
    },
    label: preview ? `⚠ Elementor ${type}: "${preview}"` : `⚠ Elementor: ${type}`,
  };
}

// ─── Main converter ───────────────────────────────────────────────────────────

/**
 * @param {Array|object} input - Elementor tree (array) or export wrapper { content:[...] }
 * @param {object} [opts]
 * @returns {{ elements: Array, report: object }}
 */
function elementorToBricks(input, opts = {}) {
  const tree = Array.isArray(input) ? input : (input?.content || input?.elements || []);
  if (!Array.isArray(tree)) {
    throw new Error('Could not locate an Elementor element array (expected array or { content: [...] }).');
  }

  const usedIds = new Set();
  const elements = [];
  const report = {
    widgetsTotal: 0,
    mapped: {},        // widgetType -> count
    unsupported: [],   // [{ id, widgetType }]
  };

  function walk(node, parentId) {
    const isWidget = node.elType === 'widget';
    let built;

    if (isWidget) {
      report.widgetsTotal++;
      const mapper = WIDGET_MAP[node.widgetType];
      if (mapper) {
        report.mapped[node.widgetType] = (report.mapped[node.widgetType] || 0) + 1;
        const { name, settings } = mapper(node.settings || {});
        built = {
          id: generateId(usedIds),
          name,
          parent: parentId,
          children: [],
          settings: cleanSettings(settings),
        };
      } else {
        built = handleUnsupportedWidget(node, { usedIds, parentId, report });
        if (!built) return; // policy chose to skip
      }
    } else {
      // Structural node (section / column / container)
      built = {
        id: generateId(usedIds),
        name: structuralBricksName(node),
        parent: parentId,
        children: [],
        settings: cleanSettings(translateCommonStyles(node.settings || {})),
      };
    }

    elements.push(built);

    for (const child of node.elements || []) {
      const childId = walk(child, built.id);
      if (childId) built.children.push(childId);
    }
    return built.id;
  }

  for (const root of tree) walk(root, 0);

  return { elements, report };
}

/** Drop undefined/empty settings so the payload stays lean. */
function cleanSettings(s) {
  const out = {};
  for (const [k, v] of Object.entries(s || {})) {
    if (v === undefined || v === null) continue;
    if (typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0) continue;
    out[k] = v;
  }
  return out;
}

export { elementorToBricks, WIDGET_MAP, translateCommonStyles, generateId };
