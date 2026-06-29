# Tools Reference

All tools are prefixed with `bricks_`. Parameters marked with * are required.

---

## Pages

| Tool | Description |
|------|-------------|
| `bricks_list_pages` | List all pages with Bricks data. Filter by post_type, status. |
| `bricks_get_page` | Get full Bricks element data for a page. Params: page_id* |
| `bricks_update_page` | Replace all elements on a page (full PUT). Params: page_id*, bricks_data* |
| `bricks_patch_page` | Delta update — add, update, or remove specific elements. Params: page_id*, add?, update?, remove? |
| `bricks_append_elements` | Append elements to a page without replacing existing content. Params: page_id*, elements* |
| `bricks_build_page` | Build a page from section arrays. Params: page_id*, sections* |
| `bricks_search_pages` | Search pages by title or content. Params: query* |
| `bricks_clone_page` | Clone a page with all Bricks data. Params: page_id*, title? |
| `bricks_search_elements` | Search elements across pages by pattern. Params: pattern* |

## Scripts & Assets

| Tool | Description |
|------|-------------|
| `bricks_get_scripts` | Get per-page scripts. Params: page_id* |
| `bricks_update_scripts` | Update per-page scripts. Params: page_id*, scripts* |
| `bricks_get_page_assets` | Get structured page assets (CSS/JS/deps). Params: page_id* |
| `bricks_update_page_assets` | Update structured page assets. Params: page_id*, css?, js?, js_deps? |
| `bricks_set_gsap_flag` | Enable/disable GSAP enqueue for a page. Params: page_id*, enabled* |

## SEO (Page-Level)

| Tool | Description |
|------|-------------|
| `bricks_get_page_seo` | Get SEO meta for a page. Params: page_id* |
| `bricks_update_page_seo` | Update SEO meta. Params: page_id*, title?, description?, robots? |
| `bricks_get_page_schema` | Get JSON-LD schema markup. Params: page_id* |
| `bricks_update_page_schema` | Update JSON-LD schema. Params: page_id*, schema* |
| `bricks_seo_audit` | Run SEO audit on a page. Params: page_id* |
| `bricks_seo_analyze` | Analyze SEO for a page. Params: page_id* |

## Templates

| Tool | Description |
|------|-------------|
| `bricks_list_templates` | List all Bricks templates. |
| `bricks_create_template` | Create a new template. Params: title*, type*, bricks_data* |
| `bricks_get_template` | Get template data. Params: template_id* |
| `bricks_update_template` | Update a template. Params: template_id*, bricks_data* |
| `bricks_delete_template` | Delete a template. Params: template_id* |
| `bricks_clone_template` | Clone a template. Params: template_id*, title? |
| `bricks_import_template` | Import template from JSON. Params: json* |
| `bricks_search_templates` | Search templates. Params: query* |

## Backup & Snapshots

| Tool | Description |
|------|-------------|
| `bricks_get_backup` | Get backup data from a slot. Params: page_id*, slot? |
| `bricks_list_backups` | List available backups. Params: page_id* |
| `bricks_restore_backup` | Restore from backup slot. Params: page_id*, slot? |
| `bricks_create_snapshot` | Create a named snapshot. Params: page_id*, name* |
| `bricks_list_snapshots` | List all snapshots. Params: page_id* |
| `bricks_restore_snapshot` | Restore from named snapshot. Params: page_id*, snapshot_id* |
| `bricks_delete_snapshot` | Delete a snapshot. Params: page_id*, snapshot_id* |

## Global Classes

| Tool | Description |
|------|-------------|
| `bricks_list_global_classes` | List all global CSS classes. |
| `bricks_create_global_class` | Create a new class. Params: name*, settings* |
| `bricks_update_global_class` | Update a class. Params: id*, settings* |
| `bricks_delete_global_class` | Delete a class. Params: id* |
| `bricks_get_global_class_usage` | Get class usage across pages. Params: id? |
| `bricks_bulk_create_global_classes` | Create multiple classes at once. Params: classes* |
| `bricks_apply_class_to_element` | Apply a class to elements. Params: page_id*, element_ids*, class_id* |

## BEM Components

| Tool | Description |
|------|-------------|
| `bricks_generate_bem_component` | Generate a BEM class set. Params: block*, elements?, modifiers? |
| `bricks_apply_bem_component` | Apply BEM classes to page elements. Params: page_id*, block*, mapping* |
| `bricks_validate_bem` | Validate BEM naming on a page. Params: prefix? |

## Style System

| Tool | Description |
|------|-------------|
| `bricks_get_color_palette` | Get site color palette. |
| `bricks_update_color_palette` | Update color palette. Params: colors* |
| `bricks_list_fonts` | List registered fonts. |
| `bricks_register_font` | Register a custom font. Params: family*, variants* |
| `bricks_upload_font` | Upload font files. Params: file*, family* |
| `bricks_get_global_css` | Get global custom CSS. |
| `bricks_update_global_css` | Update global CSS. Params: css* |
| `bricks_get_css_variables` | Get CSS custom properties. |
| `bricks_update_css_variables` | Update CSS variables. Params: variables* |
| `bricks_get_breakpoints` | Get responsive breakpoint configuration. |

## Theme Styles

| Tool | Description |
|------|-------------|
| `bricks_get_theme_styles` | Get global theme style settings. |
| `bricks_update_theme_styles` | Update theme styles. Params: styles* |

## Presets

| Tool | Description |
|------|-------------|
| `bricks_list_presets` | List available section presets. |
| `bricks_instantiate_section` | Create elements from a preset. Params: preset_name*, variables? |
| `bricks_save_preset` | Save elements as a reusable preset. Params: name*, elements* |
| `bricks_delete_preset` | Delete a preset. Params: name* |

## SEO (Advanced)

| Tool | Description |
|------|-------------|
| `bricks_seo_auto_fix` | Auto-fix common SEO issues across pages. |
| `bricks_seo_bulk_update` | Bulk update SEO meta. Params: updates* |
| `bricks_readability` | Analyze content readability. Params: page_id* |
| `bricks_sitemap_ping` | Ping search engines about sitemap. |
| `bricks_social_preview` | Get OG/Twitter preview. Params: page_id* |
| `bricks_seo_plugin_info` | Detect installed SEO plugins. |
| `bricks_check_links` | Check for broken links. Params: page_id* |
| `bricks_sitemap_analyze` | Analyze sitemap structure. |
| `bricks_competitor_extract` | Extract competitor SEO data. Params: url* |
| `bricks_list_redirects` | List URL redirects. |
| `bricks_create_redirect` | Create a redirect. Params: from*, to*, type? |
| `bricks_delete_redirect` | Delete a redirect. Params: id* |
| `bricks_internal_links` | Suggest internal linking opportunities. Params: page_id* |

## WordPress Content

| Tool | Description |
|------|-------------|
| `bricks_wp_list_posts` | List posts. Params: post_type?, status? |
| `bricks_wp_get_post` | Get a post. Params: post_id* |
| `bricks_wp_create_post` | Create a post. Params: title*, content?, status? |
| `bricks_wp_update_post` | Update a post. Params: post_id*, title?, content? |
| `bricks_wp_list_categories` | List categories. |
| `bricks_wp_create_category` | Create a category. Params: name* |
| `bricks_wp_list_tags` | List tags. |
| `bricks_wp_create_tag` | Create a tag. Params: name* |

## Menus

| Tool | Description |
|------|-------------|
| `bricks_create_menu` | Create a navigation menu. Params: name* |
| `bricks_delete_menu` | Delete a menu. Params: menu_id* |
| `bricks_add_menu_item` | Add item to a menu. Params: menu_id*, title*, url* |
| `bricks_delete_menu_item` | Remove a menu item. Params: item_id* |
| `bricks_get_menu_locations` | Get registered menu locations. |

## Site Management

| Tool | Description |
|------|-------------|
| `bricks_get_settings` | Get site settings. |
| `bricks_update_settings` | Update site settings. Params: settings* |
| `bricks_create_page` | Create a new WordPress page. Params: title*, status? |
| `bricks_get_page_settings` | Get page-level settings. Params: page_id* |
| `bricks_update_page_settings` | Update page settings. Params: page_id*, settings* |
| `bricks_validate_elements` | Validate element structure. Params: elements* |
| `bricks_get_stats` | Get site statistics. |
| `bricks_get_post_types` | List registered post types. |
| `bricks_purge_cache` | Purge all caches. |

## Media

| Tool | Description |
|------|-------------|
| `bricks_upload_media` | Upload a media file. Params: file_path* or url* |
| `bricks_list_media` | List media library items. |
| `bricks_edit_media` | Edit media metadata. Params: media_id*, alt?, title? |

## Multi-Site

| Tool | Description |
|------|-------------|
| `bricks_list_sites` | List configured sites. |
| `bricks_switch_site` | Switch active site. Params: site_key* |
| `bricks_active_site` | Get current active site info. |

## Utilities

| Tool | Description |
|------|-------------|
| `bricks_connection_test` | Test WordPress connection. |
| `bricks_html_to_bricks` | Convert HTML to Bricks elements. Params: html* |
| `bricks_elementor_to_bricks` | Migrate an Elementor page/template to Bricks. Feed an Elementor JSON export (UI → Templates → Export) or raw `_elementor_data`; returns a Bricks element array + coverage report. Structural mapping, no HTML rendering, no write. Params: json, file_path |
| `bricks_batch` | Execute multiple operations in one request. Params: operations* (max 20) |
