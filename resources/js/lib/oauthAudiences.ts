/**
 * How the MCP consent page (pages/auth/OAuthAuthorize.vue) shows an OAuth
 * audience a package adds, one that isn't a workspace's MCP server
 * (App\Support\Mcp\OAuthAudience). The package puts its copy in
 * resources/js/oauth/{key}.ts, and its consent props include `{key}: true`,
 * so the page knows it's that one. The strings are in English and go
 * through $t like the page's own. A build without the packages finds none.
 */
export type OAuthAudienceCopy = {
    /** What the app wants to use, like "the ticket board". */
    name: string;
    /** After the name in the Where box, like "· the team’s tickets, as you". */
    where: string;
    /** The warning above Approve: what the app does as them, and where to disconnect it. */
    notice: string;
    /** The scope it always gets. */
    read: string;
    /** The scope that lets it change things, when it asks. */
    write: string;
};

const fromPackages = import.meta.glob<{ default: OAuthAudienceCopy }>(
    '../../../vendor/serveroshq/*/resources/js/oauth/*.ts',
    { eager: true },
);

const AUDIENCE_FILE = /\/resources\/js\/oauth\/([^/]+)\.ts$/;

/** The copy of the package audience these consent props are for, or null (a workspace). */
export function oauthAudienceFor(
    props: Record<string, unknown>,
): OAuthAudienceCopy | null {
    for (const [path, module] of Object.entries(fromPackages)) {
        const key = AUDIENCE_FILE.exec(path)?.[1];

        if (key && props[key] === true) {
            return module.default;
        }
    }

    return null;
}
