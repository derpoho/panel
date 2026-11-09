<script setup lang="ts">
import { Check } from '@lucide/vue';
import { SolarCopy as Copy, SolarRotateCw as RotateCw } from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import { ALL_MACHINES, CONDITIONS, EVENTS, FROM_TRIGGER, STEPS } from './kinds';
import type { Options, WorkflowNode } from './kinds';
import AgentSettings from './AgentSettings.vue';
import ReleaseSettings from './ReleaseSettings.vue';
import { useWorkflowEditor } from './useWorkflowEditor';

const props = defineProps<{
    node: WorkflowNode;
    options: Options;
    webhookUrl: string | null;
    twoFactor: boolean;
}>();

const emit = defineEmits<{ rotateWebhook: [] }>();

const editor = useWorkflowEditor();
const readOnly = editor.readOnly;

const rules = computed(() => props.node.rules);

function set(key: string, value: string | number | boolean | null): void {
    editor.setRule(props.node.id, key, value);
}

function value(key: string): string {
    const v = rules.value[key];

    return v === null || v === undefined ? '' : String(v);
}

/** The trigger's event: which "from the trigger" choices make sense. */
const triggerEvent = computed(() => {
    const trigger = editor.nodes.value.find((n) => n.kind === 'trigger');

    return (
        EVENTS.find((e) => e.id === (trigger?.rules.event ?? 'manual')) ??
        EVENTS[0]
    );
});

const event = computed(
    () => EVENTS.find((e) => e.id === value('event')) ?? EVENTS[0],
);

const machine = computed(() =>
    props.options.machines.find((m) => String(m.id) === value('machine_id')),
);

/** Services the step can act on: all, or only managed (and containers for commands). */
const services = computed(() => {
    if (value('machine_id') === FROM_TRIGGER) return [];
    const list = machine.value?.services ?? [];
    if (props.node.kind === 'command' || props.node.kind === 'agent')
        return list.filter((s) => s.container);

    return list;
});

/* Projects and their environments, for copying data and promoting */
const projects = computed(() => props.options.projects ?? []);
const project = computed(() =>
    projects.value.find((p) => String(p.id) === value('project')),
);
const copies = computed(
    () => project.value?.environments.filter((e) => e.source !== null) ?? [],
);

function chooseProject(id: string): void {
    set('project', id);
    const chosen = projects.value.find((p) => String(p.id) === id);
    const copy = chosen?.environments.find((e) => e.source !== null);
    if (props.node.kind === 'copy_data') {
        set('environment', copy ? String(copy.id) : '');
    } else {
        // Promote a copy into the environment it was made from.
        set('from', copy ? String(copy.id) : '');
        set('to', copy?.source ? String(copy.source) : '');
    }
}

const canUseTriggerMachine = computed(
    () => triggerEvent.value.machine === true,
);
const canUseTriggerService = computed(() =>
    props.node.kind === 'agent'
        ? false
        : props.node.kind === 'redeploy'
          ? triggerEvent.value.service === 'deploy'
          : triggerEvent.value.service === 'key',
);

const copied = ref<string | null>(null);
function copy(text: string, what: string): void {
    void navigator.clipboard?.writeText(text);
    copied.value = what;
    setTimeout(() => (copied.value = null), 1500);
}

const curl = computed(() =>
    props.webhookUrl
        ? `curl -X POST ${props.webhookUrl} \\\n  -H 'Content-Type: application/json' \\\n  -d '{"ref": "refs/heads/main"}'`
        : '',
);

/** A step to add at the end of a GitHub Actions job, once tests pass. */
const githubActions = computed(() =>
    props.webhookUrl
        ? [
              '- name: Deploy with ServerOS',
              "  if: github.ref == 'refs/heads/main'",
              '  run: |',
              '    curl -fsS -X POST "$SERVEROS_WEBHOOK" \\',
              "      -H 'Content-Type: application/json' \\",
              '      -d \'{"ref": "${{ github.ref }}", "commit": "${{ github.sha }}"}\'',
              '  env:',
              '    SERVEROS_WEBHOOK: ${{ secrets.SERVEROS_WEBHOOK }}',
          ].join('\n')
        : '',
);
const snippet = ref<'github' | 'curl'>('github');

const placeholders =
    '{{service}} {{machine}} {{trigger}} {{workflow}} {{run_url}} {{data.field}}';

const field = 'wf-field h-9 w-full px-3 text-[13px]/5';
const label = 'text-[12px]/5 font-[550] text-(--wf-muted)';
</script>

<template>
    <div class="flex flex-col gap-4 text-(--wf-text)">
        <template v-if="node.kind === 'trigger'">
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Starts when') }}</span>
                <select
                    :class="field"
                    :value="value('event') || 'manual'"
                    :disabled="readOnly"
                    @change="
                        set('event', ($event.target as HTMLSelectElement).value)
                    "
                >
                    <option v-for="e in EVENTS" :key="e.id" :value="e.id">
                        {{ e.label }}
                    </option>
                </select>
                <span class="text-[12px]/4 text-(--wf-faint)">{{
                    event.description
                }}</span>
            </label>

            <template v-if="event.id === 'schedule'">
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex flex-col gap-1.5">
                        <span :class="label">{{ $t('Every') }}</span>
                        <select
                            :class="field"
                            :value="value('every')"
                            :disabled="readOnly"
                            @change="
                                set(
                                    'every',
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="" disabled>
                                {{ $t('Choose…') }}
                            </option>
                            <option value="hour">{{ $t('Hour') }}</option>
                            <option value="day">{{ $t('Day') }}</option>
                            <option value="week">{{ $t('Week') }}</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span :class="label">{{
                            value('every') === 'hour'
                                ? $t('At minute (UTC)')
                                : $t('At (UTC)')
                        }}</span>
                        <input
                            type="time"
                            :class="field"
                            :value="value('at') || '03:00'"
                            :disabled="readOnly"
                            @change="
                                set(
                                    'at',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </label>
                </div>
                <label
                    v-if="value('every') === 'week'"
                    class="flex flex-col gap-1.5"
                >
                    <span :class="label">{{ $t('On') }}</span>
                    <select
                        :class="field"
                        :value="value('weekday') || '1'"
                        :disabled="readOnly"
                        @change="
                            set(
                                'weekday',
                                Number(
                                    ($event.target as HTMLSelectElement).value,
                                ),
                            )
                        "
                    >
                        <option
                            v-for="(day, i) in [
                                'Monday',
                                'Tuesday',
                                'Wednesday',
                                'Thursday',
                                'Friday',
                                'Saturday',
                                'Sunday',
                            ]"
                            :key="day"
                            :value="i + 1"
                        >
                            {{ day }}
                        </option>
                    </select>
                </label>
            </template>

            <template v-if="event.id === 'git.push'">
                <div class="grid grid-cols-[minmax(0,1fr)_8rem] gap-2">
                    <label class="flex flex-col gap-1.5">
                        <span :class="label">{{ $t('Repository') }}</span>
                        <input
                            :class="[field, 'font-ui-mono text-[12px]!']"
                            :value="value('repo')"
                            :disabled="readOnly"
                            placeholder="owner/app"
                            spellcheck="false"
                            @input="
                                set(
                                    'repo',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </label>
                    <label class="flex flex-col gap-1.5">
                        <span :class="label">{{ $t('Branch') }}</span>
                        <input
                            :class="[field, 'font-ui-mono text-[12px]!']"
                            :value="value('branch')"
                            :disabled="readOnly"
                            placeholder="main"
                            spellcheck="false"
                            @input="
                                set(
                                    'branch',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </label>
                </div>
                <span class="text-[12px]/4 text-(--wf-faint)">
                    {{
                        ' ' +
                        $t(
                            'Pushes reach ServerOS through its GitHub App, so connect it to the repository’s owner under Settings → General. The commit is available as',
                        )
                    }}
                    <code class="font-ui-mono" v-text="'{{data.commit}}'" />.
                </span>
            </template>

            <div v-if="event.id === 'webhook'" class="flex flex-col gap-2">
                <span :class="label">{{ $t('Webhook URL') }}</span>
                <template v-if="webhookUrl">
                    <div class="flex gap-2">
                        <input
                            :class="[field, 'font-ui-mono text-[12px]!']"
                            :value="webhookUrl"
                            readonly
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <button
                            type="button"
                            class="wf-button shrink-0"
                            :aria-label="
                                copied === 'url' ? $t('Copied') : $t('Copy URL')
                            "
                            @click="copy(webhookUrl, 'url')"
                        >
                            <component
                                :is="copied === 'url' ? Check : Copy"
                                class="size-3.5"
                            />
                        </button>
                        <button
                            v-if="!readOnly"
                            type="button"
                            class="wf-button shrink-0"
                            :title="
                                $t('Make a new URL; the old one stops working')
                            "
                            :aria-label="$t('New URL')"
                            @click="emit('rotateWebhook')"
                        >
                            <RotateCw class="size-3.5" />
                        </button>
                    </div>
                    <span class="text-[12px]/4 text-(--wf-faint)">
                        {{
                            ' ' +
                            $t(
                                'Anyone with this URL can start the workflow, so keep it in your CI’s secrets. JSON you send is available as',
                            )
                        }}
                        <code class="font-ui-mono" v-text="'{{data.field}}'" />.
                    </span>
                    <div class="flex gap-1 text-[12px]/4">
                        <button
                            v-for="option in [
                                { value: 'github', label: 'GitHub Actions' },
                                { value: 'curl', label: 'Any CI (curl)' },
                            ] as const"
                            :key="option.value"
                            type="button"
                            class="cursor-pointer rounded-md px-2 py-1"
                            :class="
                                snippet === option.value
                                    ? 'bg-(--wf-hover) font-medium text-(--wf-text)'
                                    : 'text-(--wf-muted) hover:text-(--wf-text)'
                            "
                            @click="snippet = option.value"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                    <div class="relative">
                        <pre
                            class="wf-code overflow-x-auto px-3 py-2.5 font-ui-mono text-[11.5px]/5"
                            >{{
                                snippet === 'github' ? githubActions : curl
                            }}</pre>
                        <button
                            type="button"
                            class="wf-button absolute top-1.5 right-1.5 h-6! px-1.5!"
                            :aria-label="$t('Copy')"
                            @click="
                                copy(
                                    snippet === 'github' ? githubActions : curl,
                                    'snippet',
                                )
                            "
                        >
                            <component
                                :is="copied === 'snippet' ? Check : Copy"
                                class="size-3"
                            />
                        </button>
                    </div>
                    <span
                        v-if="snippet === 'github'"
                        class="text-[12px]/4 text-(--wf-faint)"
                    >
                        {{
                            ' ' +
                            $t('Save the URL as a repository secret named')
                        }}
                        <code class="font-ui-mono">SERVEROS_WEBHOOK</code>
                        {{
                            $t(
                                '(Settings › Secrets and variables › Actions), then add this as the last step of the job that runs your tests.',
                            ) + ' '
                        }}
                    </span>
                </template>
                <span v-else class="text-[12px]/4 text-(--wf-faint)">{{
                    $t('Save to get the URL.')
                }}</span>
            </div>

            <label
                v-if="event.filters.includes('machine')"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('On machine') }}</span>
                <select
                    :class="field"
                    :value="value('machine_id')"
                    :disabled="readOnly"
                    @change="
                        set(
                            'machine_id',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">{{ $t('Any machine') }}</option>
                    <option
                        v-for="m in options.machines"
                        :key="m.id"
                        :value="String(m.id)"
                    >
                        {{ m.name }}
                    </option>
                </select>
            </label>
            <label
                v-if="event.filters.includes('service')"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('Service') }}</span>
                <select
                    :class="field"
                    :value="value('service')"
                    :disabled="readOnly"
                    @change="
                        set(
                            'service',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">{{ $t('Any service') }}</option>
                    <option
                        v-for="s in machine?.services ?? []"
                        :key="s.key"
                        :value="s.key"
                    >
                        {{ s.name }}
                    </option>
                </select>
            </label>
            <label
                v-if="event.filters.includes('deploy')"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('Service') }}</span>
                <select
                    :class="field"
                    :value="value('service')"
                    :disabled="readOnly"
                    @change="
                        set(
                            'service',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">{{ $t('Any service') }}</option>
                    <option
                        v-for="d in machine?.deploys ?? [
                            ...new Set(
                                options.machines.flatMap((m) => m.deploys),
                            ),
                        ]"
                        :key="d"
                        :value="d"
                    >
                        {{ d }}
                    </option>
                </select>
            </label>
            <label
                v-if="event.filters.includes('outcome')"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('When it') }}</span>
                <select
                    :class="field"
                    :value="value('outcome') || 'any'"
                    :disabled="readOnly"
                    @change="
                        set(
                            'outcome',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="any">{{ $t('Succeeds or fails') }}</option>
                    <option value="succeeded">{{ $t('Succeeds') }}</option>
                    <option value="failed">{{ $t('Fails') }}</option>
                </select>
            </label>
            <label
                v-if="event.filters.includes('monitor')"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('Monitor') }}</span>
                <select
                    :class="field"
                    :value="value('monitor_id')"
                    :disabled="readOnly"
                    @change="
                        set(
                            'monitor_id',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">{{ $t('Any monitor') }}</option>
                    <option
                        v-for="m in options.monitors"
                        :key="m.id"
                        :value="String(m.id)"
                    >
                        {{ m.name }}
                    </option>
                </select>
            </label>
        </template>

        <!-- Steps on a machine -->
        <template
            v-if="
                [
                    'redeploy',
                    'restart',
                    'start',
                    'stop',
                    'command',
                    'agent',
                    'backup',
                ].includes(node.kind)
            "
        >
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Machine') }}</span>
                <select
                    :class="field"
                    :value="value('machine_id')"
                    :disabled="readOnly"
                    @change="
                        set(
                            'machine_id',
                            ($event.target as HTMLSelectElement).value,
                        );
                        set('service', '');
                    "
                >
                    <option value="" disabled>
                        {{ $t('Choose a machine…') }}
                    </option>
                    <option v-if="canUseTriggerMachine" :value="FROM_TRIGGER">
                        {{ ' ' + $t('The one from the trigger') + ' ' }}
                    </option>
                    <option
                        v-if="node.kind === 'backup' && options.machines.length"
                        :value="ALL_MACHINES"
                    >
                        {{ ' ' + $t('Every machine') + ' ' }}
                    </option>
                    <option
                        v-for="m in options.machines"
                        :key="m.id"
                        :value="String(m.id)"
                    >
                        {{ m.name }}
                    </option>
                </select>
                <span
                    v-if="!options.machines.length"
                    class="text-[12px]/4 text-(--wf-faint)"
                    >{{ $t('Connect a machine first, from Machines.') }}</span
                >
                <span
                    v-else-if="value('machine_id') === ALL_MACHINES"
                    class="text-[12px]/4 text-(--wf-faint)"
                    >{{
                        $t(
                            'Machines that are offline when it runs are skipped and noted in the run.',
                        )
                    }}</span
                >
            </label>
            <label v-if="node.kind !== 'backup'" class="flex flex-col gap-1.5">
                <span :class="label">{{
                    node.kind === 'redeploy'
                        ? $t('App')
                        : node.kind === 'agent'
                          ? $t('Agent’s container')
                          : $t('Service')
                }}</span>
                <select
                    :class="field"
                    :value="value('service')"
                    :disabled="readOnly || !value('machine_id')"
                    @change="
                        set(
                            'service',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="" disabled>{{ $t('Choose…') }}</option>
                    <option v-if="canUseTriggerService" :value="FROM_TRIGGER">
                        {{ ' ' + $t('The one from the trigger') + ' ' }}
                    </option>
                    <template v-if="node.kind === 'redeploy'">
                        <option
                            v-for="d in machine?.deploys ?? []"
                            :key="d"
                            :value="d"
                        >
                            {{ d }}
                        </option>
                    </template>
                    <template v-else>
                        <option
                            v-for="s in services"
                            :key="s.key"
                            :value="s.key"
                            :disabled="!s.managed"
                        >
                            {{ s.name
                            }}{{ s.managed ? '' : ' ' + $t('(not managed)') }}
                        </option>
                    </template>
                </select>
                <span
                    v-if="
                        node.kind === 'redeploy' &&
                        machine &&
                        !machine.deploys.length
                    "
                    class="text-[12px]/4 text-(--ds-amber)"
                >
                    {{
                        ' ' +
                        $t(
                            'Nothing has been deployed to :name yet. Deploy the app once from its page; then it can be redeployed here.',
                            { name: machine.name },
                        ) +
                        ' '
                    }}
                </span>
                <span
                    v-else-if="node.kind === 'redeploy'"
                    class="text-[12px]/4 text-(--wf-faint)"
                >
                    {{
                        ' ' +
                        $t(
                            'Runs its last deploy again. Started by a push to the app’s GitHub repository, it deploys the pushed commit.',
                        ) +
                        ' '
                    }}
                </span>
            </label>
            <label v-if="node.kind === 'command'" class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Command') }}</span>
                <textarea
                    class="wf-field min-h-20 w-full px-3 py-2 font-ui-mono text-[12.5px]/5"
                    :value="value('command')"
                    :disabled="readOnly"
                    placeholder="php artisan migrate --force"
                    spellcheck="false"
                    @input="
                        set(
                            'command',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <span
                    class="text-[12px]/4"
                    :class="
                        twoFactor ? 'text-(--wf-faint)' : 'text-(--ds-amber)'
                    "
                >
                    {{
                        twoFactor
                            ? $t(
                                  'Runs in the container as you, the last person to save. Up to 5 minutes.',
                              )
                            : $t(
                                  'Turn on two-factor authentication to save a workflow that runs commands.',
                              )
                    }}
                </span>
            </label>
        </template>

        <template v-if="node.kind === 'copy_data' || node.kind === 'promote'">
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Project') }}</span>
                <select
                    :class="field"
                    :value="value('project')"
                    :disabled="readOnly"
                    @change="
                        chooseProject(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="" disabled>
                        {{ $t('Choose a project…') }}
                    </option>
                    <option
                        v-for="p in projects"
                        :key="p.id"
                        :value="String(p.id)"
                    >
                        {{ p.name }}
                    </option>
                </select>
                <span
                    v-if="!projects.length"
                    class="text-[12px]/4 text-(--wf-faint)"
                    >{{ $t('Make a project first, from Projects.') }}</span
                >
            </label>

            <label
                v-if="node.kind === 'copy_data'"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('Copy data into') }}</span>
                <select
                    :class="field"
                    :value="value('environment')"
                    :disabled="readOnly || !project"
                    @change="
                        set(
                            'environment',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="" disabled>{{ $t('Choose…') }}</option>
                    <option
                        v-for="e in copies"
                        :key="e.id"
                        :value="String(e.id)"
                    >
                        {{ e.name }}
                    </option>
                </select>
                <span
                    v-if="project && !copies.length"
                    class="text-[12px]/4 text-(--ds-amber)"
                >
                    {{
                        ' ' +
                        $t(
                            ':name has no copy yet. Make one with New environment on the project’s page.',
                            { name: project.name },
                        ) +
                        ' '
                    }}
                </span>
                <span v-else class="text-[12px]/4 text-(--wf-faint)">
                    {{
                        ' ' +
                        $t(
                            'Its PostgreSQL, MySQL, MariaDB and MongoDB databases are filled from the environment it was made from, replacing what’s there. Up to an hour each.',
                        ) +
                        ' '
                    }}
                </span>
            </label>

            <template v-if="node.kind === 'promote'">
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Code from') }}</span>
                    <select
                        :class="field"
                        :value="value('from')"
                        :disabled="readOnly || !project"
                        @change="
                            set(
                                'from',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="" disabled>{{ $t('Choose…') }}</option>
                        <option
                            v-for="e in project?.environments ?? []"
                            :key="e.id"
                            :value="String(e.id)"
                        >
                            {{ e.name }}
                        </option>
                    </select>
                </label>
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Goes to') }}</span>
                    <select
                        :class="field"
                        :value="value('to')"
                        :disabled="readOnly || !project"
                        @change="
                            set(
                                'to',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="" disabled>{{ $t('Choose…') }}</option>
                        <option
                            v-for="e in (project?.environments ?? []).filter(
                                (e) => String(e.id) !== value('from'),
                            )"
                            :key="e.id"
                            :value="String(e.id)"
                        >
                            {{ e.name }}
                        </option>
                    </select>
                    <span class="text-[12px]/4 text-(--wf-faint)">
                        {{
                            ' ' +
                            $t(
                                'Each app there is deployed at the code its copy runs, keeping its own settings, domain and data. Apps already at that code are left alone.',
                            ) +
                            ' '
                        }}
                    </span>
                </label>
            </template>
        </template>

        <span
            v-if="
                node.kind === 'agent' &&
                machine &&
                !services.some((s) => s.managed)
            "
            class="-mt-2 text-[12px]/4 text-(--ds-amber)"
        >
            {{
                $t(
                    'Deploy the Coding agent template to :name first, from Deploy, then choose its container here.',
                    { name: machine.name },
                )
            }}
        </span>
        <AgentSettings
            v-if="node.kind === 'agent'"
            :node="node"
            :options="options"
            :two-factor="twoFactor"
        />

        <ReleaseSettings
            v-if="node.kind === 'release'"
            :node="node"
            :options="options"
            :two-factor="twoFactor"
        />

        <template v-if="node.kind === 'check_monitor'">
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Monitor') }}</span>
                <select
                    :class="field"
                    :value="value('monitor_id')"
                    :disabled="readOnly"
                    @change="
                        set(
                            'monitor_id',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="" disabled>
                        {{ $t('Choose a monitor…') }}
                    </option>
                    <option
                        v-for="m in options.monitors"
                        :key="m.id"
                        :value="String(m.id)"
                    >
                        {{ m.name }}
                    </option>
                </select>
                <span
                    v-if="!options.monitors.length"
                    class="text-[12px]/4 text-(--wf-faint)"
                    >{{ $t('Add a monitor on the Monitors page first.') }}</span
                >
            </label>
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Give up after') }}</span>
                <select
                    :class="field"
                    :value="value('timeout') || '5'"
                    :disabled="readOnly"
                    @change="
                        set(
                            'timeout',
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="m in [1, 2, 5, 10, 15, 30]"
                        :key="m"
                        :value="m"
                    >
                        {{ m }} {{ m === 1 ? $t('minute') : $t('minutes') }}
                    </option>
                </select>
            </label>
        </template>

        <template v-if="node.kind === 'http'">
            <div class="grid grid-cols-[6rem_minmax(0,1fr)] gap-2">
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Method') }}</span>
                    <select
                        :class="field"
                        :value="value('method') || 'POST'"
                        :disabled="readOnly"
                        @change="
                            set(
                                'method',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option>{{ $t('GET') }}</option>
                        <option>{{ $t('POST') }}</option>
                        <option>{{ $t('PUT') }}</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('URL') }}</span>
                    <input
                        :class="[field, 'font-ui-mono text-[12px]!']"
                        :value="value('url')"
                        :disabled="readOnly"
                        :placeholder="$t('https://hooks.slack.com/services/…')"
                        spellcheck="false"
                        @input="
                            set(
                                'url',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
            </div>
            <label
                v-if="value('method') !== 'GET'"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('Body') }}</span>
                <textarea
                    class="wf-field min-h-20 w-full px-3 py-2 font-ui-mono text-[12px]/5"
                    :value="value('body')"
                    :disabled="readOnly"
                    placeholder='{"text": "Deployed {{service}}"}'
                    spellcheck="false"
                    @input="
                        set(
                            'body',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
                <span class="text-[12px]/4 text-(--wf-faint)">{{
                    $t(
                        'Empty sends the workflow, run and trigger as JSON. A 2xx answer counts as success.',
                    )
                }}</span>
            </label>
        </template>

        <template v-if="node.kind === 'alert'">
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Send to') }}</span>
                <select
                    :class="field"
                    :value="value('to') || 'team'"
                    :disabled="readOnly"
                    @change="
                        set('to', ($event.target as HTMLSelectElement).value)
                    "
                >
                    <option value="team">{{ $t('The team') }}</option>
                    <option value="emails">{{ $t('Specific people') }}</option>
                    <option value="both">
                        {{ $t('The team and specific people') }}
                    </option>
                </select>
            </label>
            <label
                v-if="(value('to') || 'team') !== 'team'"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('Email addresses') }}</span>
                <input
                    :class="field"
                    type="text"
                    inputmode="email"
                    :value="value('emails')"
                    :disabled="readOnly"
                    :placeholder="$t('client@example.com, boss@example.com')"
                    @input="
                        set('emails', ($event.target as HTMLInputElement).value)
                    "
                />
                <span class="text-[12px]/4 text-(--wf-faint)">{{
                    $t(
                        'Up to 5, separated by commas. They get a plain email from your workspace’s name with no links into ServerOS; replies go to the workspace owner.',
                    )
                }}</span>
            </label>
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Subject') }}</span>
                <input
                    :class="field"
                    :value="value('subject')"
                    :disabled="readOnly"
                    @input="
                        set(
                            'subject',
                            ($event.target as HTMLInputElement).value,
                        )
                    "
                />
            </label>
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Message') }}</span>
                <textarea
                    class="wf-field min-h-16 w-full px-3 py-2 text-[13px]/5"
                    :value="value('message')"
                    :disabled="readOnly"
                    @input="
                        set(
                            'message',
                            ($event.target as HTMLTextAreaElement).value,
                        )
                    "
                />
            </label>
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('It’s') }}</span>
                <select
                    :class="field"
                    :value="value('tone') || 'bad'"
                    :disabled="readOnly"
                    @change="
                        set('tone', ($event.target as HTMLSelectElement).value)
                    "
                >
                    <option value="bad">{{ $t('A problem') }}</option>
                    <option value="good">{{ $t('Good news') }}</option>
                </select>
            </label>
            <span class="text-[12px]/4 text-(--wf-faint)">
                {{
                    ' ' +
                    $t(
                        'The team means owners and admins, as each has chosen under Settings › Profile, plus the workspace’s alert webhook. You can use',
                    )
                }}
                <code class="font-ui-mono">{{ placeholders }}</code
                >.
            </span>
        </template>

        <template v-if="node.kind === 'wait'">
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Wait for') }}</span>
                <select
                    :class="field"
                    :value="value('minutes') || '2'"
                    :disabled="readOnly"
                    @change="
                        set(
                            'minutes',
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="m in [
                            1, 2, 5, 10, 15, 30, 60, 120, 360, 720, 1440,
                        ]"
                        :key="m"
                        :value="m"
                    >
                        {{
                            m < 60
                                ? `${m} ${m === 1 ? $t('minute') : $t('minutes')}`
                                : $t(
                                      m === 60 ? ':count hour' : ':count hours',
                                      { count: String(m / 60) },
                                  )
                        }}
                    </option>
                </select>
            </label>
        </template>

        <template v-if="node.kind === 'branch'">
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Check') }}</span>
                <select
                    :class="field"
                    :value="value('condition')"
                    :disabled="readOnly"
                    @change="
                        set(
                            'condition',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option v-for="c in CONDITIONS" :key="c.id" :value="c.id">
                        {{ c.label }}
                    </option>
                </select>
            </label>
            <div
                v-if="value('condition') === 'trigger_field'"
                class="grid grid-cols-2 gap-2"
            >
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Field') }}</span>
                    <input
                        :class="[field, 'font-ui-mono text-[12px]!']"
                        :value="value('field')"
                        :disabled="readOnly"
                        :placeholder="$t('ref')"
                        @input="
                            set(
                                'field',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Equals') }}</span>
                    <input
                        :class="[field, 'font-ui-mono text-[12px]!']"
                        :value="value('value')"
                        :disabled="readOnly"
                        :placeholder="$t('refs/heads/main')"
                        @input="
                            set(
                                'value',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <span class="col-span-2 text-[12px]/4 text-(--wf-faint)">
                    {{ ' ' + $t('A webhook’s JSON, or the event’s:') }}
                    <code class="font-ui-mono">outcome</code>,
                    <code class="font-ui-mono">service</code>,
                    <code class="font-ui-mono">machine</code>.
                </span>
            </div>
            <label
                v-if="value('condition') === 'monitor_up'"
                class="flex flex-col gap-1.5"
            >
                <span :class="label">{{ $t('Monitor') }}</span>
                <select
                    :class="field"
                    :value="value('monitor_id')"
                    :disabled="readOnly"
                    @change="
                        set(
                            'monitor_id',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="" disabled>{{ $t('Choose…') }}</option>
                    <option
                        v-for="m in options.monitors"
                        :key="m.id"
                        :value="String(m.id)"
                    >
                        {{ m.name }}
                    </option>
                </select>
            </label>
            <template
                v-if="
                    ['service_running', 'disk_below'].includes(
                        value('condition'),
                    )
                "
            >
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Machine') }}</span>
                    <select
                        :class="field"
                        :value="value('machine_id')"
                        :disabled="readOnly"
                        @change="
                            set(
                                'machine_id',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="" disabled>{{ $t('Choose…') }}</option>
                        <option
                            v-if="canUseTriggerMachine"
                            :value="FROM_TRIGGER"
                        >
                            {{ ' ' + $t('The one from the trigger') + ' ' }}
                        </option>
                        <option
                            v-for="m in options.machines"
                            :key="m.id"
                            :value="String(m.id)"
                        >
                            {{ m.name }}
                        </option>
                    </select>
                </label>
                <label
                    v-if="value('condition') === 'service_running'"
                    class="flex flex-col gap-1.5"
                >
                    <span :class="label">{{ $t('Service') }}</span>
                    <select
                        :class="field"
                        :value="value('service')"
                        :disabled="readOnly"
                        @change="
                            set(
                                'service',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="" disabled>{{ $t('Choose…') }}</option>
                        <option
                            v-if="triggerEvent.service === 'key'"
                            :value="FROM_TRIGGER"
                        >
                            {{ ' ' + $t('The one from the trigger') + ' ' }}
                        </option>
                        <option
                            v-for="s in machine?.services ?? []"
                            :key="s.key"
                            :value="s.key"
                        >
                            {{ s.name }}
                        </option>
                    </select>
                </label>
                <label v-else class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Below') }}</span>
                    <select
                        :class="field"
                        :value="value('percent') || '85'"
                        :disabled="readOnly"
                        @change="
                            set(
                                'percent',
                                Number(
                                    ($event.target as HTMLSelectElement).value,
                                ),
                            )
                        "
                    >
                        <option
                            v-for="p in [50, 70, 80, 85, 90, 95]"
                            :key="p"
                            :value="p"
                        >
                            {{ $t(':p% full', { p: String(p ?? '') }) + ' ' }}
                        </option>
                    </select>
                </label>
            </template>
            <div
                v-if="value('condition') === 'time_between'"
                class="grid grid-cols-2 gap-2"
            >
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('From (UTC)') }}</span>
                    <input
                        type="time"
                        :class="field"
                        :value="value('from') || '09:00'"
                        :disabled="readOnly"
                        @change="
                            set(
                                'from',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('To (UTC)') }}</span>
                    <input
                        type="time"
                        :class="field"
                        :value="value('to') || '17:00'"
                        :disabled="readOnly"
                        @change="
                            set('to', ($event.target as HTMLInputElement).value)
                        "
                    />
                </label>
            </div>
        </template>

        <p
            v-if="STEPS[node.kind].ports.includes('failed')"
            class="text-[12px]/4 text-(--wf-faint)"
        >
            {{ ' ' + $t('If it fails, the run goes down') }}
            <span class="font-semibold text-(--ds-red)">{{ $t('FAILED') }}</span
            >{{ $t('; with nothing there, the run stops as failed.') + ' ' }}
        </p>
    </div>
</template>
