<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { computed } from 'vue';
import {
    actionPoint,
    inputPoint,
    labelPoint,
    nodeHeight,
    openPorts,
    outputPoint,
    roundedPath,
    routePoints,
} from './geometry';
import type { Point } from './geometry';
import { PORT_LABELS } from './kinds';
import type { PortId, WorkflowEdge, WorkflowNode } from './kinds';
import type { Selection } from './useWorkflowEditor';

export type EdgePreview = {
    from: Point;
    to: Point;
    fromColor: string;
    toColor: string;
};

const props = defineProps<{
    nodes: WorkflowNode[];
    edges: WorkflowEdge[];
    sizes: Record<string, number>;
    selection: Selection;
    snapKey: string | null;
    pendingKey: string | null;
    preview: EdgePreview | null;
    traversed: string[];
    running: boolean;
    readOnly: boolean;
}>();

const emit = defineEmits<{
    edgePointerdown: [id: string, event: PointerEvent];
    endpointPointerdown: [source: string, port: PortId, event: PointerEvent];
    deleteEdge: [];
}>();

function portKey(source: string, port: PortId): string {
    return `${source}:${port}`;
}

const byId = computed(() => new Map(props.nodes.map((n) => [n.id, n])));

const routed = computed(() =>
    props.edges.flatMap((edge) => {
        const source = byId.value.get(edge.source);
        const target = byId.value.get(edge.target);
        if (!source || !target) return [];
        const from = outputPoint(source, nodeHeight(source, props.sizes));
        const to = inputPoint(target);
        const points = routePoints(from, to);

        return [
            {
                edge,
                source,
                target,
                from,
                to,
                points,
                path: roundedPath(points),
            },
        ];
    }),
);

const labels = computed(() => {
    const out: { id: string; point: Point; port: PortId }[] = [];
    for (const { edge, points } of routed.value) {
        if (!PORT_LABELS[edge.port]) continue;
        const preferred = labelPoint(points);
        const taken = out.some(
            (l) =>
                Math.abs(l.point.x - preferred.x) < 64 &&
                Math.abs(l.point.y - preferred.y) < 24,
        );
        out.push({
            id: edge.id,
            port: edge.port,
            point: taken ? actionPoint(points) : preferred,
        });
    }

    return out;
});

const open = computed(() =>
    props.readOnly ? [] : openPorts(props.nodes, props.edges, props.sizes),
);

const stubs = computed(() =>
    open.value.map((port) => {
        const from = outputPoint(
            port.source,
            nodeHeight(port.source, props.sizes),
        );
        const points = routePoints(from, port.point);

        return {
            ...port,
            key: portKey(port.source.id, port.port),
            from,
            path: roundedPath(points),
            label: labelPoint(points),
        };
    }),
);

const selectedEdge = computed(() =>
    props.selection?.type === 'edge'
        ? routed.value.find((r) => r.edge.id === props.selection!.id)
        : undefined,
);

function gradient(from: Point, to: Point) {
    const flat = Math.abs(from.x - to.x) < 0.5 && Math.abs(from.y - to.y) < 0.5;

    return {
        x1: from.x,
        y1: from.y,
        x2: flat ? from.x : to.x,
        y2: flat ? from.y + 1 : to.y,
    };
}

function at(point: Point) {
    return { left: `${point.x}px`, top: `${point.y}px` };
}
</script>

<template>
    <svg
        aria-hidden="true"
        width="1"
        height="1"
        class="pointer-events-none absolute top-0 left-0 overflow-visible"
    >
        <g v-for="r in routed" :key="r.edge.id" class="group/edge">
            <defs>
                <linearGradient
                    :id="`wf-edge-${r.edge.id}`"
                    gradientUnits="userSpaceOnUse"
                    v-bind="gradient(r.from, r.to)"
                >
                    <stop stop-color="var(--wf-edge)" />
                    <stop offset="1" stop-color="var(--wf-edge)" />
                </linearGradient>
            </defs>
            <path
                :d="r.path"
                fill="none"
                :stroke="`url(#wf-edge-${r.edge.id})`"
                stroke-width="2"
                stroke-linecap="round"
                class="transition-opacity duration-150"
                :class="
                    selection?.type === 'edge' && selection.id === r.edge.id
                        ? 'opacity-100'
                        : traversed.includes(r.edge.id)
                          ? 'opacity-100'
                          : traversed.length
                            ? 'opacity-15'
                            : 'opacity-40 group-hover/edge:opacity-70'
                "
            />
            <path
                v-if="running && traversed.includes(r.edge.id)"
                :d="r.path"
                fill="none"
                stroke="var(--wf-text)"
                stroke-opacity="0.7"
                stroke-width="2"
                stroke-linecap="round"
                stroke-dasharray="4 8"
                class="wf-dash motion-reduce:hidden"
            />
            <path
                :d="r.path"
                fill="none"
                stroke="transparent"
                stroke-width="16"
                class="[pointer-events:stroke] cursor-pointer"
                @pointerdown="emit('edgePointerdown', r.edge.id, $event)"
            />
        </g>
        <template v-for="stub in stubs" :key="stub.key">
            <template v-if="snapKey !== stub.key">
                <defs>
                    <linearGradient
                        :id="`wf-stub-${stub.key.replace(':', '-')}`"
                        gradientUnits="userSpaceOnUse"
                        v-bind="gradient(stub.from, stub.point)"
                    >
                        <stop stop-color="var(--wf-edge)" />
                        <stop offset="1" stop-color="var(--wf-stub)" />
                    </linearGradient>
                </defs>
                <path
                    :d="stub.path"
                    fill="none"
                    :stroke="`url(#wf-stub-${stub.key.replace(':', '-')})`"
                    stroke-width="2"
                    stroke-linecap="round"
                    class="opacity-40"
                />
            </template>
        </template>
        <template v-if="preview">
            <defs>
                <linearGradient
                    id="wf-edge-preview"
                    gradientUnits="userSpaceOnUse"
                    v-bind="gradient(preview.from, preview.to)"
                >
                    <stop :stop-color="preview.fromColor" />
                    <stop offset="1" :stop-color="preview.toColor" />
                </linearGradient>
            </defs>
            <path
                :d="roundedPath(routePoints(preview.from, preview.to))"
                fill="none"
                stroke="url(#wf-edge-preview)"
                stroke-width="2"
                stroke-linecap="round"
                class="opacity-70"
            />
        </template>
    </svg>

    <span
        v-for="label in labels"
        :key="label.id"
        class="wf-port-pill"
        :data-port="label.port"
        :style="at(label.point)"
    >
        {{ PORT_LABELS[label.port] }}
    </span>

    <template v-for="stub in stubs" :key="`end-${stub.key}`">
        <span
            v-if="PORT_LABELS[stub.port]"
            class="wf-port-pill"
            :data-port="stub.port"
            :style="at(stub.label)"
        >
            {{ PORT_LABELS[stub.port] }}
        </span>
        <span
            role="button"
            :aria-label="
                stub.port === 'out'
                    ? $t('Add a step after :title', {
                          title: stub.source.title,
                      })
                    : $t('Add a step on :port after :title', {
                          port: PORT_LABELS[stub.port] ?? '',
                          title: stub.source.title,
                      })
            "
            :data-snapped="
                snapKey === stub.key || pendingKey === stub.key || undefined
            "
            class="wf-endpoint"
            :style="at(stub.point)"
            :title="$t('Click to add a step here, or drag to connect')"
            @pointerdown="
                emit('endpointPointerdown', stub.source.id, stub.port, $event)
            "
        >
            <Plus class="size-2.5" :stroke-width="3" aria-hidden="true" />
        </span>
    </template>

    <div
        v-if="selectedEdge && !readOnly"
        class="absolute -translate-x-1/2 -translate-y-1/2"
        :style="at(actionPoint(selectedEdge.points))"
        @pointerdown.stop
    >
        <button
            type="button"
            class="ds-raised-button h-7! cursor-pointer px-2.5! text-xs!"
            @click="emit('deleteEdge')"
        >
            {{ ' ' + $t('Delete') + ' ' }}
        </button>
    </div>
</template>
