<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Check } from '@lucide/vue';
import { SolarCopy as Copy, SolarRotateCw as RotateCw } from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import { toast } from '@/lib/toast';
import AppButton from '@/components/workspace/AppButton.vue';

/**
 * A container's `docker inspect`, read from the machine when asked:
 * networks, ports, mounts, labels and environment variable names laid out,
 * and the whole JSON underneath. The daemon never sends environment values.
 */
const props = defineProps<{
    job: {
        state: string;
        result: Record<string, unknown> | null;
        error: { message?: string } | null;
    } | null;
    /** The daemon can do it, the machine is online and they may. */
    canLoad: boolean;
    /** Why not, when the daemon is too old. */
    unavailable: string | null;
    loading: boolean;
}>();

const emit = defineEmits<{ load: [] }>();

type Json = Record<string, unknown>;

const running = computed(
    () =>
        props.loading ||
        ['queued', 'accepted', 'running'].includes(props.job?.state ?? ''),
);
const inspect = computed<Json | null>(() => {
    const value = props.job?.result?.inspect;

    return value && typeof value === 'object' ? (value as Json) : null;
});
const failed = computed(() =>
    props.job && ['failed', 'stale', 'cancelled'].includes(props.job.state)
        ? (props.job.error?.message ?? trans('Couldn’t read it.'))
        : null,
);

function at(path: string[]): unknown {
    let value: unknown = inspect.value;
    for (const key of path) {
        value =
            value && typeof value === 'object'
                ? (value as Json)[key]
                : undefined;
    }

    return value;
}

const networks = computed(() =>
    Object.entries((at(['NetworkSettings', 'Networks']) as Json) ?? {}).map(
        ([name, raw]) => {
            const n = (raw ?? {}) as Json;
            const aliases = Array.isArray(n.Aliases)
                ? (n.Aliases as string[])
                : [];

            return {
                name,
                ip: (n.IPAddress as string) || '',
                gateway: (n.Gateway as string) || '',
                aliases,
            };
        },
    ),
);

const ports = computed(() =>
    Object.entries((at(['NetworkSettings', 'Ports']) as Json) ?? {}).map(
        ([port, raw]) => ({
            port,
            published: Array.isArray(raw)
                ? (raw as Json[]).map((b) =>
                      [b.HostIp, b.HostPort].filter(Boolean).join(':'),
                  )
                : [],
        }),
    ),
);

const mounts = computed(() =>
    ((at(['Mounts']) as Json[]) ?? []).map((m) => ({
        type: String(m.Type ?? ''),
        source: String(m.Name ?? m.Source ?? ''),
        destination: String(m.Destination ?? ''),
        readOnly: m.RW === false,
    })),
);

const labels = computed(() =>
    Object.entries((at(['Config', 'Labels']) as Json) ?? {})
        .map(([key, value]) => ({ key, value: String(value ?? '') }))
        .sort((a, b) => a.key.localeCompare(b.key)),
);

const envNames = computed(() =>
    ((at(['Config', 'Env']) as string[]) ?? []).map(
        (entry) => entry.split('=')[0],
    ),
);

const json = computed(() =>
    inspect.value ? JSON.stringify(inspect.value, null, 2) : '',
);

const copied = ref(false);
async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(json.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        toast.error(trans('Could not copy. Select the JSON and copy it.'));
    }
}

const heading = 'text-[13px]/5 font-medium text-(--ds-text)';
const row =
    'grid gap-x-4 gap-y-0.5 border-b border-(--ds-divider) py-2 last:border-b-0 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)]';
</script>

<template>
    <section class="ds-tile flex flex-col font-ui text-[13px]/5">
        <header
            class="flex flex-wrap items-center justify-between gap-3 border-b border-(--ds-divider) px-5 py-4"
        >
            <div class="flex flex-col gap-0.5">
                <h3 class="text-sm/5 font-semibold">
                    {{ $t('Container config') }}
                </h3>
                <p class="text-(--ds-text-muted)">
                    {{
                        $t(
                            'What docker inspect shows, read from the machine. Environment variable values never leave it.',
                        )
                    }}
                </p>
            </div>
            <div class="flex items-center gap-2">
                <AppButton
                    v-if="inspect"
                    size="sm"
                    :aria-label="$t('Copy JSON')"
                    @click="copy"
                >
                    <Check v-if="copied" class="size-3.5" />
                    <Copy v-else class="size-3.5" />
                    {{ $t('Copy JSON') }}
                </AppButton>
                <AppButton
                    v-if="canLoad"
                    size="sm"
                    :disabled="running"
                    @click="emit('load')"
                >
                    <RotateCw
                        class="size-3.5"
                        :class="running ? 'animate-spin' : ''"
                    />
                    {{ inspect ? $t('Refresh') : $t('Load') }}
                </AppButton>
            </div>
        </header>

        <p v-if="unavailable" class="px-5 py-4 text-(--ds-text-muted)">
            {{ unavailable }}
        </p>
        <p
            v-else-if="running && !inspect"
            class="px-5 py-4 text-(--ds-text-muted)"
        >
            {{ $t('Reading it from the machine…') }}
        </p>
        <p v-else-if="failed && !inspect" class="px-5 py-4 text-(--ds-red)">
            {{ failed }}
        </p>
        <p v-else-if="!inspect" class="px-5 py-4 text-(--ds-text-muted)">
            {{
                canLoad
                    ? $t('Load it to see networks, ports, mounts and labels.')
                    : $t('The machine needs to be online to read it.')
            }}
        </p>

        <div v-else class="flex flex-col gap-5 px-5 py-4">
            <div v-if="networks.length" class="flex flex-col">
                <h4 :class="heading">{{ $t('Networks') }}</h4>
                <div v-for="n in networks" :key="n.name" :class="row">
                    <span class="font-ui-mono break-all">{{ n.name }}</span>
                    <span class="text-(--ds-text-secondary)">
                        <span class="font-ui-mono">{{ n.ip || '–' }}</span>
                        <template v-if="n.aliases.length">
                            {{
                                ' · ' +
                                $t('aliases :names', {
                                    names: n.aliases.join(', '),
                                })
                            }}
                        </template>
                    </span>
                </div>
            </div>

            <div v-if="ports.length" class="flex flex-col">
                <h4 :class="heading">{{ $t('Ports') }}</h4>
                <div v-for="p in ports" :key="p.port" :class="row">
                    <span class="font-ui-mono">{{ p.port }}</span>
                    <span
                        class="font-ui-mono break-all text-(--ds-text-secondary)"
                        >{{
                            p.published.length
                                ? p.published.join(', ')
                                : $t('Not published')
                        }}</span
                    >
                </div>
            </div>

            <div v-if="mounts.length" class="flex flex-col">
                <h4 :class="heading">{{ $t('Mounts') }}</h4>
                <div v-for="m in mounts" :key="m.destination" :class="row">
                    <span class="font-ui-mono break-all">{{
                        m.destination
                    }}</span>
                    <span
                        class="font-ui-mono break-all text-(--ds-text-secondary)"
                    >
                        {{ m.source }}
                        <span class="font-ui text-(--ds-text-muted)">{{
                            ' · ' +
                            m.type +
                            (m.readOnly ? ' · ' + $t('read-only') : '')
                        }}</span>
                    </span>
                </div>
            </div>

            <div v-if="labels.length" class="flex flex-col">
                <h4 :class="heading">{{ $t('Labels') }}</h4>
                <div v-for="l in labels" :key="l.key" :class="row">
                    <span class="font-ui-mono break-all">{{ l.key }}</span>
                    <span
                        class="font-ui-mono break-all text-(--ds-text-secondary)"
                        >{{ l.value }}</span
                    >
                </div>
            </div>

            <div v-if="envNames.length" class="flex flex-col gap-1.5">
                <h4 :class="heading">
                    {{ $t('Environment') }}
                    <span class="font-normal text-(--ds-text-muted)">{{
                        ' · ' + $t('names only')
                    }}</span>
                </h4>
                <p class="font-ui-mono break-all text-(--ds-text-secondary)">
                    {{ envNames.join('  ') }}
                </p>
            </div>

            <details class="group">
                <summary
                    class="cursor-pointer text-(--ds-text-secondary) select-none hover:text-(--ds-text)"
                >
                    {{ $t('Full JSON') }}
                </summary>
                <pre
                    class="mt-2 max-h-[32rem] overflow-auto rounded-lg border border-(--ds-border) bg-(--ds-row-hover) p-3 font-ui-mono text-xs/5"
                    >{{ json }}</pre>
            </details>
        </div>
    </section>
</template>
