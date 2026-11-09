<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import type { RequestPayload } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    AppWindow,
    ArrowUpFromLine,
    ArrowUpRight,
    Check,
    ChevronDown,
    DatabaseBackup,
    Database,
    Ellipsis,
    GitPullRequest,
    FolderClosed,
    Pencil,
    Plus,
    RadioTower,
    RefreshCw,
    Server,
    SlidersHorizontal,
    Trash2,
    X,
    Zap,
} from '@lucide/vue';
import type { Component } from 'vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import AppVariablesDialog from '@/components/machines/AppVariablesDialog.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import WorkspacePage from '@/components/workspace/WorkspacePage.vue';
import { appButtonClasses } from '@/lib/appButton';
import { docsUrl } from '@/lib/docs';
import { toast } from '@/lib/toast';
import type { Workspace, WorkspaceAccess } from '@/types';

type Kind = 'app' | 'database' | 'cache' | 'storage' | 'websockets';
type ProjectNode = {
    id: number;
    environment: number;
    origin: number | null;
    data: {
        state: 'waiting' | 'copying' | 'copied' | 'failed';
        error: string | null;
        at: string | null;
    } | null;
    name: string;
    kind: Kind;
    template: string | null;
    machine: { id: number; name: string };
    status: 'running' | 'stopped' | null;
    /** Nothing of it on the machine because its last deploy failed. */
    failed: boolean;
    /** Its service page, while it has containers on the machine. */
    href: string | null;
    port: number | null;
};
type ProjectLink = {
    id: number;
    app: number;
    resource: number;
    state: 'pending' | 'applied' | 'failed';
    error: string | null;
    env: string[];
};
type Member = {
    machine: number;
    address: string;
    state: 'pending' | 'up' | 'failed' | 'offline';
    error: string | null;
};
type Environment = {
    id: number;
    name: string;
    source: number | null;
    pullRequest: number | null;
    head: string | null;
    refresh: 'daily' | 'weekly' | null;
    refreshedAt: string | null;
};
type PromotionRow = {
    app: string;
    machine: string;
    from: string | null;
    to: string | null;
    repo: boolean;
    missing: string[];
    ready: boolean;
    reason: string | null;
};
type Candidate = {
    name: string;
    kind: Kind;
    template: string | null;
    machine: { id: number; name: string };
};

const props = defineProps<{
    workspace: Workspace;
    project: { uid: string; name: string; cidr: string };
    environments: Environment[];
    nodes: ProjectNode[];
    links: ProjectLink[];
    members: Member[];
    candidates?: Candidate[];
    machines: { id: number; name: string }[];
    resourceKinds: { kind: Exclude<Kind, 'app'>; template: string }[];
    copyable: string[];
    notes: string[];
    previews: {
        enabled: boolean;
        repo: string;
        machine: number | null;
        copyData: boolean;
        domain: string | null;
        limit: number;
        max: number;
        github: boolean;
    };
    repos?: string[];
    promotion?: PromotionRow[];
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const base = computed(() => `/projects/${props.project.uid}`);

/* What each kind is called and drawn with */
const kinds: Record<Kind, { label: string; icon: Component }> = {
    app: { label: 'App', icon: AppWindow },
    database: { label: 'Database', icon: Database },
    cache: { label: 'Cache', icon: Zap },
    storage: { label: 'Object storage', icon: FolderClosed },
    websockets: { label: 'WebSockets', icon: RadioTower },
};
const templateNames: Record<string, string> = {
    postgres: 'PostgreSQL',
    mysql: 'MySQL',
    mariadb: 'MariaDB',
    mongodb: 'MongoDB',
    redis: 'Redis',
    valkey: 'Valkey',
    minio: 'MinIO',
    soketi: 'Soketi',
};

/* Which environment the canvas shows: ?env= in the address, else the first */
const envId = ref<number>(
    (() => {
        const asked = Number(
            new URLSearchParams(window.location.search).get('env'),
        );

        return props.environments.some((e) => e.id === asked)
            ? asked
            : (props.environments[0]?.id ?? 0);
    })(),
);
const env = computed(
    () =>
        props.environments.find((e) => e.id === envId.value) ??
        props.environments[0],
);
const sourceEnv = computed(() =>
    env.value?.source
        ? (props.environments.find((e) => e.id === env.value?.source) ?? null)
        : null,
);
const isFirst = computed(() => env.value?.id === props.environments[0]?.id);
const envNodes = computed(() =>
    props.nodes.filter((n) => n.environment === env.value?.id),
);
const envLinks = computed(() => {
    const ids = new Set(envNodes.value.map((n) => n.id));

    return props.links.filter((l) => ids.has(l.app));
});

function showEnv(id: number): void {
    envId.value = id;
    const url = new URL(window.location.href);
    if (id === props.environments[0]?.id) url.searchParams.delete('env');
    else url.searchParams.set('env', String(id));
    window.history.replaceState(window.history.state, '', url);
}

/* The canvas: one column per machine, apps first */
const groups = computed(() => {
    const byMachine = new Map<
        number,
        { id: number; name: string; nodes: ProjectNode[] }
    >();
    for (const node of envNodes.value) {
        const group = byMachine.get(node.machine.id) ?? {
            id: node.machine.id,
            name: node.machine.name,
            nodes: [],
        };
        group.nodes.push(node);
        byMachine.set(node.machine.id, group);
    }

    return [...byMachine.values()]
        .map((group) => ({
            ...group,
            nodes: [...group.nodes].sort(
                (a, b) =>
                    Number(b.kind === 'app') - Number(a.kind === 'app') ||
                    a.name.localeCompare(b.name),
            ),
            member: props.members.find((m) => m.machine === group.id) ?? null,
        }))
        .sort(
            (a, b) =>
                Number(b.nodes.some((n) => n.kind === 'app')) -
                    Number(a.nodes.some((n) => n.kind === 'app')) ||
                a.name.localeCompare(b.name),
        );
});

const nodeById = computed(
    () => new Map(props.nodes.map((node) => [node.id, node])),
);
const resources = computed(() =>
    envNodes.value.filter((n) => n.kind !== 'app'),
);

function linksOf(node: ProjectNode): ProjectLink[] {
    return envLinks.value.filter((l) =>
        node.kind === 'app' ? l.app === node.id : l.resource === node.id,
    );
}

function unlinked(app: ProjectNode): ProjectNode[] {
    const used = new Set(
        envLinks.value.filter((l) => l.app === app.id).map((l) => l.resource),
    );

    return resources.value.filter((r) => !used.has(r.id));
}

const memberTone: Record<Member['state'], string> = {
    pending: 'bg-(--ds-blue)',
    up: 'bg-(--ds-green)',
    failed: 'bg-(--ds-red)',
    offline: 'bg-(--ds-text-faint)',
};
const memberLabel: Record<Member['state'], string> = {
    pending: 'Joining',
    up: 'On the network',
    failed: 'Couldn’t join',
    offline: 'Offline',
};
const linkLabel: Record<ProjectLink['state'], string> = {
    pending: 'Waiting for the network',
    applied: 'Connected',
    failed: 'Couldn’t connect',
};

let poll: number | undefined;

/* Keep the picture current while machines join or links wait */
const settling = computed(
    () =>
        props.members.some((m) => m.state === 'pending') ||
        props.links.some((l) => l.state === 'pending') ||
        props.nodes.some(
            (n) => n.data?.state === 'waiting' || n.data?.state === 'copying',
        ),
);

/* Apps on the left, what they use on the right, each side under its
   machines' headings */
function column(kind: 'apps' | 'resources') {
    return groups.value
        .map((group) => ({
            ...group,
            nodes: group.nodes.filter((n) =>
                kind === 'apps' ? n.kind === 'app' : n.kind !== 'app',
            ),
        }))
        .filter((group) => group.nodes.length);
}
const appColumn = computed(() => column('apps'));
const resourceColumn = computed(() => column('resources'));
const apps = computed(() => envNodes.value.filter((n) => n.kind === 'app'));
const sides = computed(() => [
    { key: 'apps' as const, groups: appColumn.value },
    { key: 'resources' as const, groups: resourceColumn.value },
]);
const slot =
    'flex h-[54px] w-[256px] cursor-pointer items-center justify-center gap-1.5 rounded-[10px] border border-dashed border-(color:--ds-border-strong) text-[13px] text-(--ds-text-muted) transition-colors hover:border-(color:--ds-text-faint) hover:bg-(--ds-card) hover:text-(--ds-text) disabled:pointer-events-none disabled:opacity-50';

/* Connectors, routed only through the gutter between the columns: each
   app has its own lane there, so lines never cross a card or each other */
const board = ref<HTMLElement | null>(null);
const gutter = ref<HTMLElement | null>(null);
function setGutter(el: unknown): void {
    gutter.value = el instanceof HTMLElement ? el : null;
}
const cards = new Map<number, HTMLElement>();
const edges = ref<{ id: number; d: string; state: ProjectLink['state'] }[]>([]);
const boardSize = ref({ width: 0, height: 0 });

function cardRef(id: number) {
    return (el: unknown) => {
        if (el instanceof HTMLElement) cards.set(id, el);
        else cards.delete(id);
    };
}

/** A smooth S from one card's right edge to another's left edge. */
function curve(x1: number, y1: number, x2: number, y2: number): string {
    const mid = (x1 + x2) / 2;

    return `M ${x1} ${y1} C ${mid} ${y1} ${mid} ${y2} ${x2} ${y2}`;
}

function measure(): void {
    const root = board.value;
    if (!root || !gutter.value) return;
    const box = root.getBoundingClientRect();
    boardSize.value = { width: box.width, height: box.height };

    // Waiting and failed ones first, so a connected line sharing their
    // way out of an app is drawn over them.
    const ordered = [...envLinks.value].sort(
        (a, b) => Number(a.state === 'applied') - Number(b.state === 'applied'),
    );
    edges.value = ordered.flatMap((link) => {
        const from = cards.get(link.app)?.getBoundingClientRect();
        const to = cards.get(link.resource)?.getBoundingClientRect();
        if (!from || !to) return [];
        const y1 = from.top - box.top + from.height / 2;
        const y2 = to.top - box.top + to.height / 2;

        return [
            {
                id: link.id,
                d: curve(from.right - box.left, y1, to.left - box.left, y2),
                state: link.state,
            },
        ];
    });
}

let observer: ResizeObserver | null = null;

watch(
    () => [envNodes.value, envLinks.value],
    () => nextTick(measure),
    { deep: true },
);

onMounted(() => {
    nextTick(measure);
    observer = new ResizeObserver(() => measure());
    if (board.value) observer.observe(board.value);
    // The board is made again after an empty environment: watch the new one.
    watch(board, (el, old) => {
        if (old) observer?.unobserve(old);
        if (el) observer?.observe(el);
        nextTick(measure);
    });
    poll = window.setInterval(() => {
        if (settling.value && document.visibilityState === 'visible') {
            router.reload({
                only: ['nodes', 'links', 'members', 'environments'],
            });
        }
    }, 8000);
});

// What the last change left out or couldn't do, said once.
watch(
    () => props.notes,
    (notes) => {
        if (notes.length) toast.info(notes.join(' '));
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    observer?.disconnect();
    window.clearInterval(poll);
});

/* Changing what's in the project */
const busy = ref(false);

function send(
    method: 'post' | 'patch' | 'delete',
    url: string,
    data: RequestPayload = {},
    done?: () => void,
): void {
    busy.value = true;
    router.visit(url, {
        method,
        data,
        preserveScroll: true,
        onSuccess: () => done?.(),
        onError: (errors) =>
            toast.error(Object.values(errors)[0] ?? trans('That didn’t work.')),
        onFinish: () => (busy.value = false),
    });
}

const adding = ref(false);
const addFilter = ref<'app' | 'resource' | null>(null);
const shownCandidates = computed(() =>
    (props.candidates ?? []).filter((c) =>
        addFilter.value === null
            ? true
            : addFilter.value === 'app'
              ? c.kind === 'app'
              : c.kind !== 'app',
    ),
);

function openAdd(filter: 'app' | 'resource' | null): void {
    addFilter.value = filter;
    adding.value = true;
    router.reload({ only: ['candidates'] });
}

function addExisting(candidate: Candidate): void {
    send(
        'post',
        `${base.value}/nodes`,
        {
            machine: candidate.machine.id,
            name: candidate.name,
            environment: envId.value,
        },
        () => (adding.value = false),
    );
}

function remove(node: ProjectNode): void {
    send('delete', `${base.value}/nodes/${node.id}`);
}

/*
 * Delete: off the canvas and off the machine, every version of it, and its
 * data too if asked. One whose deploys all failed has nothing there.
 */
const deletingNode = ref<ProjectNode | null>(null);
const deletingOpen = computed({
    get: () => deletingNode.value !== null,
    set: (open: boolean) => {
        if (!open) deletingNode.value = null;
    },
});
const deleteConfirm = ref('');
const deleteData = ref(false);

function openDelete(node: ProjectNode): void {
    deletingNode.value = node;
    deleteConfirm.value = '';
    deleteData.value = false;
}

function confirmDelete(): void {
    const node = deletingNode.value;
    if (!node) return;
    send(
        'delete',
        `${base.value}/nodes/${node.id}`,
        {
            delete: true,
            delete_data: deleteData.value,
            confirm: deleteConfirm.value,
        },
        () => {
            deletingNode.value = null;
            if (node.href) {
                toast.success(
                    trans('Deleting :name from :machine.', {
                        name: node.name,
                        machine: node.machine.name,
                    }),
                );
            }
        },
    );
}

function connect(app: ProjectNode, resource: ProjectNode): void {
    send('post', `${base.value}/links`, { app: app.id, resource: resource.id });
}

function disconnect(link: ProjectLink): void {
    send('delete', `${base.value}/links/${link.id}`);
}

/* A new resource from the toolbar, on a machine you pick */
const placing = ref<Exclude<Kind, 'app'> | null>(null);
const placeOn = ref<number | null>(null);

function addResource(kind: Exclude<Kind, 'app'>): void {
    if (props.machines.length === 1) {
        send('post', `${base.value}/resources`, {
            kind,
            machine: props.machines[0].id,
            environment: envId.value,
        });

        return;
    }
    placeOn.value = groups.value[0]?.id ?? props.machines[0]?.id ?? null;
    placing.value = kind;
}

function confirmPlace(): void {
    if (!placing.value || placeOn.value === null) return;
    send(
        'post',
        `${base.value}/resources`,
        {
            kind: placing.value,
            machine: placeOn.value,
            environment: envId.value,
        },
        () => (placing.value = null),
    );
}

/* Renaming and deleting the project itself */
const renaming = ref(false);
const newName = ref(props.project.name);
const deleting = ref(false);

function rename(): void {
    send('patch', base.value, { name: newName.value }, () => {
        renaming.value = false;
    });
}

function destroy(): void {
    send('delete', base.value);
}

const machineCount = computed(() => groups.value.length);

/* A new environment: a copy of one, on the machines you pick */
const making = ref(false);
const envName = ref('');
const envSource = ref<number>(0);
const envMachines = ref<Record<number, number>>({});
const envCopyData = ref(true);
const sourceMachines = computed(() => {
    const seen = new Map<number, string>();
    for (const n of props.nodes) {
        if (n.environment === envSource.value) {
            seen.set(n.machine.id, n.machine.name);
        }
    }

    return [...seen].map(([id, name]) => ({ id, name }));
});
const sourceHasData = computed(() =>
    props.nodes.some(
        (n) =>
            n.environment === envSource.value &&
            n.template !== null &&
            props.copyable.includes(n.template),
    ),
);

function openMake(): void {
    envName.value = props.environments.some((e) => e.name === 'Staging')
        ? ''
        : 'Staging';
    envSource.value = props.environments[0]?.id ?? 0;
    envCopyData.value = true;
    making.value = true;
}

// Reset the machine choices when the dialog opens or the source changes,
// never because a poll brought the same nodes back.
watch(
    () => [making.value, envSource.value] as const,
    ([open]) => {
        if (!open) return;
        envMachines.value = Object.fromEntries(
            sourceMachines.value.map((m) => [m.id, m.id]),
        );
    },
    { immediate: true },
);

function makeEnv(): void {
    if (!envName.value.trim()) return;
    send(
        'post',
        `${base.value}/environments`,
        {
            name: envName.value.trim(),
            source: envSource.value,
            machines: envMachines.value,
            copy_data: envCopyData.value && sourceHasData.value,
        },
        () => {
            making.value = false;
            const made = props.environments.find(
                (e) => e.name === envName.value.trim(),
            );
            if (made) showEnv(made.id);
        },
    );
}

/* An environment's data, schedule, name and removal */
function refreshData(): void {
    if (!env.value) return;
    send('post', `${base.value}/environments/${env.value.id}/refresh`, {}, () =>
        toast.success(trans('Copying its data again.')),
    );
}

function setRefresh(refresh: 'daily' | 'weekly' | null): void {
    if (!env.value) return;
    send('patch', `${base.value}/environments/${env.value.id}`, { refresh });
}

const renamingEnv = ref(false);
const newEnvName = ref('');

function renameEnv(): void {
    if (!env.value) return;
    send(
        'patch',
        `${base.value}/environments/${env.value.id}`,
        { name: newEnvName.value },
        () => (renamingEnv.value = false),
    );
}

const deletingEnv = ref(false);
const removeServices = ref(true);

function deleteEnv(): void {
    if (!env.value) return;
    send(
        'delete',
        `${base.value}/environments/${env.value.id}`,
        { remove_services: removeServices.value },
        () => {
            deletingEnv.value = false;
            showEnv(props.environments[0]?.id ?? 0);
        },
    );
}

/* Promoting: this environment's code into the one it was copied from */
const promoting = ref(false);
// The rows for this opening only: an earlier answer is never shown.
const promotionFor = ref<string | null>(null);
const promotionRows = computed(() =>
    promotionFor.value === `${env.value?.id}>${sourceEnv.value?.id}`
        ? (props.promotion ?? [])
        : undefined,
);
const promotionReady = computed(() =>
    (promotionRows.value ?? []).some((row) => row.ready),
);

/** Asked-for props come back on the address; take them off again. */
function cleanAddress(): void {
    const url = new URL(window.location.href);
    for (const key of ['from', 'to', 'app']) url.searchParams.delete(key);
    window.history.replaceState(window.history.state, '', url);
}

function openPromote(): void {
    if (!env.value || !sourceEnv.value) return;
    const key = `${env.value.id}>${sourceEnv.value.id}`;
    promotionFor.value = null;
    promoting.value = true;
    router.reload({
        only: ['promotion'],
        data: { from: env.value.id, to: sourceEnv.value.id },
        onSuccess: () => (promotionFor.value = key),
        onFinish: cleanAddress,
    });
}

function promote(): void {
    if (!env.value || !sourceEnv.value) return;
    send(
        'post',
        `${base.value}/promote`,
        { from: env.value.id, to: sourceEnv.value.id },
        () => {
            promoting.value = false;
            toast.success(trans('Deploying the new code.'));
        },
    );
}

/* An app's settings: names only; values go in, never come back */
const editing = ref<ProjectNode | null>(null);
const editingOpen = computed({
    get: () => editing.value !== null,
    set: (open: boolean) => {
        if (!open) editing.value = null;
    },
});

function openSettings(node: ProjectNode): void {
    editing.value = node;
}

/* Pull request previews: an environment per pull request */
const previewing = ref(false);
const previewForm = ref({
    enabled: false,
    repo: '',
    machine: null as number | null,
    copy_data: false,
    domain: '',
    limit: 3,
});

function openPreviews(): void {
    previewForm.value = {
        enabled: props.previews.enabled || !props.previews.repo,
        repo: props.previews.repo,
        machine: props.previews.machine ?? props.machines[0]?.id ?? null,
        copy_data: props.previews.copyData,
        domain: props.previews.domain ?? '',
        limit: props.previews.limit,
    };
    previewing.value = true;
    router.reload({
        only: ['repos'],
        onSuccess: () => {
            if (!previewForm.value.repo && props.repos?.length) {
                previewForm.value.repo = props.repos[0];
            }
        },
    });
}

function savePreviews(): void {
    const f = previewForm.value;
    send(
        'patch',
        `${base.value}/previews`,
        {
            enabled: f.enabled,
            repo: f.repo.trim(),
            machine: f.machine,
            copy_data: f.copy_data,
            domain: f.domain.trim(),
            limit: f.limit,
        },
        () => {
            previewing.value = false;
            toast.success(
                f.enabled
                    ? trans('Pull requests on :repo get a preview now.', {
                          repo: f.repo.trim(),
                      })
                    : trans('Previews are off.'),
            );
        },
    );
}

const pullRequestUrl = computed(() =>
    env.value?.pullRequest && props.previews.repo
        ? `https://github.com/${props.previews.repo}/pull/${env.value.pullRequest}`
        : null,
);

/* "2 hours ago" for when a copy last ran */
function ago(iso: string | null): string {
    if (!iso) return '';
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 1) return trans('just now');
    if (minutes < 60)
        return trans(':count min ago', { count: String(minutes) });
    const hours = Math.round(minutes / 60);
    if (hours < 24) return trans(':count h ago', { count: String(hours) });

    return trans(':count d ago', { count: String(Math.round(hours / 24)) });
}
</script>

<template>
    <Head :title="project.name" />

    <WorkspacePage :title="project.name" toolbar>
        <template #description>
            {{
                ' ' +
                (machineCount === 0
                    ? $t(
                          'Add an app, then what it uses. They’ll share a private network: :cidr.',
                          { cidr: project.cidr },
                      )
                    : $t('Private network :cidr across :count.', {
                          cidr: project.cidr,
                          count:
                              machineCount === 1
                                  ? $t('1 machine')
                                  : $t(':count machines', {
                                        count: String(machineCount),
                                    }),
                      })) +
                ' '
            }}
            <a
                :href="docsUrl($page.props.docsUrl, '/guides/projects')"
                target="_blank"
                rel="noopener"
                class="font-medium text-(--ds-blue) hover:underline hover:underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                >{{ ' ' + $t('Learn more') + ' ' }}</a
            >
        </template>

        <template #tabs>
            <div class="flex max-w-full items-center gap-2 overflow-x-auto">
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Environments')"
                >
                    <button
                        v-for="e in environments"
                        :key="e.id"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :aria-pressed="e.id === env?.id"
                        @click="showEnv(e.id)"
                    >
                        <GitPullRequest
                            v-if="e.pullRequest"
                            class="size-3.5 text-(--ds-text-muted)"
                            :stroke-width="1.75"
                        />
                        {{ e.name }}
                    </button>
                </div>
                <button
                    v-if="canManage"
                    type="button"
                    :class="appButtonClasses('quiet', 'sm')"
                    @click="openMake"
                >
                    <Plus class="size-3.5" />
                    {{ $t('New environment') }}
                </button>
            </div>
        </template>

        <template v-if="canManage" #actions>
            <template v-if="sourceEnv">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            :disabled="busy"
                        >
                            <DatabaseBackup
                                class="size-3.5"
                                :stroke-width="1.75"
                            />
                            {{ $t('Data') }}
                            <ChevronDown class="-mr-0.5 size-3.5 opacity-60" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-64 font-ui"
                        align="end"
                    >
                        <DropdownMenuLabel
                            class="text-xs font-normal text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                env?.refreshedAt
                                    ? $t(
                                          'Its databases were last copied from :source :when.',
                                          {
                                              source: sourceEnv.name,
                                              when: ago(env.refreshedAt),
                                          },
                                      )
                                    : $t(
                                          'Its databases hold their own data. Copy :source’s in to test against real data.',
                                          { source: sourceEnv.name },
                                      )
                            }}
                        </DropdownMenuLabel>
                        <DropdownMenuItem @select="refreshData">
                            <RefreshCw class="size-4" :stroke-width="1.75" />
                            {{
                                $t('Copy data from :source now', {
                                    source: sourceEnv.name,
                                })
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel
                            class="text-xs font-normal text-(--ds-text-muted)"
                            >{{
                                $t('Copy it again automatically')
                            }}</DropdownMenuLabel
                        >
                        <DropdownMenuItem
                            v-for="option in [
                                { value: null, label: 'Never' },
                                { value: 'daily', label: 'Every night' },
                                { value: 'weekly', label: 'Every week' },
                            ] as const"
                            :key="option.label"
                            role="menuitemradio"
                            :aria-checked="
                                (env?.refresh ?? null) === option.value
                            "
                            @select="setRefresh(option.value)"
                        >
                            <Check
                                class="size-4"
                                :class="
                                    (env?.refresh ?? null) === option.value
                                        ? 'opacity-100'
                                        : 'opacity-0'
                                "
                            />
                            {{ $t(option.label) }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <a
                    v-if="pullRequestUrl"
                    :href="pullRequestUrl"
                    target="_blank"
                    rel="noopener"
                    :class="appButtonClasses('secondary')"
                >
                    <GitPullRequest class="size-3.5" :stroke-width="1.75" />
                    {{
                        $t('Pull request #:n', { n: String(env?.pullRequest) })
                    }}
                    <span
                        v-if="env?.head"
                        class="font-ui-mono text-xs text-(--ds-text-muted)"
                        >{{ env.head }}</span
                    >
                </a>
                <button
                    v-else
                    type="button"
                    :class="appButtonClasses('secondary')"
                    :disabled="busy"
                    @click="openPromote"
                >
                    <ArrowUpFromLine class="size-3.5" :stroke-width="1.75" />
                    {{ $t('Promote to :name', { name: sourceEnv.name }) }}
                </button>
            </template>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        :class="appButtonClasses('primary')"
                        :disabled="busy"
                    >
                        <Plus class="size-3.5" />
                        {{ $t('Add') }}
                        <ChevronDown class="-mr-0.5 size-3.5 opacity-70" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-60 font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="openAdd('app')">
                        <AppWindow class="size-4" :stroke-width="1.75" />
                        {{ $t('An app you’ve deployed') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="openAdd('resource')">
                        <Server class="size-4" :stroke-width="1.75" />
                        {{ $t('A service you’ve deployed') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel
                        class="text-xs font-normal text-(--ds-text-muted)"
                        >{{
                            $t('Deploy a new one and connect it')
                        }}</DropdownMenuLabel
                    >
                    <DropdownMenuItem
                        v-for="option in resourceKinds"
                        :key="option.kind"
                        :disabled="!machines.length"
                        @select="addResource(option.kind)"
                    >
                        <component
                            :is="kinds[option.kind].icon"
                            class="size-4"
                            :stroke-width="1.75"
                        />
                        {{ $t(kinds[option.kind].label) }}
                        <span class="ml-auto text-xs text-(--ds-text-muted)">{{
                            templateNames[option.template]
                        }}</span>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        :class="appButtonClasses('secondary', 'md', 'px-2')"
                        :aria-label="$t('More')"
                    >
                        <Ellipsis class="size-4" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-44 font-ui"
                    align="end"
                >
                    <template v-if="!isFirst">
                        <DropdownMenuItem
                            @select="
                                () => {
                                    newEnvName = env?.name ?? '';
                                    renamingEnv = true;
                                }
                            "
                        >
                            <Pencil class="size-4" :stroke-width="1.75" />
                            {{ $t('Rename :name', { name: env?.name ?? '' }) }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                            @select="
                                () => {
                                    removeServices = true;
                                    deletingEnv = true;
                                }
                            "
                        >
                            <Trash2 class="size-4" :stroke-width="1.75" />
                            {{ $t('Delete :name', { name: env?.name ?? '' }) }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </template>
                    <DropdownMenuItem @select="openPreviews">
                        <GitPullRequest class="size-4" :stroke-width="1.75" />
                        {{ $t('Pull request previews…') }}
                        <span
                            v-if="previews.enabled"
                            class="ml-auto text-xs text-(--ds-green)"
                            >{{ $t('On') }}</span
                        >
                    </DropdownMenuItem>
                    <DropdownMenuItem @select="renaming = true">
                        <Pencil class="size-4" :stroke-width="1.75" />
                        {{ $t('Rename project') + ' ' }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                        @select="deleting = true"
                    >
                        <Trash2 class="size-4" :stroke-width="1.75" />
                        {{ $t('Delete project') + ' ' }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>

        <!-- The canvas: apps on the left, what they use on the right,
             each under its machine, with connectors only in the gutter -->
        <div
            class="ds-tile relative overflow-hidden bg-[radial-gradient(var(--ds-divider)_1px,transparent_1px)] [background-size:18px_18px]"
        >
            <div
                v-if="!envNodes.length"
                class="flex min-h-[max(340px,calc(100dvh-19.5rem))] flex-col items-center justify-center px-6 py-12 text-center"
            >
                <p class="text-sm/6 font-semibold">
                    {{
                        isFirst
                            ? $t('Nothing in this project yet')
                            : $t('Nothing in :name yet', {
                                  name: env?.name ?? '',
                              })
                    }}
                </p>
                <p
                    class="mt-1 max-w-sm text-[13px]/5 text-pretty text-(--ds-text-muted)"
                >
                    {{
                        ' ' +
                        $t(
                            'Start with an app you’ve deployed, then add a database, a cache or storage.',
                        ) +
                        ' '
                    }}
                </p>
                <button
                    v-if="canManage"
                    type="button"
                    class="mt-5"
                    :class="appButtonClasses('secondary')"
                    @click="openAdd('app')"
                >
                    <Plus class="size-3.5" /> {{ $t('Add an app') }}
                </button>
            </div>

            <div v-else class="overflow-x-auto">
                <div
                    ref="board"
                    class="relative mx-auto flex min-h-[max(340px,calc(100dvh-19.5rem))] w-max items-center justify-center px-12 py-14"
                >
                    <svg
                        class="pointer-events-none absolute top-0 left-0"
                        :width="boardSize.width"
                        :height="boardSize.height"
                        aria-hidden="true"
                    >
                        <path
                            v-for="edge in edges"
                            :key="edge.id"
                            :d="edge.d"
                            fill="none"
                            stroke-width="1.5"
                            stroke-linecap="round"
                            :stroke-dasharray="
                                edge.state === 'pending' ? '2 5' : undefined
                            "
                            :class="[
                                edge.state === 'failed'
                                    ? 'stroke-(--ds-red)'
                                    : edge.state === 'pending'
                                      ? 'stroke-(--ds-blue)'
                                      : 'stroke-(--ds-text-faint)',
                                edge.state === 'pending' &&
                                    'animate-[dash-flow_1.4s_linear_infinite] motion-reduce:animate-none',
                            ]"
                        />
                    </svg>

                    <template v-for="side in sides" :key="side.key">
                        <!-- The gutter the connectors run through -->
                        <div
                            v-if="side.key === 'resources'"
                            :ref="setGutter"
                            class="w-[136px] shrink-0 self-stretch"
                        />

                        <div class="flex flex-col gap-8">
                            <div
                                v-for="group in side.groups"
                                :key="group.id"
                                class="flex flex-col gap-2"
                            >
                                <p
                                    class="flex items-center gap-1.5 px-0.5 text-xs/4"
                                    :title="
                                        group.member
                                            ? (group.member.error ??
                                              $t(
                                                  memberLabel[
                                                      group.member.state
                                                  ],
                                              ))
                                            : undefined
                                    "
                                >
                                    <span
                                        class="font-medium text-(--ds-text-secondary)"
                                        >{{ group.name }}</span
                                    >
                                    <span
                                        v-if="group.member"
                                        class="font-ui-mono text-[11px] text-(--ds-text-faint)"
                                        >{{ group.member.address }}</span
                                    >
                                    <span
                                        v-if="
                                            group.member &&
                                            group.member.state !== 'up'
                                        "
                                        class="ml-0.5 size-1.5 rounded-full"
                                        :class="[
                                            memberTone[group.member.state],
                                            group.member.state === 'pending' &&
                                                'animate-pulse',
                                        ]"
                                    />
                                </p>
                                <article
                                    v-for="node in group.nodes"
                                    :key="node.id"
                                    :ref="cardRef(node.id)"
                                    class="group/card flex w-[256px] items-center gap-3 rounded-[10px] bg-(--ds-card) py-2.5 pr-2 pl-2.5 shadow-[0_0_0_1px_var(--ds-divider),0_1px_2px_rgb(0_0_0/0.04)] transition-shadow hover:shadow-[0_0_0_1px_var(--ds-border-strong),0_1px_2px_rgb(0_0_0/0.04)]"
                                >
                                    <span
                                        class="grid size-8 shrink-0 place-items-center rounded-[8px] bg-(--ds-hover) text-(--ds-text-secondary)"
                                    >
                                        <component
                                            :is="kinds[node.kind].icon"
                                            class="size-4"
                                            :stroke-width="1.75"
                                        />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p
                                            class="flex items-center gap-1.5 text-[13px]/5 font-semibold"
                                        >
                                            <span class="truncate">{{
                                                node.name
                                            }}</span>
                                            <span
                                                class="size-1.5 shrink-0 rounded-full"
                                                :class="
                                                    node.status === 'running'
                                                        ? 'bg-(--ds-green)'
                                                        : node.status ===
                                                            'stopped'
                                                          ? 'bg-(--ds-amber)'
                                                          : node.failed
                                                            ? 'bg-(--ds-red)'
                                                            : 'bg-(--ds-text-faint)'
                                                "
                                                :title="
                                                    node.status === 'running'
                                                        ? $t('Running')
                                                        : node.status ===
                                                            'stopped'
                                                          ? $t('Stopped')
                                                          : node.failed
                                                            ? $t(
                                                                  'Its deploy failed',
                                                              )
                                                            : $t('Starting')
                                                "
                                            />
                                        </p>
                                        <p
                                            class="truncate text-xs/4 text-(--ds-text-muted)"
                                        >
                                            {{
                                                node.template
                                                    ? (templateNames[
                                                          node.template
                                                      ] ?? node.template)
                                                    : $t(
                                                          kinds[node.kind]
                                                              .label,
                                                      )
                                            }}<template v-if="node.port">
                                                ·
                                                <span class="font-ui-mono">{{
                                                    node.port
                                                }}</span></template
                                            >
                                        </p>
                                        <p
                                            v-if="node.data"
                                            class="mt-0.5 flex items-center gap-1 truncate text-[11px]/4"
                                            :class="
                                                node.data.state === 'failed'
                                                    ? 'text-(--ds-red)'
                                                    : 'text-(--ds-text-faint)'
                                            "
                                            :title="
                                                node.data.error ?? undefined
                                            "
                                        >
                                            <DatabaseBackup
                                                class="size-3 shrink-0"
                                                :stroke-width="1.75"
                                            />
                                            <span class="truncate">{{
                                                node.data.state === 'copied'
                                                    ? $t('Data copied :when', {
                                                          when: ago(
                                                              node.data.at,
                                                          ),
                                                      })
                                                    : node.data.state ===
                                                        'copying'
                                                      ? $t('Copying data…')
                                                      : node.data.state ===
                                                          'waiting'
                                                        ? $t(
                                                              'Data copy waiting',
                                                          )
                                                        : $t(
                                                              'Couldn’t copy data',
                                                          )
                                            }}</span>
                                        </p>
                                    </div>
                                    <DropdownMenu v-if="canManage">
                                        <DropdownMenuTrigger as-child>
                                            <button
                                                type="button"
                                                class="grid size-6 shrink-0 cursor-pointer place-items-center rounded-[6px] text-(--ds-text-faint) opacity-0 transition-opacity group-hover/card:opacity-100 hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:opacity-100 data-[state=open]:opacity-100"
                                                :aria-label="
                                                    $t('Options for :name', {
                                                        name: node.name,
                                                    })
                                                "
                                            >
                                                <Ellipsis class="size-3.5" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            class="ds-modal min-w-52 font-ui"
                                            align="start"
                                        >
                                            <DropdownMenuItem
                                                v-if="node.href"
                                                @select="
                                                    router.visit(node.href)
                                                "
                                            >
                                                <ArrowUpRight
                                                    class="size-4"
                                                    :stroke-width="1.75"
                                                />
                                                {{ $t('Open') }}
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator
                                                v-if="
                                                    node.href &&
                                                    node.kind !== 'app'
                                                "
                                            />
                                            <template
                                                v-if="node.kind === 'app'"
                                            >
                                                <DropdownMenuItem
                                                    @select="openSettings(node)"
                                                >
                                                    <SlidersHorizontal
                                                        class="size-4"
                                                        :stroke-width="1.75"
                                                    />
                                                    {{ $t('Settings…') }}
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                            </template>
                                            <template
                                                v-if="
                                                    node.kind === 'app' &&
                                                    unlinked(node).length
                                                "
                                            >
                                                <DropdownMenuLabel
                                                    class="text-xs font-normal text-(--ds-text-muted)"
                                                    >{{
                                                        $t('Connect to')
                                                    }}</DropdownMenuLabel
                                                >
                                                <DropdownMenuItem
                                                    v-for="resource in unlinked(
                                                        node,
                                                    )"
                                                    :key="resource.id"
                                                    @select="
                                                        connect(node, resource)
                                                    "
                                                >
                                                    <component
                                                        :is="
                                                            kinds[resource.kind]
                                                                .icon
                                                        "
                                                        class="size-4"
                                                        :stroke-width="1.75"
                                                    />
                                                    {{ resource.name }}
                                                </DropdownMenuItem>
                                            </template>
                                            <template
                                                v-if="linksOf(node).length"
                                            >
                                                <DropdownMenuLabel
                                                    class="text-xs font-normal text-(--ds-text-muted)"
                                                    >{{
                                                        $t('Disconnect')
                                                    }}</DropdownMenuLabel
                                                >
                                                <DropdownMenuItem
                                                    v-for="link in linksOf(
                                                        node,
                                                    )"
                                                    :key="link.id"
                                                    @select="disconnect(link)"
                                                >
                                                    <X
                                                        class="size-4"
                                                        :stroke-width="1.75"
                                                    />
                                                    {{
                                                        nodeById.get(
                                                            node.kind === 'app'
                                                                ? link.resource
                                                                : link.app,
                                                        )?.name
                                                    }}
                                                </DropdownMenuItem>
                                            </template>
                                            <DropdownMenuSeparator
                                                v-if="
                                                    linksOf(node).length ||
                                                    (node.kind === 'app' &&
                                                        unlinked(node).length)
                                                "
                                            />
                                            <DropdownMenuItem
                                                @select="remove(node)"
                                            >
                                                <X
                                                    class="size-4"
                                                    :stroke-width="1.75"
                                                />
                                                {{
                                                    $t(
                                                        'Take out of the project',
                                                    )
                                                }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="text-(--ds-red) focus:bg-(--ds-red)/10 focus:text-(--ds-red)"
                                                @select="openDelete(node)"
                                            >
                                                <Trash2
                                                    class="size-4 text-current"
                                                    :stroke-width="1.75"
                                                />
                                                {{ $t('Delete…') }}
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </article>
                            </div>

                            <!-- A side with nothing on it yet -->
                            <template v-if="canManage && !side.groups.length">
                                <button
                                    v-if="side.key === 'apps'"
                                    type="button"
                                    :class="slot"
                                    @click="openAdd('app')"
                                >
                                    <Plus class="size-3.5" />
                                    {{ $t('Add an app') }}
                                </button>
                                <DropdownMenu v-else>
                                    <DropdownMenuTrigger as-child>
                                        <button
                                            type="button"
                                            :class="slot"
                                            :disabled="busy || !machines.length"
                                        >
                                            <Plus class="size-3.5" />
                                            {{
                                                $t(
                                                    'Add a database, cache or storage',
                                                )
                                            }}
                                        </button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                        class="ds-modal min-w-56 font-ui"
                                        align="start"
                                    >
                                        <DropdownMenuItem
                                            v-for="option in resourceKinds"
                                            :key="option.kind"
                                            @select="addResource(option.kind)"
                                        >
                                            <component
                                                :is="kinds[option.kind].icon"
                                                class="size-4"
                                                :stroke-width="1.75"
                                            />
                                            {{ $t(kinds[option.kind].label) }}
                                            <span
                                                class="ml-auto text-xs text-(--ds-text-muted)"
                                                >{{
                                                    templateNames[
                                                        option.template
                                                    ]
                                                }}</span
                                            >
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            @select="openAdd('resource')"
                                        >
                                            <Server
                                                class="size-4"
                                                :stroke-width="1.75"
                                            />
                                            {{
                                                $t('A service you’ve deployed')
                                            }}
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <!-- Add something that's already deployed -->
        <Dialog v-model:open="adding">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
            >
                <div class="flex flex-col gap-1 px-5 pt-5 pb-3">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        addFilter === 'resource'
                            ? $t('Add a service you’ve deployed')
                            : $t('Add an app you’ve deployed')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'It keeps running where it is. Its machine joins the project’s private network.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>
                <div class="max-h-[50vh] overflow-y-auto px-2 pb-3">
                    <p
                        v-if="candidates === undefined"
                        class="px-3 py-6 text-center text-[13px] text-(--ds-text-muted)"
                    >
                        {{ $t('Loading…') }}
                    </p>
                    <p
                        v-else-if="!shownCandidates.length"
                        class="px-3 py-6 text-center text-[13px] text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            addFilter === 'resource'
                                ? $t(
                                      'No databases, caches or storage deployed from ServerOS are left to add. Use New in the bar to deploy one.',
                                  )
                                : $t(
                                      'No deployed apps are left to add. Deploy one from Services first.',
                                  )
                        }}
                    </p>
                    <button
                        v-for="c in shownCandidates"
                        :key="`${c.machine.id}/${c.name}`"
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 rounded-[8px] px-3 py-2.5 text-left text-[13px]/5 hover:bg-(--ds-hover) disabled:opacity-50"
                        :disabled="busy"
                        @click="addExisting(c)"
                    >
                        <component
                            :is="kinds[c.kind].icon"
                            class="size-4 shrink-0 text-(--ds-text-secondary)"
                            :stroke-width="1.75"
                        />
                        <span class="min-w-0 truncate font-medium">{{
                            c.name
                        }}</span>
                        <span class="text-(--ds-text-muted)">{{
                            c.template
                                ? (templateNames[c.template] ?? c.template)
                                : $t('App')
                        }}</span>
                        <span
                            class="ml-auto shrink-0 text-xs text-(--ds-text-muted)"
                            >{{ c.machine.name }}</span
                        >
                    </button>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Which machine a new resource goes on -->
        <Dialog
            :open="placing !== null"
            @update:open="(open) => !open && (placing = null)"
        >
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <form
                    class="flex flex-col gap-4 p-5"
                    @submit.prevent="confirmPlace"
                >
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            placing
                                ? $t('New :kind', {
                                      kind: $t(kinds[placing].label),
                                  })
                                : ''
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t(
                                    'Pick the machine it runs on. Every app in the project is connected to it once it’s up, wherever they run.',
                                ) +
                                ' '
                            }}
                        </DialogDescription>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label
                            v-for="m in machines"
                            :key="m.id"
                            class="flex cursor-pointer items-center gap-2.5 rounded-[8px] px-3 py-2 text-[13px] hover:bg-(--ds-hover)"
                        >
                            <input
                                v-model="placeOn"
                                type="radio"
                                name="machine"
                                :value="m.id"
                                class="accent-(--ds-blue)"
                            />
                            <Server
                                class="size-4 text-(--ds-text-secondary)"
                                :stroke-width="1.75"
                            />
                            {{ m.name }}
                        </label>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="placing = null"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="appButtonClasses('primary')"
                            :disabled="busy || placeOn === null"
                        >
                            {{ $t('Deploy and connect') }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="renaming">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <form class="flex flex-col gap-4 p-5" @submit.prevent="rename">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Rename project')
                    }}</DialogTitle>
                    <DialogDescription class="sr-only">{{
                        $t('Rename project')
                    }}</DialogDescription>
                    <Input v-model="newName" maxlength="80" required />
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="renaming = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="appButtonClasses('primary')"
                            :disabled="busy || !newName.trim()"
                        >
                            {{ $t('Save') }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deleting">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('Delete :name?', { name: project.name })
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t(
                                    'Everything in it keeps running, and apps keep the settings they were given. The private network is taken down, so apps on other machines lose the way to reach these services.',
                                ) +
                                ' '
                            }}
                        </DialogDescription>
                    </div>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="deleting = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="button"
                            :class="appButtonClasses('danger')"
                            :disabled="busy"
                            @click="destroy"
                        >
                            {{ $t('Delete project') }}
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
        <Dialog v-model:open="making">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
            >
                <form class="flex flex-col gap-5 p-5" @submit.prevent="makeEnv">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('New environment')
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t(
                                    'A copy to try things in before they reach your users. Its apps run the same code with no domain, and its databases, caches and storage are new ones with passwords of their own.',
                                ) +
                                ' '
                            }}
                        </DialogDescription>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1.5 text-[13px]">
                            <span class="font-medium">{{ $t('Name') }}</span>
                            <Input
                                v-model="envName"
                                maxlength="40"
                                required
                                :placeholder="$t('Staging')"
                            />
                        </label>
                        <label class="flex flex-col gap-1.5 text-[13px]">
                            <span class="font-medium">{{
                                $t('A copy of')
                            }}</span>
                            <select
                                v-model.number="envSource"
                                class="ds-select ds-raised-button h-9 w-full cursor-pointer appearance-none pr-8! text-[13px]"
                            >
                                <option
                                    v-for="e in environments"
                                    :key="e.id"
                                    :value="e.id"
                                >
                                    {{ e.name }}
                                </option>
                            </select>
                        </label>
                    </div>

                    <div
                        v-if="sourceMachines.length"
                        class="flex flex-col gap-2"
                    >
                        <p class="text-[13px] font-medium">
                            {{ $t('Where it runs') }}
                        </p>
                        <div
                            class="flex flex-col divide-y divide-(--ds-divider) rounded-[8px] border border-(color:--ds-divider)"
                        >
                            <div
                                v-for="m in sourceMachines"
                                :key="m.id"
                                class="flex items-center gap-3 px-3 py-2 text-[13px]"
                            >
                                <span
                                    class="min-w-0 flex-1 truncate text-(--ds-text-secondary)"
                                    >{{
                                        $t('What’s on :name goes on', {
                                            name: m.name,
                                        })
                                    }}</span
                                >
                                <select
                                    v-model.number="envMachines[m.id]"
                                    :aria-label="
                                        $t('What’s on :name goes on', {
                                            name: m.name,
                                        })
                                    "
                                    class="ds-select ds-raised-button h-8 max-w-[45%] cursor-pointer appearance-none pr-8! text-[13px]"
                                >
                                    <option
                                        v-for="target in machines"
                                        :key="target.id"
                                        :value="target.id"
                                    >
                                        {{ target.name }}
                                    </option>
                                </select>
                            </div>
                        </div>
                        <p class="text-xs/4 text-(--ds-text-muted)">
                            {{
                                $t(
                                    'A cheaper machine is fine: it only runs what you test.',
                                )
                            }}
                        </p>
                    </div>

                    <label
                        v-if="sourceHasData"
                        class="flex cursor-pointer items-start gap-2.5 rounded-[8px] border border-(color:--ds-divider) p-3 text-[13px]"
                    >
                        <input
                            v-model="envCopyData"
                            type="checkbox"
                            class="mt-0.5 accent-(--ds-blue)"
                        />
                        <span class="flex flex-col gap-0.5">
                            <span class="font-medium">{{
                                $t('Copy the data in its databases')
                            }}</span>
                            <span class="text-xs/4 text-(--ds-text-muted)">{{
                                $t(
                                    'Taken over the project’s private network once they’re up. Copy it again any time, or every night, from the Data menu.',
                                )
                            }}</span>
                        </span>
                    </label>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="making = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="appButtonClasses('primary')"
                            :disabled="busy || !envName.trim()"
                        >
                            {{ $t('Make the environment') }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="promoting">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
            >
                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('Promote :from to :to', {
                                from: env?.name ?? '',
                                to: sourceEnv?.name ?? '',
                            })
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t(
                                    'Each app in :to is deployed at the code its copy runs. Its own settings, domain and data stay as they are, and if a deploy fails its health check the live version keeps running.',
                                    { to: sourceEnv?.name ?? '' },
                                ) +
                                ' '
                            }}
                        </DialogDescription>
                    </div>
                    <div
                        v-if="promotionRows === undefined"
                        class="py-6 text-center text-[13px] text-(--ds-text-muted)"
                    >
                        {{ $t('Working out what changes…') }}
                    </div>
                    <p
                        v-else-if="!promotionRows.length"
                        class="py-4 text-[13px] text-(--ds-text-muted)"
                    >
                        {{
                            $t('No app here is a copy of one in :to.', {
                                to: sourceEnv?.name ?? '',
                            })
                        }}
                    </p>
                    <ul
                        v-else
                        class="flex flex-col divide-y divide-(--ds-divider) rounded-[8px] border border-(color:--ds-divider)"
                    >
                        <li
                            v-for="row in promotionRows"
                            :key="row.app"
                            class="flex flex-col gap-1 px-3 py-2.5 text-[13px]"
                        >
                            <div class="flex items-center gap-2">
                                <AppWindow
                                    class="size-4 text-(--ds-text-secondary)"
                                    :stroke-width="1.75"
                                />
                                <span class="font-medium">{{ row.app }}</span>
                                <span class="text-xs text-(--ds-text-faint)">{{
                                    row.machine
                                }}</span>
                                <span
                                    v-if="row.from && row.to"
                                    class="ml-auto font-ui-mono text-xs"
                                    :class="
                                        row.ready
                                            ? 'text-(--ds-text-secondary)'
                                            : 'text-(--ds-text-faint)'
                                    "
                                >
                                    {{ row.from }} →
                                    <span
                                        :class="row.ready && 'text-(--ds-text)'"
                                        >{{ row.to }}</span
                                    >
                                </span>
                            </div>
                            <p
                                v-if="row.reason"
                                class="pl-6 text-xs/4 text-(--ds-text-muted)"
                            >
                                {{ $t(row.reason) }}
                            </p>
                            <p
                                v-if="row.missing.length"
                                class="pl-6 text-xs/4 text-(--ds-amber)"
                            >
                                {{
                                    $t(
                                        'Only the copy has :names. Add them to :app’s settings first if the new code needs them.',
                                        {
                                            names: row.missing.join(', '),
                                            app: row.app,
                                        },
                                    )
                                }}
                            </p>
                        </li>
                    </ul>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="promoting = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="button"
                            :class="appButtonClasses('primary')"
                            :disabled="busy || !promotionReady"
                            @click="promote"
                        >
                            {{ $t('Promote') }}
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="renamingEnv">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <form
                    class="flex flex-col gap-4 p-5"
                    @submit.prevent="renameEnv"
                >
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Rename :name', { name: env?.name ?? '' })
                    }}</DialogTitle>
                    <DialogDescription class="sr-only">{{
                        $t('Rename the environment')
                    }}</DialogDescription>
                    <Input v-model="newEnvName" maxlength="40" required />
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="renamingEnv = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="appButtonClasses('primary')"
                            :disabled="busy || !newEnvName.trim()"
                        >
                            {{ $t('Save') }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog v-model:open="deletingEnv">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('Delete :name?', { name: env?.name ?? '' })
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t(
                                    'It comes out of the project. Nothing in any other environment is touched.',
                                ) +
                                ' '
                            }}
                        </DialogDescription>
                    </div>
                    <label
                        class="flex cursor-pointer items-start gap-2.5 rounded-[8px] border border-(color:--ds-divider) p-3 text-[13px]"
                    >
                        <input
                            v-model="removeServices"
                            type="checkbox"
                            class="mt-0.5 accent-(--ds-red)"
                        />
                        <span class="flex flex-col gap-0.5">
                            <span class="font-medium">{{
                                $t('Remove its apps and services too')
                            }}</span>
                            <span class="text-xs/4 text-(--ds-text-muted)">{{
                                $t(
                                    'They’re stopped and removed from their machines with their data. Leave this off to keep them running.',
                                )
                            }}</span>
                        </span>
                    </label>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="deletingEnv = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="button"
                            :class="appButtonClasses('danger')"
                            :disabled="busy"
                            @click="deleteEnv"
                        >
                            {{ $t('Delete environment') }}
                        </button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <!-- Delete something: off the canvas and the machine -->
        <Dialog v-model:open="deletingOpen">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
            >
                <form
                    v-if="deletingNode"
                    class="flex flex-col gap-4 p-5"
                    @submit.prevent="confirmDelete"
                >
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('Delete :name?', { name: deletingNode.name })
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                (deletingNode.href
                                    ? $t(
                                          'Its containers on :machine stop and are deleted, every version of it, and it comes off the project. Data in volumes is kept unless you choose otherwise.',
                                          {
                                              machine:
                                                  deletingNode.machine.name,
                                          },
                                      )
                                    : $t(
                                          'Nothing of it is running on :machine, so this only takes it off the project.',
                                          {
                                              machine:
                                                  deletingNode.machine.name,
                                          },
                                      )) +
                                ' '
                            }}
                        </DialogDescription>
                    </div>
                    <template v-if="deletingNode.href">
                        <div class="flex flex-col gap-1.5">
                            <label for="delete-confirm" class="text-[13px]/5">{{
                                $t('Type :name to confirm', {
                                    name: deletingNode.name,
                                })
                            }}</label>
                            <Input
                                id="delete-confirm"
                                v-model="deleteConfirm"
                                autocomplete="off"
                                class="font-ui-mono"
                            />
                        </div>
                        <label class="flex items-start gap-2 text-[13px]/5">
                            <TickBox v-model="deleteData" class="mt-0.5" />
                            {{
                                $t(
                                    'Also delete its data (volumes). This can’t be undone.',
                                )
                            }}
                        </label>
                    </template>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary', 'sm')"
                            @click="deletingNode = null"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <DangerButton
                            :label="$t('Delete')"
                            :processing="busy"
                            :disabled="
                                !busy &&
                                !!deletingNode.href &&
                                deleteConfirm !== deletingNode.name
                            "
                        />
                    </div>
                </form>
            </DialogContent>
        </Dialog>
        <AppVariablesDialog
            v-if="editing"
            v-model:open="editingOpen"
            :machine-id="editing.machine.id"
            :app="editing.name"
        />
        <Dialog v-model:open="previewing">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
            >
                <form
                    class="flex flex-col gap-5 p-5"
                    @submit.prevent="savePreviews"
                >
                    <div class="flex flex-col gap-1">
                        <DialogTitle class="text-base/6 font-semibold">{{
                            $t('Pull request previews')
                        }}</DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                        >
                            {{
                                ' ' +
                                $t(
                                    'Each pull request gets its own copy of :env on the machine you pick, running the pull request’s code. New commits deploy it again, and it’s removed when the pull request closes. Pull requests from forks never get one.',
                                    { env: environments[0]?.name ?? '' },
                                ) +
                                ' '
                            }}
                        </DialogDescription>
                    </div>

                    <p
                        v-if="!previews.github"
                        class="rounded-[8px] border border-(color:--ds-divider) p-3 text-[13px] text-pretty text-(--ds-text-secondary)"
                    >
                        {{
                            $t(
                                'ServerOS hears about pull requests through its GitHub App.',
                            )
                        }}
                        <a
                            href="/settings"
                            class="font-medium text-(--ds-blue) hover:underline"
                            >{{ $t('Connect GitHub in Settings') }}</a
                        >
                    </p>

                    <label
                        class="flex cursor-pointer items-center gap-2.5 text-[13px] font-medium"
                    >
                        <input
                            v-model="previewForm.enabled"
                            type="checkbox"
                            class="accent-(--ds-blue)"
                        />
                        {{ $t('Make a preview for each pull request') }}
                    </label>

                    <template v-if="previewForm.enabled">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex flex-col gap-1.5 text-[13px]">
                                <span class="font-medium">{{
                                    $t('Repository')
                                }}</span>
                                <Input
                                    v-model="previewForm.repo"
                                    list="project-repos"
                                    required
                                    placeholder="owner/name"
                                    class="font-ui-mono text-xs"
                                />
                                <datalist id="project-repos">
                                    <option
                                        v-for="r in repos ?? []"
                                        :key="r"
                                        :value="r"
                                    />
                                </datalist>
                            </label>
                            <label class="flex flex-col gap-1.5 text-[13px]">
                                <span class="font-medium">{{
                                    $t('Runs on')
                                }}</span>
                                <select
                                    v-model.number="previewForm.machine"
                                    class="ds-select ds-raised-button h-9 w-full cursor-pointer appearance-none pr-8! text-[13px]"
                                >
                                    <option
                                        v-for="m in machines"
                                        :key="m.id"
                                        :value="m.id"
                                    >
                                        {{ m.name }}
                                    </option>
                                </select>
                            </label>
                        </div>

                        <label class="flex flex-col gap-1.5 text-[13px]">
                            <span class="font-medium"
                                >{{ $t('Domain') }}
                                <span
                                    class="font-normal text-(--ds-text-muted)"
                                    >{{ $t('(optional)') }}</span
                                ></span
                            >
                            <Input
                                v-model="previewForm.domain"
                                placeholder="preview.example.com"
                                class="font-ui-mono text-xs"
                            />
                            <span class="text-xs/4 text-(--ds-text-muted)">{{
                                previewForm.domain.trim()
                                    ? $t(
                                          'Each preview opens at an address like :example. Point *.:domain at the machine in your DNS.',
                                          {
                                              example: `shop-pr-12.${previewForm.domain.trim()}`,
                                              domain: previewForm.domain.trim(),
                                          },
                                      )
                                    : $t(
                                          'Without one, previews have no address of their own. Add one to an app from its page.',
                                      )
                            }}</span>
                        </label>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex flex-col gap-1.5 text-[13px]">
                                <span class="font-medium">{{
                                    $t('Open at once, at most')
                                }}</span>
                                <select
                                    v-model.number="previewForm.limit"
                                    class="ds-select ds-raised-button h-9 w-full cursor-pointer appearance-none pr-8! text-[13px]"
                                >
                                    <option
                                        v-for="n in previews.max"
                                        :key="n"
                                        :value="n"
                                    >
                                        {{ n }}
                                    </option>
                                </select>
                            </label>
                        </div>

                        <label
                            class="flex cursor-pointer items-start gap-2.5 rounded-[8px] border border-(color:--ds-divider) p-3 text-[13px]"
                        >
                            <input
                                v-model="previewForm.copy_data"
                                type="checkbox"
                                class="mt-0.5 accent-(--ds-blue)"
                            />
                            <span class="flex flex-col gap-0.5">
                                <span class="font-medium">{{
                                    $t('Copy :env’s data into each preview', {
                                        env: environments[0]?.name ?? '',
                                    })
                                }}</span>
                                <span
                                    class="text-xs/4 text-(--ds-text-muted)"
                                    >{{
                                        $t(
                                            'Off by default: anyone who can push a branch would see real data in their preview.',
                                        )
                                    }}</span
                                >
                            </span>
                        </label>
                    </template>

                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            :class="appButtonClasses('secondary')"
                            @click="previewing = false"
                        >
                            {{ $t('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            :class="appButtonClasses('primary')"
                            :disabled="
                                busy ||
                                (previewForm.enabled &&
                                    (!previewForm.repo.trim() ||
                                        previewForm.machine === null))
                            "
                        >
                            {{ $t('Save') }}
                        </button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    </WorkspacePage>
</template>
