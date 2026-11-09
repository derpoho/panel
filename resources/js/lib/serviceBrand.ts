/**
 * Brand marks for discovered services, shared by the panel's ServiceLogo.vue
 * and the marketing site's ServiceLogo.tsx. Matches on the service's name and
 * its container image, never the service key: every container's key starts
 * with "docker:", which would make them all look like Docker. Specific apps
 * come before the generic runtimes (Docker, containerd, Node, PHP, Python).
 */
import {
    siAdminer,
    siApache,
    siCaddy,
    siClickhouse,
    siContainerd,
    siCounterstrike,
    siDirectus,
    siDocker,
    siElasticsearch,
    siGhost,
    siGitea,
    siGrafana,
    siInfluxdb,
    siLaravel,
    siMariadb,
    siMeilisearch,
    siMetabase,
    siMinio,
    siMongodb,
    siMysql,
    siN8n,
    siNatsdotio,
    siNginx,
    siNodedotjs,
    siOllama,
    siPhp,
    siPostgresql,
    siPrometheus,
    siPterodactyl,
    siPython,
    siRabbitmq,
    siRedis,
    siSyncthing,
    siTeamspeak,
    siTraefikproxy,
    siUmami,
    siUptimekuma,
    siVaultwarden,
    siWordpress,
} from 'simple-icons';
import type { SimpleIcon } from 'simple-icons';

const matches: [RegExp, SimpleIcon][] = [
    [/clickhouse/, siClickhouse],
    [/\bcs2\b|counter-?strike/, siCounterstrike],
    [/\bredis\b/, siRedis],
    [/postgres(?:ql)?|\bpg\b|timescale/, siPostgresql],
    [/\bmaria(?:db)?\b/, siMariadb],
    [/\bmysql/, siMysql],
    [/\bmongo(?:db|d)?\b/, siMongodb],
    [/meilisearch|getmeili/, siMeilisearch],
    [/elasticsearch/, siElasticsearch],
    [/influxdb/, siInfluxdb],
    [/rabbitmq/, siRabbitmq],
    [/\bnats\b/, siNatsdotio],
    [/\bminio\b/, siMinio],
    [/grafana/, siGrafana],
    [/prometheus/, siPrometheus],
    [/pterodactyl/, siPterodactyl],
    [/\bgitea\b/, siGitea],
    [/\bn8n\b/, siN8n],
    [/\bollama\b/, siOllama],
    [/\bumami\b/, siUmami],
    [/syncthing/, siSyncthing],
    [/teamspeak|ts3server/, siTeamspeak],
    [/\bghost\b/, siGhost],
    [/wordpress/, siWordpress],
    [/directus/, siDirectus],
    [/metabase/, siMetabase],
    [/uptime-?kuma/, siUptimekuma],
    [/vaultwarden|bitwarden/, siVaultwarden],
    [/adminer/, siAdminer],
    [/traefik/, siTraefikproxy],
    [/\bnginx\b/, siNginx],
    [/\bcaddy\b/, siCaddy],
    [/\bapache(?:2)?\b|\bhttpd\b/, siApache],
    [/\blaravel\b/, siLaravel],
    [/\bphp(?:-fpm|\d)?\b|serversideup/, siPhp],
    [/\bnode(?:\.js|js)?\b/, siNodedotjs],
    [/\bpython(?:3|\b)|gunicorn|uvicorn/, siPython],
    [/\bcontainerd\b/, siContainerd],
    [/\bdocker\b/, siDocker],
];

export function serviceBrand(
    name: string,
    image?: string | null,
): SimpleIcon | null {
    const label = `${name} ${image ?? ''}`.toLowerCase();

    return matches.find(([pattern]) => pattern.test(label))?.[1] ?? null;
}

/** Near-black brand colours vanish in dark mode; undefined means use the text colour. */
export function serviceBrandColour(
    brand: SimpleIcon | null,
): string | undefined {
    const hex = brand?.hex ?? '000000';
    const [r, g, b] = [0, 2, 4].map(
        (i) => parseInt(hex.slice(i, i + 2), 16) / 255,
    );
    const luminance = 0.2126 * r + 0.7152 * g + 0.0722 * b;

    return luminance < 0.12 ? undefined : `#${hex}`;
}
