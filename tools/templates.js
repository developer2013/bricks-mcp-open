/**
 * Bricks Builder Template Tools
 * Tools for managing Bricks templates (headers, footers, sections, etc.)
 */
import { wpGet, wpGetCached, wpPost, wpPut, wpDelete } from '../utils/wp-api.js';
import { cache, TTL } from '../utils/cache.js';
import { validateContent } from '../utils/validator.js';
import { autofix } from '../utils/autofix.js';

const templateTools = [
  {
    name: 'bricks_list_templates',
    description: 'List all Bricks Builder templates. Can filter by template type (header, footer, section, content).',
    inputSchema: {
      type: 'object',
      properties: {
        template_type: { type: 'string', description: 'Filter by template type', enum: ['header', 'footer', 'section', 'content', 'single', 'archive', 'popup', 'search', 'error'] },
      },
    },
    handler: async (args) => {
      try {
        let endpoint = '/templates';
        if (args.template_type) endpoint += `?template_type=${encodeURIComponent(args.template_type)}`;
        const data = await wpGetCached(endpoint, TTL.TEMPLATES);
        if (!data || !Array.isArray(data) || data.length === 0) return { content: [{ type: 'text', text: 'No Bricks templates found.' }] };

        const list = data.map(t => {
          const elements = t.element_count || 0;
          const cond = t.conditions && t.conditions.length > 0 ? ` | conditions: ${JSON.stringify(t.conditions)}` : '';
          return `ID: ${t.id} | ${(t.type || t.template_type || 'section').toUpperCase()} | ${t.title} | ${elements} elements${cond}`;
        }).join('\n');

        return { content: [{ type: 'text', text: `Found ${data.length} template(s):\n\n${list}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error listing templates: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_create_template',
    description: 'Create a new Bricks Builder template with the given content.',
    inputSchema: {
      type: 'object',
      properties: {
        title: { type: 'string', description: 'Template title' },
        template_type: { type: 'string', description: 'Template type (default: section)', default: 'section', enum: ['header', 'footer', 'section', 'content', 'single', 'archive', 'popup', 'search', 'error'] },
        content: { type: 'array', description: 'Array of Bricks elements', items: { type: 'object' } },
        conditions: { type: 'array', description: 'Template conditions, e.g. [{"main":"entireWebsite"}] for site-wide header/footer', items: { type: 'object' } },
      },
      required: ['title', 'content'],
    },
    handler: async (args) => {
      try {
        const { title, template_type, content, conditions } = args;
        if (!title) return { content: [{ type: 'text', text: 'Error: title is required' }] };
        if (!content || !Array.isArray(content)) return { content: [{ type: 'text', text: 'Error: content must be an array of elements' }] };

        // Auto-fix common issues before validation
        const fixResult = autofix(content);
        const fixedContent = fixResult.content;

        const validation = validateContent(fixedContent);
        if (!validation.valid) {
          const errorList = validation.errors.map(e => `  - ${e}`).join('\n');
          return { content: [{ type: 'text', text: `Validation failed with ${validation.errors.length} error(s):\n${errorList}\n\nFix these issues and try again.` }] };
        }

        const payload = { title, template_type: template_type || 'section', content: fixedContent };
        if (conditions) payload.conditions = conditions;
        const result = await wpPost('/templates', payload);
        const fixInfo = fixResult.log.length > 0 ? `\nAuto-fixed ${fixResult.log.length} issue(s)` : '';
        const warnInfo = validation.warnings.length > 0 ? `\n\n⚠️ ${validation.warnings.length} warning(s):\n${validation.warnings.map(w => `  - ${w}`).join('\n')}` : '';
        const infoHints = validation.info.length > 0 ? `\nℹ️ ${validation.info.length} hint(s):\n${validation.info.map(i => `  - ${i}`).join('\n')}` : '';
        return { content: [{ type: 'text', text: `Template created successfully.\nID: ${result.id}\nTitle: ${title}\nType: ${template_type || 'section'}\nElements: ${fixedContent.length}${fixInfo}${warnInfo}${infoHints}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error creating template: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_import_template',
    description: 'Import a Bricks Builder JSON template file. Accepts the full Bricks export format with content array.',
    inputSchema: {
      type: 'object',
      properties: {
        title: { type: 'string', description: 'Template title' },
        template_type: { type: 'string', description: 'Template type (default: section)', default: 'section', enum: ['header', 'footer', 'section', 'content', 'single', 'archive', 'popup', 'search', 'error'] },
        json_data: { type: 'object', description: 'Complete Bricks template JSON with content array' },
      },
      required: ['title', 'json_data'],
    },
    handler: async (args) => {
      try {
        const { title, template_type, json_data } = args;
        if (!title) return { content: [{ type: 'text', text: 'Error: title is required' }] };
        if (!json_data || typeof json_data !== 'object') return { content: [{ type: 'text', text: 'Error: json_data must be a valid Bricks template object' }] };

        const result = await wpPost('/templates/import', { title, template_type: template_type || 'section', json_data });
        return { content: [{ type: 'text', text: `Template imported successfully.\nID: ${result.id}\nTitle: ${title}\nType: ${template_type || 'section'}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error importing template: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_delete_template',
    description: 'Delete a Bricks Builder template.',
    inputSchema: {
      type: 'object',
      properties: {
        template_id: { type: 'number', description: 'Template ID to delete' },
        force: { type: 'boolean', description: 'Permanently delete instead of trash (default: false)', default: false },
      },
      required: ['template_id'],
    },
    handler: async (args) => {
      try {
        const { template_id, force } = args;
        if (!template_id) return { content: [{ type: 'text', text: 'Error: template_id is required' }] };
        const forceParam = force ? '?force=true' : '';
        await wpDelete(`/templates/${template_id}${forceParam}`);
        return { content: [{ type: 'text', text: `Template ${template_id} deleted successfully.${force ? ' (permanently)' : ' (moved to trash)'}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error deleting template: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_get_template',
    description: 'Get the full Bricks Builder data for a specific template. Returns element tree with all settings.',
    inputSchema: {
      type: 'object',
      properties: { template_id: { type: 'number', description: 'Template ID' } },
      required: ['template_id'],
    },
    handler: async (args) => {
      try {
        const { template_id } = args;
        if (!template_id) return { content: [{ type: 'text', text: 'Error: template_id is required' }] };
        const data = await wpGetCached(`/templates/${template_id}`, TTL.TEMPLATES);
        if (!data) return { content: [{ type: 'text', text: `No data found for template ${template_id}` }] };

        const bricksData = data.bricks_data || data.content || [];
        const conditions = data.conditions && data.conditions.length > 0 ? data.conditions : null;
        const header = [
          `Template ID: ${data.id || template_id}`,
          `Title: ${data.title || 'Untitled'}`,
          `Type: ${data.type || data.template_type || 'section'}`,
          `Elements: ${Array.isArray(bricksData) ? bricksData.length : 0}`,
          ...(conditions ? [`Conditions: ${JSON.stringify(conditions)}`] : []),
        ].join('\n');

        return { content: [{ type: 'text', text: `${header}\n\nTemplate Data:\n${JSON.stringify(bricksData, null, 2)}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting template: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_update_template',
    description: 'Update an existing Bricks Builder template with new content.',
    inputSchema: {
      type: 'object',
      properties: {
        template_id: { type: 'number', description: 'Template ID to update' },
        title: { type: 'string', description: 'New template title (optional)' },
        content: { type: 'array', description: 'Array of Bricks elements', items: { type: 'object' } },
        template_type: { type: 'string', description: 'Template type (optional)', enum: ['header', 'footer', 'section', 'content', 'single', 'archive', 'popup', 'search', 'error'] },
        conditions: { type: 'array', description: 'Template conditions, e.g. [{"main":"entireWebsite"}]', items: { type: 'object' } },
      },
      required: ['template_id'],
    },
    handler: async (args) => {
      try {
        const { template_id, title, content, template_type, conditions } = args;
        if (!template_id) return { content: [{ type: 'text', text: 'Error: template_id is required' }] };

        const body = {};
        let fixResult = null;
        let validation = null;
        if (content && Array.isArray(content)) {
          // Auto-fix common issues before validation.
          //
          // mode:'preserve', because this is a save of content that already exists.
          // Without it the mode defaults to 'normalize', Pass 5 runs, and el.name
          // 'block' is rewritten to 'container' on any element carrying a flex
          // property — a structural change to a layout a human authored. Observed on
          // a live template: three blocks silently promoted to containers.
          //
          // This mirrors what 06d34cd already does for pages (tools/pages.js), which
          // fixed the page writer and left the template writer normalizing.
          // bricks_create_template still normalizes, deliberately: content the tool
          // generates itself is exactly what the aggressive repairs are for.
          fixResult = autofix(content, { mode: 'preserve' });
          const fixedContent = fixResult.content;

          validation = validateContent(fixedContent);
          if (!validation.valid) {
            const errorList = validation.errors.map(e => `  - ${e}`).join('\n');
            return { content: [{ type: 'text', text: `Validation failed with ${validation.errors.length} error(s):\n${errorList}\n\nFix these issues and try again.` }] };
          }
          body.bricks_data = fixedContent;
        }

        if (title) body.title = title;
        if (template_type) body.template_type = template_type;
        if (conditions) body.conditions = conditions;

        if (Object.keys(body).length === 0) {
          return { content: [{ type: 'text', text: 'Error: at least one field to update is required (content, title, template_type, or conditions)' }] };
        }

        cache.invalidatePrefix('/templates');
        await wpPut(`/templates/${template_id}`, body);

        const parts = [`Template ${template_id} updated.`];
        if (body.bricks_data) parts.push(`Elements: ${body.bricks_data.length}`);
        if (title) parts.push(`Title: ${title}`);
        if (conditions) parts.push(`Conditions: ${JSON.stringify(conditions)}`);
        if (fixResult && fixResult.log && fixResult.log.length > 0) parts.push(`Auto-fixed ${fixResult.log.length} issue(s)`);
        if (validation && validation.warnings && validation.warnings.length > 0) parts.push(`\n⚠️ ${validation.warnings.length} warning(s):\n${validation.warnings.map(w => `  - ${w}`).join('\n')}`);
        if (validation && validation.info && validation.info.length > 0) parts.push(`ℹ️ ${validation.info.length} hint(s):\n${validation.info.map(i => `  - ${i}`).join('\n')}`);
        return { content: [{ type: 'text', text: parts.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error updating template: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_search_templates',
    description: 'Search templates by title or content.',
    inputSchema: {
      type: 'object',
      properties: {
        query: { type: 'string', description: 'Search query' },
        template_type: { type: 'string', description: 'Filter by template type', enum: ['header', 'footer', 'section', 'content', 'single', 'archive', 'popup', 'search', 'error'] },
      },
      required: ['query'],
    },
    handler: async (args) => {
      try {
        const { query, template_type } = args;
        let endpoint = `/templates?search=${encodeURIComponent(query)}`;
        if (template_type) endpoint += `&template_type=${encodeURIComponent(template_type)}`;
        const data = await wpGet(endpoint);
        if (!data || !Array.isArray(data) || data.length === 0) {
          return { content: [{ type: 'text', text: `No templates found matching "${query}".` }] };
        }
        const list = data.map(t => {
          const elements = t.element_count || 0;
          return `ID: ${t.id} | ${(t.type || t.template_type || 'section').toUpperCase()} | ${t.title} | ${elements} elements`;
        }).join('\n');
        return { content: [{ type: 'text', text: `Found ${data.length} template(s) matching "${query}":\n\n${list}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error searching templates: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_clone_template',
    description: 'Clone a template with all Bricks data.',
    inputSchema: {
      type: 'object',
      properties: {
        template_id: { type: 'number', description: 'Template ID to clone' },
        new_title: { type: 'string', description: 'Optional title for the clone' },
      },
      required: ['template_id'],
    },
    handler: async (args) => {
      try {
        const { template_id, new_title } = args;
        if (!template_id) return { content: [{ type: 'text', text: 'Error: template_id is required' }] };
        cache.invalidatePrefix('/templates');
        const body = {};
        if (new_title) body.title = new_title;
        const result = await wpPost(`/templates/${template_id}/clone`, body);
        return { content: [{ type: 'text', text: `Template cloned. New ID: ${result.id}, Title: ${result.title}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error cloning template: ${error.message}` }] };
      }
    },
  },
];

export { templateTools };
