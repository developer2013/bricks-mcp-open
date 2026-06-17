/**
 * HTML-to-Bricks Converter
 *
 * Transforms standard HTML/CSS into valid Bricks Builder JSON elements.
 * Follows all Bricks conventions: 6-char IDs, parent/children arrays,
 * container for flex, _cssCustom for grid, unitless values, etc.
 */
import * as cheerio from 'cheerio';

// ─── ID Generator ────────────────────────────────────────────────────────────

function generateId(usedIds) {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  const digits = '0123456789';
  let id;
  let attempts = 0;
  do {
    id = '';
    for (let i = 0; i < 6; i++) id += chars[Math.floor(Math.random() * chars.length)];
    // Ensure at least one digit
    if (!/[0-9]/.test(id)) {
      const pos = Math.floor(Math.random() * 6);
      id = id.substring(0, pos) + digits[Math.floor(Math.random() * 10)] + id.substring(pos + 1);
    }
    attempts++;
  } while (usedIds.has(id) && attempts < 100);
  usedIds.add(id);
  return id;
}

// ─── Helpers ─────────────────────────────────────────────────────────────────

function stripUnit(value) {
  if (!value) return '';
  const trimmed = String(value).trim();
  const num = parseFloat(trimmed);
  // Keep values like "1.6", "-0.02em", "100%", "auto", "inherit" etc. as-is
  if (isNaN(num)) return trimmed;
  // Strip px suffix only
  if (/^-?[\d.]+px$/.test(trimmed)) return String(num);
  // Keep other units (em, rem, %, vh, vw) as-is
  if (/^-?[\d.]+[a-z%]+$/i.test(trimmed)) return trimmed;
  return String(num);
}

function parseFourSides(value) {
  if (!value) return null;
  const parts = value.trim().split(/\s+/).map(stripUnit);
  if (parts.length === 1) return { top: parts[0], right: parts[0], bottom: parts[0], left: parts[0] };
  if (parts.length === 2) return { top: parts[0], right: parts[1], bottom: parts[0], left: parts[1] };
  if (parts.length === 3) return { top: parts[0], right: parts[1], bottom: parts[2], left: parts[1] };
  return { top: parts[0], right: parts[1], bottom: parts[2], left: parts[3] };
}

// ─── CSS Property → Bricks Settings Mapper ───────────────────────────────────

/**
 * Maps a CSS property+value to Bricks settings.
 * Returns { mapped: {settings object}, unmapped: boolean }.
 * If unmapped=true, the property should go into _cssCustom.
 */
function mapCSSProperty(prop, value) {
  const p = prop.trim().toLowerCase();
  const v = value.trim();

  switch (p) {
    // Layout — flex maps to native container settings
    case 'display':
      if (v === 'flex' || v === 'inline-flex') return { mapped: { _display: 'flex' } };
      // Grid must go via _cssCustom, never _display: "grid"
      return { unmapped: true };
    case 'flex-direction':
      return { mapped: { _direction: v } };
    case 'align-items':
      return { mapped: { _alignItems: v } };
    case 'justify-content':
      return { mapped: { _justifyContent: v } };
    case 'flex-wrap':
      return { mapped: { _flexWrap: v } };
    // Gap must go via _cssCustom (unreliable natively)
    case 'gap':
    case 'row-gap':
    case 'column-gap':
      return { unmapped: true };

    // Spacing
    case 'padding':
      return { mapped: { _padding: parseFourSides(v) } };
    case 'padding-top':
      return { mapped: { _padding: { top: stripUnit(v) } }, merge: '_padding' };
    case 'padding-right':
      return { mapped: { _padding: { right: stripUnit(v) } }, merge: '_padding' };
    case 'padding-bottom':
      return { mapped: { _padding: { bottom: stripUnit(v) } }, merge: '_padding' };
    case 'padding-left':
      return { mapped: { _padding: { left: stripUnit(v) } }, merge: '_padding' };
    case 'margin':
      return { mapped: { _margin: parseFourSides(v) } };
    case 'margin-top':
      return { mapped: { _margin: { top: stripUnit(v) } }, merge: '_margin' };
    case 'margin-right':
      return { mapped: { _margin: { right: stripUnit(v) } }, merge: '_margin' };
    case 'margin-bottom':
      return { mapped: { _margin: { bottom: stripUnit(v) } }, merge: '_margin' };
    case 'margin-left':
      return { mapped: { _margin: { left: stripUnit(v) } }, merge: '_margin' };

    // Sizing
    case 'width':
      return { mapped: { _width: stripUnit(v) } };
    case 'max-width':
      return { mapped: { _maxWidth: stripUnit(v) } };
    case 'min-width':
      return { mapped: { _minWidth: stripUnit(v) } };
    case 'height':
      return { mapped: { _height: stripUnit(v) } };
    case 'min-height':
      return { mapped: { _minHeight: stripUnit(v) } };

    // Background
    case 'background-color':
      return { mapped: { _background: { color: { raw: v } } }, merge: '_background' };
    case 'background-image':
      if (v.startsWith('url(')) {
        const url = v.replace(/^url\(['"]?/, '').replace(/['"]?\)$/, '');
        return { mapped: { _background: { image: { url } } }, merge: '_background' };
      }
      // Gradients → _cssCustom
      return { unmapped: true };
    case 'background':
      // Simple color values
      if (/^#[0-9a-fA-F]{3,8}$/.test(v) || /^rgba?\(/.test(v) || /^[a-z]+$/i.test(v)) {
        return { mapped: { _background: { color: { raw: v } } }, merge: '_background' };
      }
      return { unmapped: true };
    case 'background-size':
      return { mapped: { _background: { size: v } }, merge: '_background' };
    case 'background-position':
      return { mapped: { _background: { position: v } }, merge: '_background' };

    // Typography
    case 'color':
      return { mapped: { _typography: { color: { raw: v } } }, merge: '_typography' };
    case 'font-size':
      return { mapped: { _typography: { 'font-size': stripUnit(v) } }, merge: '_typography' };
    case 'font-weight':
      return { mapped: { _typography: { 'font-weight': v } }, merge: '_typography' };
    case 'font-family':
      return { mapped: { _typography: { 'font-family': v.replace(/['"]/g, '').split(',')[0].trim() } }, merge: '_typography' };
    case 'letter-spacing':
      return { mapped: { _typography: { 'letter-spacing': v } }, merge: '_typography' };
    case 'line-height':
      return { mapped: { _typography: { 'line-height': v } }, merge: '_typography' };
    case 'text-align':
      return { mapped: { _typography: { 'text-align': v } }, merge: '_typography' };
    case 'text-transform':
      return { mapped: { _typography: { 'text-transform': v } }, merge: '_typography' };
    case 'text-decoration':
      return { mapped: { _typography: { 'text-decoration': v } }, merge: '_typography' };

    // Border radius
    case 'border-radius': {
      const r = stripUnit(v);
      return { mapped: { _border: { radius: { top: r, right: r, bottom: r, left: r } } }, merge: '_border' };
    }
    case 'border': {
      // Parse shorthand: "1px solid #ccc"
      const parts = v.split(/\s+/);
      if (parts.length >= 2) {
        const result = { _border: { style: parts[1] || 'solid' } };
        if (parts[0]) {
          const w = stripUnit(parts[0]);
          result._border.width = { top: w, right: w, bottom: w, left: w };
        }
        if (parts[2]) result._border.color = { hex: parts[2] };
        return { mapped: result, merge: '_border' };
      }
      return { unmapped: true };
    }

    // Box shadow
    case 'box-shadow': {
      // Parse simple box-shadow: "0 4px 20px rgba(10,37,64,0.03)"
      const shadowMatch = v.match(/^(-?[\d.]+\w*)\s+(-?[\d.]+\w*)\s+(-?[\d.]+\w*)\s+(.+)$/);
      if (shadowMatch) {
        return {
          mapped: {
            _boxShadow: {
              values: [{
                offsetX: stripUnit(shadowMatch[1]),
                offsetY: stripUnit(shadowMatch[2]),
                blur: stripUnit(shadowMatch[3]),
                color: shadowMatch[4],
              }],
            },
          },
        };
      }
      return { unmapped: true };
    }

    // Everything else → _cssCustom
    case 'opacity':
    case 'overflow':
    case 'position':
    case 'top':
    case 'right':
    case 'bottom':
    case 'left':
    case 'z-index':
    case 'transform':
    case 'transition':
    case 'animation':
    case 'cursor':
    case 'object-fit':
    case 'aspect-ratio':
    case 'filter':
    case 'backdrop-filter':
    case 'mix-blend-mode':
    case 'clip-path':
    case 'grid-template-columns':
    case 'grid-template-rows':
    case 'grid-column':
    case 'grid-row':
    case 'white-space':
    case 'word-break':
    case 'text-overflow':
      return { unmapped: true };

    default:
      return { unmapped: true };
  }
}

// ─── CSS Parsing ─────────────────────────────────────────────────────────────

function parseInlineStyle(style) {
  if (!style) return {};
  const props = {};
  style.split(';').forEach(decl => {
    const colonIdx = decl.indexOf(':');
    if (colonIdx === -1) return;
    const prop = decl.substring(0, colonIdx).trim();
    const value = decl.substring(colonIdx + 1).trim();
    if (prop && value) props[prop] = value;
  });
  return props;
}

function parseCSS(css) {
  const map = {};
  if (!css) return map;
  // Remove comments
  const cleaned = css.replace(/\/\*[\s\S]*?\*\//g, '');
  // Simple rule parser for selectors { properties }
  const ruleRegex = /([^{}]+)\{([^}]+)\}/g;
  let match;
  while ((match = ruleRegex.exec(cleaned)) !== null) {
    const selectors = match[1].trim().split(',').map(s => s.trim());
    const props = parseInlineStyle(match[2]);
    for (const sel of selectors) {
      if (!map[sel]) map[sel] = {};
      Object.assign(map[sel], props);
    }
  }
  return map;
}

/**
 * Convert a CSS property map to Bricks settings, collecting unmapped props.
 */
function mapCSSToSettings(cssProps) {
  const settings = {};
  const unmappedParts = [];

  for (const [prop, value] of Object.entries(cssProps)) {
    const result = mapCSSProperty(prop, value);
    if (result.unmapped) {
      unmappedParts.push(`${prop}: ${value}`);
    } else if (result.mapped) {
      // Deep-merge mapped settings
      for (const [key, val] of Object.entries(result.mapped)) {
        if (result.merge && typeof val === 'object' && typeof settings[key] === 'object') {
          settings[key] = deepMerge(settings[key], val);
        } else {
          settings[key] = val;
        }
      }
    }
  }

  return { settings, unmappedCSS: unmappedParts };
}

function deepMerge(target, source) {
  const result = { ...target };
  for (const [key, value] of Object.entries(source)) {
    if (value && typeof value === 'object' && !Array.isArray(value) && result[key] && typeof result[key] === 'object') {
      result[key] = deepMerge(result[key], value);
    } else {
      result[key] = value;
    }
  }
  return result;
}

// ─── HTML → Bricks Element Mapping ──────────────────────────────────────────

const TAG_MAP = {
  'section': 'section',
  'h1': 'heading', 'h2': 'heading', 'h3': 'heading',
  'h4': 'heading', 'h5': 'heading', 'h6': 'heading',
  'p': 'text-basic',
  'span': 'text-basic',
  'a': 'button',
  'button': 'button',
  'img': 'image',
  'video': 'video',
  'ul': 'text-basic',
  'ol': 'text-basic',
  'li': 'text-basic',
  'form': 'form',
  'input': 'form-field',
  'textarea': 'form-field',
  'select': 'form-field',
  // Semantic containers
  'div': 'container',
  'nav': 'container',
  'header': 'container',
  'footer': 'container',
  'main': 'container',
  'article': 'container',
  'aside': 'container',
  'figure': 'container',
  'figcaption': 'text-basic',
  'blockquote': 'text-basic',
  'pre': 'text-basic',
  'code': 'text-basic',
  'table': 'text-basic',
  'hr': 'block',
  'br': null, // skip
  'script': null,
  'style': null,
  'link': null,
  'meta': null,
  'noscript': null,
  'svg': null, // skip svg elements
  'path': null,
};

/**
 * Determine if a div/container should be a leaf block or a container.
 * Containers have child elements; blocks/leaves do not.
 */
function getElementName(tagName, $el, $) {
  const tag = tagName.toLowerCase();
  const mapped = TAG_MAP[tag];
  if (mapped === null) return null; // skip
  if (mapped !== undefined) return mapped;
  // Unknown tags → container if has children, text-basic if leaf
  const hasChildElements = $el.children().filter((_, c) => c.type === 'tag' && TAG_MAP[c.tagName?.toLowerCase()] !== null).length > 0;
  return hasChildElements ? 'container' : 'text-basic';
}

// ─── Main Converter ──────────────────────────────────────────────────────────

/**
 * Convert HTML + optional CSS into a Bricks Builder element array.
 *
 * @param {string} html - HTML markup to convert
 * @param {string} [css=''] - Optional CSS stylesheet
 * @param {object} [options={}] - Options
 * @param {boolean} [options.wrapInSection=true] - Wrap top-level non-section elements in sections
 * @returns {Array} Bricks element array
 */
export function htmlToBricks(html, css = '', options = {}) {
  const { wrapInSection = true } = options;
  const $ = cheerio.load(html);
  const usedIds = new Set();
  const elements = [];
  const styleMap = parseCSS(css);

  function processNode(node, parentId = 0) {
    if (node.type !== 'tag') return null;
    const tagName = node.tagName?.toLowerCase();
    if (!tagName) return null;

    const $node = $(node);
    let bricksName = getElementName(tagName, $node, $);
    if (bricksName === null) return null; // skip script/style/etc.

    const id = generateId(usedIds);

    // Start building settings
    let allCSS = {};
    let combinedUnmapped = [];

    // 1. Gather CSS from classes
    const classes = ($node.attr('class') || '').split(/\s+/).filter(Boolean);
    for (const cls of classes) {
      const clsProps = styleMap[`.${cls}`];
      if (clsProps) Object.assign(allCSS, clsProps);
      // Also try tag.class selector
      const tagClsProps = styleMap[`${tagName}.${cls}`];
      if (tagClsProps) Object.assign(allCSS, tagClsProps);
    }

    // 2. Check tag-level CSS rules
    const tagProps = styleMap[tagName];
    if (tagProps) {
      // Apply tag-level rules with lower priority (don't override class rules)
      for (const [k, v] of Object.entries(tagProps)) {
        if (!(k in allCSS)) allCSS[k] = v;
      }
    }

    // 3. Inline styles override everything
    const inlineStyle = $node.attr('style');
    if (inlineStyle) {
      Object.assign(allCSS, parseInlineStyle(inlineStyle));
    }

    // Container-ish tags (div/nav/header/main/...) map to a Bricks `container`,
    // which is display:flex. If the resolved styles don't actually lay the
    // element out as flex/grid, downgrade it to a plain `block` (a real <div>)
    // so it keeps normal block flow instead of inheriting flex positioning it
    // never had. (Reported: plain wrapper divs silently becoming flex containers.)
    if (bricksName === 'container') {
      const disp = String(allCSS['display'] || '').toLowerCase().trim();
      if (!['flex', 'inline-flex', 'grid', 'inline-grid'].includes(disp)) {
        bricksName = 'block';
      }
    }

    // Map all CSS to Bricks settings
    const { settings: mappedSettings, unmappedCSS } = mapCSSToSettings(allCSS);
    combinedUnmapped = unmappedCSS;

    // Build the element settings
    const settings = { ...mappedSettings };

    // Handle heading tag
    if (bricksName === 'heading') {
      settings.tag = tagName.toLowerCase();
    }

    // Handle text content
    if (['heading', 'text-basic', 'button'].includes(bricksName)) {
      const innerHTML = $node.html();
      const directText = $node.contents().filter((_, el) => el.type === 'text').text().trim();

      // Check if element has child tags that are meaningful (not just inline formatting)
      const hasBlockChildren = $node.children().filter((_, c) => {
        const childTag = c.tagName?.toLowerCase();
        return childTag && !['br', 'strong', 'em', 'b', 'i', 'u', 'span', 'a', 'small', 'sub', 'sup', 'mark', 'code'].includes(childTag);
      }).length > 0;

      if (!hasBlockChildren && innerHTML) {
        // Use innerHTML to preserve inline formatting
        settings.text = innerHTML.trim();
      } else if (directText) {
        settings.text = directText;
      }
    }

    // Handle list content (ul/ol)
    if ((tagName === 'ul' || tagName === 'ol') && bricksName === 'text-basic') {
      settings.text = $node.html()?.trim() || '';
    }

    // Handle image
    if (bricksName === 'image') {
      const src = $node.attr('src');
      const alt = $node.attr('alt');
      if (src) {
        settings.image = { url: src };
        if (alt) settings.image.alt = alt;
      }
    }

    // Handle button/link
    if (bricksName === 'button') {
      const href = $node.attr('href');
      if (href) settings.link = { url: href, type: 'external' };
      // Get text content if not already set
      if (!settings.text) {
        settings.text = $node.text().trim() || '';
      }
    }

    // Handle video
    if (bricksName === 'video') {
      const src = $node.attr('src');
      if (src) settings.videoUrl = src;
    }

    // Handle form fields
    if (bricksName === 'form-field') {
      const type = $node.attr('type') || 'text';
      const placeholder = $node.attr('placeholder');
      const name = $node.attr('name');
      if (type) settings.type = type;
      if (placeholder) settings.placeholder = placeholder;
      if (name) settings.fieldName = name;
    }

    // Handle hr as separator
    if (tagName === 'hr') {
      settings._height = '1';
      settings._width = '100%';
      settings._background = { color: { raw: '#e0e0e0' } };
    }

    // Upgrade bare gap declarations to !important (Bricks _gap is unreliable)
    // gap/row-gap/column-gap are already in combinedUnmapped from mapCSSToSettings
    combinedUnmapped = combinedUnmapped.map(s => {
      if (/^gap:\s/.test(s) && !s.includes('!important')) return s + ' !important';
      return s;
    });

    // Grid display → _cssCustom only (never _display: "grid" — brx-grid bug)
    if (allCSS['display'] === 'grid' || allCSS['display'] === 'inline-grid') {
      const gridParts = ['display: grid !important'];
      if (allCSS['grid-template-columns']) gridParts.push(`grid-template-columns: ${allCSS['grid-template-columns']} !important`);
      if (allCSS['grid-template-rows']) gridParts.push(`grid-template-rows: ${allCSS['grid-template-rows']}`);
      // Remove already-collected grid props to avoid duplication
      combinedUnmapped = combinedUnmapped.filter(s =>
        !s.startsWith('display:') &&
        !s.startsWith('grid-template-columns:') &&
        !s.startsWith('grid-template-rows:')
      );
      combinedUnmapped.unshift(...gridParts);
      // Override _display to block (avoid brx-grid bug)
      delete settings._display;
    }

    // Grid child placement
    if (allCSS['grid-column'] && !combinedUnmapped.some(s => s.startsWith('grid-column:'))) {
      combinedUnmapped.push(`grid-column: ${allCSS['grid-column']}`);
    }
    if (allCSS['grid-row'] && !combinedUnmapped.some(s => s.startsWith('grid-row:'))) {
      combinedUnmapped.push(`grid-row: ${allCSS['grid-row']}`);
    }

    if (combinedUnmapped.length > 0) {
      const existing = settings._cssCustom || '';
      const newCss = `%root% { ${combinedUnmapped.join('; ')}; }`;
      settings._cssCustom = existing ? `${existing}\n${newCss}` : newCss;
    }

    // Create element
    const element = {
      id,
      name: bricksName,
      parent: parentId,
      children: [],
      settings,
    };

    // Add label from class or tag
    const label = $node.attr('data-label') || $node.attr('aria-label') || classes[0] || '';
    if (label) element.label = label;

    // Preserve source class names for global class resolution
    if (classes.length > 0) {
      element._sourceClasses = classes.join(' ');
    }

    elements.push(element);

    // Process child elements (only for container-like elements)
    if (['section', 'container', 'block', 'form'].includes(bricksName)) {
      $node.children().each((_, child) => {
        if (child.type === 'tag') {
          const childId = processNode(child, id);
          if (childId) element.children.push(childId);
        }
      });
    }

    return id;
  }

  // Start processing from body children (or root if no body)
  const body = $('body').length ? $('body') : $.root();
  body.children().each((_, child) => {
    if (child.type !== 'tag') return;

    const tagName = child.tagName?.toLowerCase();
    if (!tagName || TAG_MAP[tagName] === null) return;

    const bricksName = getElementName(tagName, $(child), $);
    if (bricksName === null) return;

    if (wrapInSection && bricksName !== 'section') {
      // Wrap top-level non-section elements in a section > container
      const sectionId = generateId(usedIds);
      const containerId = generateId(usedIds);

      const section = {
        id: sectionId,
        name: 'section',
        parent: 0,
        children: [containerId],
        settings: {},
      };

      const container = {
        id: containerId,
        name: 'container',
        parent: sectionId,
        children: [],
        settings: {},
      };

      elements.push(section);
      elements.push(container);

      // Process the child within this container
      const childId = processNode(child, containerId);
      if (childId) container.children.push(childId);
    } else {
      processNode(child, 0);
    }
  });

  return elements;
}

export { generateId, parseCSS, parseInlineStyle, mapCSSToSettings, stripUnit };
