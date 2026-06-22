# Changelog

## 1.2.3 (2026-06-22)

### Added
- **CSS editability guard — keep agent-written CSS editable in the Bricks builder.** Human-editable layout/responsive/visual CSS should live in a Bricks-native channel (element `_cssCustom`, page `customCss` via `bricks_update_page_settings`, or global classes) — not in the plugin-private `_bab_page_assets` bundle, which is injected at `wp_head` priority 9997 (before element CSS) and has no Bricks editing UI, so it silently overrides what the builder shows. The MCP `bricks_update_page_assets` now classifies the `css` field and **warns** when it carries editable rules (set `BRICKS_MCP_BLOCK_EDITABLE_CSS=1` to hard-block instead); the plugin enforces the same at the REST boundary via `BAB_GUARD_EDITABLE_CSS` (`off` default / `warn` / `block`), so non-MCP callers are covered too. Reserve the `css` field for infra (`@font-face`, `:root{--vars}`, `@keyframes`, critical CSS). (h/t the r/BricksBuilder feedback that plugin-managed page CSS a human can't edit defeats the purpose of using Bricks.)

### Changed
- **MCP steering: build global classes with native settings, not `_cssCustom`.** A global class's `_cssCustom` is not compiled into the page CSS via the API path (only its native settings are: `_padding`, `_typography`, `_background`, `_gridTemplateColumns`, `_columnGap`, `_border`, `_boxShadow`, …). Tool descriptions and the server preamble now steer toward the native-settings + element-`_cssCustom` channels.

## 1.2.2 (2026-06-17)

### Fixed
- **HTML→Bricks converter: a plain `<div>` is no longer forced into a flex container.** The converter was blanket-mapping `<div>` (and `nav` / `header` / `footer` / `main` / `article` / `aside` / `figure`) to a Bricks `container`, which is `display: flex` — so a plain block-flow wrapper silently inherited flex positioning it never had. It now maps those tags to a `block` (a real `<div>`) and only reaches for `container` when the resolved styles (inline **or** class-resolved from the provided stylesheet) actually lay it out as flex/grid. (h/t the r/BricksBuilder thread.)

### Added
- **Connect: a "Plugin-managed page CSS" panel.** The setup screen now lists pages that carry plugin-injected per-page CSS — the bundle stored in `_bab_page_assets` and echoed into `<head>` as `<style id="bab-page-css-{ID}">`. That CSS is intentionally not shown in the Bricks builder/settings/theme/class panels (it's for CSS variables, critical CSS and anything the assistant couldn't express via element/class settings), which is what made it feel like "CSS I can't find anywhere." It's read-only here (editable via `bricks_update_page_assets`), so it's no longer a black box.

## 1.2.1 (2026-06-17)

### Added
- **Connect — a guided setup UI.** Turns the headless REST bridge into an admin screen ("Bricks MCP") so you don't have to wire everything by hand: a status grid (plugin / Bricks / REST endpoints / Application Passwords), **one-click Application Password creation** (copy button; the plaintext is shown once via a 120s transient — never via the URL — with a revoke table), a **multi-client config generator** with ready-to-paste config for Claude Code, Claude Desktop, Cursor, Windsurf, Cherry Studio and Hermes (pre-filled with your site URL, username and the generated password), a connection test, and an admin-bar status chip. Purely additive — it registers an admin menu only (no REST route or auth path touched), gated behind `is_admin()` + the `bab_admin_ui_enabled` flag.
- **Full-state backup & restore.** Capture the whole Bricks layer (pages / templates / global classes / menus) plus a curated allowlist of WordPress core settings into one downloadable file, and restore it. The backup directory is hardened (`.htaccess` deny + `index.php` + an unguessable token filename) and the settings dump deliberately excludes secrets / API keys.

### Security
- **Spoofing-resistant client IP for rate-limiting & the login lockout.** Proxy-forwarded headers (`X-Forwarded-For` / `CF-Connecting-IP` / `X-Real-IP`) are now honored only from configured trusted proxies (the `bab_trusted_proxies` constant / option / filter; set it to `'*'` to restore the legacy behavior); otherwise the real TCP peer (`REMOTE_ADDR`) is used. Closes a bypass where a client could forge `X-Forwarded-For` to rotate the throttle key and defeat the login lockout. The REST and login throttles now key on the same source.
- **Object-level authorization on page-mutating endpoints.** `update_page` / `patch_page` / `append_elements` / `clone_page` / `build_page` / `sign-code` now require `edit_post` on the specific target page, not merely the generic `edit_posts` capability — so a lower-privileged user can no longer overwrite arbitrary pages by ID. Reads are unchanged; administrators are unaffected.
- **Backup restore is allowlist-based.** `import_full_state` now writes only the same curated WordPress-core option keys the export captures (previously an infra-only denylist), so a crafted backup file can no longer set arbitrary options.
- **Backup filename token widened** 6 → 20 characters (the only protection on nginx, where the directory's `.htaccess` deny is ignored).
- **Application Passwords force-enable is now opt-out-able** via the `BAB_FORCE_APP_PASSWORDS` constant / `bab_force_app_passwords` filter (default unchanged), so a site can keep its deliberate decision to disable App Passwords.

## 1.0.4 (2026-06-04)

### Added
- **Custom `sites.json` location** — set the `BRICKS_SITES_PATH` env var to keep your multi-site config outside the server folder (handy if you collect MCP servers in one shared directory). Accepts absolute, relative (to the working directory), or `~`-prefixed paths, and still falls back to the bundled `./sites.json` when unset, so existing setups are unaffected. When the variable **is** set but the file is missing, startup now fails loudly instead of silently falling back to the `WORDPRESS_*` vars — a path typo can no longer quietly connect you to the wrong site. Removes the need to symlink `sites.json` into the repo folder. (h/t the r/BricksBuilder discussion on running the server under DDEV/opencode with a shared MCP-config folder.)

### Changed
- **Model steering toward native Bricks elements.** The server now sends an MCP `instructions` preamble (injected into the client's system context, so it reaches *any* model on *every* request) telling the model to prefer Bricks' structured path — theme styles, global classes, `settings.style`, native elements like `text-link`/`button` — over freelancing raw `_cssCustom`/inline styling. The same THEME-FIRST guidance now also rides on `bricks_update_page` and `bricks_patch_page`, which previously had none (it was only on create/append/build). Closes the gap where models with weaker tool-calling discipline would inject custom CSS/classes instead of using the tools. (h/t the r/BricksBuilder DDEV/opencode thread.)

## 1.0.3 (2026-06-01)

### Added
- **Security audit** — two new read-only tools: `bricks_security_audit` and `bricks_security_inventory`. The audit scores the site 0–100 (A–F) across Bricks-core CVE exposure, bridge route permissions (a self-audit of the bridge's own write surfaces), code-element exposure, platform currency (WordPress core / PHP / plugin updates), configuration hygiene, and access/transport. Findings are returned worst-first with remediation; any open CRITICAL hard-caps the grade to F. It is a posture/exposure report, **not** a malware scanner. Outdated-component detection reads WordPress' own update transients — no external network calls. Plugin-side, behind the `bab_security_audit_enabled` option (default on).
- New bridge endpoints (admin-only): `GET /security/audit` and `GET /security/inventory/components`.
- **Structured call/diff log** — a new `bricks_call_log` tool plus server-side logging of every tool call (summarized args, status, duration) and, for page-mutating calls, a before/after element diff (count delta + added/removed element IDs). Answers "which call changed or corrupted the page, and with what input?" — the trail a snapshot rollback erases. Light log for all calls is free (no extra requests); the before/after diff fires only for the page-data write tools and reuses a recent cached page read. Args are summarized (large arrays/strings collapsed) and the JSONL log rotates at 5 MB, so it stays compact. Best-effort and fail-safe — logging can never break a tool call. Flags: `BAB_CALL_LOG=0` to disable, `BAB_CALL_LOG_DIFF=0` to keep the light log without diffs, `BAB_CALL_LOG_PATH` to relocate the file. (h/t the r/BricksBuilder discussion on observability past 100 tools.)
- These bring the MCP to **108 tools**.

### Security
- **Hardened code-surface routes (defense in depth).** The `sign-code` and page-`assets` write endpoints now require `manage_options` instead of `edit_posts`, so signing executable Bricks code or storing raw page CSS/JS is restricted to administrators. Gated by the `bab_harden_code_routes` option (default on); set it to `false` to restore the previous behavior. Structured element writes (`PUT /pages/{id}`) and the GSAP flag remain at `edit_posts`. The `scripts` endpoint already required `manage_options`.

### Changed
- Plugin version bumped to 1.0.1 (security audit class + route hardening).

### Fixed
- **Plugin 1.0.2 hotfix** — fatal `TypeError` in `class-dynamic-tags.php` that could take down the entire frontend. When an image element used a dynamic-data source inside a nested query loop, Bricks passed the full settings **array** (not a string) through the `bricks/dynamic_data/render_tag` and `render_content` filters; both handlers called `strpos()` on it without a type check. Added an additive `is_string()` guard on both call sites — no behavior change for valid string tags, the previously-fatal array case now passes through. The `bricks-api-bridge.zip` on this release was rebuilt with the fix. ([#3](https://github.com/developer2013/bricks-mcp-open/issues/3), reported + diagnosed by @sawka-tech.)

## 1.0.2 (2026-05-28)

### Changed
- Tool descriptions for `bricks_patch_page`, `bricks_append_elements`, and `bricks_build_page` now explicitly advertise the plugin-side auto-backup guarantee that was previously documented only on `bricks_update_page`. All four destructive write operations create a backup before writing — enforced server-side, cannot be skipped from the MCP/LLM. No behavior change; closes a documentation gap. (h/t [u/justinnealey](https://www.reddit.com/r/BricksBuilder/) for flagging it during the v1.0.1 launch discussion.)

## 1.0.1 (2026-05-28)

### Fixed
- Validator allowlist expanded from 24 to 60 elements. Previously rejected: `text-link`, `list`, `svg`, `html`, `divider`, `alert`, `progress-bar`, `counter`, `countdown`, `breadcrumbs`, `search`, `social-icons`, `icon-box`, `audio`, `map`, `shortcode`, `logo`, `slider`, `lottie`, `nav-nested`, `offcanvas`, `toggle`, `sidebar`, `tabs`, `div`, and 12 `post-*` template elements. (h/t [u/MysteryBros](https://www.reddit.com/r/BricksBuilder/) for flagging the missing `text-link`.)

### Changed
- Build-tool descriptions (`bricks_build_page`, `bricks_create_page`, `bricks_append_elements`) now nudge the LLM toward theme-first workflow: fetch `bricks_get_theme_styles` and `bricks_list_global_classes` before generating elements, prefer `settings.style: "primary"` on buttons over manual styling, use `text-link` for links instead of styled text-basic. Same tools, sharper guidance.

## 1.0.0 (2026-04-10)

### Initial Release

- 100+ MCP tools for Bricks Builder
- Full page CRUD (list, get, update, patch, append, build, clone, search)
- Template management (CRUD, clone, import, search)
- Global CSS classes with BEM support
- Complete style system (colors, fonts, CSS variables, theme styles)
- Backup and snapshot management
- SEO tools (meta, schema, sitemap, redirects, link checking)
- WordPress content management (posts, categories, tags)
- Navigation menu management
- Section presets (list, instantiate, save)
- Media management (upload, list, edit)
- Multi-site support with runtime switching
- HTML to Bricks converter
- Batch operations (up to 20 per request)
- Per-page asset management (CSS/JS separation)
- Security hardening (rate limiting, enumeration protection)
- Responsive inference for automatic breakpoint generation
