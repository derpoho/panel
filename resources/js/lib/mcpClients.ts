import { trans } from 'laravel-vue-i18n';
import type { AiBrand } from '@/lib/aiBrands';

/**
 * How to connect each AI app to a workspace's MCP server. OAuth is the
 * way in where the app supports it (it signs in through ServerOS and asks
 * you to approve); an API key, sent as a Bearer header, works everywhere
 * else.
 */
export type McpClient = {
    id: string;
    name: string;
    brand: AiBrand;
    /** Under the name, in the picker. */
    note: string;
    steps: string[];
    /** A command or config to copy, with the URL (and key) filled in. */
    snippet?: (url: string, key: string | null) => string;
    snippetLabel?: string;
    /** Signs in through ServerOS with OAuth; no key needed. */
    oauth: boolean;
};

const json = (value: unknown) => JSON.stringify(value, null, 2);

const bearer = (key: string | null) => `Bearer ${key ?? '<your API key>'}`;

export const MCP_CLIENTS: McpClient[] = [
    {
        id: 'claude',
        name: 'Claude',
        brand: 'claude',
        note: trans('claude.ai and the desktop app'),
        oauth: true,
        steps: [
            trans(
                'In Claude, open Settings › Connectors and choose Add custom connector.',
            ),
            trans(
                'Name it ServerOS and paste the server URL. Leave the OAuth fields empty.',
            ),
            trans(
                'Choose Connect. ServerOS opens: check the workspace and what Claude may do, then approve.',
            ),
        ],
    },
    {
        id: 'chatgpt',
        name: 'ChatGPT',
        brand: 'openai',
        note: trans('Developer mode connectors'),
        oauth: true,
        steps: [
            trans(
                'In ChatGPT, open Settings › Apps & Connectors › Advanced settings and turn on Developer mode.',
            ),
            trans(
                'Back in Apps & Connectors, choose Create. Name it ServerOS, paste the server URL and pick OAuth.',
            ),
            trans(
                'Approve the connection when ServerOS asks, then turn ServerOS on in a chat from the tools menu.',
            ),
        ],
    },
    {
        id: 'claude-code',
        name: 'Claude Code',
        brand: 'claude',
        note: trans('In your terminal'),
        oauth: true,
        steps: [
            trans('Add the server, then start Claude Code.'),
            trans(
                'Run /mcp, pick serveros and choose Authenticate. Approve it in the browser.',
            ),
        ],
        snippetLabel: trans('Terminal'),
        snippet: (url, key) =>
            key
                ? `claude mcp add --transport http serveros ${url} \\\n  --header "Authorization: ${bearer(key)}"`
                : `claude mcp add --transport http serveros ${url}`,
    },
    {
        id: 'codex',
        name: 'Codex',
        brand: 'codex',
        note: trans('The Codex app and CLI'),
        oauth: true,
        steps: [
            trans(
                'In the Codex app, open Plugins › MCPs and add a custom MCP. Choose Streamable HTTP, not STDIO.',
            ),
            trans(
                'Name it serveros and paste the server URL. Save, then choose Authenticate and approve it in ServerOS.',
            ),
            trans('In the Codex CLI, run the command below instead.'),
        ],
        snippetLabel: trans('Codex CLI'),
        snippet: (url, key) =>
            key
                ? `# ~/.codex/config.toml, with SERVEROS_MCP_KEY=${key} in your environment\n[mcp_servers.serveros]\nurl = "${url}"\nbearer_token_env_var = "SERVEROS_MCP_KEY"`
                : `codex mcp add serveros --url ${url}\ncodex mcp login serveros`,
    },
    {
        id: 'cursor',
        name: 'Cursor',
        brand: 'cursor',
        note: trans('Agent mode'),
        oauth: true,
        steps: [
            trans(
                'Add this to ~/.cursor/mcp.json (or .cursor/mcp.json in a project).',
            ),
            trans(
                'Open Cursor Settings › MCP: ServerOS asks you to log in. Approve it in the browser.',
            ),
        ],
        snippetLabel: 'mcp.json',
        snippet: (url, key) =>
            json({
                mcpServers: {
                    serveros: key
                        ? { url, headers: { Authorization: bearer(key) } }
                        : { url },
                },
            }),
    },
    {
        id: 'vscode',
        name: 'VS Code',
        brand: 'copilot',
        note: trans('GitHub Copilot agent mode'),
        oauth: true,
        steps: [
            trans(
                'Add this to .vscode/mcp.json in your project (or run MCP: Add Server).',
            ),
            trans(
                'Choose Start above the server, sign in when VS Code asks, and approve it in ServerOS.',
            ),
        ],
        snippetLabel: '.vscode/mcp.json',
        snippet: (url, key) =>
            json({
                servers: {
                    serveros: key
                        ? {
                              type: 'http',
                              url,
                              headers: { Authorization: bearer(key) },
                          }
                        : { type: 'http', url },
                },
            }),
    },
    {
        id: 'other',
        name: 'Other apps',
        brand: 'mcp',
        note: trans('Anything that speaks MCP over HTTP'),
        oauth: false,
        steps: [
            trans('Use the Streamable HTTP transport with the server URL.'),
            trans(
                'Apps with OAuth find the sign-in on their own. Otherwise, make an API key below and send it as a Bearer token.',
            ),
        ],
        snippetLabel: trans('Request header'),
        snippet: (_url, key) => `Authorization: ${bearer(key)}`,
    },
];
