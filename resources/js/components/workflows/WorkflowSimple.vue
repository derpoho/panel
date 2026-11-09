<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { SolarTrash2 as Trash2 } from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import {
    EVENTS,
    FROM_TRIGGER,
    STEPS,
    environmentName,
    projectName,
} from './kinds';
import type { Options, PortId, StepKind, WorkflowNode } from './kinds';
import type { SimpleStep } from './simple';
import { provideSimple } from './simple';
import SimpleAddMenu from './SimpleAddMenu.vue';
import SimpleChain from './SimpleChain.vue';
import { useWorkflowEditor } from './useWorkflowEditor';

/**
 * Easy mode: the workflow as a list you read top to bottom, "when this
 * happens, do this, then this; if that fails, do that", with each step's
 * settings a click away. It edits the same graph the canvas draws, so you
 * can switch between them at any time.
 */
const props = defineProps<{
    options: Options;
    webhookUrl: string | null;
    twoFactor: boolean;
    problems: Record<string, string>;
}>();

const emit = defineEmits<{ rotateWebhook: [] }>();

const editor = useWorkflowEditor();
const readOnly = editor.readOnly;

const mainPort = (node: WorkflowNode): PortId =>
    node.kind === 'branch' ? 'true' : 'out';

const sideLabel: Partial<Record<PortId, string>> = {
    failed: trans('If it fails'),
    true: trans('If yes'),
    false: trans('If not'),
};

/**
 * Walk the graph from the trigger: the main path down the page, other
 * paths indented under the step they leave. A step reached twice is shown
 * once; the second path says where it carries on.
 */
const layout = computed(() => {
    const byId = new Map(editor.nodes.value.map((n) => [n.id, n]));
    const seen = new Set<string>();
    const targets = (id: string, port: PortId) =>
        editor.edges.value
            .filter((e) => e.source === id && e.port === port)
            .map((e) => byId.get(e.target))
            .filter((n): n is WorkflowNode => n !== undefined);

    function chain(start: WorkflowNode | undefined): {
        steps: SimpleStep[];
        jump: WorkflowNode | null;
    } {
        const steps: SimpleStep[] = [];
        let node = start;
        while (node) {
            if (seen.has(node.id)) {
                return { steps, jump: node };
            }
            seen.add(node.id);
            const step: SimpleStep = { node, sides: [], jump: null };
            steps.push(step);

            // A check splits in two: both answers are paths of their own,
            // and the main path ends there.
            const split = node.kind === 'branch';
            for (const port of STEPS[node.kind].ports) {
                if (port === mainPort(node) && !split) continue;
                const next = targets(node.id, port);
                const c = next[0] ? chain(next[0]) : { steps: [], jump: null };
                step.sides.push({
                    port,
                    label: sideLabel[port] ?? port,
                    chain: c.steps,
                    jump: c.jump,
                    from: node.id,
                    parallel: false,
                });
            }
            if (split) break;
            const main = targets(node.id, mainPort(node));
            // More than one step after this one: the rest run alongside.
            for (const extra of main.slice(1)) {
                const c = chain(extra);
                step.sides.push({
                    port: mainPort(node),
                    label: trans('At the same time'),
                    chain: c.steps,
                    jump: c.jump,
                    from: node.id,
                    parallel: true,
                });
            }
            const nextNode = main[0];
            if (nextNode && seen.has(nextNode.id)) {
                step.jump = nextNode;

                break;
            }
            node = nextNode;
        }

        return { steps, jump: null };
    }

    const trigger = editor.nodes.value.find((n) => n.kind === 'trigger');
    const main = chain(trigger).steps;
    const loose = editor.nodes.value.filter((n) => !seen.has(n.id));

    return { main, loose };
});

const machineName = (id: unknown): string =>
    id === FROM_TRIGGER
        ? trans('the machine from the trigger')
        : id === '@all'
          ? trans('every machine')
          : (props.options.machines.find((m) => String(m.id) === String(id))
                ?.name ?? 'a machine');

function serviceName(node: WorkflowNode): string {
    const key = node.rules.service;
    if (key === FROM_TRIGGER) return trans('the app from the trigger');
    if (!key) return trans('an app');
    const machine = props.options.machines.find(
        (m) => String(m.id) === String(node.rules.machine_id),
    );

    return machine?.services.find((s) => s.key === key)?.name ?? String(key);
}

const monitorName = (id: unknown): string =>
    props.options.monitors.find((m) => String(m.id) === String(id))?.name ??
    'a monitor';

/** The step in a phrase, from its settings. */
function detail(node: WorkflowNode): string {
    const r = node.rules;
    switch (node.kind) {
        case 'trigger': {
            const event = EVENTS.find((e) => e.id === (r.event ?? 'manual'));
            if (r.event === 'schedule') {
                return r.every === 'hour'
                    ? trans('Every hour at :minute', {
                          minute: ':' + String(r.at ?? '00:00').slice(3),
                      })
                    : r.every === 'week'
                      ? trans('Every week at :time UTC', {
                            time: String(r.at ?? '03:00'),
                        })
                      : trans('Every day at :time UTC', {
                            time: String(r.at ?? '03:00'),
                        });
            }
            if (r.event === 'git.push' && r.repo) {
                return trans('Push to :branch of :repo', {
                    branch: String(r.branch || 'main'),
                    repo: String(r.repo),
                });
            }
            if (r.event === 'monitor.down' || r.event === 'monitor.up') {
                return `${event?.label ?? ''}: ${r.monitor_id ? monitorName(r.monitor_id) : trans('any monitor')}`;
            }

            return event?.description ?? '';
        }
        case 'redeploy':
            return r.service === FROM_TRIGGER
                ? trans(
                      'Deploy the latest code of the app from the trigger on :machine',
                      { machine: machineName(r.machine_id) },
                  )
                : r.service
                  ? trans('Deploy the latest :service on :machine', {
                        service: String(r.service),
                        machine: machineName(r.machine_id),
                    })
                  : trans('Deploy the latest code on :machine', {
                        machine: machineName(r.machine_id),
                    });
        case 'release':
            return r.repo && r.path
                ? trans('Release :repo to :path on :machine', {
                      repo: String(r.repo),
                      path: String(r.path),
                      machine: machineName(r.machine_id),
                  })
                : trans('Release an app on :machine', {
                      machine: machineName(r.machine_id),
                  });
        case 'restart':
        case 'start':
        case 'stop':
            return trans(':action :service on :machine', {
                action: STEPS[node.kind].label.replace(' service', ''),
                service: serviceName(node),
                machine: machineName(r.machine_id),
            });
        case 'command':
            return trans('Run “:command” in :service', {
                command: String(r.command || '…'),
                service: serviceName(node),
            });
        case 'agent':
            return trans(':agent: “:prompt”', {
                agent: r.agent === 'codex' ? 'Codex' : 'Claude Code',
                prompt: String(r.prompt || '…').slice(0, 80),
            });
        case 'backup':
            return trans('Back up :machineName', {
                machineName: String(machineName(r.machine_id)),
            });
        case 'copy_data':
            return trans('Copy data into :environment of :project', {
                environment:
                    environmentName(props.options, r.project, r.environment) ??
                    trans('an environment'),
                project:
                    projectName(props.options, r.project) ?? trans('a project'),
            });
        case 'promote':
            return trans('Promote :from to :to', {
                from:
                    environmentName(props.options, r.project, r.from) ??
                    trans('an environment'),
                to:
                    environmentName(props.options, r.project, r.to) ??
                    trans('another'),
            });
        case 'check_monitor':
            return trans('Check :monitorName is up, for up to :value min', {
                monitorName: String(monitorName(r.monitor_id)),
                value: String(r.timeout ?? 5),
            });
        case 'http':
            return `${r.method ?? 'POST'} ${
                r.url
                    ? String(r.url)
                          .replace(/^https?:\/\//, '')
                          .slice(0, 48)
                    : 'a URL'
            }`;
        case 'alert': {
            const to =
                r.to === 'emails'
                    ? String(r.emails || 'someone')
                    : r.to === 'both'
                      ? trans('the team and :emails', {
                            emails: String(r.emails || trans('someone')),
                        })
                      : trans('the team');

            return trans('Email :to: “:subject”', {
                to,
                subject: String(r.subject || '…'),
            });
        }
        case 'wait':
            return `Wait ${r.minutes ?? 1} ${Number(r.minutes) === 1 ? trans('minute') : trans('minutes')}`;
        case 'branch':
            switch (r.condition) {
                case 'monitor_up':
                    return trans('Is :monitorName up?', {
                        monitorName: String(monitorName(r.monitor_id)),
                    });
                case 'service_running':
                    return trans('Is :serviceName running?', {
                        serviceName: String(serviceName(node)),
                    });
                case 'disk_below':
                    return trans('Is :machineName’s disk under :value%?', {
                        machineName: String(machineName(r.machine_id)),
                        value: String(r.percent ?? 85),
                    });
                case 'time_between':
                    return trans('Is it between :from and :to UTC?', {
                        from: String(r.from ?? '09:00'),
                        to: String(r.to ?? '17:00'),
                    });
                case 'trigger_field':
                    return r.field
                        ? trans('Is :field “:value”?', {
                              field: String(r.field),
                              value: String(r.value ?? ''),
                          })
                        : trans('Choose what to check');
                default:
                    return trans('Choose what to check');
            }
    }
}

const open = ref<string | null>(
    Object.keys(props.problems)[0] ??
        (editor.nodes.value.length <= 1
            ? (editor.nodes.value[0]?.id ?? null)
            : null),
);

function add(kind: StepKind, from: string, port: PortId): void {
    const parent = editor.nodes.value.find((n) => n.id === from);
    if (!parent) return;
    const side = port !== mainPort(parent);
    const node = editor.createNode(
        kind,
        parent.x + (side ? 260 : 0),
        parent.y + 200,
    );
    // A step added in the middle of a path goes before what came next.
    const next = editor.edges.value.find(
        (e) => e.source === from && e.port === port,
    )?.target;
    editor.addNode(node, { source: from, port });
    if (next) editor.connect(node.id, mainPort(node), next);
    open.value = node.id;
}

/** Remove a step and join what came before it to what came after. */
function remove(node: WorkflowNode): void {
    const into = editor.edges.value.filter((e) => e.target === node.id);
    const after = editor.edges.value.find(
        (e) => e.source === node.id && e.port === mainPort(node),
    )?.target;
    editor.removeNode(node.id);
    if (after) {
        for (const e of into) editor.connect(e.source, e.port, after);
    }
    if (open.value === node.id) open.value = null;
}

provideSimple({
    open,
    toggle: (id) => (open.value = open.value === id ? null : id),
    add,
    remove,
    detail,
    mainPort,
    runStep: (id) => editor.run.value?.steps[id] ?? null,
    problems: () => props.problems,
    options: () => props.options,
    webhookUrl: () => props.webhookUrl,
    twoFactor: () => props.twoFactor,
    rotateWebhook: () => emit('rotateWebhook'),
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div class="wf-root min-h-0 flex-1 overflow-y-auto">
        <div class="mx-auto flex max-w-2xl flex-col px-4 py-6 sm:px-6">
            <SimpleChain :steps="layout.main" :depth="0" />

            <!-- Add to the end of the main path -->
            <template
                v-if="
                    !readOnly &&
                    layout.main.length &&
                    layout.main.at(-1)!.node.kind !== 'branch'
                "
            >
                <div
                    class="ml-[27px] h-5 w-px bg-(--ds-border-strong)"
                    aria-hidden="true"
                />
                <SimpleAddMenu
                    prominent
                    :label="
                        layout.main.length === 1
                            ? $t('Add the first step')
                            : $t('Add a step')
                    "
                    @pick="
                        (kind) =>
                            add(
                                kind,
                                layout.main.at(-1)!.node.id,
                                mainPort(layout.main.at(-1)!.node),
                            )
                    "
                />
            </template>

            <!-- Steps nothing leads to -->
            <div v-if="layout.loose.length" class="mt-8 flex flex-col gap-2">
                <p class="text-[12.5px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'Not connected, so these never run. Connect them in Power mode, or remove them.',
                        ) +
                        ' '
                    }}
                </p>
                <div
                    v-for="node in layout.loose"
                    :key="node.id"
                    class="ds-tile flex items-center gap-3 px-3.5 py-2.5 text-[13px]/5"
                >
                    <component
                        :is="STEPS[node.kind].icon"
                        class="size-4 text-(--ds-text-muted)"
                    />
                    <span class="min-w-0 grow truncate">{{
                        node.title || STEPS[node.kind].title
                    }}</span>
                    <button
                        v-if="!readOnly && node.kind !== 'trigger'"
                        type="button"
                        class="cursor-pointer rounded p-1 text-(--ds-text-muted) hover:text-(--ds-red)"
                        :class="focusRing"
                        :aria-label="
                            $t('Remove :title', { title: String(node.title) })
                        "
                        @click="editor.removeNode(node.id)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
