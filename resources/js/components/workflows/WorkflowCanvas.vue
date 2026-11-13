<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Maximize, Minus, Plus } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import ActionsPanel from './ActionsPanel.vue';
import {
    DEFAULT_VIEW,
    GRID_SIZE,
    MAX_ZOOM,
    MIN_ZOOM,
    NODE_WIDTH,
    clampZoom,
    estimateHeight,
    freePlacement,
    hasInput,
    inputPoint,
    nodeHeight,
    openPorts,
    outputPoint,
    placementFor,
    portFromPointer,
    snapToNodes,
    toWorld,
    zoomAround,
} from './geometry';
import type { OpenPort, Point, View } from './geometry';
import {
    EVENTS,
    FROM_TRIGGER,
    STEPS,
    environmentName,
    projectName,
    stepTheme,
} from './kinds';
import type { Options, PortId, StepKind, WorkflowNode } from './kinds';
import NodePanel from './NodePanel.vue';
import WorkflowEdges from './WorkflowEdges.vue';
import type { EdgePreview } from './WorkflowEdges.vue';
import WorkflowNodeCard from './WorkflowNode.vue';
import { useWorkflowEditor } from './useWorkflowEditor';

const props = defineProps<{
    options: Options;
    webhookUrl: string | null;
    twoFactor: boolean;
    problems: Record<string, string>;
}>();

const emit = defineEmits<{ rotateWebhook: [] }>();

const editor = useWorkflowEditor();
const { nodes, edges, sizes, view, selection, readOnly } = editor;

const container = useTemplateRef<HTMLDivElement>('container');
const panelEl = ref<HTMLElement | null>(null);

function setPanel(component: unknown): void {
    panelEl.value = (component as { $el?: HTMLElement } | null)?.$el ?? null;
}

const SNAP_RADIUS = 48;
const DRAG_THRESHOLD = 4;
const ZOOM_STEP = 1.25;
const PANEL_INSET = 332;
const INSPECTOR_INSET = 472;
const SNAP_TOLERANCE = 10;

type PaletteDrag = {
    kind: StepKind;
    clientX: number;
    clientY: number;
    grabX: number;
    grabY: number;
    snap: OpenPort | null;
    placement: Point | null;
};

type LinkDrag = {
    source: string;
    port: PortId | null;
    pointer: Point;
    target: string | null;
};

const panelOpen = ref(true);
const palette = ref<PaletteDrag | null>(null);
const link = ref<LinkDrag | null>(null);
const draggingNode = ref<string | null>(null);
const panning = ref(false);

let stopTracking: (() => void) | null = null;
let animation = 0;

function bounds(): DOMRect {
    return container.value!.getBoundingClientRect();
}

function track(
    onMove: (event: PointerEvent) => void,
    onEnd: (event: PointerEvent, cancelled: boolean) => void,
): void {
    stopTracking?.();
    const up = (event: PointerEvent) => {
        stop();
        onEnd(event, false);
    };
    const cancel = (event: PointerEvent) => {
        stop();
        onEnd(event, true);
    };
    function stop() {
        window.removeEventListener('pointermove', onMove);
        window.removeEventListener('pointerup', up);
        window.removeEventListener('pointercancel', cancel);
        stopTracking = null;
    }
    window.addEventListener('pointermove', onMove);
    window.addEventListener('pointerup', up);
    window.addEventListener('pointercancel', cancel);
    stopTracking = () => cancel(new PointerEvent('pointercancel'));
}

function animateView(update: (current: View) => View): void {
    cancelAnimationFrame(animation);
    const from = { ...view.value };
    const to = update(from);
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        view.value = to;

        return;
    }
    const start = performance.now();
    const ease = (t: number) =>
        t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    const frame = (now: number) => {
        const t = Math.min(1, (now - start) / 250);
        const p = ease(t);
        view.value = {
            x: from.x + (to.x - from.x) * p,
            y: from.y + (to.y - from.y) * p,
            zoom: from.zoom + (to.zoom - from.zoom) * p,
        };
        if (t < 1) animation = requestAnimationFrame(frame);
    };
    animation = requestAnimationFrame(frame);
}

function findSnap(world: Point, zoom: number) {
    let best: { port: OpenPort; placement: Point } | null = null;
    let bestDistance = Infinity;
    for (const port of openPorts(nodes.value, edges.value, sizes)) {
        const placement = placementFor(port.source, port.port, sizes);
        const distance =
            Math.hypot(world.x - port.point.x, world.y - port.point.y) * zoom;
        const inside =
            world.x >= placement.x &&
            world.x <= placement.x + NODE_WIDTH &&
            world.y >= port.point.y - 12 &&
            world.y <= placement.y + 121;
        if ((distance <= SNAP_RADIUS || inside) && distance < bestDistance) {
            best = {
                port,
                placement: freePlacement(placement, nodes.value, sizes),
            };
            bestDistance = distance;
        }
    }

    return best;
}

function nodeAt(world: Point, exclude: string): string | null {
    for (let i = nodes.value.length - 1; i >= 0; i--) {
        const node = nodes.value[i];
        if (node.id === exclude || !hasInput(node)) continue;
        const height = nodeHeight(node, sizes);
        if (
            world.x >= node.x &&
            world.x <= node.x + NODE_WIDTH &&
            world.y >= node.y - 16 &&
            world.y <= node.y + height
        )
            return node.id;
    }

    return null;
}

function reveal(node: WorkflowNode): void {
    const rect = bounds();
    const current = view.value;
    const height = estimateHeight(node);
    const inset = panelOpen.value && rect.width >= 900 ? PANEL_INSET : 16;
    const right =
        editor.inspector.value && rect.width >= 1100 ? INSPECTOR_INSET : 16;
    const left = rect.width / 2 + current.x + node.x * current.zoom;
    const top = current.y + node.y * current.zoom;
    const visible =
        left >= inset &&
        left + NODE_WIDTH * current.zoom <= rect.width - right &&
        top >= 16 &&
        top + height * current.zoom <= rect.height - 16;
    if (visible) return;
    const centerX = inset + (rect.width - inset - right) / 2;
    animateView((value) => ({
        ...value,
        x: centerX - rect.width / 2 - (node.x + NODE_WIDTH / 2) * value.zoom,
        y: rect.height / 2 - (node.y + height / 2) * value.zoom,
    }));
}

function create(
    kind: StepKind,
    position: Point,
    source: { source: string; port: PortId } | null,
): WorkflowNode {
    const node = editor.createNode(kind, position.x, position.y);
    const event = EVENTS.find(
        (e) =>
            e.id === nodes.value.find((n) => n.kind === 'trigger')?.rules.event,
    );
    if (
        event?.machine &&
        ['restart', 'start', 'stop', 'backup', 'command', 'redeploy'].includes(
            kind,
        )
    ) {
        node.rules.machine_id = FROM_TRIGGER;
        if (
            event.service === (kind === 'redeploy' ? 'deploy' : 'key') &&
            kind !== 'backup'
        )
            node.rules.service = FROM_TRIGGER;
    }
    editor.addNode(node, source);

    return node;
}

function addToNextSlot(kind: StepKind): void {
    if (readOnly.value) return;
    const ports = openPorts(nodes.value, edges.value, sizes);
    const pending = editor.pendingPort.value;
    const preferred =
        (pending &&
            ports.find(
                (p) =>
                    p.source.id === pending.source && p.port === pending.port,
            )) ??
        ports.find(
            (p) =>
                selection.value?.type === 'node' &&
                p.source.id === selection.value.id &&
                p.port !== 'failed',
        ) ??
        ports.find((p) => p.port !== 'failed') ??
        ports[0];
    if (preferred) {
        const node = create(
            kind,
            freePlacement(
                placementFor(preferred.source, preferred.port, sizes),
                nodes.value,
                sizes,
            ),
            { source: preferred.source.id, port: preferred.port },
        );
        editor.openInspector(node.id, 'build');
        void nextTick(() => reveal(node));

        return;
    }
    const rect = bounds();
    const center = toWorld(
        rect.left + rect.width / 2,
        rect.top + rect.height / 3,
        rect,
        view.value,
    );
    const node = create(
        kind,
        freePlacement(
            { x: center.x - NODE_WIDTH / 2, y: center.y },
            nodes.value,
            sizes,
        ),
        null,
    );
    void nextTick(() => reveal(node));
}

function resolvePalette(
    kind: StepKind,
    clientX: number,
    clientY: number,
    grabX: number,
    grabY: number,
): PaletteDrag {
    const base: PaletteDrag = {
        kind,
        clientX,
        clientY,
        grabX,
        grabY,
        snap: null,
        placement: null,
    };
    const rect = bounds();
    const panel = panelEl.value?.getBoundingClientRect();
    const overPanel =
        panel &&
        clientX >= panel.left &&
        clientX <= panel.right &&
        clientY >= panel.top &&
        clientY <= panel.bottom;
    const inside =
        clientX >= rect.left &&
        clientX <= rect.right &&
        clientY >= rect.top &&
        clientY <= rect.bottom;
    if (overPanel || !inside) return base;
    const world = toWorld(clientX, clientY, rect, view.value);
    const snap = findSnap(world, view.value.zoom);
    if (snap) return { ...base, snap: snap.port, placement: snap.placement };

    return {
        ...base,
        placement: { x: world.x - NODE_WIDTH / 2, y: world.y - 18 },
    };
}

function onItemPointerdown(kind: StepKind, event: PointerEvent): void {
    if (event.button !== 0 || readOnly.value) return;
    const item = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const grabX = event.clientX - item.left;
    const grabY = event.clientY - item.top;
    const startX = event.clientX;
    const startY = event.clientY;
    let active = false;
    track(
        (move) => {
            if (
                !active &&
                Math.hypot(move.clientX - startX, move.clientY - startY) <
                    DRAG_THRESHOLD
            )
                return;
            active = true;
            palette.value = resolvePalette(
                kind,
                move.clientX,
                move.clientY,
                grabX,
                grabY,
            );
        },
        (end, cancelled) => {
            palette.value = null;
            if (cancelled) return;
            if (!active) {
                addToNextSlot(kind);

                return;
            }
            const drop = resolvePalette(
                kind,
                end.clientX,
                end.clientY,
                grabX,
                grabY,
            );
            if (!drop.placement) return;
            const node = create(
                kind,
                drop.placement,
                drop.snap
                    ? { source: drop.snap.source.id, port: drop.snap.port }
                    : null,
            );
            editor.openInspector(node.id, 'build');
        },
    );
}

function onNodePointerdown(id: string, event: PointerEvent): void {
    if (event.button !== 0) return;
    event.stopPropagation();
    selection.value = { type: 'node', id };
    if (readOnly.value) {
        editor.openInspector(id);

        return;
    }
    const node = nodes.value.find((n) => n.id === id);
    if (!node) return;
    const zoom = view.value.zoom;
    const startX = event.clientX;
    const startY = event.clientY;
    const origin = { x: node.x, y: node.y };
    let active = false;
    track(
        (move) => {
            const dx = move.clientX - startX;
            const dy = move.clientY - startY;
            if (!active) {
                if (Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
                active = true;
                editor.checkpoint();
                draggingNode.value = id;
            }
            const moving = {
                x: origin.x + dx / zoom,
                y: origin.y + dy / zoom,
                width: NODE_WIDTH,
                height: nodeHeight(node, sizes),
            };
            const others = nodes.value
                .filter((n) => n.id !== id)
                .map((n) => ({
                    x: n.x,
                    y: n.y,
                    width: NODE_WIDTH,
                    height: nodeHeight(n, sizes),
                }));
            const snapped = snapToNodes(moving, others, SNAP_TOLERANCE / zoom);
            editor.moveNode(id, Math.round(snapped.x), Math.round(snapped.y));
        },
        () => (draggingNode.value = null),
    );
}

function startLink(
    source: string,
    port: PortId | null,
    event: PointerEvent,
): void {
    if (event.button !== 0 || readOnly.value) return;
    event.stopPropagation();
    const startX = event.clientX;
    const startY = event.clientY;
    let moved = false;
    const measure = (clientX: number, clientY: number): LinkDrag => {
        const pointer = toWorld(clientX, clientY, bounds(), view.value);

        return { source, port, pointer, target: nodeAt(pointer, source) };
    };
    track(
        (move) => {
            if (
                !moved &&
                Math.hypot(move.clientX - startX, move.clientY - startY) <
                    DRAG_THRESHOLD
            )
                return;
            moved = true;
            link.value = measure(move.clientX, move.clientY);
        },
        (end, cancelled) => {
            link.value = null;
            if (cancelled) return;
            if (!moved) {
                if (port) {
                    editor.pendingPort.value = { source, port };
                    panelOpen.value = true;
                }

                return;
            }
            const result = measure(end.clientX, end.clientY);
            if (!result.target) return;
            const sourceNode = nodes.value.find((n) => n.id === source);
            if (!sourceNode) return;
            editor.connect(
                source,
                port ?? portFromPointer(sourceNode, result.pointer.x),
                result.target,
            );
        },
    );
}

function onEdgePointerdown(id: string, event: PointerEvent): void {
    if (event.button !== 0 || readOnly.value) return;
    event.stopPropagation();
    selection.value = { type: 'edge', id };
}

function onCanvasPointerdown(event: PointerEvent): void {
    if (event.button !== 0 && event.button !== 1) return;
    cancelAnimationFrame(animation);
    const start = { ...view.value };
    const startX = event.clientX;
    const startY = event.clientY;
    let active = false;
    track(
        (move) => {
            const dx = move.clientX - startX;
            const dy = move.clientY - startY;
            if (!active) {
                if (Math.hypot(dx, dy) < DRAG_THRESHOLD) return;
                active = true;
                panning.value = true;
            }
            view.value = { ...start, x: start.x + dx, y: start.y + dy };
        },
        () => {
            panning.value = false;
            if (!active) {
                selection.value = null;
                editor.pendingPort.value = null;
            }
        },
    );
}

function onWheel(event: WheelEvent): void {
    if (
        event.target instanceof Element &&
        event.target.closest('[data-canvas-overlay]')
    )
        return;
    event.preventDefault();
    cancelAnimationFrame(animation);
    const scale = event.deltaMode === 1 ? 16 : 1;
    const rect = bounds();
    if (event.ctrlKey || event.metaKey) {
        const factor = Math.exp(-event.deltaY * scale * 0.01);
        view.value = zoomAround(
            view.value,
            view.value.zoom * factor,
            event.clientX - rect.left,
            event.clientY - rect.top,
            rect.width,
        );

        return;
    }
    view.value = {
        ...view.value,
        x: view.value.x - event.deltaX * scale,
        y: view.value.y - event.deltaY * scale,
    };
}

function onKeydown(event: KeyboardEvent): void {
    const target = event.target;
    if (
        target instanceof Element &&
        target.closest("input, textarea, select, [contenteditable='true']")
    )
        return;
    const key = event.key.toLowerCase();
    if ((event.metaKey || event.ctrlKey) && (key === 'z' || key === 'y')) {
        event.preventDefault();
        if (key === 'y' || event.shiftKey) editor.redo();
        else editor.undo();

        return;
    }
    if (
        (key === 'delete' || key === 'backspace') &&
        selection.value &&
        !readOnly.value
    ) {
        event.preventDefault();
        editor.removeSelection();

        return;
    }
    if (key === 'escape') {
        stopTracking?.();
        palette.value = null;
        link.value = null;
        editor.pendingPort.value = null;
        if (editor.inspector.value) editor.inspector.value = null;
        else selection.value = null;
    }
}

function zoomBy(factor: number): void {
    const rect = bounds();
    animateView((current) =>
        zoomAround(
            current,
            current.zoom * factor,
            rect.width / 2,
            rect.height / 2,
            rect.width,
        ),
    );
}

function fitView(options: { animate?: boolean } = {}): void {
    if (!nodes.value.length || !container.value) return;
    const rect = bounds();
    const minX = Math.min(...nodes.value.map((n) => n.x)) - 40;
    const maxX = Math.max(...nodes.value.map((n) => n.x + NODE_WIDTH)) + 40;
    const minY = Math.min(...nodes.value.map((n) => n.y)) - 24;
    const maxY =
        Math.max(...nodes.value.map((n) => n.y + nodeHeight(n, sizes))) + 104;
    const inset =
        panelOpen.value && rect.width >= 900 && !readOnly.value
            ? PANEL_INSET
            : 0;
    const zoom = clampZoom(
        Math.min(
            (rect.width - inset - 64) / (maxX - minX),
            (rect.height - 64) / (maxY - minY),
        ),
    );
    const centerX = inset + (rect.width - inset) / 2;
    const fits =
        maxX - minX <= rect.width - inset - 64 &&
        maxY - minY <= rect.height - 64;
    const target = {
        zoom,
        x: centerX - rect.width / 2 - ((minX + maxX) / 2) * zoom,
        y: fits
            ? DEFAULT_VIEW.y - minY + 24
            : rect.height / 2 - ((minY + maxY) / 2) * zoom,
    };
    if (options.animate === false) view.value = target;
    else animateView(() => target);
}

function focusNode(id: string): void {
    const node = nodes.value.find((n) => n.id === id);
    if (node) reveal(node);
}

function appendTo(sourceId: string, kind: StepKind): void {
    const source = nodes.value.find((n) => n.id === sourceId);
    if (!source) return;
    const port =
        openPorts(nodes.value, edges.value, sizes).find(
            (p) => p.source.id === sourceId && p.port !== 'failed',
        )?.port ?? STEPS[source.kind].ports[0];
    const node = create(
        kind,
        freePlacement(placementFor(source, port, sizes), nodes.value, sizes),
        { source: sourceId, port },
    );
    editor.openInspector(node.id, 'build');
    void nextTick(() => reveal(node));
}

function openNode(id: string): void {
    editor.openInspector(id);
    focusNode(id);
}

defineExpose({ fitView });

onMounted(() => {
    container.value?.addEventListener('wheel', onWheel, { passive: false });
    window.addEventListener('keydown', onKeydown);
    if (window.innerWidth < 900) panelOpen.value = false;
    requestAnimationFrame(() => fitView({ animate: false }));
});

onBeforeUnmount(() => {
    container.value?.removeEventListener('wheel', onWheel);
    window.removeEventListener('keydown', onKeydown);
    stopTracking?.();
    cancelAnimationFrame(animation);
});

watch(readOnly, () => requestAnimationFrame(() => fitView()));

const snapKey = computed(() =>
    palette.value?.snap
        ? `${palette.value.snap.source.id}:${palette.value.snap.port}`
        : null,
);
const pendingKey = computed(() =>
    editor.pendingPort.value
        ? `${editor.pendingPort.value.source}:${editor.pendingPort.value.port}`
        : null,
);
const pendingLabel = computed(() => {
    const pending = editor.pendingPort.value;
    if (!pending) return null;
    const node = nodes.value.find((n) => n.id === pending.source);

    return node
        ? `${node.title}${pending.port === 'out' ? '' : ` (${pending.port.toUpperCase()})`}`
        : null;
});

const preview = computed<EdgePreview | null>(() => {
    if (link.value) {
        const source = nodes.value.find((n) => n.id === link.value!.source);
        if (!source) return null;
        const target = link.value.target
            ? nodes.value.find((n) => n.id === link.value!.target)
            : undefined;

        return {
            from: outputPoint(source, nodeHeight(source, sizes)),
            to: target ? inputPoint(target) : link.value.pointer,
            fromColor: 'var(--wf-edge)',
            toColor: target ? 'var(--wf-edge)' : 'var(--wf-stub)',
        };
    }
    if (palette.value?.snap && palette.value.placement) {
        const source = palette.value.snap.source;

        return {
            from: outputPoint(source, nodeHeight(source, sizes)),
            to: {
                x: palette.value.placement.x + NODE_WIDTH / 2,
                y: palette.value.placement.y,
            },
            fromColor: 'var(--wf-edge)',
            toColor: 'var(--wf-edge)',
        };
    }

    return null;
});

const interacting = computed(() =>
    Boolean(palette.value || link.value || draggingNode.value || panning.value),
);

const gridStyle = computed(() => {
    const size = GRID_SIZE * view.value.zoom * (view.value.zoom < 0.5 ? 2 : 1);

    return {
        backgroundSize: `${size}px ${size}px`,
        backgroundPosition: `calc(50% + ${view.value.x}px) ${view.value.y}px`,
    };
});

function summary(node: WorkflowNode): string | null {
    const r = node.rules;
    const machine =
        r.machine_id === FROM_TRIGGER
            ? 'trigger’s machine'
            : props.options.machines.find(
                  (m) => String(m.id) === String(r.machine_id),
              )?.name;
    const service =
        r.service === FROM_TRIGGER
            ? 'trigger’s service'
            : (props.options.machines
                  .flatMap((m) => m.services)
                  .find((s) => s.key === r.service)?.name ??
              (r.service ? String(r.service) : null));
    const monitor = props.options.monitors.find(
        (m) => String(m.id) === String(r.monitor_id),
    )?.name;

    switch (node.kind) {
        case 'trigger': {
            const event = EVENTS.find((e) => e.id === r.event);
            if (r.event === 'schedule')
                return r.every
                    ? `every ${r.every}${r.every !== 'hour' ? ` at ${r.at ?? '03:00'} UTC` : ''}`
                    : null;

            return event && event.id !== 'manual'
                ? event.label.toLowerCase()
                : null;
        }
        case 'redeploy':
        case 'restart':
        case 'start':
        case 'stop':
            return machine && service ? `${service} · ${machine}` : null;
        case 'command':
            return r.command ? `$ ${r.command}` : null;
        case 'agent':
            return r.prompt
                ? `${r.agent === 'codex' ? 'Codex' : 'Claude Code'} · ${String(r.prompt).slice(0, 60)}`
                : null;
        case 'backup':
            return machine ?? null;
        case 'copy_data': {
            const into = environmentName(
                props.options,
                r.project,
                r.environment,
            );

            return into
                ? `${into} · ${projectName(props.options, r.project)}`
                : null;
        }
        case 'promote': {
            const from = environmentName(props.options, r.project, r.from);
            const to = environmentName(props.options, r.project, r.to);

            return from && to ? `${from} → ${to}` : null;
        }
        case 'check_monitor':
            return monitor
                ? trans(':monitor · up to :value min', {
                      monitor: String(monitor),
                      value: String(r.timeout ?? 5),
                  })
                : null;
        case 'http':
            return r.url
                ? `${r.method ?? 'POST'} ${String(r.url).replace(/^https?:\/\//, '')}`
                : null;
        case 'alert':
            return r.subject ? String(r.subject) : null;
        case 'wait':
            return null;
        case 'branch':
            if (r.condition === 'trigger_field')
                return r.field ? `${r.field} = ${r.value}` : null;
            if (r.condition === 'monitor_up')
                return monitor
                    ? trans(':monitor is up', { monitor: String(monitor) })
                    : null;
            if (r.condition === 'service_running')
                return service
                    ? trans(':service is running', { service: String(service) })
                    : null;
            if (r.condition === 'disk_below')
                return machine ? `${machine} disk < ${r.percent ?? 85}%` : null;
            if (r.condition === 'time_between')
                return `${r.from ?? '09:00'}–${r.to ?? '17:00'} UTC`;

            return null;
    }

    return null;
}
</script>

<template>
    <div
        ref="container"
        role="application"
        aria-roledescription="workflow canvas"
        :aria-label="$t('Workflow canvas')"
        class="wf-root wf-canvas relative min-h-0 flex-1 touch-none overflow-hidden select-none"
        @pointerdown="onCanvasPointerdown"
    >
        <div
            class="wf-grid pointer-events-none absolute inset-0"
            :style="gridStyle"
            aria-hidden="true"
        />

        <div
            class="absolute top-0 left-1/2 origin-top-left"
            :style="{
                transform: `translate(${view.x}px, ${view.y}px) scale(${view.zoom})`,
            }"
        >
            <WorkflowEdges
                :nodes="nodes"
                :edges="edges"
                :sizes="sizes"
                :selection="selection"
                :snap-key="snapKey"
                :pending-key="pendingKey"
                :preview="preview"
                :traversed="editor.traversed.value"
                :running="editor.run.value?.state === 'running'"
                :read-only="readOnly"
                @edge-pointerdown="onEdgePointerdown"
                @endpoint-pointerdown="startLink"
                @delete-edge="editor.removeSelection()"
            />

            <div
                v-if="palette?.snap && palette.placement"
                aria-hidden="true"
                class="wf-placeholder pointer-events-none absolute top-0 left-0 h-[121px] w-[400px] rounded-[12px] border border-dashed"
                :style="{
                    ...stepTheme(palette.kind),
                    translate: `${palette.placement.x}px ${palette.placement.y}px`,
                }"
            />

            <WorkflowNodeCard
                v-for="node in nodes"
                :key="node.id"
                :node="node"
                :selected="
                    selection?.type === 'node' && selection.id === node.id
                "
                :targeted="link?.target === node.id"
                :dragging="draggingNode === node.id"
                :run-state="editor.runState(node.id)"
                :problem="problems[node.id] ?? null"
                :editing="editor.inspector.value?.nodeId === node.id"
                :read-only="readOnly"
                :summary="summary(node)"
                @measure="(id, h) => (sizes[id] = h)"
                @node-pointerdown="onNodePointerdown"
                @handle-pointerdown="(id, e) => startLink(id, null, e)"
                @open="openNode"
            />
        </div>

        <ActionsPanel
            v-if="!readOnly"
            :ref="setPanel"
            :open="panelOpen"
            :dragging-kind="palette?.kind ?? null"
            :pending-label="pendingLabel"
            @toggle="panelOpen = !panelOpen"
            @item-pointerdown="onItemPointerdown"
            @add="addToNextSlot"
            @cancel-pending="editor.pendingPort.value = null"
        />

        <NodePanel
            :options="options"
            :webhook-url="webhookUrl"
            :two-factor="twoFactor"
            :problems="problems"
            @focus="focusNode"
            @append="appendTo"
            @rotate-webhook="emit('rotateWebhook')"
        />

        <div
            role="toolbar"
            :aria-label="$t('Canvas zoom')"
            data-canvas-overlay
            class="absolute right-0 bottom-0 z-20 flex flex-col gap-2 p-4"
            @pointerdown.stop
        >
            <button
                type="button"
                class="wf-button size-9! p-0!"
                :aria-label="$t('Zoom in')"
                :disabled="view.zoom >= MAX_ZOOM - 0.001"
                @click="zoomBy(ZOOM_STEP)"
            >
                <Plus class="size-4" />
            </button>
            <button
                type="button"
                class="wf-button size-9! p-0!"
                :aria-label="$t('Zoom out')"
                :disabled="view.zoom <= MIN_ZOOM + 0.001"
                @click="zoomBy(1 / ZOOM_STEP)"
            >
                <Minus class="size-4" />
            </button>
            <button
                type="button"
                class="wf-button size-9! p-0!"
                :aria-label="$t('Fit workflow to screen')"
                @click="fitView()"
            >
                <Maximize class="size-4" />
            </button>
        </div>

        <slot />

        <div
            v-if="interacting"
            aria-hidden="true"
            class="fixed inset-0 z-40"
            :class="link ? 'cursor-crosshair' : 'cursor-grabbing'"
        />

        <div
            v-if="palette"
            aria-hidden="true"
            class="pointer-events-none fixed top-0 left-0 z-50"
            :style="{
                translate: `${palette.clientX - palette.grabX}px ${palette.clientY - palette.grabY}px`,
            }"
        >
            <div
                class="wf-ghost flex h-9 w-[260px] items-center rounded-[8px] px-1 py-1.5 transition-[opacity,scale] duration-150"
                :class="{ 'scale-[0.96] opacity-60': palette.snap }"
                :style="stepTheme(palette.kind)"
            >
                <span class="flex shrink-0 items-center pl-1">
                    <component
                        :is="STEPS[palette.kind].icon"
                        class="wf-tone size-[18px]"
                        :stroke-width="2"
                    />
                </span>
                <span class="pl-2 text-[14px]/6 font-medium text-(--wf-text)">{{
                    STEPS[palette.kind].label
                }}</span>
            </div>
        </div>
    </div>
</template>
