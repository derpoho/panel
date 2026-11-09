<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import { computed } from 'vue';
import { LARAVEL_STEPS } from './kinds';
import type { Options, ReleaseCommand, WorkflowNode } from './kinds';
import { useWorkflowEditor } from './useWorkflowEditor';

/**
 * A "Release app" step's settings: where the app lives on the machine,
 * the repository it comes from, and the commands a release runs.
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

const machine = computed(() =>
    props.options.machines.find((m) => String(m.id) === value('machine_id')),
);

/** Apps on the machine run under systemd units, not in containers. */
const services = computed(() =>
    (machine.value?.services ?? []).filter((s) => !s.container),
);

const pushTrigger = computed(
    () =>
        editor.nodes.value.find((n) => n.kind === 'trigger')?.rules.event ===
        'git.push',
);

const commands = computed<ReleaseCommand[]>(() => {
    try {
        const parsed = JSON.parse(value('steps') || '[]');

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
});

function save(list: ReleaseCommand[]): void {
    set('steps', JSON.stringify(list));
}

function update(i: number, change: Partial<ReleaseCommand>): void {
    save(commands.value.map((c, j) => (j === i ? { ...c, ...change } : c)));
}

const atomic = computed(() => value('mode') === 'atomic');

/** A list rule: one per line or comma separated. */
function list(key: string): string[] {
    return value(key)
        .split(/[\n,]+/)
        .map((v) => v.trim())
        .filter(Boolean);
}

function toggle(key: string, service: string, on: boolean): void {
    const rest = list(key).filter((k) => k !== service);
    set(key, (on ? [...rest, service] : rest).join('\n'));
}

const field = 'wf-field h-9 w-full px-3 text-[13px]/5';
const label = 'text-[12px]/5 font-[550] text-(--wf-muted)';
const hint = 'text-[12px]/4 text-(--wf-faint)';
</script>

<template>
    <label class="flex flex-col gap-1.5">
        <span :class="label">{{ $t('Machine') }}</span>
        <select
            :class="field"
            :value="value('machine_id')"
            :disabled="readOnly"
            @change="
                set('machine_id', ($event.target as HTMLSelectElement).value);
                set('service', '');
                set('reload', '');
                set('restart', '');
            "
        >
            <option value="" disabled>{{ $t('Choose a machine…') }}</option>
            <option
                v-for="m in options.machines"
                :key="m.id"
                :value="String(m.id)"
            >
                {{ m.name }}
            </option>
        </select>
    </label>

    <div class="flex flex-col gap-1.5">
        <span :class="label">{{ $t('Goes live') }}</span>
        <div class="flex gap-1 text-[12.5px]/4">
            <button
                v-for="option in [
                    { value: 'atomic', label: $t('Build beside, then switch') },
                    { value: 'in_place', label: $t('Update in place') },
                ]"
                :key="option.value"
                type="button"
                class="cursor-pointer rounded-md px-2 py-1"
                :class="
                    (value('mode') || 'in_place') === option.value
                        ? 'bg-(--wf-hover) font-medium text-(--wf-text)'
                        : 'text-(--wf-muted) hover:text-(--wf-text)'
                "
                :disabled="readOnly"
                @click="set('mode', option.value)"
            >
                {{ option.label }}
            </button>
        </div>
        <span :class="hint">{{
            atomic
                ? $t(
                      'Each release is built in its own folder beside the app, and the app switches to it in one step: no half-updated moment, a failed build changes nothing, and going back is instant.',
                  )
                : $t(
                      'The app’s folder is updated where it is, so it runs half-updated while the commands run. A failure puts the last commit back and runs the commands marked On rollback.',
                  )
        }}</span>
    </div>

    <div class="grid grid-cols-[minmax(0,1fr)_8rem] gap-2">
        <label class="flex flex-col gap-1.5">
            <span :class="label">{{ $t('Folder') }}</span>
            <input
                :class="[field, 'font-ui-mono text-[12px]!']"
                :value="value('path')"
                :disabled="readOnly"
                placeholder="/var/www/app"
                spellcheck="false"
                @input="set('path', ($event.target as HTMLInputElement).value)"
            />
        </label>
        <label class="flex flex-col gap-1.5">
            <span :class="label">{{ $t('Runs as') }}</span>
            <input
                :class="[field, 'font-ui-mono text-[12px]!']"
                :value="value('user')"
                :disabled="readOnly"
                placeholder="www-data"
                spellcheck="false"
                @input="set('user', ($event.target as HTMLInputElement).value)"
            />
        </label>
    </div>
    <span :class="[hint, '-mt-2']">{{
        $t(
            'A git checkout of the app. Every command runs there as this user, never root.',
        )
    }}</span>

    <label class="flex flex-col gap-1.5">
        <span :class="label">{{ $t('Runs under') }}</span>
        <select
            :class="field"
            :value="value('service')"
            :disabled="readOnly || !value('machine_id')"
            @change="set('service', ($event.target as HTMLSelectElement).value)"
        >
            <option value="" disabled>{{ $t('Choose…') }}</option>
            <option
                v-for="s in services"
                :key="s.key"
                :value="s.key"
                :disabled="!s.managed"
            >
                {{ s.name }}{{ s.managed ? '' : ' ' + $t('(not managed)') }}
            </option>
        </select>
        <span :class="hint">{{
            $t(
                'The adopted service that serves the app, like PHP-FPM. ServerOS only releases into folders it already looks after.',
            )
        }}</span>
    </label>

    <div class="grid grid-cols-[minmax(0,1fr)_8rem] gap-2">
        <label class="flex flex-col gap-1.5">
            <span :class="label">{{ $t('Repository') }}</span>
            <input
                :class="[field, 'font-ui-mono text-[12px]!']"
                :value="value('repo')"
                :disabled="readOnly"
                placeholder="owner/app"
                spellcheck="false"
                @input="set('repo', ($event.target as HTMLInputElement).value)"
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
                    set('branch', ($event.target as HTMLInputElement).value)
                "
            />
        </label>
    </div>
    <span :class="[hint, '-mt-2']">{{
        pushTrigger
            ? $t(
                  'Releases the pushed commit when it’s this repository and branch, otherwise the branch’s latest.',
              )
            : $t('Releases the branch’s latest commit.')
    }}</span>

    <div class="flex flex-col gap-1.5">
        <div class="flex items-center justify-between">
            <span :class="label">{{ $t('Commands') }}</span>
            <button
                v-if="!readOnly"
                type="button"
                class="text-[12px]/4 text-(--wf-muted) hover:text-(--wf-text)"
                @click="save(LARAVEL_STEPS)"
            >
                {{ $t('Use Laravel’s') }}
            </button>
        </div>
        <ul
            v-if="commands.length"
            class="flex flex-col divide-y divide-(--wf-line) overflow-hidden rounded-md border border-(--wf-line)"
        >
            <li
                v-for="(command, i) in commands"
                :key="i"
                class="flex items-center gap-2 px-2 py-1.5"
            >
                <span
                    class="w-4 shrink-0 text-right text-[11px] text-(--wf-faint) tabular-nums"
                    >{{ i + 1 }}</span
                >
                <input
                    class="min-w-0 grow bg-transparent font-ui-mono text-[12px]/5 outline-none"
                    :value="command.run"
                    :disabled="readOnly"
                    spellcheck="false"
                    @input="
                        update(i, {
                            run: ($event.target as HTMLInputElement).value,
                        })
                    "
                />
                <label
                    v-if="!atomic"
                    class="flex shrink-0 items-center gap-1 text-[11px]/4 text-(--wf-muted)"
                    :title="
                        $t(
                            'Run it again on the old commit when a release fails and is put back. Leave migrations off.',
                        )
                    "
                >
                    <input
                        type="checkbox"
                        :checked="command.again"
                        :disabled="readOnly"
                        @change="
                            update(i, {
                                again: ($event.target as HTMLInputElement)
                                    .checked,
                            })
                        "
                    />
                    {{ $t('On rollback') }}
                </label>
                <button
                    v-if="!readOnly"
                    type="button"
                    class="shrink-0 text-(--wf-faint) hover:text-(--wf-text)"
                    :aria-label="$t('Remove')"
                    @click="save(commands.filter((_, j) => j !== i))"
                >
                    <X class="size-3.5" />
                </button>
            </li>
        </ul>
        <button
            v-if="!readOnly && commands.length < 12"
            type="button"
            class="wf-button self-start"
            @click="save([...commands, { run: '', again: true }])"
        >
            <Plus class="size-3.5" />
            {{ $t('Add a command') }}
        </button>
        <span :class="hint">{{
            atomic
                ? $t(
                      'In order, in the new release’s folder, before it goes live. If one fails, the live app is never touched.',
                  )
                : $t(
                      'In order, after the checkout. If one fails, ServerOS checks out the last commit again and runs the ones marked On rollback.',
                  )
        }}</span>
    </div>

    <div class="flex flex-col gap-1.5">
        <span :class="label">{{ $t('Then') }}</span>
        <ul
            v-if="services.some((s) => s.managed)"
            class="flex flex-col divide-y divide-(--wf-line) overflow-hidden rounded-md border border-(--wf-line)"
        >
            <li
                v-for="s in services.filter((s) => s.managed)"
                :key="s.key"
                class="flex items-center gap-3 px-2.5 py-1.5 text-[12.5px]/5"
            >
                <span class="min-w-0 grow truncate">{{ s.name }}</span>
                <label
                    v-for="action in ['reload', 'restart'] as const"
                    :key="action"
                    class="flex shrink-0 items-center gap-1 text-[11.5px]/4 text-(--wf-muted)"
                >
                    <input
                        type="checkbox"
                        :checked="list(action).includes(s.key)"
                        :disabled="readOnly"
                        @change="
                            toggle(
                                action,
                                s.key,
                                ($event.target as HTMLInputElement).checked,
                            )
                        "
                    />
                    {{ action === 'reload' ? $t('Reload') : $t('Restart') }}
                </label>
            </li>
        </ul>
        <span :class="hint">{{
            atomic
                ? $t(
                      'Reload PHP-FPM so it serves the new release, and restart queue workers and the scheduler so they run it too.',
                  )
                : $t(
                      'PHP-FPM, so it stops serving the old code from its cache.',
                  )
        }}</span>
    </div>

    <template v-if="atomic">
        <label class="flex flex-col gap-1.5">
            <span :class="label">{{ $t('Shared by every release') }}</span>
            <textarea
                class="wf-field min-h-14 w-full px-3 py-2 font-ui-mono text-[12px]/5"
                :value="value('shared')"
                :disabled="readOnly"
                placeholder=".env&#10;storage"
                spellcheck="false"
                @input="
                    set('shared', ($event.target as HTMLTextAreaElement).value)
                "
            />
            <span :class="hint">{{
                $t(
                    'One per line, inside the app. The first release moves them out of the app’s folder; every release after links to them.',
                )
            }}</span>
        </label>
        <div class="grid grid-cols-[minmax(0,1fr)_7rem] gap-2">
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Drop from old releases') }}</span>
                <input
                    :class="[field, 'font-ui-mono text-[12px]!']"
                    :value="value('trim')"
                    :disabled="readOnly"
                    placeholder="node_modules"
                    spellcheck="false"
                    @input="
                        set('trim', ($event.target as HTMLInputElement).value)
                    "
                />
            </label>
            <label class="flex flex-col gap-1.5">
                <span :class="label">{{ $t('Keep') }}</span>
                <select
                    :class="field"
                    :value="value('keep') || '3'"
                    :disabled="readOnly"
                    @change="
                        set(
                            'keep',
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option
                        v-for="n in [2, 3, 5, 10]"
                        :key="n"
                        :value="String(n)"
                    >
                        {{ $t(':count releases', { count: String(n) }) }}
                    </option>
                </select>
            </label>
        </div>
        <span :class="[hint, '-mt-2']">{{
            $t(
                'Kept releases are what you can roll back to in a moment. Dropping node_modules from them saves disk once their assets are built.',
            )
        }}</span>
    </template>

    <label class="flex flex-col gap-1.5">
        <span :class="label">{{ $t('Health check') }}</span>
        <input
            :class="[field, 'font-ui-mono text-[12px]!']"
            :value="value('health_url')"
            :disabled="readOnly"
            placeholder="https://example.com/up"
            spellcheck="false"
            @input="
                set('health_url', ($event.target as HTMLInputElement).value)
            "
        />
        <span :class="hint">{{
            $t(
                'Optional. If it doesn’t answer 2xx after the release, the last commit goes back.',
            )
        }}</span>
    </label>

    <label v-if="!atomic" class="flex items-start gap-2 text-[12.5px]/5">
        <input
            type="checkbox"
            class="mt-1"
            :checked="node.rules.discard_changes === true"
            :disabled="readOnly"
            @change="
                set(
                    'discard_changes',
                    ($event.target as HTMLInputElement).checked,
                )
            "
        />
        <span>
            {{ $t('Discard edits made on the machine') }}
            <span :class="['block', hint]">{{
                $t(
                    'Off, a release stops if someone edited a tracked file on the server, so nothing is lost.',
                )
            }}</span>
        </span>
    </label>

    <span v-if="!twoFactor" class="text-[12px]/4 text-(--ds-amber)">{{
        $t(
            'Turn on two-factor authentication to save a workflow that releases apps.',
        )
    }}</span>
</template>
