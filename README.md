# bricks-mcp

```text
▟███████████████████████████████████████████▙
█  ____  ____  ___ ____ _  ______           █
█ | __ )|  _ \|_ _/ ___| |/ / ___|   BRICKS █
█ |  _ \| |_) || | |   | ' /\___ \          █
█ | |_) |  _ < | | |___| . \ ___) |  MCP    █
█ |____/|_| \_\___\____|_|\_\____/          █
▜▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▀▛
  105 tools · 17 modules · MIT licensed
```

> Printed on startup. In a real terminal the box animates in brick-by-brick (set `BRICKS_BANNER=anim|static|off`).

**The most comprehensive open-source MCP server for [Bricks Builder](https://bricksbuilder.io/).**

100+ tools to manage pages, templates, styles, SEO, content, and more — directly from Claude Code, Cursor, Windsurf, or any MCP-compatible AI assistant.

![Tools](https://img.shields.io/badge/tools-105-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Bricks](https://img.shields.io/badge/Bricks_Builder-2.0%2B-orange)
![MCP](https://img.shields.io/badge/MCP-1.8-purple)
![Runtime](https://img.shields.io/badge/Node_%7C_Bun-green)

---

## Architecture

```
Claude Code / Cursor / AI Assistant
        ↕ MCP Protocol (stdio)
   bricks-mcp (Node.js)
        ↕ REST API (Basic Auth)
   bricks-api-bridge (WordPress Plugin)
        ↕ PHP
   WordPress + Bricks Builder
```

The MCP server communicates with your WordPress site through the included REST API plugin. Your AI assistant gets full control over Bricks Builder — reading pages, updating elements, managing styles, and building sections.

---

## Quick Start (5 Minutes)

### 1. Install the WordPress Plugin

The `plugin/` folder in this repo contains the **Bricks API Bridge** WordPress plugin. Choose one of these install methods:

**Option A: ZIP Upload (easiest)**
1. Download **[bricks-api-bridge.zip](https://github.com/developer2013/bricks-mcp-open/releases/latest/download/bricks-api-bridge.zip)** from the latest release
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Upload the ZIP and click "Install Now"
4. Click "Activate"

**Option B: Manual Upload via FTP/SSH**
1. Clone this repo or download it
2. Copy the `plugin/` folder to your WordPress installation:
   ```bash
   cp -r plugin/ /path/to/wordpress/wp-content/plugins/bricks-api-bridge/
   ```
3. Go to WordPress Admin → Plugins → Activate "Bricks API Bridge"

**Option C: WP-CLI**
```bash
# From the repo root
cd plugin && zip -r ../bricks-api-bridge.zip . && cd ..
wp plugin install bricks-api-bridge.zip --activate
```

After activation, the plugin registers REST API endpoints under `/wp-json/bricks-bridge/v1/`. No configuration needed — it works out of the box with WordPress Application Passwords.

### 2. Create an Application Password

In WordPress Admin → Users → Your Profile → Application Passwords:
- Enter a name (e.g., "MCP Server")
- Click "Add New Application Password"
- Copy the generated password

### 3. Install the MCP Server

```bash
git clone https://github.com/developer2013/bricks-mcp-open.git
cd bricks-mcp-open
npm install   # or: bun install
```

### 4. Configure Credentials

Copy `.env.example` to `.env` and fill in your details:

```bash
cp .env.example .env
```

```env
WORDPRESS_URL=https://your-site.com
WORDPRESS_USER=your-username
WORDPRESS_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
```

### 5. Add to Your AI Assistant

**Claude Code** — add to `~/.claude/.mcp.json`:
```json
{
  "mcpServers": {
    "bricks": {
      "command": "node",
      "args": ["/path/to/bricks-mcp-open/index.js"]
    }
  }
}
```

Or with Bun (faster startup):
```json
{
  "mcpServers": {
    "bricks": {
      "command": "bun",
      "args": ["/path/to/bricks-mcp-open/index.js"]
    }
  }
}
```

**Claude Code iOS / Android App** — open Settings → MCP Servers → Add Server:

1. **Name:** `bricks`
2. **Command:** `node` (or `bun`)
3. **Arguments:** `/path/to/bricks-mcp-open/index.js`
4. **Environment Variables:**
   - `WORDPRESS_URL` = `https://your-site.com`
   - `WORDPRESS_USER` = `your-username`
   - `WORDPRESS_APP_PASSWORD` = `xxxx xxxx xxxx xxxx xxxx xxxx`

**Cursor / Windsurf** — add the same config to `.cursor/mcp.json` in your project.

**[Hermes Agent](https://github.com/NousResearch/hermes-agent)** (Nous Research) — add to `config.yaml`:
```yaml
mcp_servers:
  bricks:
    command: node
    args: ["/path/to/bricks-mcp-open/index.js"]
    env:
      WORDPRESS_URL: https://your-site.com
      WORDPRESS_USER: your-username
      WORDPRESS_APP_PASSWORD: "xxxx xxxx xxxx xxxx xxxx xxxx"
```
Hermes has native MCP client support since v0.2.0 — all 105 tools appear in its tool list automatically. Works with any model (Hermes 3, Llama, OpenRouter, etc.).

---

### Manage Your WordPress Site from Your Phone

Deploy the MCP server as a **self-hosted MCP environment** on Railway (or any Docker host), then connect it to the Claude Code mobile app. No Mac running in the background needed — the server runs 24/7 in the cloud.

**How it works:**
```
iPhone / Android (Claude Code App)
      ↕ Claude Self-Hosted MCP
Railway / Docker (MCP Server — always on)
      ↕ REST API
WordPress + Bricks Builder
```

**Setup:**
1. Deploy the MCP server to Railway using the included `agent-service/Dockerfile`
2. In [Claude Console](https://console.anthropic.com) → MCP → Create Self-Hosted Environment
3. Generate an environment key and add it to your Railway deployment
4. The Claude Code mobile app automatically connects to your self-hosted environment

**What you can do from your phone — no laptop needed:**

```
You:  "List all my pages and their SEO scores"
You:  "Create a new landing page for my dental practice"
You:  "Update the hero headline on page 42 to 'Welcome to Our Practice'"
You:  "Run an SEO audit and fix all missing meta descriptions"
You:  "Add a testimonials section using the testimonials-slider preset"
You:  "Create a snapshot called 'before-redesign', then change the palette"
```

| Action | Example Prompt |
|--------|---------------|
| Build pages | *"Create a hero section with dark gradient background"* |
| Edit content | *"Change the button text on page 42 to 'Book Now'"* |
| SEO management | *"Set meta description and OG image for all pages"* |
| Style updates | *"Update the primary color to #2563EB across the palette"* |
| Backup & restore | *"Create a snapshot called 'before-redesign'"* |
| QA checks | *"Check all links on page 42 for broken URLs"* |
| Template management | *"Clone the header template and modify the navigation"* |

The server runs 24/7 on Railway — your phone is just the control surface. All 105 tools are available anywhere, anytime.

### 6. Test the Connection

Ask your AI assistant:
> "Use the bricks_connection_test tool to verify my WordPress connection."

---

## Tool Categories (100+ Tools)

| Category | Tools | Description |
|----------|-------|-------------|
| **Pages** | 9 | List, get, update, patch, append, build, clone, search pages |
| **Scripts & Assets** | 5 | Per-page CSS/JS management, GSAP flag control |
| **SEO (Page)** | 6 | Page-level SEO meta, schema markup, audit |
| **Templates** | 8 | Full template CRUD, clone, import, search |
| **Backup & Snapshots** | 7 | Named snapshots, multi-slot backups, restore |
| **Global Classes** | 7 | CSS class CRUD, bulk create, usage analysis |
| **BEM Components** | 3 | Generate, apply, and validate BEM class sets |
| **Style System** | 10 | Color palette, fonts, CSS variables, breakpoints |
| **Theme Styles** | 2 | Global theme style read/write |
| **Presets** | 4 | Section presets: list, instantiate, save, delete |
| **SEO (Advanced)** | 13 | Auto-fix, bulk update, sitemap, redirects, link check |
| **WordPress Content** | 8 | Posts, categories, tags CRUD |
| **Menus** | 5 | Navigation menu management |
| **Site Management** | 9 | Settings, page creation, validation, cache, stats |
| **Media** | 3 | Upload, list, edit media files |
| **Multi-Site** | 3 | Switch between WordPress sites at runtime |
| **Utilities** | 3 | Connection test, HTML→Bricks converter, batch ops |

---

## Keeping your CSS editable in Bricks

The whole point of Bricks is a visual builder — so CSS the assistant writes should stay **editable in Bricks**, not vanish into a place you can't reach. This plugin can inject per-page CSS into a private bundle (`_bab_page_assets`, output at `wp_head` priority **9997 — before** element CSS) via `bricks_update_page_assets`. That bundle is **not shown anywhere in the Bricks builder** and silently overrides what the builder displays — great for infrastructure CSS (`@font-face`, `:root{--vars}`, `@keyframes`, critical above-the-fold), but the wrong home for the layout/responsive/visual CSS you'll want to tweak by hand later. (This is exactly the r/BricksBuilder feedback: *"plugin-managed page CSS that a human can't edit defeats the purpose of using Bricks."*)

**Where editable CSS belongs (priority order):**

1. **Native element settings** — padding, typography, colors, etc. (fully visual controls)
2. **Element `_cssCustom`** — per-element custom CSS, editable on the element's CSS tab
3. **Page `customCss`** — `bricks_update_page_settings` → Bricks *Page Settings → Custom CSS*
4. **Global classes** — editable in the Class Manager (build them with **native settings**, not `_cssCustom`, which is not compiled into page CSS via the API path)

### The editability guard

To stop editable CSS from leaking into the invisible bundle, the guard classifies the `css` field of `bricks_update_page_assets` and acts in three modes:

| Mode | Behaviour |
|------|-----------|
| `off` *(default)* | No-op — fully backwards-compatible |
| `warn` | Write proceeds, the REST response carries a `warnings[]` field naming the editable rules |
| `block` | Editable CSS is rejected (HTTP 422) and **nothing is written** |

**Enable it** by adding one line to your `wp-config.php` (above `/* That's all, stop editing! */`):

```php
// Bricks API Bridge — warn (or 'block') when editable CSS is sent to the private page-assets bundle
define( 'BAB_GUARD_EDITABLE_CSS', 'warn' );
```

(Or, without editing files: `wp option update bab_guard_editable_css warn`.) On the MCP side, the `bricks_update_page_assets` tool warns by default; set the env var `BRICKS_MCP_BLOCK_EDITABLE_CSS=1` to hard-block there too. Infrastructure CSS always passes — use the `css_critical` / `css_deferred` fields for it.

---

## Multi-Site Support

Manage multiple WordPress sites from a single MCP server. Copy `sites.json.example` to `sites.json`:

```json
{
  "default": "production",
  "sites": {
    "production": {
      "label": "Live Site",
      "url": "https://your-site.com",
      "username": "admin",
      "password": "xxxx xxxx xxxx xxxx xxxx xxxx"
    },
    "staging": {
      "label": "Staging",
      "url": "https://staging.your-site.com",
      "username": "admin",
      "password": "xxxx xxxx xxxx xxxx xxxx xxxx"
    }
  }
}
```

Switch sites at runtime:
> "Switch to the staging site."

**Custom `sites.json` location.** If you collect your MCP servers in one folder and keep site configs elsewhere, set `BRICKS_SITES_PATH` to point at the file — absolute, relative (to the working directory), or `~`-prefixed all work:

```
BRICKS_SITES_PATH=~/mcp-config/bricks-sites.json
```

When set, a missing file fails loudly at startup (instead of silently falling back to the `WORDPRESS_*` env vars), so a typo in the path can't connect you to the wrong site. No need to symlink `sites.json` into the server folder.

---

## Usage Examples

### Build a page section
> "Create a hero section on page 42 with a full-width background, centered heading 'Welcome', a subtitle, and a CTA button."

### Manage styles
> "Update the color palette — set the primary color to #2563EB and secondary to #7C3AED."

### SEO optimization
> "Run an SEO audit on all published pages and auto-fix missing meta descriptions."

### Backup before changes
> "Create a snapshot of page 42 named 'before-redesign', then update the hero section."

---

## Plugin Features

The included WordPress plugin (`bricks-api-bridge`) provides:

- **REST API endpoints** for all Bricks Builder data
- **Security hardening** — rate limiting, user enumeration protection, security headers
- **Responsive inference** — automatic mobile/tablet breakpoint generation
- **Element validation** — catches invalid IDs, broken parent-child links
- **Auto-fix** — corrects common CSS issues (overflow, grid, container width)
- **Backup system** — multi-slot backups + named snapshots
- **Design token import/export** — JSON, ACSS, Tailwind formats

---

## Feature Comparison

| Feature | bricks-mcp | Novamira | cristianuibar/bricks-mcp | sabiertas/bricks-mcp-server |
|---------|-----------|----------|--------------------------|----------------------------|
| **Focus** | **Bricks Builder** | Generic WordPress | Bricks Builder | Bricks Builder |
| **Tools** | **105** | 22 (12 core + 10 Gutenberg) | 11 | 10 |
| **Approach** | REST API (structured endpoints) | PHP execution + filesystem | REST API | REST API |
| **Agent Service** | Autonomous 5-phase pipeline | - | - | - |
| **Mobile Control** | Telegram bot (iPhone/Android) | - | - | - |
| **Multi-Site** | Runtime switching | - | - | Environment vars |
| **BEM Support** | Generate + Apply + Validate | - | - | - |
| **SEO Tools** | 19 (meta + audit + redirects) | - | - | - |
| **Backup System** | Snapshots + Multi-slot | - | - | - |
| **Section Presets** | 25 ready-to-use | - | - | - |
| **Security Hardening** | Rate limit + Headers | - | - | - |
| **Responsive Inference** | Auto breakpoints | - | - | - |
| **WordPress Content** | Posts + Categories + Tags + Menus | Via PHP execution | - | - |
| **Batch Operations** | Up to 20 per request | - | - | - |
| **Design Tokens** | Import/Export (JSON, ACSS) | - | - | - |
| **Auto-Fix** | Overflow, grid, container | - | - | - |
| **Gutenberg Support** | - | Block Editor Queue | - | - |
| **PHP Execution** | - | Direct PHP eval | - | - |
| **License** | MIT | AGPL-3.0 | GPL-2.0 | MIT |

---

## Requirements

- **Node.js** >= 18 or **Bun** >= 1.0
- **WordPress** >= 6.0
- **Bricks Builder** >= 2.0
- **PHP** >= 8.0

---

## Agent Service (Autonomous Page Builder)

The `agent-service/` directory contains an autonomous agent that builds entire WordPress pages from industry briefs — using Claude + the MCP server in a 5-phase pipeline:

```
Industry Brief → Historian → Design → Code → Update → QA → Fix Loop
```

- **Build from your iPhone or Android** — Use the Telegram bot or the [Claude Code mobile app](https://claude.ai/download) as your control center: trigger builds, QA, fixes, and screenshots from anywhere
- **CLI Mode** — Single builds or overnight batch processing
- **Budget Control** — Per-phase cost caps, total cost tracking
- **Docker Ready** — Deploys to Railway, Fly.io, or any container host

See [`agent-service/README.md`](agent-service/README.md) for setup instructions.

---

## Premium

Looking for more? The premium edition includes **260+ tools** with:

- **AI Build Pipeline** — Build entire pages from a URL in one command
- **Learning System** — Remembers CSS fixes and improves over time
- **Math Library** — 33 modules for design tokens, color harmony, spring physics
- **Visual QA** — Puppeteer screenshots, pixel comparison, accessibility audits
- **Design Intelligence** — Brand archetypes, competitive benchmarking, typography suggestions
- **WooCommerce, ACF, Gravity Forms** integrations
- **Bio-inspired animations** — Levy stagger, density-intensity, quorum sensing

[Reach out for premium access](mailto:me@carstensachse.de)

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for guidelines.

## License

MIT License. See [LICENSE](LICENSE).
