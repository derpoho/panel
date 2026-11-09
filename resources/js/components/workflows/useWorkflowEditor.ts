import { computed, inject, provide, reactive, ref } from 'vue';
import type { InjectionKey, Ref } from 'vue';
import { DEFAULT_VIEW } from './geometry';
import type { View } from './geometry';
import { LARAVEL_STEPS, STEPS } from './kinds';
import type {
    Graph,
    PortId,
    Run,
    StepKind,
    WorkflowEdge,
    WorkflowNode,
} from './kinds';

export type Selection = { type: 'node' | 'edge'; id: string } | null;

export type InspectorTab = 'build' | 'status' | 'logs';

const HISTORY_LIMIT = 100;

function createId(): string {
    return (crypto.randomUUID?.() ?? `${Date.now()}${Math.random()}`)
        .replace(/[^a-z0-9]/gi, '')
        .slice(0, 16);
}

/**
 * The editor's state: the graph being drawn, the view, what's selected,
 * the step panel, undo history, and (when looking at one) a run whose
 * step states colour the canvas.
 */
export function createWorkflowEditor(
    initial: Graph,
    readOnly: Readonly<Ref<boolean>>,
) {
    const nodes = ref<WorkflowNode[]>(clone(initial.nodes));
    const edges = ref<WorkflowEdge[]>(clone(initial.edges));
    const sizes = reactive<Record<string, number>>({});
    const view = ref<View>({ ...DEFAULT_VIEW });
    const selection = ref<Selection>(null);
    const inspector = ref<{ nodeId: string; tab: InspectorTab } | null>(null);
    const past = ref<Graph[]>([]);
    const future = ref<Graph[]>([]);
    const run = ref<Run | null>(null);
    /** An open port someone clicked: the next step added goes there. */
    const pendingPort = ref<{ source: string; port: PortId } | null>(null);
    const savedJson = ref(JSON.stringify(serialise(initial)));

    function snapshot(): Graph {
        return { nodes: clone(nodes.value), edges: clone(edges.value) };
    }

    function checkpoint(): void {
        past.value = [...past.value, snapshot()].slice(-HISTORY_LIMIT);
        future.value = [];
    }

    function addNode(
        node: WorkflowNode,
        link?: { source: string; port: PortId } | null,
    ): void {
        if (readOnly.value) return;
        checkpoint();
        nodes.value = [...nodes.value, node];
        if (link) {
            edges.value = [
                ...edges.value.filter(
                    (e) => !(e.source === link.source && e.port === link.port),
                ),
                {
                    id: createId(),
                    source: link.source,
                    port: link.port,
                    target: node.id,
                },
            ];
        }
        selection.value = { type: 'node', id: node.id };
        pendingPort.value = null;
    }

    function createNode(kind: StepKind, x: number, y: number): WorkflowNode {
        const step = STEPS[kind];

        return {
            id: createId(),
            kind,
            x: Math.round(x),
            y: Math.round(y),
            title: step.title,
            description: step.description,
            rules: defaultRules(kind),
            fresh: true,
        };
    }

    function moveNode(id: string, x: number, y: number): void {
        const node = nodes.value.find((n) => n.id === id);
        if (node) {
            node.x = x;
            node.y = y;
        }
    }

    function updateNode(id: string, patch: Partial<WorkflowNode>): void {
        if (readOnly.value) return;
        const node = nodes.value.find((n) => n.id === id);
        if (node) Object.assign(node, patch);
    }

    function setRule(
        id: string,
        key: string,
        value: string | number | boolean | null,
    ): void {
        if (readOnly.value) return;
        const node = nodes.value.find((n) => n.id === id);
        if (node) node.rules = { ...node.rules, [key]: value };
    }

    function connect(source: string, port: PortId, target: string): void {
        if (readOnly.value || source === target) return;
        const targetNode = nodes.value.find((n) => n.id === target);
        if (!targetNode || targetNode.kind === 'trigger') return;
        if (
            edges.value.some(
                (e) =>
                    e.source === source &&
                    e.port === port &&
                    e.target === target,
            )
        )
            return;
        checkpoint();
        const edge = { id: createId(), source, port, target };
        edges.value = [...edges.value, edge];
        selection.value = { type: 'edge', id: edge.id };
    }

    function removeNode(id: string): void {
        if (readOnly.value) return;
        const node = nodes.value.find((n) => n.id === id);
        if (!node || node.kind === 'trigger') return;
        checkpoint();
        nodes.value = nodes.value.filter((n) => n.id !== id);
        edges.value = edges.value.filter(
            (e) => e.source !== id && e.target !== id,
        );
        if (selection.value?.id === id) selection.value = null;
        if (inspector.value?.nodeId === id) inspector.value = null;
    }

    function removeSelection(): void {
        const current = selection.value;
        if (!current || readOnly.value) return;
        if (current.type === 'node') {
            removeNode(current.id);

            return;
        }
        checkpoint();
        edges.value = edges.value.filter((e) => e.id !== current.id);
        selection.value = null;
    }

    function undo(): void {
        const previous = past.value.at(-1);
        if (!previous || readOnly.value) return;
        future.value = [snapshot(), ...future.value];
        past.value = past.value.slice(0, -1);
        nodes.value = previous.nodes;
        edges.value = previous.edges;
        selection.value = null;
    }

    function redo(): void {
        const next = future.value[0];
        if (!next || readOnly.value) return;
        past.value = [...past.value, snapshot()];
        future.value = future.value.slice(1);
        nodes.value = next.nodes;
        edges.value = next.edges;
        selection.value = null;
    }

    function openInspector(nodeId: string, tab?: InspectorTab): void {
        inspector.value = {
            nodeId,
            tab:
                tab ??
                inspector.value?.tab ??
                (readOnly.value ? 'status' : 'build'),
        };
        selection.value = { type: 'node', id: nodeId };
    }

    function replace(graph: Graph): void {
        nodes.value = clone(graph.nodes);
        edges.value = clone(graph.edges);
        selection.value = null;
        inspector.value = null;
        past.value = [];
        future.value = [];
    }

    function markSaved(): void {
        savedJson.value = JSON.stringify(serialise(snapshot()));
    }

    const dirty = computed(
        () => JSON.stringify(serialise(snapshot())) !== savedJson.value,
    );

    /** A step's state in the run being shown. */
    function runState(id: string): string {
        const step = run.value?.steps[id];
        if (!run.value) return 'idle';
        if (!step) return 'skipped';

        return step.state;
    }

    const traversed = computed(() =>
        Object.values(run.value?.steps ?? {}).flatMap((s) => s.edges ?? []),
    );

    return {
        nodes,
        edges,
        sizes,
        view,
        selection,
        inspector,
        past,
        future,
        run,
        pendingPort,
        readOnly,
        dirty,
        traversed,
        checkpoint,
        addNode,
        createNode,
        moveNode,
        updateNode,
        setRule,
        connect,
        removeNode,
        removeSelection,
        undo,
        redo,
        openInspector,
        replace,
        markSaved,
        runState,
        snapshot,
        serialise: () => serialise(snapshot()),
    };
}

export type WorkflowEditor = ReturnType<typeof createWorkflowEditor>;

const KEY: InjectionKey<WorkflowEditor> = Symbol('workflow-editor');

export function provideWorkflowEditor(editor: WorkflowEditor): void {
    provide(KEY, editor);
}

export function useWorkflowEditor(): WorkflowEditor {
    const editor = inject(KEY);
    if (!editor) throw new Error('No workflow editor provided.');

    return editor;
}

function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

/** The graph as the server stores it: no editor-only flags. */
function serialise(graph: Graph): Graph {
    return {
        nodes: graph.nodes.map(({ fresh: _fresh, ...node }) => ({
            ...node,
            rules: { ...node.rules },
        })),
        edges: graph.edges.map((e) => ({ ...e })),
    };
}

/** Settings a new step starts with. */
export function defaultRules(kind: StepKind): WorkflowNode['rules'] {
    switch (kind) {
        case 'trigger':
            return { event: 'manual' };
        case 'release':
            return {
                path: '',
                user: 'www-data',
                repo: '',
                branch: 'main',
                steps: JSON.stringify(LARAVEL_STEPS),
                mode: 'atomic',
                shared: '.env\nstorage',
                trim: 'node_modules',
                keep: 3,
            };
        case 'agent':
            return {
                agent: 'claude',
                prompt: '',
                repo: '',
                branch: 'main',
                pull_request: true,
                minutes: 20,
            };
        case 'check_monitor':
            return { timeout: 5 };
        case 'http':
            return { method: 'POST', url: '' };
        case 'alert':
            return {
                subject: '{{workflow}}: {{trigger}}',
                message: '',
                tone: 'bad',
            };
        case 'wait':
            return { minutes: 2 };
        case 'branch':
            return { condition: 'trigger_field', field: '', value: '' };
        default:
            return {};
    }
}
