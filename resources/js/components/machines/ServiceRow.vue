<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import { SolarRotateCcw as RotateCcw } from '@/lib/solarIcons';
import CpuBar from '@/components/machines/CpuBar.vue';
import {
    bytes,
    memLimit,
    serviceColumns,
    serviceColumnsWithMachine,
    status,
    uptime,
} from '@/components/machines/serviceFormat';
import type { Service, ServiceStat } from '@/components/machines/serviceFormat';
import ServiceLogo from '@/components/machines/ServiceLogo.vue';

/** One service in the services table; opens the service's page. */
defineProps<{
    /** Shows a checkbox to pick it for Hide and Unhide. */
    selectable?: boolean;
    selected?: boolean;
    service: Service;
    stat: ServiceStat | null;
    machineId: number;
    /** Inside an open group: indented under it. */
    nested?: boolean;
    /** On the workspace list: shows a Machine column. */
    machineName?: string;
}>();

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <Link
        :href="`/machines/${machineId}/services/${service.app ? `app:${service.app}` : service.key}`"
        class="w-full px-4 py-2.5 text-left text-[13px]/4 transition-colors hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
        :class="
            machineName === undefined
                ? serviceColumns
                : serviceColumnsWithMachine
        "
        :aria-selected="selectable ? selected : undefined"
        role="row"
    >
        <span
            class="flex min-w-0 items-center gap-3"
            :class="{ 'pl-5.5': nested, 'opacity-60': service.hidden }"
            role="cell"
        >
            <button
                v-if="selectable"
                type="button"
                role="checkbox"
                :aria-checked="selected"
                :aria-label="
                    $t('Select :value', {
                        value: String(
                            service.label ??
                                service.app ??
                                service.found ??
                                service.name,
                        ),
                    })
                "
                class="flex size-4 shrink-0 cursor-pointer items-center justify-center rounded-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                :class="
                    selected
                        ? 'bg-(--ds-inverse)'
                        : 'border-[1.5px] border-(--ds-border-hover) hover:border-(--ds-border-focus)'
                "
                @click.prevent.stop="emit('toggle')"
            >
                <svg
                    v-if="selected"
                    width="10"
                    height="10"
                    viewBox="0 0 10 10"
                    aria-hidden="true"
                >
                    <path
                        d="M2 5.2L4.2 7.2L8 3"
                        fill="none"
                        class="stroke-(--ds-inverse-text)"
                        stroke-width="1.6"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </button>
            <ServiceLogo
                :name="service.name"
                :kind="service.kind"
                :service-key="service.key"
                :image="service.details?.image"
                :icon-url="service.icon"
            />
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="truncate font-medium">
                    {{
                        service.label ??
                        service.app ??
                        service.found ??
                        service.name
                    }}
                </span>
                <span class="truncate text-xs text-(--ds-text-muted)">
                    <template v-if="service.label"
                        >{{ service.app ?? service.name }} · </template
                    >{{ service.manager }} · {{ service.kind
                    }}<template v-if="service.confidence < 60">
                        {{ ' ' + $t('· tentative') }}</template
                    ><template v-if="service.hidden">
                        {{ ' ' + $t('· hidden') }}</template
                    >
                </span>
            </span>
        </span>

        <span
            v-if="machineName !== undefined"
            class="hidden truncate text-(--ds-text-secondary) md:block"
            role="cell"
        >
            {{ machineName }}
        </span>

        <span class="hidden items-center gap-1.75 md:flex" role="cell">
            <span
                class="size-1.5 shrink-0 rounded-full"
                :class="status(service).dot"
            />
            {{ status(service).label }}
        </span>

        <span class="hidden min-w-0 items-center gap-2 md:flex" role="cell">
            <CpuBar
                v-if="stat?.cpu !== undefined && stat?.cpu !== null"
                :cpu="Math.round(stat.cpu)"
                themed
                class="min-w-0 grow"
            />
            <span v-else class="text-(--ds-text-faint)">—</span>
        </span>

        <span class="hidden flex-col md:flex" role="cell">
            <span class="tabular-nums">{{ bytes(stat?.memUsed) }}</span>
            <span
                v-if="memLimit(stat)"
                class="text-xs text-(--ds-text-faint) tabular-nums"
            >
                {{ ' ' + $t('of :value', { value: bytes(memLimit(stat)) }) }}
            </span>
        </span>

        <span
            class="hidden items-center gap-1.5 tabular-nums md:flex"
            role="cell"
        >
            {{ uptime(stat?.startedAt) }}
            <span
                v-if="(stat?.restarts ?? 0) > 0"
                class="inline-flex items-center gap-0.5 rounded-[4px] bg-(--ds-amber)/12 px-1 text-[11px]/4 text-(--ds-amber)"
                :title="
                    $t('Restarted :restarts times', {
                        restarts: String(stat?.restarts),
                    })
                "
            >
                <RotateCcw class="size-2.5" :stroke-width="2.2" />
                {{ stat?.restarts }}
            </span>
        </span>

        <span
            class="hidden truncate font-ui-mono text-xs text-(--ds-text-secondary) md:block"
            role="cell"
        >
            {{
                service.ports.length
                    ? service.ports.map((p) => `:${p}`).join(' ')
                    : '—'
            }}
        </span>

        <ChevronRight
            class="size-4 justify-self-end text-(--ds-text-muted)"
            role="cell"
        />
    </Link>
</template>
