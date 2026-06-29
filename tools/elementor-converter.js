/**
 * Elementor → Bricks Builder Converter Tool
 *
 * Accepts an Elementor template/page JSON export (or raw _elementor_data) and
 * returns a valid Bricks element array — ready to review and push with
 * bricks_update_page / bricks_import_page (which keep snapshot discipline).
 *
 * Pure structural translation: no HTML rendering, no remote calls, no WP write.
 * This makes the tool fully additive and risk-free (no-breakage-zero-tolerance).
 */
import { readFile } from 'node:fs/promises';
import { elementorToBricks } from '../utils/elementor-converter.js';
import { validateContent } from '../utils/validator.js';
import { autofix } from '../utils/autofix.js';

const elementorConverterTools = [
  {
    name: 'bricks_elementor_to_bricks',
    description:
      'Convert an Elementor page/template into a Bricks Builder element array. ' +
      'Input is an Elementor JSON export (Elementor UI → Templates → Export, or raw _elementor_data). ' +
      'Maps Elementor widgets/containers to native Bricks elements structurally — no HTML rendering. ' +
      'Returns the Bricks element array + a coverage report (which widgets mapped, which need manual work). ' +
      'Does NOT write to WordPress: review the output, then push with bricks_update_page or bricks_import_page.',
    inputSchema: {
      type: 'object',
      properties: {
        json: {
          type: 'string',
          description: 'Elementor export JSON as a string. Either an export wrapper { content: [...] } or a raw element array.',
        },
        file_path: {
          type: 'string',
          description: 'Absolute path to an Elementor .json export file. Used if `json` is not provided.',
        },
      },
    },
    handler: async (args) => {
      try {
        const { json, file_path } = args;

        let raw = json;
        if (!raw && file_path) raw = await readFile(file_path, 'utf8');
        if (!raw || !raw.trim()) {
          return { content: [{ type: 'text', text: 'Error: provide `json` (Elementor export string) or `file_path`.' }] };
        }

        let parsed;
        try {
          parsed = JSON.parse(raw);
        } catch (e) {
          return { content: [{ type: 'text', text: `Error: input is not valid JSON (${e.message}).` }] };
        }

        const { elements: rawElements, report } = elementorToBricks(parsed);

        if (rawElements.length === 0) {
          return { content: [{ type: 'text', text: 'No convertible Elementor elements found. Is this an Elementor export?' }] };
        }

        // Reuse the existing Bricks safety net
        const fixResult = autofix(rawElements);
        const elements = fixResult.content;
        const validation = validateContent(elements);

        const mappedList = Object.entries(report.mapped)
          .map(([w, n]) => `${w}×${n}`)
          .join(', ') || '—';
        const unsupportedTypes = [...new Set(report.unsupported.map((u) => u.widgetType || 'unknown'))];

        const parts = [
          `Converted Elementor → ${elements.length} Bricks element(s).`,
          `Widgets: ${report.widgetsTotal} total · mapped [${mappedList}]`,
        ];
        if (unsupportedTypes.length) {
          parts.push(`⚠ ${report.unsupported.length} unsupported widget(s) → placeholders: ${unsupportedTypes.join(', ')}`);
        }
        if (fixResult.log.length) parts.push(`Auto-fixed: ${fixResult.log.join('; ')}`);
        if (validation.warnings.length) parts.push(`Warnings: ${validation.warnings.join('; ')}`);
        if (!validation.valid) parts.push(`Errors (manual fix needed): ${validation.errors.join('; ')}`);
        parts.push('');
        parts.push('Next: review, then push with bricks_update_page (snapshot first) or bricks_import_page.');
        parts.push('');
        parts.push(JSON.stringify(elements, null, 2));

        return { content: [{ type: 'text', text: parts.join('\n') }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error converting Elementor: ${error.message}` }] };
      }
    },
  },
];

export { elementorConverterTools };
