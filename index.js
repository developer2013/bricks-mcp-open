#!/usr/bin/env node
/**
 * Bricks Builder MCP Server v1.0.0 — Open Source Edition
 *
 * A Model Context Protocol server that provides 100+ tools for managing
 * Bricks Builder pages, templates, styles, and content via the
 * bricks-api-bridge WordPress plugin REST endpoints.
 *
 * https://github.com/developer2013/bricks-mcp-open
 */
import { Server } from "@modelcontextprotocol/sdk/server/index.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  ListToolsRequestSchema, CallToolRequestSchema,
} from "@modelcontextprotocol/sdk/types.js";
import config from './config.js';
import { printBanner } from './banner.js';
import { initSites, getActiveSite, validateActiveSite } from './site-manager.js';
import { wpGetCached } from './utils/wp-api.js';
import { TTL } from './utils/cache.js';

import {
  pageTools, searchTools, scriptTools, assetTools, seoTools, schemaTools, seoAuditTools,
  templateTools, backupTools, snapshotTools, mediaTools,
  themeStylesTools, batchTools, presetTools, globalClassesTools,
  styleSystemTools, connectionTools, converterTools, elementorConverterTools,
  siteTools, advancedSeoTools, siteManagementTools,
  wpContentTools, menuTools, securityTools, observabilityTools,
} from './tools/index.js';
import { setServer as setBatchServer } from './tools/batch.js';
import * as callLog from './utils/call-log.js';

// Initialize multi-site manager (loads sites.json or falls back to env)
initSites();

// Log startup information (stderr so it doesn't interfere with stdio transport)
const activeSite = getActiveSite();
const { valid: configValid, missing } = validateActiveSite();
console.error(`STARTING ${config.SERVER_NAME.toUpperCase()} MCP SERVER v${config.SERVER_VERSION}`);
console.error(`Active site: ${activeSite.label} [${activeSite.key}] → ${activeSite.url}`);
if (!configValid) {
  console.error(`Missing credentials: ${missing.join(', ')}`);
}

// Combine all tools
const TOOLS = [
  ...siteTools,
  ...pageTools,
  ...searchTools,
  ...scriptTools,
  ...assetTools,
  ...seoTools,
  ...schemaTools,
  ...seoAuditTools,
  ...templateTools,
  ...backupTools,
  ...snapshotTools,
  ...mediaTools,
  ...themeStylesTools,
  ...batchTools,
  ...presetTools,
  ...globalClassesTools,
  ...styleSystemTools,
  ...connectionTools,
  ...converterTools,
  ...elementorConverterTools,
  ...advancedSeoTools,
  ...siteManagementTools,
  ...wpContentTools,
  ...menuTools,
  ...securityTools,
  ...observabilityTools,
];

// O(1) tool lookup via Map (instead of O(n) array.find)
const TOOL_MAP = new Map();
for (const t of TOOLS) TOOL_MAP.set(t.name, t);
console.error(`Registered ${TOOLS.length} tools (Map dispatch)`);

// Pre-compute tools/list response (computed once, returned on every list call)
const TOOLS_LIST_RESPONSE = Object.freeze({
  tools: TOOLS.map(t => ({
    name: t.name,
    description: t.description,
    inputSchema: t.inputSchema,
  })),
});

// Create server
const server = new Server(
  { name: config.SERVER_NAME, version: config.SERVER_VERSION },
  { capabilities: { tools: {} }, instructions: config.BUILD_INSTRUCTIONS }
);

// Pass server reference for progress notifications
setBatchServer(server);

// Handle tools/list — return pre-computed response
server.setRequestHandler(ListToolsRequestSchema, async () => TOOLS_LIST_RESPONSE);

// Handle tools/call — O(1) Map dispatch
server.setRequestHandler(CallToolRequestSchema, async (request) => {
  const { name, arguments: args = {} } = request.params;
  console.error(`TOOL CALL: ${name}`);

  const tool = TOOL_MAP.get(name);
  if (!tool) {
    return {
      content: [{ type: "text", text: `Tool not found: ${name}` }],
      isError: true,
    };
  }

  // Structured call/diff log (best-effort, never affects the call result).
  const logging = callLog.isEnabled();
  const started = Date.now();
  let before = null;
  if (logging) {
    try { before = await callLog.captureBefore(name, args); } catch { /* ignore */ }
  }

  try {
    const result = await tool.handler(args);
    if (logging) {
      try {
        const diff = await callLog.finalizeDiff(name, args, before);
        callLog.logCall({
          tool: name, args,
          status: result && result.isError ? 'error' : 'ok',
          durationMs: Date.now() - started,
          diff,
        });
      } catch { /* logging must never break a call */ }
    }
    return result;
  } catch (error) {
    if (logging) {
      try {
        callLog.logCall({ tool: name, args, status: 'error', error: error.message, durationMs: Date.now() - started, diff: null });
      } catch { /* ignore */ }
    }
    console.error(`Error in ${name}:`, error);
    return {
      content: [{ type: "text", text: `Error: ${error.message}` }],
      isError: true,
    };
  }
});

// Start the server
const transport = new StdioServerTransport();
server.connect(transport)
  .then(async () => {
    await printBanner({
      version: config.SERVER_VERSION,
      toolCount: TOOLS.length,
      moduleCount: 17,
      site: activeSite.url.replace(/^https?:\/\//, ''),
    });
    console.error(`${config.SERVER_NAME} connected and listening`);

    // Warm cache in background (non-blocking)
    if (configValid) {
      Promise.allSettled([
        wpGetCached('/theme-styles', TTL.THEME_STYLES),
        wpGetCached('/global-classes', TTL.GLOBAL_CLASSES),
        wpGetCached('/presets', TTL.PRESETS),
        wpGetCached('/color-palette', TTL.COLOR_PALETTE),
      ]).then(results => {
        const ok = results.filter(r => r.status === 'fulfilled').length;
        console.error(`CACHE WARM: ${ok}/${results.length} endpoints prefetched`);
      });
    }
  })
  .catch(error => {
    console.error(`Connection error: ${error.message}`);
    process.exit(1);
  });
