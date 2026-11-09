<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { EVENTS, FROM_TRIGGER } from './kinds';
import type { Options, WorkflowNode } from './kinds';
import { useWorkflowEditor } from './useWorkflowEditor';

/**
 * A "Run agent" step's settings, after the machine and container: what
 * to ask (a preset or your own words), the repository it works in, and
 * whether what it changes becomes a pull request. The rest (model, setup
 * and check commands, time limit) waits under Advanced.
 */
const props = defineProps<{
    node: WorkflowNode;
    options: Options;
    twoFactor: boolean;
}>();

const editor = useWorkflowEditor();
const readOnly = editor.readOnly;

function set(key: string, value: string | number | boolean | null): void {
    editor.setRule(props.node.id, key, value);
}

function value(key: string): string {
    const v = props.node.rules[key];

    return v === null || v === undefined ? '' : String(v);
}

const event = computed(() => {
    const trigger = editor.nodes.value.find((n) => n.kind === 'trigger');

    return (
        EVENTS.find((e) => e.id === (trigger?.rules.event ?? 'manual'))?.id ??
        'manual'
    );
});

/** A failed deploy's own repository is there to work in. */
const deployTrigger = computed(() => event.value === 'deploy.finished');

const fromTrigger = computed(() => value('repo') === FROM_TRIGGER);
const hasRepo = computed(() => value('repo').trim() !== '');
const pullRequest = computed(() => props.node.rules.pull_request === true);

/* The agent's container: picked for you when a machine has one. */
type Box = { machine: number; key: string };
function agentBox(machineId?: number): Box | null {
    for (const m of props.options.machines) {
        if (machineId !== undefined && m.id !== machineId) continue;
        const s =
            m.services.find(
                (s) =>
                    s.container &&
                    s.managed &&
                    /agent/i.test(`${s.name} ${s.key}`),
            ) ?? null;
        if (s) return { machine: m.id, key: s.key };
    }

    return null;
}

onMounted(() => {
    if (readOnly.value || value('service') !== '') return;
    const id = Number(value('machine_id')) || undefined;
    const box = agentBox(id);
    if (!box) return;
    if (id === undefined) set('machine_id', String(box.machine));
    set('service', box.key);
});

watch(
    () => value('machine_id'),
    (id) => {
        if (readOnly.value || value('service') !== '') return;
        const box = agentBox(Number(id));
        if (box) set('service', box.key);
    },
);

const noAgentAnywhere = computed(() => agentBox() === null);

/* One tap to a good prompt; editing it afterwards is fine. */
const presets = computed(() => [
    {
        id: 'fix',
        label: deployTrigger.value ? 'Fix the failed deploy' : 'Fix it',
        pr: true,
        prompt: deployTrigger.value
            ? "The deploy of {{service}} on {{machine}} failed; its build log is below. Work out why. If the cause is in this repository, fix it with the smallest change that makes the build pass. If it isn't (a server problem, a missing setting), change nothing and explain what a person should do."
            : 'Something went wrong: {{trigger}}. Find the cause in this repository and fix it with the smallest change. If it isn’t in the code, change nothing and explain what a person should do.',
    },
    {
        id: 'investigate',
        label: 'Look into it',
        pr: false,
        prompt: 'Look into this: {{trigger}}. Change nothing. Explain the most likely cause, how sure you are, and what a person should do next.',
    },
    {
        id: 'upkeep',
        label: 'Tidy up',
        pr: true,
        prompt: 'Update dependencies with patch and minor releases only, fix anything that breaks, and keep the change small. Don’t touch major versions.',
    },
    {
        id: 'review',
        label: 'Review the code',
        pr: false,
        prompt: 'Review this repository for bugs and security problems. Change nothing. List the most important findings first, each with the file and line and why it matters.',
    },
]);

function usePreset(preset: { pr: boolean; prompt: string }): void {
    set('prompt', preset.prompt);
    set('pull_request', preset.pr && hasRepo.value);
    if (!hasRepo.value && deployTrigger.value && preset.pr) {
        set('repo', FROM_TRIGGER);
        set('pull_request', true);
    }
}

const placeholders =
    '{{service}} {{machine}} {{trigger}} {{data.field}} {{agent.summary}} {{agent.pr}}';

const field = 'wf-field h-9 w-full px-3 text-[13px]/5';
const label = 'text-[12px]/5 font-[550] text-(--wf-muted)';
const hint = 'text-[12px]/4 text-(--wf-faint)';
</script>

<template>
    <p
        v-if="noAgentAnywhere"
        class="rounded-md border border-(--ds-amber)/40 px-3 py-2 text-[12px]/4 text-(--ds-amber)"
    >
        {{
            $t(
                'No machine has a coding agent yet. On a machine, choose Deploy, then the Coding agent template, and add your Anthropic or OpenAI key. It shows up here once it’s running.',
            )
        }}
        <a href="/machines" class="underline">{{ $t('Open Machines') }}</a>
    </p>

    <div class="flex flex-col gap-1.5">
        <span :class="label">{{ $t('What should it do?') }}</span>
        <div v-if="!readOnly" class="flex flex-wrap gap-1.5">
            <button
                v-for="p in presets"
                :key="p.id"
                type="button"
                class="wf-field h-7 px-2.5 text-[12px]/4"
                @click="usePreset(p)"
            >
                {{ $t(p.label) }}
            </button>
        </div>
        <textarea
            class="wf-field min-h-32 w-full px-3 py-2 text-[13px]/5"
            :value="value('prompt')"
            :disabled="readOnly"
            :aria-label="$t('What should it do?')"
            :placeholder="
                $t('Pick a starting point above, or say it in your own words.')
            "
            @input="set('prompt', ($event.target as HTMLTextAreaElement).value)"
        />
        <span :class="hint">
            {{
                ' ' +
                $t(
                    'ServerOS adds what started the run, and a failed deploy’s build log. You can use',
                ) +
                ' '
            }}
            <code class="font-ui-mono">{{ placeholders }}</code
            >.
        </span>
    </div>

    <label class="flex flex-col gap-1.5">
        <span :class="label">{{ $t('Repository') }}</span>
        <select
            v-if="deployTrigger"
            :class="field"
            :value="fromTrigger ? FROM_TRIGGER : hasRepo ? 'other' : ''"
            :disabled="readOnly"
            @change="
                set(
                    'repo',
                    ($event.target as HTMLSelectElement).value === FROM_TRIGGER
                        ? FROM_TRIGGER
                        : ($event.target as HTMLSelectElement).value === ''
                          ? ''
                          : ' ',
                )
            "
        >
            <option value="">{{ $t('None') }}</option>
            <option :value="FROM_TRIGGER">
                {{ $t('The deployed app’s repository') }}
            </option>
            <option value="other">{{ $t('Another repository…') }}</option>
        </select>
        <input
            v-if="!deployTrigger || (hasRepo && !fromTrigger)"
            :class="[field, 'font-ui-mono text-[12px]!']"
            :value="value('repo').trim()"
            :disabled="readOnly"
            placeholder="owner/name"
            spellcheck="false"
            @input="set('repo', ($event.target as HTMLInputElement).value)"
        />
        <span :class="hint">
            {{
                $t(
                    'Cloned fresh for each run, through ServerOS’s GitHub App or the container’s GH_TOKEN. Leave it empty for none.',
                )
            }}
        </span>
    </label>

    <label v-if="hasRepo" class="flex items-start gap-2 text-[13px]/5">
        <input
            type="checkbox"
            class="mt-1"
            :checked="pullRequest"
            :disabled="readOnly"
            @change="
                set('pull_request', ($event.target as HTMLInputElement).checked)
            "
        />
        <span>
            {{ $t('Open a pull request with what it changed') }}
            <span :class="['block', hint]">{{
                $t(
                    'Pushed to a new branch, never merged. The agent never sees the GitHub token.',
                )
            }}</span>
        </span>
    </label>

    <details class="group flex flex-col gap-3">
        <summary
            class="cursor-pointer text-[12px]/5 font-[550] text-(--wf-muted) select-none"
        >
            {{ $t('Advanced') }}
        </summary>
        <div class="mt-3 flex flex-col gap-4">
            <div class="grid grid-cols-2 gap-2">
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Agent') }}</span>
                    <select
                        :class="field"
                        :value="value('agent') || 'claude'"
                        :disabled="readOnly"
                        @change="
                            set(
                                'agent',
                                ($event.target as HTMLSelectElement).value,
                            )
                        "
                    >
                        <option value="claude">Claude Code</option>
                        <option value="codex">Codex</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Model') }}</span>
                    <input
                        :class="[field, 'font-ui-mono text-[12px]!']"
                        :value="value('model')"
                        :disabled="readOnly"
                        :placeholder="
                            value('agent') === 'codex'
                                ? 'gpt-5-codex'
                                : 'sonnet, opus, haiku'
                        "
                        spellcheck="false"
                        @input="
                            set(
                                'model',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
            </div>
            <span :class="['-mt-2', hint]">
                {{
                    value('agent') === 'codex'
                        ? $t(
                              'Uses the workspace’s OpenAI key from Settings › AI and MCP, or the container’s own. Empty model: Codex’s default.',
                          )
                        : $t(
                              'Uses the workspace’s Anthropic key from Settings › AI and MCP, or the container’s own. Empty model: Claude Code’s default.',
                          )
                }}
            </span>

            <template v-if="hasRepo">
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{
                        $t('Setup, before it starts')
                    }}</span>
                    <input
                        :class="[field, 'font-ui-mono text-[12px]!']"
                        :value="value('setup')"
                        :disabled="readOnly"
                        placeholder="npm ci"
                        spellcheck="false"
                        @input="
                            set(
                                'setup',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                </label>
                <label class="flex flex-col gap-1.5">
                    <span :class="label">{{
                        $t('Check that must pass before a pull request')
                    }}</span>
                    <input
                        :class="[field, 'font-ui-mono text-[12px]!']"
                        :value="value('check')"
                        :disabled="readOnly"
                        placeholder="npm test"
                        spellcheck="false"
                        @input="
                            set(
                                'check',
                                ($event.target as HTMLInputElement).value,
                            )
                        "
                    />
                    <span :class="hint">{{
                        $t(
                            'The agent is asked to make it pass; ServerOS runs it again after, and opens nothing if it fails.',
                        )
                    }}</span>
                </label>
                <div class="grid grid-cols-[8rem_minmax(0,1fr)] gap-2">
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
                    <label class="flex flex-col gap-1.5">
                        <span :class="label">{{
                            $t('Pull request title')
                        }}</span>
                        <input
                            :class="field"
                            :value="value('pr_title')"
                            :disabled="readOnly || !pullRequest"
                            :placeholder="$t('Changes from the agent')"
                            @input="
                                set(
                                    'pr_title',
                                    ($event.target as HTMLInputElement).value,
                                )
                            "
                        />
                    </label>
                </div>
            </template>

            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Time limit') }}</span>
                <select
                    :class="field"
                    :value="value('minutes') || '20'"
                    :disabled="readOnly"
                    @change="
                        set(
                            'minutes',
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="m in [5, 10, 20, 30, 45, 60]"
                        :key="m"
                        :value="String(m)"
                    >
                        {{ $t(':count minutes', { count: String(m) }) }}
                    </option>
                </select>
            </label>
        </div>
    </details>

    <span
        class="text-[12px]/4"
        :class="twoFactor ? 'text-(--wf-faint)' : 'text-(--ds-amber)'"
    >
        {{
            twoFactor
                ? $t(
                      'Runs in the container as you, the last person to save, and is stopped when its time is up.',
                  )
                : $t(
                      'Turn on two-factor authentication to save a workflow that runs agents.',
                  )
        }}
    </span>
</template>
