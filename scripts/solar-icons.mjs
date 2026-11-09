// Writes resources/js/lib/solarIcons.ts: the Solar "Bold Duotone" icons the
// dashboard uses, copied out of @iconify-json/solar so the whole set never
// lands in the bundle. Add a name to ICONS and run `node scripts/solar-icons.mjs`.
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';

const ICONS = {
    Home: 'home-2',
    Machines: 'server-square',
    Services: 'layers',
    Workflows: 'routing-2',
    Backups: 'archive',
    Monitors: 'pulse-2',
    Logs: 'document-text',
    Members: 'users-group-rounded',
    Activity: 'history',
    Partners: 'gift',
    Billing: 'card',
    Settings: 'settings',
    AskAi: 'chat-round-dots',
    Support: 'chat-round-line',
    WhatsNew: 'star-shine',
    // Across the dashboard, named after the Lucide icons they replace. Small
    // action glyphs (chevrons, arrows, check, plus, close, copy, edit,
    // delete, spinners) stay Lucide outlines.
    KeyRound: 'key',
    TriangleAlert: 'danger-triangle',
    ShieldCheck: 'shield-check',
    Eye: 'eye',
    EyeOff: 'eye-closed',
    Bell: 'bell',
    Mail: 'letter',
    Lock: 'lock-keyhole-minimalistic',
    LayoutGrid: 'widget-2',
    ImagePlus: 'gallery-add',
    Folder: 'folder',
    Download: 'download-minimalistic',
    BookOpen: 'book-2',
    Wrench: 'tuning-2',
    Workflow: 'routing-2',
    UserRound: 'user-rounded',
    Palette: 'palette',
    UserPlus: 'user-plus-rounded',
    Sun: 'sun',
    SquarePlus: 'add-square',
    ShieldAlert: 'shield-warning',
    Server: 'server-square',
    ScanLine: 'scanner',
    Radio: 'station',
    Power: 'power',
    Moon: 'moon',
    Monitor: 'monitor',
    LogOut: 'logout-2',
    LockKeyhole: 'lock-keyhole',
    Link2: 'link',
    History: 'history',
    GitBranch: 'branching-paths-up',
    FolderGit2: 'folder-path-connect',
    FileText: 'document-text',
    Code: 'code-square',
    Cloud: 'cloud',
    Building2: 'buildings-2',
    Box: 'box',
    AlertCircle: 'danger-circle',
    Pulse: 'pulse-2', // Lucide's Activity
    Archive: 'archive',
    Users: 'users-group-rounded',
    Rocket: 'rocket-2',
    Layers: 'layers',
    ScrollText: 'document-text',
    Sparkles: 'magic-stick-3',
    HeartPulse: 'heart-pulse',
    Terminal: 'programming',
    CircleCheck: 'check-circle',
    CreditCard: 'card',
    Bug: 'bug',
    DatabaseBackup: 'database',
    UserRoundCog: 'user-id',
    Clock: 'clock-circle',
    Globe: 'global',
    FolderPlus: 'add-folder',
    ChartNoAxesColumn: 'chart-2',
    Hammer: 'sledgehammer',
    Plug: 'plug-circle',
    CircleAlert: 'danger-circle',
    House: 'home-2',
    User: 'user',
    Pin: 'pin',
    MessageSquare: 'chat-square',
    Bookmark: 'bookmark',
    Database: 'database',
    Gamepad2: 'gamepad',
    Music: 'music-note',
    NotebookPen: 'notebook',
    Table: 'widget-4',
    MailCheck: 'letter-opened',
    OctagonX: 'forbidden-circle',
    Siren: 'siren',
    Sunrise: 'sunrise',
    Zap: 'bolt',
    FolderOpen: 'folder-open',
    File: 'file',
    FileSymlink: 'file-send',
    Upload: 'upload-minimalistic',
    ScanSearch: 'radar',
    BriefcaseBusiness: 'case-round',
    Handshake: 'hand-shake',
    LayoutDashboard: 'widget-5',
    Megaphone: 'speaker',
    PenLine: 'pen-new-square',
    MessageSquareText: 'chat-square-2',
    BellOff: 'bell-off',
    Cpu: 'cpu',
    Import: 'import',
    Key: 'key',
    MemoryStick: 'ssd-square',
    TextSearch: 'magnifer',
    Inbox: 'inbox',
    Gift: 'gift',
    // Landing-only.
    Code2: 'code-square',
    Briefcase: 'case-round',
    Webhook: 'link-round-angle',
    CircleArrowUp: 'round-arrow-up',
    BadgePercent: 'sale-square',
    BellRing: 'bell-bing',
    ServerCog: 'server-path',
    Truck: 'delivery',
    Rss: 'feed',
    BadgeCheck: 'verified-check',
    Paperclip: 'paperclip',
    // The rest of the dashboard's Lucide icons, by their Lucide names.
    Copy: 'copy',
    Search: 'magnifer',
    Trash2: 'trash-bin-trash',
    Pencil: 'pen',
    RotateCw: 'restart',
    RefreshCw: 'refresh',
    Play: 'play',
    RotateCcw: 'undo-left-round',
    SquareTerminal: 'programming',
    Square: 'stop',
    HardDrive: 'ssd-round',
    CircleX: 'close-circle',
    ThumbsUp: 'like',
    ThumbsDown: 'dislike',
    Pause: 'pause',
    CircleSlash: 'forbidden-circle',
    CopyPlus: 'copy',
    FileX: 'file-remove',
    Package: 'box',
    Eraser: 'eraser',
    Tag: 'tag',
    SquarePen: 'pen-new-square',
    MessagesSquare: 'chat-round-dots',
    Info: 'info-circle',
    CircleHelp: 'question-circle',
    Languages: 'translation',
    SunMoon: 'sun-2',
    // The staff assistant's send and minimise.
    Send: 'plain',
    Minimize: 'minimize-square',
};

// The docs side nav (React), by page slug; anything else gets DOCS_DEFAULT.
const DOCS = {
    introduction: 'home-smile',
    quickstart: 'rocket-2',
    beta: 'test-tube',
    machines: 'server-square',
    services: 'layers',
    'one-click-apps': 'widget-add',
    'deploy-your-code': 'code-square',
    'environment-variables': 'key',
    'domains-and-https': 'global',
    'updating-an-app': 'refresh-circle',
    databases: 'database',
    'fixing-a-failed-deploy': 'bug',
    monitors: 'pulse-2',
    backups: 'archive',
    logs: 'document-text',
    workflows: 'routing-2',
    activity: 'history',
    'members-and-roles': 'users-group-rounded',
    'two-factor': 'shield-keyhole',
    'ai-apps': 'magic-stick-3',
    'ai-apps-setup': 'plug-circle',
    'ai-tool-calls': 'chart-2',
    'installing-the-daemon': 'download-minimalistic',
    security: 'shield-check',
    disconnecting: 'link-broken',
    glossary: 'book-2',
    'daemon-commands': 'programming',
    'plans-and-limits': 'card',
    'mcp-tools': 'tuning-2',
    // The How-tos tab's groups, by their first page.
    'install-docker-on-ubuntu': 'box',
    'add-user-to-sudoers': 'key',
    'check-disk-space-on-linux': 'ssd-round',
    'open-a-port-with-ufw': 'global',
    'change-hostname-on-ubuntu': 'server-path',
    'install-nodejs-on-ubuntu': 'download-minimalistic',
    'create-a-postgresql-user': 'database',
    'fix-502-bad-gateway-nginx': 'danger-triangle',
};
const DOCS_DEFAULT = 'document-text';

const set = JSON.parse(
    readFileSync(
        new URL(
            '../node_modules/@iconify-json/solar/icons.json',
            import.meta.url,
        ),
    ),
);
const lines = [
    '// Generated by scripts/solar-icons.mjs from @iconify-json/solar (MIT). Do not edit.',
    "import { defineComponent, h } from 'vue';",
    '',
    '/**',
    ' * 24px by default, like Lucide; a size class wins. strokeWidth is taken and',
    ' * ignored, so these drop in where Lucide icons were.',
    ' */',
    // Exported, and every icon marked pure: the build gives each icon its own
    // module (vite.config.ts), so a page only downloads the icons it draws.
    'export function icon(name: string, body: string) {',
    '    return defineComponent({',
    '        name,',
    '        props: {',
    '            size: { type: [Number, String], default: 24 },',
    '            strokeWidth: { type: [Number, String], default: undefined },',
    '        },',
    '        setup(props) {',
    '            return () =>',
    "                h('svg', {",
    "                    xmlns: 'http://www.w3.org/2000/svg',",
    "                    viewBox: '0 0 24 24',",
    '                    width: props.size,',
    '                    height: props.size,',
    "                    'aria-hidden': 'true',",
    '                    innerHTML: body,',
    '                });',
    '        },',
    '    });',
    '}',
    '',
];
for (const [name, id] of Object.entries(ICONS)) {
    const key = `${id}-bold-duotone`;
    // Some names are aliases of another icon.
    const entry = set.icons[key] ?? set.icons[set.aliases?.[key]?.parent];
    if (!entry) throw new Error(`No Solar icon ${key}`);
    lines.push(
        `export const Solar${name} = /* @__PURE__ */ icon('Solar${name}', ${JSON.stringify(entry.body)});`,
    );
}
writeFileSync(
    new URL('../resources/js/lib/solarIcons.ts', import.meta.url),
    lines.join('\n') + '\n',
);
console.log(`Wrote ${Object.keys(ICONS).length} icons`);

const body = (id) => {
    const key = `${id}-bold-duotone`;
    const entry = set.icons[key] ?? set.icons[set.aliases?.[key]?.parent];
    if (!entry) throw new Error(`No Solar icon ${key}`);
    return entry.body;
};
const docs = [
    '// Generated by scripts/solar-icons.mjs from @iconify-json/solar (MIT). Do not edit.',
    '',
    '/** Solar bold duotone bodies for the docs side nav, by page slug. */',
    'const bodies: Record<string, string> = {',
    ...Object.entries(DOCS).map(
        ([slug, id]) =>
            `    ${JSON.stringify(slug)}: ${JSON.stringify(body(id))},`,
    ),
    '};',
    `const fallback = ${JSON.stringify(body(DOCS_DEFAULT))};`,
    '',
    "/** A docs page's icon, from the last part of its address. */",
    'export function DocIcon({ href, className }: { href: string; className?: string }) {',
    '    // Search results can point at a heading (#...) on the page.',
    "    const slug = href.split(/[?#]/)[0].split('/').filter(Boolean).pop() ?? '';",
    '',
    '    return (',
    '        <svg',
    '            xmlns="http://www.w3.org/2000/svg"',
    '            viewBox="0 0 24 24"',
    '            className={className}',
    '            aria-hidden="true"',
    '            dangerouslySetInnerHTML={{ __html: bodies[slug] ?? fallback }}',
    '        />',
    '    );',
    '}',
];
// The docs and React icons are for a private package's website (serveros.com's
// resources/js/marketing), written into it when it's installed.
const vendor = new URL('../vendor/serveroshq/', import.meta.url);
const site = existsSync(vendor)
    ? readdirSync(vendor)
          .map((name) => new URL(`${name}/resources/js/marketing/lib/`, vendor))
          .find((dir) => existsSync(dir))
    : undefined;
if (site) {
    writeFileSync(new URL('docIcons.tsx', site), docs.join('\n') + '\n');
    console.log(`Wrote ${Object.keys(DOCS).length} docs icons`);
} else {
    console.log(
        'No website package installed: skipped the docs and React icons',
    );
}

// The same icons for the React site (landing pages), named like the Vue ones.
const react = [
    '// Generated by scripts/solar-icons.mjs from @iconify-json/solar (MIT). Do not edit.',
    "import type { ComponentType, SVGProps } from 'react';",
    '',
    "type IconProps = Omit<SVGProps<SVGSVGElement>, 'dangerouslySetInnerHTML'> & {",
    '    size?: number | string;',
    '    /** Taken and ignored, so these drop in where Lucide icons were. */',
    '    strokeWidth?: number | string;',
    '};',
    '',
    '/** Any icon here or from lucide-react: both take className, size and strokeWidth. */',
    'export type IconComponent = ComponentType<IconProps>;',
    '',
    // Exported, and every icon marked pure: the build gives each icon its own
    // module (vite.config.ts), so a page only downloads the icons it draws.
    'export function icon(name: string, body: string) {',
    '    // eslint-disable-next-line @typescript-eslint/no-unused-vars',
    '    const Icon = ({ size = 24, strokeWidth, ...props }: IconProps) => (',
    '        <svg',
    '            xmlns="http://www.w3.org/2000/svg"',
    '            viewBox="0 0 24 24"',
    '            width={size}',
    '            height={size}',
    '            aria-hidden="true"',
    '            {...props}',
    '            dangerouslySetInnerHTML={{ __html: body }}',
    '        />',
    '    );',
    '    Icon.displayName = name;',
    '',
    '    return Icon;',
    '}',
    '',
    ...Object.entries(ICONS).map(
        ([name, id]) =>
            `export const Solar${name} = /* @__PURE__ */ icon('Solar${name}', ${JSON.stringify(body(id))});`,
    ),
];
if (site) {
    writeFileSync(new URL('solarIcons.tsx', site), react.join('\n') + '\n');
    console.log(`Wrote ${Object.keys(ICONS).length} React icons`);
}
