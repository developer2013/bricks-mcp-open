/**
 * Bricks Builder Backup & Snapshot Tools
 */
import { wpGet, wpPost, wpDelete } from '../utils/wp-api.js';

const backupTools = [
  {
    name: 'bricks_get_backup',
    description: 'Get the backup of a page\'s Bricks data. Supports multi-slot backups (1-5, newest first).',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        slot: { type: 'number', description: 'Backup slot (1-5, default: 1 = most recent)', default: 1 },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, slot = 1 } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const endpoint = slot > 1 ? `/pages/${page_id}/backup?slot=${slot}` : `/pages/${page_id}/backup`;
        const data = await wpGet(endpoint);
        if (!data || !data.backup_data) return { content: [{ type: 'text', text: `No backup found for page ${page_id} (slot ${slot}). Backups are created automatically before each update.` }] };

        const elements = Array.isArray(data.backup_data) ? data.backup_data.length : 0;
        return { content: [{ type: 'text', text: `Backup for page ${page_id} (slot ${slot}):\nCreated: ${data.timestamp || 'unknown'}\nElements: ${elements}\n\nBackup Data:\n${JSON.stringify(data.backup_data, null, 2)}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error getting backup: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_list_backups',
    description: 'List all available backup slots for a page. Shows slot number, timestamp, and element count for each backup.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const data = await wpGet(`/pages/${page_id}/backups`);
        const backups = data.backups || [];

        if (backups.length === 0) {
          return { content: [{ type: 'text', text: `No backups found for page ${page_id}.` }] };
        }

        const list = backups.map(b =>
          `Slot ${b.slot}: ${b.timestamp || 'unknown'} | ${b.element_count || 0} elements`
        ).join('\n');

        return { content: [{ type: 'text', text: `Backups for page ${page_id} (${backups.length} slot(s)):\n\n${list}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error listing backups: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_restore_backup',
    description: 'Restore a page\'s Bricks data from a backup slot.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        slot: { type: 'number', description: 'Backup slot to restore (1-5, default: 1 = most recent)', default: 1 },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, slot = 1 } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const body = slot > 1 ? { slot } : {};
        const result = await wpPost(`/pages/${page_id}/restore`, body);
        return { content: [{ type: 'text', text: `Page ${page_id} restored from backup (slot ${slot}) successfully.\nElements restored: ${result.element_count || 'unknown'}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error restoring backup: ${error.message}` }] };
      }
    },
  },

  // ── Full-state (whole-site Bricks layer) backups ──
  {
    name: 'bricks_create_full_backup',
    description: 'Create a full-state backup of the entire Bricks layer (all pages incl. drafts, templates, global styles/classes/fonts/colors, navigation menus, and curated WordPress core settings) as a single JSON file on the server. Use before risky bulk operations. Does NOT back up media files or the database — pair with a host snapshot for full disaster recovery.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        const result = await wpPost('/backup/full', {});
        const b = result.backup || {};
        const c = b.counts || {};
        return { content: [{ type: 'text', text: `Full-state backup created: ${b.file}\nSize: ${b.size} bytes\nPages: ${c.pages ?? '?'} | Templates: ${c.templates ?? '?'} | Menus: ${c.menus ?? '?'}\n\nDownload it from the WordPress admin (Bricks MCP → Backup & Export).` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error creating full backup: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_list_full_backups',
    description: 'List all stored full-state backups (file name, date, size). Created via bricks_create_full_backup or the WordPress admin Backup section.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        const data = await wpGet('/backup/full');
        const backups = data.backups || [];
        if (backups.length === 0) return { content: [{ type: 'text', text: 'No full-state backups found yet.' }] };
        const list = backups.map(b => {
          const when = b.time ? new Date(b.time * 1000).toISOString() : 'unknown';
          return `${b.file} | ${when} | ${b.size} bytes`;
        }).join('\n');
        return { content: [{ type: 'text', text: `Full-state backups (${backups.length}):\n\n${list}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error listing full backups: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_get_full_state',
    description: 'Return the live full-state of the Bricks layer as JSON WITHOUT writing a file (all pages incl. drafts, templates, globals, menus, curated WP settings). Use to inspect or to save a backup snapshot locally on the client.',
    inputSchema: { type: 'object', properties: {} },
    handler: async () => {
      try {
        const state = await wpGet('/backup/state');
        const m = state.manifest || {};
        const summary = `Full-state snapshot (${m.timestamp || 'now'}):\n` +
          `Pages: ${(state.pages || []).length} | Templates: ${(state.templates || []).length} | ` +
          `Menus: ${((state.menus || {}).menus || []).length} | WP settings: ${Object.keys(state.wp_settings || {}).length}\n\n`;
        return { content: [{ type: 'text', text: summary + JSON.stringify(state, null, 2) }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error fetching full state: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_delete_full_backup',
    description: 'Delete one stored full-state backup file by its filename (as listed by bricks_list_full_backups).',
    inputSchema: {
      type: 'object',
      properties: {
        file: { type: 'string', description: 'Backup filename, e.g. bricks-fullstate-20260611-120000-a1b2c3.json' },
      },
      required: ['file'],
    },
    handler: async (args) => {
      try {
        const { file } = args;
        if (!file) return { content: [{ type: 'text', text: 'Error: file is required' }] };
        const result = await wpDelete(`/backup/full?file=${encodeURIComponent(file)}`);
        return { content: [{ type: 'text', text: result.message || 'Backup deleted.' }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error deleting full backup: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_restore_full_backup',
    description: 'Restore the site from a stored full-state backup file (pages incl. drafts, templates, global tokens, and WP settings). SAFE: takes an automatic safety backup first, and never overwrites infrastructure options (siteurl/home/active theme/active plugins) unless force_infra=true. Pages/templates match by ID (same-site restore). Menus are not restored yet.',
    inputSchema: {
      type: 'object',
      properties: {
        file: { type: 'string', description: 'Stored backup filename (from bricks_list_full_backups)' },
        pages: { type: 'boolean', description: 'Restore pages (default: true)' },
        templates: { type: 'boolean', description: 'Restore templates (default: true)' },
        globals: { type: 'boolean', description: 'Restore global styles/classes/fonts/colors (default: true)' },
        wp_settings: { type: 'boolean', description: 'Restore WordPress settings (default: true; infra keys still protected)' },
        create_missing: { type: 'boolean', description: 'Create pages/templates whose ID no longer exists (default: true)' },
        force_infra: { type: 'boolean', description: 'DANGER: also overwrite siteurl/home/active theme/plugins (default: false)' },
      },
      required: ['file'],
    },
    handler: async (args) => {
      try {
        const { file, ...opts } = args;
        if (!file) return { content: [{ type: 'text', text: 'Error: file is required' }] };
        const result = await wpPost('/backup/import', { file, options: opts });
        return { content: [{ type: 'text', text: `Restore complete from ${file}.\nSafety backup: ${result.report?.safety_backup || 'n/a'}\n\n${JSON.stringify(result.report, null, 2)}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error restoring backup: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_import_full_state',
    description: 'Import a full-state backup supplied as JSON (e.g. a file downloaded from another site) rather than a server-stored file. Same safety guarantees as bricks_restore_full_backup (auto safety backup, infra protection).',
    inputSchema: {
      type: 'object',
      properties: {
        backup: { type: 'object', description: 'A full-state backup object (must contain a manifest, pages, etc.)' },
        pages: { type: 'boolean', description: 'Restore pages (default: true)' },
        templates: { type: 'boolean', description: 'Restore templates (default: true)' },
        globals: { type: 'boolean', description: 'Restore globals (default: true)' },
        wp_settings: { type: 'boolean', description: 'Restore WP settings (default: true; infra protected)' },
        create_missing: { type: 'boolean', description: 'Create missing pages/templates (default: true)' },
        force_infra: { type: 'boolean', description: 'DANGER: overwrite infra options (default: false)' },
      },
      required: ['backup'],
    },
    handler: async (args) => {
      try {
        const { backup, ...opts } = args;
        if (!backup || typeof backup !== 'object') return { content: [{ type: 'text', text: 'Error: backup object is required' }] };
        const result = await wpPost('/backup/import', { backup, options: opts });
        return { content: [{ type: 'text', text: `Import complete.\nSafety backup: ${result.report?.safety_backup || 'n/a'}\n\n${JSON.stringify(result.report, null, 2)}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error importing backup: ${error.message}` }] };
      }
    },
  },
];

const snapshotTools = [
  {
    name: 'bricks_create_snapshot',
    description: 'Create a named snapshot of the current page state. Use for milestones like "client-approved", "before-redesign", etc.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        name: { type: 'string', description: 'Snapshot name (e.g. "client-approved", "before-redesign")' },
        description: { type: 'string', description: 'Optional description of this snapshot' },
      },
      required: ['page_id', 'name'],
    },
    handler: async (args) => {
      try {
        const { page_id, name, description } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!name) return { content: [{ type: 'text', text: 'Error: name is required' }] };

        const body = { name };
        if (description) body.description = description;

        const result = await wpPost(`/pages/${page_id}/snapshots`, body);
        return { content: [{ type: 'text', text: `Snapshot "${result.name}" created for page ${page_id}.\nID: ${result.snapshot_id}\nElements: ${result.element_count}\nTimestamp: ${result.timestamp}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error creating snapshot: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_list_snapshots',
    description: 'List all named snapshots for a page. Shows name, description, element count, and timestamp.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
      },
      required: ['page_id'],
    },
    handler: async (args) => {
      try {
        const { page_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };

        const data = await wpGet(`/pages/${page_id}/snapshots`);
        const snapshots = data.snapshots || [];

        if (snapshots.length === 0) {
          return { content: [{ type: 'text', text: `No snapshots found for page ${page_id}.` }] };
        }

        const list = snapshots.map(s => {
          const desc = s.description ? ` — ${s.description}` : '';
          return `"${s.name}" [${s.id}] | ${s.element_count} elements | ${s.timestamp}${desc}`;
        }).join('\n');

        return { content: [{ type: 'text', text: `Snapshots for page ${page_id} (${snapshots.length}):\n\n${list}` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error listing snapshots: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_restore_snapshot',
    description: 'Restore a page from a named snapshot. Creates an auto-backup before restoring. Accepts snapshot ID or name.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        snapshot_id: { type: 'string', description: 'Snapshot ID (snap_...) or name to restore' },
      },
      required: ['page_id', 'snapshot_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, snapshot_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!snapshot_id) return { content: [{ type: 'text', text: 'Error: snapshot_id is required' }] };

        const result = await wpPost(`/pages/${page_id}/snapshots/${encodeURIComponent(snapshot_id)}/restore`, {});
        return { content: [{ type: 'text', text: `Snapshot "${result.name}" restored for page ${page_id}.\nElements: ${result.element_count}\nAuto-backup created before restore.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error restoring snapshot: ${error.message}` }] };
      }
    },
  },

  {
    name: 'bricks_delete_snapshot',
    description: 'Delete a named snapshot. Accepts snapshot ID or name.',
    inputSchema: {
      type: 'object',
      properties: {
        page_id: { type: 'number', description: 'WordPress page/post ID' },
        snapshot_id: { type: 'string', description: 'Snapshot ID (snap_...) or name to delete' },
      },
      required: ['page_id', 'snapshot_id'],
    },
    handler: async (args) => {
      try {
        const { page_id, snapshot_id } = args;
        if (!page_id) return { content: [{ type: 'text', text: 'Error: page_id is required' }] };
        if (!snapshot_id) return { content: [{ type: 'text', text: 'Error: snapshot_id is required' }] };

        const result = await wpDelete(`/pages/${page_id}/snapshots/${encodeURIComponent(snapshot_id)}`);
        return { content: [{ type: 'text', text: result.message || `Snapshot deleted.` }] };
      } catch (error) {
        return { content: [{ type: 'text', text: `Error deleting snapshot: ${error.message}` }] };
      }
    },
  },
];

export { backupTools, snapshotTools };
