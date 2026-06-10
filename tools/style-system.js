/**
 * Bricks Builder Style System Tools
 * Color palette, fonts, and global CSS management
 */
import fs from 'fs';
import path from 'path';
import { wpGet, wpGetCached, wpPut, wpPost, wpDelete, wpPostFile } from '../utils/wp-api.js';
import { cache, TTL } from '../utils/cache.js';

const DOWNLOAD_DIR = path.resolve(path.dirname(new URL(import.meta.url).pathname), '../../downloads');

const styleSystemTools = [
  // Color Palette tools
  {
    name: 'bricks_get_color_palette',
    description: 'Get the Bricks Builder color palette. Returns all defined color variables with their values.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    handler: async (args) => {
      try {
        const data = await wpGetCached('/color-palette', TTL.COLOR_PALETTE);

        const palette = data.color_palette || data.colors || [];
        if (!palette || palette.length === 0) {
          return {
            content: [{ type: 'text', text: 'No color palette found.' }],
          };
        }

        const colors = palette.map(c =>
          `${c.name || 'unnamed'}: ${c.raw || c.hex || c.value || 'N/A'} (${c.id || 'no-id'})`
        ).join('\n');

        return {
          content: [{
            type: 'text',
            text: `Color Palette (${palette.length} colors):\n\n${colors}\n\nRaw data:\n${JSON.stringify(palette, null, 2)}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error getting color palette: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_update_color_palette',
    description: 'Update the Bricks Builder color palette. Can add, modify, or replace colors. Bricks 2.3: Supports auto-generating dark mode variants from light colors using OKLCH color science.',
    inputSchema: {
      type: 'object',
      properties: {
        colors: {
          type: 'array',
          description: 'Array of color definitions. Each needs at minimum: {id, name, raw}',
          items: {
            type: 'object',
            properties: {
              id: { type: 'string', description: 'Color variable ID' },
              name: { type: 'string', description: 'Human-readable color name' },
              raw: { type: 'string', description: 'Color value (hex, rgb, hsl)' },
            },
            required: ['name', 'raw'],
          },
        },
        merge: {
          type: 'boolean',
          default: true,
          description: 'If true (default), merge with existing palette. If false, replace entirely.',
        },
        generate_dark_variants: {
          type: 'boolean',
          default: false,
          description: 'Bricks 2.3: Auto-generate dark mode variants for each color using OKLCH. Adds "-dark" suffixed colors with inverted lightness.',
        },
      },
      required: ['colors'],
    },
    handler: async (args) => {
      try {
        const { colors, merge = true, generate_dark_variants = false } = args;

        if (!colors || !Array.isArray(colors) || colors.length === 0) {
          return { content: [{ type: 'text', text: 'Error: colors must be a non-empty array' }] };
        }

        let processedColors = [...colors];

        // Auto-generate dark mode variants using OKLCH
        if (generate_dark_variants) {
          const darkVariants = [];
          for (const color of colors) {
            if (!color.raw || !color.raw.startsWith('#')) continue;
            const darkHex = generateDarkVariant(color.raw);
            if (darkHex) {
              darkVariants.push({
                id: color.id ? `${color.id}-dark` : undefined,
                name: `${color.name} (Dark)`,
                raw: darkHex,
              });
            }
          }
          processedColors = [...colors, ...darkVariants];
        }

        let finalColors = processedColors;
        if (merge) {
          const existing = await wpGet('/color-palette');
          const existingPalette = existing.color_palette || existing.colors || [];
          if (Array.isArray(existingPalette) && existingPalette.length > 0) {
            // Merge: update existing by id, add new ones
            const merged = [...existingPalette];
            for (const newColor of processedColors) {
              const idx = merged.findIndex(c => c.id && newColor.id && c.id === newColor.id);
              if (idx >= 0) {
                merged[idx] = { ...merged[idx], ...newColor };
              } else {
                merged.push(newColor);
              }
            }
            finalColors = merged;
          }
        }

        // Invalidate cache
        cache.invalidatePrefix('/color-palette');

        const result = await wpPut('/color-palette', { color_palette: finalColors });

        const darkNote = generate_dark_variants
          ? `\nDark variants: ${processedColors.length - colors.length} generated`
          : '';

        return {
          content: [{
            type: 'text',
            text: `Color palette updated.\nMode: ${merge ? 'merge' : 'replace'}\nColors: ${colors.length} ${merge ? 'added/updated' : 'set'}\nTotal: ${result.count || finalColors.length}${darkNote}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error updating color palette: ${error.message}` }],
        };
      }
    },
  },

  // Font tools
  {
    name: 'bricks_list_fonts',
    description: 'List all custom fonts registered in Bricks Builder.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    handler: async (args) => {
      try {
        const data = await wpGetCached('/fonts', TTL.PRESETS);

        const fonts = data.fonts || data || [];
        if (!fonts || !Array.isArray(fonts) || fonts.length === 0) {
          return {
            content: [{ type: 'text', text: 'No custom fonts found. Default system/Google fonts are still available.' }],
          };
        }

        const list = fonts.map(f =>
          `${f.name} | ${f.type || 'custom'} | Variants: ${(f.variants || f.weights || []).join(', ') || 'regular'}`
        ).join('\n');

        return {
          content: [{
            type: 'text',
            text: `Found ${fonts.length} custom font(s):\n\n${list}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error listing fonts: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_register_font',
    description: 'Register a custom font in Bricks Builder. Supports Google Fonts, custom uploaded fonts, and Adobe Fonts.',
    inputSchema: {
      type: 'object',
      properties: {
        name: {
          type: 'string',
          description: 'Font family name (e.g., "Inter", "PP Neue Montreal")',
        },
        type: {
          type: 'string',
          enum: ['google', 'custom', 'adobe'],
          description: 'Font source type',
        },
        variants: {
          type: 'array',
          items: { type: 'string' },
          description: 'Font variants to load (e.g., ["300", "400", "500", "600", "700"])',
        },
        url: {
          type: 'string',
          description: 'URL for custom font files (for type "custom")',
        },
      },
      required: ['name', 'type'],
    },
    handler: async (args) => {
      try {
        const { name, type, variants, url } = args;

        if (!name) {
          return { content: [{ type: 'text', text: 'Error: name is required' }] };
        }

        const font = { name, type };
        if (variants) font.variants = variants;
        if (url) font.url = url;

        // Read existing fonts first (read-modify-write)
        cache.invalidatePrefix('/fonts');
        const existing = await wpGet('/fonts');
        const existingFonts = existing?.fonts || [];

        // Replace if same name exists, otherwise append
        const idx = existingFonts.findIndex(f => f.name === name);
        if (idx >= 0) {
          existingFonts[idx] = font;
        } else {
          existingFonts.push(font);
        }

        const result = await wpPost('/fonts', { fonts: existingFonts });

        return {
          content: [{
            type: 'text',
            text: `Font "${name}" registered.\nType: ${type}\nVariants: ${(variants || ['regular']).join(', ')}\nTotal fonts: ${existingFonts.length}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error registering font: ${error.message}` }],
        };
      }
    },
  },

  // Upload + Register Custom Font (all-in-one)
  {
    name: 'bricks_upload_font',
    description: 'Upload custom font files (.woff2, .woff, .ttf, .otf) and register them in Bricks Builder. Uploads to WordPress Media Library, registers the font, and generates @font-face CSS in Global CSS.',
    inputSchema: {
      type: 'object',
      properties: {
        name: {
          type: 'string',
          description: 'Font family name (e.g., "PP Neue Montreal", "Cabinet Grotesk")',
        },
        files: {
          type: 'array',
          items: {
            type: 'object',
            properties: {
              path: { type: 'string', description: 'Absolute local file path to font file (.woff2/.woff/.ttf/.otf)' },
              url: { type: 'string', description: 'URL to download font file from (alternative to path)' },
              weight: { type: 'string', description: 'Font weight: "100"-"900" (default: "400")' },
              style: { type: 'string', description: 'Font style: "normal" or "italic" (default: "normal")' },
            },
          },
          description: 'Array of font files with weight/style mapping. Provide either path or url per file.',
        },
        display: {
          type: 'string',
          enum: ['swap', 'block', 'fallback', 'optional', 'auto'],
          description: 'CSS font-display value (default: "swap")',
        },
      },
      required: ['name', 'files'],
    },
    handler: async (args) => {
      try {
        const { name: fontName, files, display = 'swap' } = args;

        if (!fontName) {
          return { content: [{ type: 'text', text: 'Error: name is required' }] };
        }
        if (!files || !Array.isArray(files) || files.length === 0) {
          return { content: [{ type: 'text', text: 'Error: files array is required (at least one font file)' }] };
        }

        const validExts = ['.woff2', '.woff', '.ttf', '.otf'];
        const uploadResults = [];
        const errors = [];

        // Step 1: Upload each font file to WordPress Media Library
        for (const file of files) {
          const weight = file.weight || '400';
          const style = file.style || 'normal';

          let localPath = file.path;
          let tempFile = false;

          // Download from URL if no local path
          if (file.url && !file.path) {
            try {
              if (!fs.existsSync(DOWNLOAD_DIR)) fs.mkdirSync(DOWNLOAD_DIR, { recursive: true });
              const urlPath = new URL(file.url).pathname;
              const filename = path.basename(urlPath) || `font-${Date.now()}.woff2`;
              localPath = path.join(DOWNLOAD_DIR, filename);

              const response = await fetch(file.url);
              if (!response.ok) throw new Error(`Download failed: ${response.status}`);
              const buffer = Buffer.from(await response.arrayBuffer());
              fs.writeFileSync(localPath, buffer);
              tempFile = true;
            } catch (e) {
              errors.push(`Download failed for weight ${weight}: ${e.message}`);
              continue;
            }
          }

          if (!localPath || !fs.existsSync(localPath)) {
            errors.push(`File not found: ${localPath || '(no path)'} (weight ${weight})`);
            continue;
          }

          // Validate extension
          const ext = path.extname(localPath).toLowerCase();
          if (!validExts.includes(ext)) {
            errors.push(`Invalid font format: ${ext} (weight ${weight}). Supported: ${validExts.join(', ')}`);
            if (tempFile) fs.unlinkSync(localPath);
            continue;
          }

          try {
            const result = await wpPostFile('/wp/v2/media', localPath, {
              title: `${fontName} ${weight}${style === 'italic' ? ' Italic' : ''}`,
            });

            uploadResults.push({
              attachment_id: result.id,
              url: result.source_url,
              weight,
              style,
            });
          } catch (e) {
            errors.push(`Upload failed for weight ${weight}: ${e.message}`);
          }

          // Cleanup temp files
          if (tempFile && fs.existsSync(localPath)) fs.unlinkSync(localPath);
        }

        if (uploadResults.length === 0) {
          return {
            content: [{
              type: 'text',
              text: `Error: No font files uploaded successfully.\n${errors.join('\n')}`,
            }],
          };
        }

        // Step 2: Call PHP endpoint to register font + generate @font-face CSS
        cache.invalidatePrefix('/fonts');
        cache.invalidatePrefix('/global-css');

        const registerResult = await wpPost('/fonts/register-custom', {
          font_family: fontName,
          files: uploadResults.map(r => ({
            attachment_id: r.attachment_id,
            weight: r.weight,
            style: r.style,
          })),
          display,
        });

        // Build summary
        const lines = [
          `Font "${fontName}" registered successfully!`,
          '',
          `Variants uploaded: ${uploadResults.map(r => `${r.weight}${r.style === 'italic' ? 'i' : ''}`).join(', ')}`,
          `Font display: ${display}`,
          `Total fonts registered: ${registerResult.total_fonts || '?'}`,
          '',
          `@font-face CSS added to Global CSS.`,
          '',
          `Use in Bricks elements:`,
          `  _typography: { 'font-family': '${fontName}' }`,
        ];

        if (errors.length > 0) {
          lines.push('', `Warnings:`, ...errors.map(e => `  - ${e}`));
        }

        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error uploading font: ${error.message}` }] };
      }
    },
  },

  // Global CSS tools
  {
    name: 'bricks_get_global_css',
    description: 'Get the global custom CSS from Bricks Builder settings.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    handler: async (args) => {
      try {
        const data = await wpGetCached('/global-css', TTL.PRESETS);

        return {
          content: [{
            type: 'text',
            text: `Global CSS (${(data.global_css || '').length} chars):\n\n${data.global_css || '(empty)'}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error getting global CSS: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_update_global_css',
    description: 'Update the global custom CSS in Bricks Builder. This CSS is applied site-wide.',
    inputSchema: {
      type: 'object',
      properties: {
        css: {
          type: 'string',
          description: 'Complete CSS to set as global custom CSS',
        },
        append: {
          type: 'boolean',
          default: false,
          description: 'If true, append to existing CSS instead of replacing',
        },
      },
      required: ['css'],
    },
    handler: async (args) => {
      try {
        const { css, append = false } = args;

        let finalCss = css;
        if (append) {
          const existing = await wpGet('/global-css');
          finalCss = ((existing.global_css || '') + '\n\n' + css).trim();
        }

        // Invalidate cache
        cache.invalidatePrefix('/global-css');

        const result = await wpPut('/global-css', { global_css: finalCss });

        return {
          content: [{
            type: 'text',
            text: `Global CSS ${append ? 'appended' : 'updated'}.\nTotal length: ${result.length || finalCss.length} characters`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error updating global CSS: ${error.message}` }],
        };
      }
    },
  },

  // CSS Variables tools
  {
    name: 'bricks_get_css_variables',
    description: 'Get all CSS variables (design tokens) from Bricks Global Variables Manager. Returns variable names and values (colors, spacing, z-index, container sizes, etc.).',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    handler: async (args) => {
      try {
        const data = await wpGetCached('/css-variables', TTL.COLOR_PALETTE);

        let variables = data.css_variables || [];
        // Legacy/corrupted data can come back as a JSON string. Parse it so it renders
        // as real tokens instead of being iterated character-by-character.
        if (typeof variables === 'string') {
          try { variables = JSON.parse(variables); } catch (e) { /* leave as-is */ }
        }
        const count = Array.isArray(variables) ? variables.length : (data.count || 0);
        const optionKey = data.option_key || 'unknown';

        if (!variables || (Array.isArray(variables) && variables.length === 0) || (typeof variables === 'object' && Object.keys(variables).length === 0)) {
          return {
            content: [{ type: 'text', text: `No CSS variables found. Option key tried: ${optionKey}\n\nThis might mean the variables are stored under a different option key. Check Bricks version.` }],
          };
        }

        // Format variables - handle both array and object formats
        let formatted;
        if (Array.isArray(variables)) {
          formatted = variables.map(v =>
            `${v.name || v.id || 'unnamed'}: ${v.value || v.raw || 'N/A'}`
          ).join('\n');
        } else {
          formatted = Object.entries(variables).map(([key, val]) =>
            `${key}: ${typeof val === 'object' ? JSON.stringify(val) : val}`
          ).join('\n');
        }

        return {
          content: [{
            type: 'text',
            text: `CSS Variables (${count} total, option: ${optionKey}):\n\n${formatted}\n\nRaw data:\n${JSON.stringify(variables, null, 2)}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error getting CSS variables: ${error.message}` }],
        };
      }
    },
  },

  {
    name: 'bricks_update_css_variables',
    description: 'Update CSS variables in the Bricks Global Variables Manager.',
    inputSchema: {
      type: 'object',
      properties: {
        css_variables: {
          description: 'Array or object of CSS variable definitions to set. Format depends on what Bricks uses (discovered from GET).',
        },
      },
      required: ['css_variables'],
    },
    handler: async (args) => {
      try {
        let { css_variables } = args;
        // Bricks stores `bricks_global_variables` as an ARRAY. If we forward a JSON
        // string, the option is saved as a string and the builder's Variables Manager
        // iterates it character-by-character (thousands of garbage rows -> broken UI).
        // Always send a real array/object.
        if (typeof css_variables === 'string') {
          try { css_variables = JSON.parse(css_variables); } catch (e) { /* leave as-is */ }
        }

        cache.invalidatePrefix('/css-variables');

        const result = await wpPut('/css-variables', { css_variables });

        return {
          content: [{
            type: 'text',
            text: `CSS variables updated.\nCount: ${result.count || 'unknown'}\nOption key: ${result.option_key || 'unknown'}`,
          }],
        };
      } catch (error) {
        return {
          content: [{ type: 'text', text: `Error updating CSS variables: ${error.message}` }],
        };
      }
    },
  },

  // Breakpoints
  {
    name: 'bricks_get_breakpoints',
    description: 'Get all active Bricks Builder breakpoints with pixel values and setting suffixes. Returns default breakpoints (Desktop, Tablet Portrait <992px, Mobile Landscape <768px, Mobile Portrait <478px) plus any custom breakpoints.',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    handler: async (args) => {
      try {
        const data = await wpGetCached('/breakpoints', TTL.PRESETS);
        const bps = data.breakpoints || [];

        if (!bps.length) {
          return { content: [{ type: 'text', text: 'No breakpoints found.' }] };
        }

        const table = bps.map(bp => {
          const suffix = bp.base ? '(base)' : `:${bp.key}`;
          const media = bp.width ? `@media (max-width: ${bp.width - 1}px)` : '(no query)';
          return `${bp.label} | ${suffix} | ${bp.width ? '<' + bp.width + 'px' : 'base'} | ${media}`;
        }).join('\n');

        return {
          content: [{
            type: 'text',
            text: `Breakpoints (${bps.length} total, custom: ${data.has_custom ? 'yes' : 'no'}):\n\n${table}`,
          }],
        };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting breakpoints: ${error.message}` }] };
      }
    },
  },
];

// ─── Dark Mode Variant Generator (OKLCH-based) ─────────────────────────────

/**
 * Generate a dark mode variant of a hex color by inverting OKLCH lightness.
 *
 * Light colors (L > 0.5) become dark, dark colors become light.
 * Preserves chroma and hue for perceptually consistent dark variants.
 *
 * @param {string} hex - Hex color value (#RGB or #RRGGBB)
 * @returns {string|null} Dark variant hex or null on error
 */
function generateDarkVariant(hex) {
  try {
    // Parse hex to RGB
    let r, g, b;
    const cleaned = hex.replace('#', '');
    if (cleaned.length === 3) {
      r = parseInt(cleaned[0] + cleaned[0], 16) / 255;
      g = parseInt(cleaned[1] + cleaned[1], 16) / 255;
      b = parseInt(cleaned[2] + cleaned[2], 16) / 255;
    } else if (cleaned.length === 6) {
      r = parseInt(cleaned.slice(0, 2), 16) / 255;
      g = parseInt(cleaned.slice(2, 4), 16) / 255;
      b = parseInt(cleaned.slice(4, 6), 16) / 255;
    } else {
      return null;
    }

    // Approximate OKLCH lightness from sRGB
    // Using simplified perceptual luminance
    const L = 0.2126 * gammaToLinear(r) + 0.7152 * gammaToLinear(g) + 0.0722 * gammaToLinear(b);
    const oklL = Math.cbrt(L); // Approximate OKLab L

    // Invert lightness: light → dark, dark → light
    // Target: remap L so that 0.8 → 0.2, 0.9 → 0.15, etc.
    const targetL = Math.max(0.05, Math.min(0.95, 1 - oklL));
    const ratio = oklL > 0.01 ? targetL / oklL : 1;

    // Scale RGB channels to achieve target lightness
    // This is approximate but works well for palette generation
    const factor = Math.pow(ratio, 3); // Cube to reverse cbrt
    const nr = Math.max(0, Math.min(1, linearToGamma(gammaToLinear(r) * factor)));
    const ng = Math.max(0, Math.min(1, linearToGamma(gammaToLinear(g) * factor)));
    const nb = Math.max(0, Math.min(1, linearToGamma(gammaToLinear(b) * factor)));

    // Convert back to hex
    const toHex = (v) => Math.round(v * 255).toString(16).padStart(2, '0');
    return `#${toHex(nr)}${toHex(ng)}${toHex(nb)}`;
  } catch {
    return null;
  }
}

function gammaToLinear(c) {
  return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function linearToGamma(c) {
  return c <= 0.0031308 ? c * 12.92 : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;
}

export { styleSystemTools };
