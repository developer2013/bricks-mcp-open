/**
 * Bricks Builder Page Tools
 * Tools for listing, reading, and updating Bricks page data
 */
import { wpGet, wpGetCached, wpPut, wpPatch, wpPost, wpFetch, wpDelete } from '../utils/wp-api.js';
import { cache, TTL } from '../utils/cache.js';
import { validateContent, isValidBricksId } from '../utils/validator.js';
import { autofix } from '../utils/autofix.js';
import { getActiveSiteKey } from '../site-manager.js';
import { classifyCss, formatEditableCssWarning } from '../utils/css-classify.js';

function formatPageEntry(page) {
  const elements = page.element_count || 0;
  return `ID: ${page.id} | ${(page.status || 'unknown').toUpperCase()} | /${page.slug}/ | ${page.title} | ${elements} elements | ${page.url || ''}`;
}

function buildTreeSummary(elements) {
  // Build parent→children index once (O(n)), then walk (O(n)).
  // Old approach: .filter() per level = O(n²) for deep trees.
  const childrenOf = new Map();
  for (const el of elements) {
    const pid = el.parent || 0;
    if (!childrenOf.has(pid)) childrenOf.set(pid, []);
    childrenOf.get(pid).push(el);
  }

  const lines = [];
  function walk(parentId, depth) {
    const children = childrenOf.get(parentId) || [];
    for (const el of children) {
      const indent = '  '.repeat(depth);
      const label = el.label || '';
      const labelStr = label ? ` "${label}"` : '';
      lines.push(`${indent}- ${el.name}${labelStr} [${el.id}]`);
      walk(el.id, depth + 1);
    }
  }
  walk(0, 0);
  return lines;
}

const pageTools = [
  {
    name: 'bricks_list_pages',
    description: 'List all WordPress pages/posts that have Bricks Builder data. Returns page ID, title, slug, status, element count, and URL.',
    inputSchema: {
      type: 'object',
      properties: {
        post_type: { type: 'string', description: 'Post type to query (default: page)', default: 'page' },
        status: { type: 'string', description: 'Post status filter (publish, draft, any)', default: 'any' },
      },
    },
    handler: async (args) => {
      try {
        const postType = args.post_type || 'page';
        const status = args.status || 'any';
        let endpoint = `/pages?post_type=${encodeURIComponent(postType)}`;
        if (status && status !== 'any') endpoint += `&status=${encodeURIComponent(status)}`;

        const data = await wpGetCached(endpoint, TTL.PAGE_LIST);
        if (!data || !Array.isArray(data) || data.length === 0) {
          return { content: [{ type: 'text', text: 'No Bricks pages found.' }] };
        }
        const pageList = data.map(formatPageEntry).join('\n');
        return { content: [{ type: 'text', text: `Found ${data.length} Bricks page(s):\n\n${pageList}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error listing pages: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_get_page',
    description: 'Get the complete Bricks Builder JSON data for a specific page. Returns the full element tree with all settings, styles, and content. Use content_area to read header or footer template data instead of main content.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        summary: { type: 'boolean', description: 'If true, show element tree overview instead of raw JSON (default: false)', default: false },
        content_area: { type: 'string', enum: ['content', 'header', 'footer'], description: 'Which content area to return (default: content). Header/footer return their respective template elements.', default: 'content' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, summary, content_area = 'content' } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const data = await wpGetCached(`/pages/${page_id}`, TTL.PAGE_DETAIL);
        if (!data) return { content: [{ type: 'text', text: `No data found for page ${page_id}` }] };

        // Select elements based on content_area
        const areaDataKey = { content: 'bricks_data', header: 'bricks_header_data', footer: 'bricks_footer_data' }[content_area];
        const areaElements = data[areaDataKey] || (content_area === 'content' ? data.bricks_data : null);
        const areaLabel = content_area !== 'content' ? ` [${content_area}]` : '';

        if (!areaElements && content_area !== 'content') {
          return { content: [{ type: 'text', text: `No ${content_area} data found for page ${page_id}. This page may use a template for its ${content_area}.` }] };
        }

        const headerInfo = [
          `Page ID: ${data.id || page_id}${areaLabel}`,
          `Title: ${data.title || 'Untitled'}`,
          `Status: ${data.status || 'unknown'}`,
          `URL: ${data.url || 'N/A'}`,
          `Elements (${content_area}): ${areaElements ? areaElements.length : 0}`,
          data.content_hash ? `Content Hash: ${data.content_hash}` : null,
          data.bricks_header_data ? `Header: ${data.bricks_header_data.length} elements` : null,
          data.bricks_footer_data ? `Footer: ${data.bricks_footer_data.length} elements` : null,
        ].filter(Boolean).join('\n');

        if (summary && areaElements) {
          const tree = buildTreeSummary(areaElements);
          return { content: [{ type: 'text', text: `${headerInfo}\n\nElement Tree (${content_area}):\n${tree.join('\n')}` }] };
        }

        const elements = areaElements || [];
        let summaryHeader = '';
        if (elements.length >= 10) {
          const sections = elements.filter(el => el.name === 'section');
          summaryHeader = `\n--- Overview: ${elements.length} elements in ${sections.length} section(s). ---\n`;
        }

        return { content: [{ type: 'text', text: `${headerInfo}${summaryHeader}\nBricks Data (${content_area}):\n${JSON.stringify(elements, null, 2)}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting page: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_page',
    description: 'Update the Bricks Builder data for a page. Automatically creates a backup before writing. Validates the JSON structure before saving. Supports optimistic locking via content_hash. Use content_area to write header or footer template data. THEME-FIRST: before generating elements, call bricks_get_theme_styles and bricks_list_global_classes — reuse existing tokens (button styles, utility classes, color palette) instead of inline styling. For buttons prefer settings.style: "primary"|"secondary" over manual _background/_typography. For links use the text-link element, not a styled text-basic. Use _cssCustom only for what settings cannot express.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        bricks_data: { type: 'array', description: 'Array of Bricks elements to save', items: { type: 'object' } },
        content_hash: { type: 'string', description: 'Content hash from bricks_get_page for optimistic locking. If provided, update fails with 409 if page was modified since read.' },
        content_area: { type: 'string', enum: ['content', 'header', 'footer'], description: 'Which content area to write (default: content). Use header/footer to manage template elements.', default: 'content' },
      },
      required: ['page_id', 'bricks_data'],
    },
    handler: async (args) => {
      try {
        const { page_id, bricks_data, content_hash, content_area = 'content' } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!bricks_data || !Array.isArray(bricks_data)) return { content: [{ type: 'text', text: 'Error: bricks_data must be an array of elements' }] };

        // Auto-fix common issues before validation
        const fixResult = autofix(bricks_data);
        const fixedData = fixResult.content;
        const fixLog = fixResult.log;

        const validation = validateContent(fixedData);
        if (!validation.valid) {
          const errorList = validation.errors.map(e => `  - ${e}`).join('\n');
          return { content: [{ type: 'text', text: `Validation failed with ${validation.errors.length} error(s):\n${errorList}\n\nFix these issues and try again.` }] };
        }

        const putOptions = content_hash ? { contentHash: content_hash } : {};
        const body = { bricks_data: fixedData };
        if (content_area !== 'content') body.content_area = content_area;
        const result = await wpPut(`/pages/${page_id}`, body, putOptions);
        cache.invalidatePrefix(`/pages/${page_id}`);
        cache.invalidatePrefix('/pages?');

        const fixInfo = fixLog.length > 0 ? `\nAuto-fixed ${fixLog.length} issue(s):\n${fixLog.map(l => `  - ${l}`).join('\n')}` : '';
        const warnInfo = validation.warnings.length > 0 ? `\n\n⚠️ ${validation.warnings.length} warning(s):\n${validation.warnings.map(w => `  - ${w}`).join('\n')}` : '';
        const infoHints = validation.info.length > 0 ? `\nℹ️ ${validation.info.length} hint(s):\n${validation.info.map(i => `  - ${i}`).join('\n')}` : '';

        return { content: [{ type: 'text', text: `Page ${page_id} updated successfully.\nBackup created: yes\nElements saved: ${fixedData.length}${fixInfo}${warnInfo}${infoHints}\n\nPage data saved. No need to re-fetch unless verifying.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error updating page: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_patch_page',
    description: 'Apply partial updates to a page. Only sends changed elements instead of full page data. More efficient than bricks_update_page for small changes. Automatically creates a backup before writing (plugin-side, cannot be skipped). Supports optimistic locking via content_hash. Use content_area to patch header or footer. THEME-FIRST: when adding or restyling elements, reuse existing theme tokens and global classes (bricks_get_theme_styles / bricks_list_global_classes) instead of inline styling; prefer settings.style and _typography over _cssCustom, and the text-link/button elements over styled divs.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'Page ID' },
        add: { type: 'array', items: { type: 'object' }, description: 'New elements to add' },
        update: { type: 'array', items: { type: 'object' }, description: 'Elements to update (must include id, only changed settings needed)' },
        remove: { type: 'array', items: { type: 'string' }, description: 'Element IDs to remove' },
        regenerate_css: { type: 'boolean', default: true, description: 'Whether to regenerate CSS after patching (default: true)' },
        content_hash: { type: 'string', description: 'Content hash from bricks_get_page for optimistic locking. If provided, patch fails with 409 if page was modified since read.' },
        content_area: { type: 'string', enum: ['content', 'header', 'footer'], description: 'Which content area to patch (default: content).', default: 'content' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, add = [], update = [], remove = [], regenerate_css = true, content_hash, content_area = 'content' } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        // Validation Gate: validate 'add' elements
        let fixedAdd = add;
        let addFixLog = [];
        if (add.length > 0) {
          const fixResult = autofix(add);
          fixedAdd = fixResult.content;
          addFixLog = fixResult.log;
          const validation = validateContent(fixedAdd);
          if (!validation.valid) {
            const errorList = validation.errors.map(e => `  - ${e}`).join('\n');
            return { content: [{ type: 'text', text: `Validation failed for 'add' elements (${validation.errors.length} errors):\n${errorList}\n\nFix these and retry.` }] };
          }
        }

        // Validation Gate: validate 'update' element IDs
        for (const el of update) {
          if (el.id && !isValidBricksId(el.id)) {
            return { content: [{ type: 'text', text: `Invalid element ID "${el.id}" in update array. Bricks IDs must be exactly 6 lowercase alphanumeric characters with at least one digit.` }] };
          }
        }

        const body = { add: fixedAdd, update, remove };
        if (content_area !== 'content') body.content_area = content_area;
        const endpoint = `/pages/${page_id}?regenerate_css=${regenerate_css}`;
        cache.invalidatePrefix(`/pages/${page_id}`);
        cache.invalidatePrefix('/pages?');

        const patchOptions = content_hash ? { contentHash: content_hash } : {};
        const result = await wpPatch(endpoint, body, patchOptions);

        // Smart response: show what was changed
        const parts = [];
        if (fixedAdd.length > 0) parts.push(`Added ${fixedAdd.length}: ${fixedAdd.map(e => e.id).join(', ')}`);
        if (update.length > 0) parts.push(`Updated ${update.length}: ${update.map(e => e.id).join(', ')}`);
        if (remove.length > 0) parts.push(`Removed ${remove.length}: ${remove.join(', ')}`);
        const fixInfo = addFixLog.length > 0 ? `\nAuto-fixed ${addFixLog.length} issue(s) in added elements.` : '';

        return { content: [{ type: 'text', text: `Page ${page_id} patched.\n${parts.join('\n')}\nTotal elements: ${result.element_count || 'unknown'}${fixInfo}\n\nPage state updated. No need to re-fetch full page.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error patching page: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_append_elements',
    description: 'Append elements to an existing page without replacing existing content. Elements are added after existing elements. Automatically creates a backup before writing (plugin-side, cannot be skipped). THEME-FIRST: before generating elements, call bricks_get_theme_styles and bricks_list_global_classes — reuse existing tokens (button styles, utility classes, color palette) instead of inline styling. For buttons prefer settings.style: "primary"|"secondary" over manual _background/_typography. For links use the text-link element, not a styled text-basic.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        elements: { type: 'array', items: { type: 'object' }, description: 'Array of Bricks elements to append' },
        parent_id: { type: 'string', description: 'Parent element ID to append under (optional, appends at root level if omitted)' },
        regenerate_css: { type: 'boolean', default: true, description: 'Whether to regenerate CSS after appending (default: true)' },
      },
      required: ['page_id', 'elements'],
    },
    handler: async (args) => {
      try {
        const { page_id, elements, parent_id, regenerate_css = true } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!elements || !Array.isArray(elements) || elements.length === 0) return { content: [{ type: 'text', text: 'Error: elements must be a non-empty array' }] };

        // Validation Gate: autofix + validate before sending
        const fixResult = autofix(elements);
        const fixedElements = fixResult.content;
        const fixLog = fixResult.log;

        const validation = validateContent(fixedElements);
        if (!validation.valid) {
          const errorList = validation.errors.map(e => `  - ${e}`).join('\n');
          return { content: [{ type: 'text', text: `Validation failed (${validation.errors.length} errors):\n${errorList}\n\nFix these and retry.` }] };
        }

        const body = { elements: fixedElements };
        if (parent_id) {
          body.position = `after:${parent_id}`;
          body.parent_id = parent_id;
        }
        const endpoint = `/pages/${page_id}/elements?regenerate_css=${regenerate_css}`;
        cache.invalidatePrefix(`/pages/${page_id}`);
        cache.invalidatePrefix('/pages?');

        const result = await wpPost(endpoint, body);
        const fixInfo = fixLog.length > 0 ? `\nAuto-fixed ${fixLog.length} issue(s).` : '';
        return { content: [{ type: 'text', text: `Appended ${fixedElements.length} element(s) to page ${page_id}.${parent_id ? ` Parent: ${parent_id}` : ''}\nTotal elements: ${result.element_count || 'unknown'}${fixInfo}\n\nPage state updated. No need to re-fetch full page.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error appending elements: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_build_page',
    description: 'Build a complete page from section presets and/or raw elements in a single request. Combines multiple sections into a full page layout. Automatically creates a backup before writing (plugin-side, cannot be skipped). THEME-FIRST: before generating raw elements, call bricks_get_theme_styles and bricks_list_global_classes — reuse existing tokens (button styles, utility classes, color palette) instead of inline styling. For buttons prefer settings.style: "primary"|"secondary" over manual _background/_typography. For links use the text-link element, not a styled text-basic.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        sections: {
          type: 'array',
          description: 'Array of sections to build. Each can be a preset name or raw elements.',
          items: {
            type: 'object',
            properties: {
              preset: { type: 'string', description: 'Preset name (e.g., "hero-dark", "features-grid")' },
              elements: { type: 'array', items: { type: 'object' }, description: 'Raw Bricks elements (used if no preset)' },
              overrides: { type: 'object', description: 'Settings overrides to apply to the preset' },
            },
          },
        },
        regenerate_css: { type: 'boolean', default: true, description: 'Whether to regenerate CSS (default: true)' },
      },
      required: ['page_id', 'sections'],
    },
    handler: async (args) => {
      try {
        const { page_id, sections, regenerate_css = true } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!sections || !Array.isArray(sections) || sections.length === 0) return { content: [{ type: 'text', text: 'Error: sections must be a non-empty array' }] };

        // Validation Gate: validate raw elements in each section
        const allFixLogs = [];
        for (let i = 0; i < sections.length; i++) {
          const section = sections[i];
          if (section.elements && Array.isArray(section.elements) && section.elements.length > 0) {
            const fixResult = autofix(section.elements);
            section.elements = fixResult.content;
            allFixLogs.push(...fixResult.log);

            const validation = validateContent(section.elements);
            if (!validation.valid) {
              const errorList = validation.errors.map(e => `  - ${e}`).join('\n');
              return { content: [{ type: 'text', text: `Validation failed in section[${i}] (${validation.errors.length} errors):\n${errorList}\n\nFix section ${i} and retry.` }] };
            }
          }
        }

        const body = { sections };
        const endpoint = `/pages/${page_id}/build?regenerate_css=${regenerate_css}`;
        cache.invalidatePrefix(`/pages/${page_id}`);
        cache.invalidatePrefix('/pages?');

        const result = await wpPost(endpoint, body);
        const totalElements = result.element_count || sections.reduce((sum, s) => sum + (s.elements?.length || 0), 0);
        const fixInfo = allFixLogs.length > 0 ? `\nAuto-fixed ${allFixLogs.length} issue(s).` : '';

        return { content: [{ type: 'text', text: `Page ${page_id} built with ${sections.length} section(s).\nTotal elements: ${totalElements}${fixInfo}\n\nPage data saved. No need to re-fetch unless verifying.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error building page: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_search_pages',
    description: 'Search pages by title, slug, or content.',
    inputSchema: {
      type: 'object',
      properties: {
        query: { type: 'string', description: 'Search query' },
        post_type: { type: 'string', description: 'Post type to search (default: page)', default: 'page' },
      },
      required: ['query'],
    },
    handler: async (args) => {
      try {
        const { query, post_type = 'page' } = args;
        const data = await wpGet(`/pages?post_type=${encodeURIComponent(post_type)}&search=${encodeURIComponent(query)}`);
        if (!data || !Array.isArray(data) || data.length === 0) {
          return { content: [{ type: 'text', text: `No pages found matching "${query}".` }] };
        }
        const pageList = data.map(formatPageEntry).join('\n');
        return { content: [{ type: 'text', text: `Found ${data.length} page(s) matching "${query}":\n\n${pageList}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error searching pages: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_clone_page',
    description: 'Clone a page with all Bricks data and scripts.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'Page ID to clone' },
        new_title: { type: 'string', description: 'Optional title for the clone' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, new_title } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        cache.invalidatePrefix('/pages');
        const body = {};
        if (new_title) body.title = new_title;
        const result = await wpPost(`/pages/${page_id}/clone`, body);
        return { content: [{ type: 'text', text: `Page cloned. New ID: ${result.id}, Title: ${result.title}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error cloning page: ${error.message}` }] };
      }
    },
  },
];

const searchTools = [
  {
    name: 'bricks_search_elements',
    description: 'Search Bricks elements across all pages. Find elements by text content, element type, CSS class, or specific settings.',
    inputSchema: {
      type: 'object',
      properties: {
        query: { type: 'string', description: 'Free text search (searches text, content, label, title settings)' },
        element_type: { type: 'string', description: 'Filter by element type (heading, button, image, container, section, text-basic, etc.)' },
        css_class: { type: 'string', description: 'Filter by CSS class name (partial match)' },
        setting_key: { type: 'string', description: 'Search for a specific setting key (e.g. "_background", "_typography")' },
        setting_value: { type: 'string', description: 'Filter setting_key results by value (partial match)' },
        post_type: { type: 'string', description: 'Post type to search (default: page)', default: 'page' },
        limit: { type: 'number', description: 'Max results (default: 50, max: 200)', default: 50 },
      },
    },
    handler: async (args) => {
      try {
        const { query, element_type, css_class, setting_key, setting_value, post_type = 'page', limit = 50 } = args;

        if (!query && !element_type && !css_class && !setting_key) {
          return { content: [{ type: 'text', text: 'Error: at least one search parameter is required (query, element_type, css_class, or setting_key)' }] };
        }

        const params = new URLSearchParams();
        if (query) params.set('q', query);
        if (element_type) params.set('element_type', element_type);
        if (css_class) params.set('css_class', css_class);
        if (setting_key) params.set('setting_key', setting_key);
        if (setting_value) params.set('setting_value', setting_value);
        if (post_type) params.set('post_type', post_type);
        if (limit) params.set('limit', String(limit));

        const data = await wpGet(`/elements/search?${params.toString()}`);
        const results = data.results || [];

        if (results.length === 0) {
          return { content: [{ type: 'text', text: `No elements found matching your search.` }] };
        }

        const list = results.map(r => {
          const val = r.matched_value ? `: ${r.matched_value.substring(0, 100)}` : '';
          return `Page ${r.post_id} "${r.page_title}" → ${r.element_name} [${r.element_id}] (${r.matched_field}${val})`;
        }).join('\n');

        return { content: [{ type: 'text', text: `Found ${data.total} element(s):\n\n${list}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error searching elements: ${error.message}` }] };
      }
    },
  },
];

const scriptTools = [
  {
    name: 'bricks_get_scripts',
    description: 'Get per-page scripts for a WordPress page. Returns custom JS/CSS injected via Bricks API Bridge in wp_footer.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        const data = await wpGetCached(`/pages/${page_id}/scripts`, TTL.PAGE_DETAIL);
        return { content: [{ type: 'text', text: `Scripts for page ${page_id}:\n\n${data.scripts || '(none)'}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting scripts: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_scripts',
    description: 'Update per-page scripts for a WordPress page. Scripts are output in wp_footer. Include <link>, <style>, and <script> tags as needed — all are output together before </body>. IMPORTANT: WordPress strips backslashes — use String.fromCharCode(10) instead of "\\n" in JavaScript strings.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        scripts: { type: 'string', description: 'HTML/JS/CSS to inject before </body>. Include <script>, <style>, and <link> tags as needed.' },
      },
      required: ['page_id', 'scripts'],
    },
    handler: async (args) => {
      try {
        const { page_id, scripts } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        cache.invalidatePrefix(`/pages/${page_id}/scripts`);
        await wpPut(`/pages/${page_id}/scripts`, { scripts: scripts || '' });
        return { content: [{ type: 'text', text: `Scripts updated for page ${page_id}.\nLength: ${(scripts || '').length} characters` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error updating scripts: ${error.message}` }] };
      }
    },
  },
];

// =========================================================================
// Structured Assets Tools (v2.3)
// =========================================================================

const assetTools = [
  {
    name: 'bricks_get_page_assets',
    description: 'Get structured per-page assets. Returns { css, js_deps, js, raw_footer } — CSS is output in wp_head, JS deps via wp_enqueue_script, JS in wp_footer. Requires Bricks API Bridge v2.3+.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        const data = await wpGet(`/pages/${page_id}/assets`);
        const assets = data.assets;
        if (!assets) {
          return { content: [{ type: 'text', text: `Page ${page_id}: No structured assets set (using legacy _bab_footer_scripts).\nGSAP flag: ${data.needs_gsap ? 'ON' : 'off'}` }] };
        }
        const lines = [
          `Structured assets for page ${page_id}:`,
          `  GSAP flag: ${data.needs_gsap ? 'ON' : 'off'}`,
          `  JS deps: ${(assets.js_deps || []).join(', ') || '(none)'}`,
          `  CSS: ${assets.css ? assets.css.length + ' chars' : '(none)'}`,
          `  JS: ${assets.js ? assets.js.length + ' chars' : '(none)'}`,
          `  Raw footer: ${assets.raw_footer ? assets.raw_footer.length + ' chars' : '(none)'}`,
        ];
        if (assets.css) lines.push('\n--- CSS (wp_head) ---\n' + assets.css);
        if (assets.js) lines.push('\n--- JS (wp_footer) ---\n' + assets.js.substring(0, 500) + (assets.js.length > 500 ? '\n...' : ''));
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_page_assets',
    description: 'Set structured per-page assets (JS deps via wp_enqueue_script, JS in wp_footer, infra CSS in wp_head). Auto-sets GSAP flag when js_deps includes "gsap". Requires Bricks API Bridge v2.3+. ⚠️ The css field lands in plugin-private _bab_page_assets (wp_head 9997, BEFORE element CSS) which is INVISIBLE/uneditable in the Bricks builder — reserve it for INFRA only (@font-face, :root{--vars}, @keyframes, critical CSS). Human-editable layout/responsive/visual CSS belongs in a native channel: element _cssCustom (bricks_patch_page), page customCss (bricks_update_page_settings), or global CSS/classes.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        css: { type: 'string', description: 'INFRA CSS only (raw rules, no <style> tags): @font-face, :root{--vars}, @keyframes, critical above-fold. NOT editable in the Bricks builder — do NOT put layout/responsive/visual CSS here; use element _cssCustom or page customCss instead. Editable CSS triggers a warning (or a hard block when BRICKS_MCP_BLOCK_EDITABLE_CSS=1).' },
        js_deps: {
          type: 'array',
          items: { type: 'string' },
          description: 'JS dependencies to enqueue: "gsap", "scrolltrigger", "lenis"',
        },
        js: { type: 'string', description: 'Inline JS to output in footer (no <script> tags, just code)' },
        raw_footer: { type: 'string', description: 'Raw HTML for wp_footer (legacy catch-all, includes own tags)' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, ...assets } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        // CSS editability guard — keep human-editable CSS in Bricks-native channels.
        // The css field lands in _bab_page_assets (wp_head 9997, BEFORE element CSS),
        // which has NO Bricks editing UI. Advisory by default; set
        // BRICKS_MCP_BLOCK_EDITABLE_CSS=1 to hard-block (returns before writing).
        const cssClass = classifyCss(assets.css);
        const cssEditable = cssClass.kind === 'editable' || cssClass.kind === 'mixed';
        if (cssEditable && process.env.BRICKS_MCP_BLOCK_EDITABLE_CSS === '1') {
          return { content: [{ type: 'text', text: `Blocked (BRICKS_MCP_BLOCK_EDITABLE_CSS=1) — assets NOT written for page ${page_id}.\n${formatEditableCssWarning(cssClass)}` }] };
        }

        cache.invalidatePrefix(`/pages/${page_id}/assets`);
        const result = await wpPut(`/pages/${page_id}/assets`, assets);
        const parts = [`Structured assets updated for page ${page_id}.`];
        if (assets.css) parts.push(`  CSS: ${assets.css.length} chars → wp_head`);
        if (assets.js_deps?.length) parts.push(`  Deps: ${assets.js_deps.join(', ')} → wp_enqueue_script`);
        if (assets.js) parts.push(`  JS: ${assets.js.length} chars → wp_footer`);
        if (assets.raw_footer) parts.push(`  Raw: ${assets.raw_footer.length} chars → wp_footer`);
        if (cssEditable) parts.push(`\n${formatEditableCssWarning(cssClass)}`);
        return { content: [{ type: 'text', text: parts.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_set_gsap_flag',
    description: 'Toggle the GSAP enqueue flag for a page. When enabled, GSAP + ScrollTrigger are loaded via wp_enqueue_script (proper dedup, browser caching). No need to add CDN <script> tags in per-page scripts.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        enabled: { type: 'boolean', description: 'true to enable GSAP enqueue, false to disable', default: true },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, enabled = true } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        await wpPut(`/pages/${page_id}/gsap-flag`, { enabled });
        return { content: [{ type: 'text', text: `GSAP flag ${enabled ? 'ENABLED' : 'DISABLED'} for page ${page_id}.\n${enabled ? 'GSAP + ScrollTrigger will be enqueued via wp_enqueue_script.' : 'GSAP will not be auto-enqueued.'}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error: ${error.message}` }] };
      }
    },
  },
];

const seoTools = [
  {
    name: 'bricks_get_page_seo',
    description: 'Get SEO meta data for a WordPress page. Returns title, description, OG/Twitter tags, canonical URL, robots directives, focus keyword, and more.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        const data = await wpGetCached(`/pages/${page_id}/seo`, TTL.PAGE_DETAIL);
        const lines = [
          `SEO data for page ${page_id}:`,
          `  Title: ${data.seo_title || '(not set)'}`,
          `  Description: ${data.description || '(not set)'}`,
          `  OG Image: ${data.og_image || '(not set)'}`,
          `  Keywords: ${data.keywords || '(not set)'}`,
          `  OG Type: ${data.og_type || '(not set)'}`,
          `  Canonical: ${data.canonical || '(not set)'}`,
          `  Noindex: ${data.noindex ? 'YES' : 'no'}`,
          `  Nofollow: ${data.nofollow ? 'YES' : 'no'}`,
          `  Focus Keyword: ${data.focus_keyword || '(not set)'}`,
          `  OG Title Override: ${data.og_title || '(not set)'}`,
          `  Twitter Title: ${data.twitter_title || '(not set)'}`,
          `  Twitter Description: ${data.twitter_description || '(not set)'}`,
          `  Twitter Image: ${data.twitter_image || '(not set)'}`,
        ];
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting SEO data: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_page_seo',
    description: 'Update SEO meta data for a WordPress page. Partial update — only provided fields are changed. Supports title, description, OG/Twitter overrides, canonical URL, robots (noindex/nofollow), and focus keyword.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        seo_title: { type: 'string', description: 'SEO title (overrides <title> tag)' },
        description: { type: 'string', description: 'Meta description (fallback for og:description and twitter:description)' },
        og_image: { type: 'string', description: 'Open Graph image URL (1200x630 recommended)' },
        keywords: { type: 'string', description: 'Meta keywords (comma-separated)' },
        og_type: { type: 'string', description: 'Open Graph type (default: website)' },
        canonical: { type: 'string', description: 'Canonical URL (outputs <link rel="canonical">)' },
        noindex: { type: 'boolean', description: 'Set to true to add noindex robots directive' },
        nofollow: { type: 'boolean', description: 'Set to true to add nofollow robots directive' },
        focus_keyword: { type: 'string', description: 'Focus keyword for density analysis' },
        og_title: { type: 'string', description: 'OG title override (separate from SEO title)' },
        twitter_title: { type: 'string', description: 'Twitter Card title override' },
        twitter_description: { type: 'string', description: 'Twitter Card description override' },
        twitter_image: { type: 'string', description: 'Twitter Card image URL override' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, ...fields } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const body = {};
        const allowed = ['seo_title', 'description', 'og_image', 'keywords', 'og_type', 'canonical', 'noindex', 'nofollow', 'focus_keyword', 'og_title', 'twitter_title', 'twitter_description', 'twitter_image'];
        for (const key of allowed) {
          if (fields[key] !== undefined) body[key] = fields[key];
        }

        if (Object.keys(body).length === 0) {
          return { content: [{ type: 'text', text: 'Error: at least one SEO field is required' }] };
        }

        cache.invalidatePrefix(`/pages/${page_id}/seo`);
        const result = await wpPut(`/pages/${page_id}/seo`, body);
        return { content: [{ type: 'text', text: `SEO updated for page ${page_id}.\nFields updated: ${result.updated.join(', ')}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error updating SEO data: ${error.message}` }] };
      }
    },
  },
];

const schemaTools = [
  {
    name: 'bricks_get_page_schema',
    description: 'Get JSON-LD structured data (Schema.org) for a page. Returns all schema objects stored for this page.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        const data = await wpGetCached(`/pages/${page_id}/schema`, TTL.PAGE_DETAIL);
        if (data.count === 0) {
          return { content: [{ type: 'text', text: `No JSON-LD schema set for page ${page_id}.` }] };
        }
        const lines = [`JSON-LD Schema for page ${page_id} (${data.count} schema${data.count > 1 ? 's' : ''}):`];
        data.schemas.forEach((s, i) => {
          lines.push(`\n--- Schema ${i + 1}: ${s['@type']} ---`);
          lines.push(JSON.stringify(s, null, 2));
        });
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting schema: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_page_schema',
    description: 'Set JSON-LD structured data (Schema.org) for a page. Automatically adds @context. Outputs as <script type="application/ld+json"> in the page head. Accepts a single schema object or an array of schemas.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        schema: {
          description: 'Schema.org JSON-LD object (or array of objects). Must include @type. Example: {"@type": "LocalBusiness", "name": "My Business", "address": {...}}',
        },
      },
      required: ['page_id', 'schema'],
    },
    handler: async (args) => {
      try {
        const { page_id, schema } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!schema) return { content: [{ type: 'text', text: 'Error: schema object is required' }] };

        cache.invalidatePrefix(`/pages/${page_id}/schema`);
        const result = await wpPut(`/pages/${page_id}/schema`, { schema });
        const types = result.schemas.map(s => s['@type']).join(', ');
        return { content: [{ type: 'text', text: `Schema updated for page ${page_id}.\nSchemas: ${result.count} (${types})\nOutput: <script type="application/ld+json"> in page head` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error updating schema: ${error.message}` }] };
      }
    },
  },
];

const seoAuditTools = [
  {
    name: 'bricks_seo_audit',
    description: 'Bulk SEO audit — scans all published pages and scores each 0-100 based on title, description, OG tags, headings, image alts, canonical, focus keyword, and structured data. Returns pages sorted by score (worst first).',
    inputSchema: {
      type: 'object',
      properties: {},
    },
    handler: async () => {
      try {
        const data = await wpGet('/seo/audit');
        const lines = [
          `SEO Audit — ${data.pages_scanned} pages scanned, average score: ${data.average_score}/100`,
          '',
        ];
        data.pages.forEach(p => {
          const status = p.noindex ? ' [NOINDEX]' : '';
          lines.push(`${p.score}/100 | ID ${p.page_id} | /${p.slug}/ | ${p.title}${status}`);
          if (p.issues) {
            p.issues.forEach(i => lines.push(`  ✗ ${i}`));
          }
          if (p.warnings) {
            p.warnings.forEach(w => lines.push(`  ⚠ ${w}`));
          }
          if (p.images_missing_alt) {
            lines.push(`  ⚠ ${p.images_missing_alt}/${p.images_total} images missing alt text`);
          }
        });
        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error running SEO audit: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_seo_analyze',
    description: 'Deep SEO analysis for a single page. Checks title/description quality, keyword density, heading hierarchy (H1-H6), image alt texts, content length, structured data, and content freshness. Returns a detailed score breakdown.',
    inputSchema: {
      type: 'object',
      properties: { page_id: { type: 'number', description: 'WordPress page/post ID' } },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        const data = await wpGet(`/pages/${page_id}/seo-analyze`);

        const lines = [
          `SEO Analysis for page ${page_id}: "${data.title}"`,
          `URL: ${data.url}`,
          `Score: ${data.score}/100`,
          '',
          '--- Score Breakdown ---',
        ];

        data.checks.forEach(c => {
          const icon = c.status === 'pass' ? '✓' : c.status === 'warn' ? '⚠' : c.status === 'fail' ? '✗' : 'ℹ';
          const note = c.note ? ` — ${c.note}` : '';
          lines.push(`  ${icon} ${c.check}: ${c.points} pts${note}`);
        });

        lines.push('');
        lines.push(`Word count: ${data.word_count}`);
        lines.push(`Headings: ${data.headings.length} (${data.headings.map(h => h.tag).join(', ') || 'none'})`);
        lines.push(`Images: ${data.images_total} total, ${data.images_missing_alt} missing alt`);
        lines.push(`Links: ${data.link_count}`);
        lines.push(`Last modified: ${data.content_freshness.last_modified} (${data.content_freshness.days_ago} days ago)`);

        if (data.heading_issues.length > 0) {
          lines.push('');
          lines.push('--- Heading Issues ---');
          data.heading_issues.forEach(i => lines.push(`  ⚠ ${i}`));
        }

        if (data.keyword_analysis) {
          const kw = data.keyword_analysis;
          lines.push('');
          lines.push(`--- Keyword Analysis: "${kw.keyword}" ---`);
          lines.push(`  Occurrences: ${kw.occurrences}, Density: ${kw.density_percent}%`);
          lines.push(`  In title: ${kw.in_title ? 'yes' : 'no'}, In description: ${kw.in_description ? 'yes' : 'no'}, In H1: ${kw.in_h1 ? 'yes' : 'no'}`);
          lines.push(`  ${kw.recommendation}`);
        }

        return { content: [{ type: 'text', text: lines.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error analyzing page: ${error.message}` }] };
      }
    },
  },
];

export { pageTools, searchTools, scriptTools, assetTools, seoTools, schemaTools, seoAuditTools };
