/**
 * WordPress Content Tools — CRUD for Posts, Categories, Tags
 *
 * Inspired by easy-mcp-ai patterns. Uses native WP REST API endpoints.
 * 8 tools for WordPress content management.
 */
import { wpGetStandard, wpPostStandard, wpPutStandard, wpDeleteStandard } from '../utils/wp-api.js';

// These tools use native WP REST API (/wp-json/wp/v2/*)
// NOT bricks-bridge, so we use wpGetStandard/wpPostStandard

// Helper: format post for output
function fmtPost(p) {
  return `[${p.id}] "${p.title?.rendered || p.title}" (${p.status}) — ${p.type} — ${p.date?.split('T')[0] || ''}`;
}

export const wpContentTools = [

  // ═══ POSTS ═══════════════════════════════════════════════════

  {
    name: 'bricks_wp_list_posts',
    description: 'List WordPress posts with filtering. Returns ID, title, status, date, author, categories. Use for content auditing or finding posts to update.',
    inputSchema: {
      type: 'object',
      properties: {
        post_type: { type: 'string', description: 'REST base of the post type (default: posts). For a CPT pass its REST base, e.g. "service-area", "service", "faq". Look it up with bricks_get_post_types.', default: 'posts' },
        status: { type: 'string', description: 'Post status: publish, draft, pending, private, any (default: publish)', default: 'publish' },
        search: { type: 'string', description: 'Search term in title/content' },
        per_page: { type: 'number', description: 'Results per page (max 100, default 20)', default: 20 },
        page: { type: 'number', description: 'Page number (default 1)', default: 1 },
        categories: { type: 'array', items: { type: 'number' }, description: 'Filter by category IDs' },
        tags: { type: 'array', items: { type: 'number' }, description: 'Filter by tag IDs' },
        orderby: { type: 'string', description: 'Order by: date, title, modified, id (default: date)' },
        order: { type: 'string', description: 'asc or desc (default: desc)' },
      },
    },
    handler: async (args) => {
      try {
        const params = new URLSearchParams();
        params.set('per_page', String(Math.min(args.per_page || 20, 100)));
        params.set('page', String(args.page || 1));
        if (args.status && args.status !== 'any') params.set('status', args.status);
        if (args.status === 'any') params.set('status', 'publish,draft,pending,private');
        if (args.search) params.set('search', args.search);
        if (args.categories?.length) params.set('categories', args.categories.join(','));
        if (args.tags?.length) params.set('tags', args.tags.join(','));
        if (args.orderby) params.set('orderby', args.orderby);
        if (args.order) params.set('order', args.order);

        const posts = await wpGetStandard(`/wp/v2/${args.post_type || 'posts'}?${params}`, { raw: true });
        if (!Array.isArray(posts) || posts.length === 0) {
          return { content: [{ type: 'text', text: 'No posts found.' }] };
        }

        const lines = posts.map(fmtPost);
        return { content: [{ type: 'text', text: `Posts (${posts.length}):\n\n${lines.join('\n')}` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_wp_get_post',
    description: 'Get a single WordPress post with full content, excerpt, categories, tags, featured image, and meta. Works on custom post types via post_type.',
    inputSchema: {
      type: 'object',
      properties: {
        post_id: { type: 'number', description: 'Post ID' },
        post_type: { type: 'string', description: 'REST base of the post type (default: posts). For a CPT pass its REST base, e.g. "service-area", "service", "faq". Look it up with bricks_get_post_types.', default: 'posts' },
      },
      required: ['post_id'],
    },
    handler: async (args) => {
      try {
        const p = await wpGetStandard(`/wp/v2/${args.post_type || 'posts'}/${args.post_id}?context=edit`, { raw: true });
        const info = [
          `ID: ${p.id}`,
          `Title: ${p.title?.raw || p.title?.rendered}`,
          `Status: ${p.status}`,
          `Date: ${p.date}`,
          `Modified: ${p.modified}`,
          `Slug: ${p.slug}`,
          `Link: ${p.link}`,
          `Excerpt: ${(p.excerpt?.raw || '').substring(0, 200)}`,
          `Categories: ${(p.categories || []).join(', ')}`,
          `Tags: ${(p.tags || []).join(', ')}`,
          `Featured Media: ${p.featured_media || 'none'}`,
          ``,
          `Content (first 500 chars):`,
          (p.content?.raw || p.content?.rendered || '').substring(0, 500),
        ];
        return { content: [{ type: 'text', text: info.join('\n') }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_wp_create_post',
    description: 'Create a new WordPress post. Returns the new post ID and URL.',
    inputSchema: {
      type: 'object',
      properties: {
        title: { type: 'string', description: 'Post title' },
        post_type: { type: 'string', description: 'REST base of the post type (default: posts). For a CPT pass its REST base, e.g. "service-area", "service", "faq". Look it up with bricks_get_post_types.', default: 'posts' },
        content: { type: 'string', description: 'Post content (HTML)' },
        status: { type: 'string', description: 'publish, draft, pending (default: draft)', default: 'draft' },
        excerpt: { type: 'string', description: 'Post excerpt' },
        categories: { type: 'array', items: { type: 'number' }, description: 'Category IDs' },
        tags: { type: 'array', items: { type: 'number' }, description: 'Tag IDs' },
        featured_media: { type: 'number', description: 'Featured image media ID' },
      },
      required: ['title'],
    },
    handler: async (args) => {
      try {
        const body = {
          title: args.title,
          content: args.content || '',
          status: args.status || 'draft',
        };
        if (args.excerpt) body.excerpt = args.excerpt;
        if (args.categories) body.categories = args.categories;
        if (args.tags) body.tags = args.tags;
        if (args.featured_media) body.featured_media = args.featured_media;

        const p = await wpPostStandard(`/wp/v2/${args.post_type || 'posts'}`, body, { raw: true });
        return { content: [{ type: 'text', text: `Post created!\n  ID: ${p.id}\n  Title: ${p.title?.rendered}\n  Status: ${p.status}\n  URL: ${p.link}` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_wp_update_post',
    description: 'Update an existing WordPress post. Only sends changed fields. Works on custom post types via post_type.',
    inputSchema: {
      type: 'object',
      properties: {
        post_id: { type: 'number', description: 'Post ID to update' },
        post_type: { type: 'string', description: 'REST base of the post type (default: posts). For a CPT pass its REST base, e.g. "service-area", "service", "faq". Look it up with bricks_get_post_types.', default: 'posts' },
        title: { type: 'string', description: 'New title' },
        content: { type: 'string', description: 'New content (HTML)' },
        status: { type: 'string', description: 'New status: publish, draft, pending, private' },
        excerpt: { type: 'string', description: 'New excerpt' },
        categories: { type: 'array', items: { type: 'number' }, description: 'Category IDs' },
        tags: { type: 'array', items: { type: 'number' }, description: 'Tag IDs' },
        featured_media: { type: 'number', description: 'Featured image media ID' },
      },
      required: ['post_id'],
    },
    handler: async (args) => {
      try {
        const { post_id, post_type, ...fields } = args;
        const p = await wpPostStandard(`/wp/v2/${post_type || 'posts'}/${post_id}`, fields, { raw: true });
        return { content: [{ type: 'text', text: `Post ${post_id} updated.\n  Title: ${p.title?.rendered}\n  Status: ${p.status}\n  URL: ${p.link}` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  // ═══ CATEGORIES ═══════════════════════════════════════════════

  {
    name: 'bricks_wp_list_categories',
    description: 'List all WordPress categories with post counts.',
    inputSchema: {
      type: 'object',
      properties: {
        per_page: { type: 'number', description: 'Max results (default 100)', default: 100 },
        search: { type: 'string', description: 'Search by name' },
      },
    },
    handler: async (args) => {
      try {
        const params = new URLSearchParams();
        params.set('per_page', String(args.per_page || 100));
        if (args.search) params.set('search', args.search);
        const cats = await wpGetStandard(`/wp/v2/categories?${params}`, { raw: true });
        if (!cats.length) return { content: [{ type: 'text', text: 'No categories found.' }] };

        const lines = cats.map(c => `  [${c.id}] ${c.name} (${c.count} posts)${c.parent ? ` — parent: ${c.parent}` : ''}`);
        return { content: [{ type: 'text', text: `Categories (${cats.length}):\n\n${lines.join('\n')}` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_wp_create_category',
    description: 'Create a new WordPress category.',
    inputSchema: {
      type: 'object',
      properties: {
        name: { type: 'string', description: 'Category name' },
        slug: { type: 'string', description: 'URL slug (auto-generated if omitted)' },
        description: { type: 'string', description: 'Category description' },
        parent: { type: 'number', description: 'Parent category ID for hierarchy' },
      },
      required: ['name'],
    },
    handler: async (args) => {
      try {
        const c = await wpPostStandard(`/wp/v2/categories`, args, { raw: true });
        return { content: [{ type: 'text', text: `Category created!\n  ID: ${c.id}\n  Name: ${c.name}\n  Slug: ${c.slug}` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  // ═══ TAGS ═════════════════════════════════════════════════════

  {
    name: 'bricks_wp_list_tags',
    description: 'List all WordPress tags with post counts.',
    inputSchema: {
      type: 'object',
      properties: {
        per_page: { type: 'number', description: 'Max results (default 100)', default: 100 },
        search: { type: 'string', description: 'Search by name' },
      },
    },
    handler: async (args) => {
      try {
        const params = new URLSearchParams();
        params.set('per_page', String(args.per_page || 100));
        if (args.search) params.set('search', args.search);
        const tags = await wpGetStandard(`/wp/v2/tags?${params}`, { raw: true });
        if (!tags.length) return { content: [{ type: 'text', text: 'No tags found.' }] };

        const lines = tags.map(t => `  [${t.id}] ${t.name} (${t.count} posts)`);
        return { content: [{ type: 'text', text: `Tags (${tags.length}):\n\n${lines.join('\n')}` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },

  {
    name: 'bricks_wp_create_tag',
    description: 'Create a new WordPress tag.',
    inputSchema: {
      type: 'object',
      properties: {
        name: { type: 'string', description: 'Tag name' },
        slug: { type: 'string', description: 'URL slug (auto-generated if omitted)' },
        description: { type: 'string', description: 'Tag description' },
      },
      required: ['name'],
    },
    handler: async (args) => {
      try {
        const t = await wpPostStandard(`/wp/v2/tags`, args, { raw: true });
        return { content: [{ type: 'text', text: `Tag created!\n  ID: ${t.id}\n  Name: ${t.name}\n  Slug: ${t.slug}` }] };
      } catch (e) {
        return { content: [{ type: 'text', text: `Error: ${e.message}` }] };
      }
    },
  },
];
