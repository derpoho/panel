<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { trans } from 'laravel-vue-i18n';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowUp, ArrowUpRight, Check, X } from '@lucide/vue';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import EmbedOptions from '@/components/status/EmbedOptions.vue';
import ImageUploadField from '@/components/workspace/ImageUploadField.vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SettingsLine from '@/components/workspace/SettingsLine.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { appButtonClasses } from '@/lib/appButton';
import type { Workspace } from '@/types';

// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

/**
 * One status page for every machine (Business): each machine is a section,
 * showing the services its own status page settings pick.
 */
const props = defineProps<{
    workspace: Workspace;
    canManage: boolean;
    planAllows: boolean;
    host: string;
    page: {
        exists: boolean;
        enabled: boolean;
        public: boolean;
        slug: string;
        title: string | null;
        /** The browser tab's, when not the name. */
        pageTitle: string | null;
        /** Beside the name; the workspace logo when there's none. */
        iconUrl: string | null;
        faviconUrl: string | null;
        description: string | null;
        notice: string | null;
        noticeKind: string;
        showUptime: boolean;
        /** Machine ids, or null for all of them. */
        machines: number[] | null;
        /** Machine groups whose machines it shows, as they come and go. */
        machineGroups: number[];
        /** How a machine with no page of its own shows. */
        newMachines: 'machine' | 'services';
        layout: {
            sections: { name: string; components: string[] }[];
            headings: Record<string, string>;
            order: number[];
        };
        /** Uptime monitors on no machine that it shows. */
        monitors: number[];
        /** People who confirmed email updates. */
        subscribers: number;
        live: boolean;
    };
    machines: {
        id: number;
        name: string;
        online: boolean;
        hasOwnSettings: boolean;
    }[];
    groups: { id: number; name: string }[];
    /** What the page can show, to arrange: keys as the layout names them. */
    components: {
        key: string;
        label: string;
        machine: string;
        machineId: number;
    }[];
    /** Uptime monitors on no machine, like a site hosted elsewhere. */
    monitors: {
        id: number;
        name: string;
        target: string | null;
        paused: boolean;
    }[];
}>();

const form = useForm({
    enabled: props.page.enabled,
    public: props.page.public,
    slug: props.page.slug,
    title: props.page.title ?? '',
    page_title: props.page.pageTitle ?? '',
    description: props.page.description ?? '',
    notice: props.page.notice ?? '',
    notice_kind: props.page.noticeKind,
    show_uptime: props.page.showUptime,
    every_machine: props.page.machines === null,
    machines: props.page.machines ?? props.machines.map((m) => m.id),
    machine_groups: props.page.machineGroups,
    new_machines: props.page.newMachines,
    monitors: props.page.monitors,
    layout: {
        sections: props.page.layout.sections.map((section) => ({
            name: section.name,
            components: [...section.components],
        })),
        headings: { ...props.page.layout.headings },
        order: initialOrder(),
    },
});

/** The machines on the page, in the order it shows them. */
function initialOrder(): number[] {
    const onPage = [...new Set(props.components.map((c) => c.machineId))];
    const saved = props.page.layout.order.filter((id) => onPage.includes(id));

    return [...saved, ...onPage.filter((id) => !saved.includes(id))];
}

form.transform(({ every_machine, machines, ...data }) => ({
    ...data,
    machines: every_machine ? null : machines,
}));

const editable = computed(
    () => props.canManage && (props.planAllows || props.page.enabled),
);

function save(): void {
    form.put('/settings/status-page', {
        preserveScroll: true,
        onSuccess: () => form.defaults(),
    });
}

function pickMonitor(id: number, on: boolean): void {
    form.monitors = on
        ? [...new Set([...form.monitors, id])]
        : form.monitors.filter((m) => m !== id);
}

function pickGroup(id: number, on: boolean): void {
    form.machine_groups = on
        ? [...new Set([...form.machine_groups, id])]
        : form.machine_groups.filter((g) => g !== id);
}

/* The layout: sections of its own, then the machines' */

const componentByKey = computed(
    () => new Map(props.components.map((c) => [c.key, c])),
);
const used = computed(
    () => new Set(form.layout.sections.flatMap((s) => s.components)),
);
const machineNames = computed(
    () => new Map(props.components.map((c) => [c.machineId, c.machine])),
);
/** What's left to add, grouped by machine. */
const available = computed(() => {
    const groups = new Map<string, typeof props.components>();
    for (const component of props.components) {
        if (used.value.has(component.key)) continue;
        groups.set(component.machine, [
            ...(groups.get(component.machine) ?? []),
            component,
        ]);
    }

    return [...groups.entries()];
});

function addSection(): void {
    form.layout.sections.push({ name: '', components: [] });
}

function moveSection(index: number, by: -1 | 1): void {
    const sections = form.layout.sections;
    const target = index + by;
    if (target < 0 || target >= sections.length) return;
    [sections[index], sections[target]] = [sections[target], sections[index]];
}

function addComponent(index: number, event: Event): void {
    const select = event.target as HTMLSelectElement;
    if (select.value) form.layout.sections[index].components.push(select.value);
    select.value = '';
}

function moveMachine(index: number, by: -1 | 1): void {
    const order = form.layout.order;
    const target = index + by;
    if (target < 0 || target >= order.length) return;
    [order[index], order[target]] = [order[target], order[index]];
}

function pick(id: number, on: boolean): void {
    form.machines = on
        ? [...new Set([...form.machines, id])]
        : form.machines.filter((m) => m !== id);
}

const url = computed(() => `https://${props.host}/status/${form.slug}`);

function tidySlug(): void {
    form.slug = form.slug
        .toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');
}

const copied = ref(false);

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(url.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1600);
    } catch {
        // Clipboard blocked; the address is still on screen.
    }
}

const noticeKinds = [
    { value: 'info', label: trans('Notice') },
    { value: 'maintenance', label: trans('Maintenance') },
    { value: 'incident', label: trans('Incident') },
] as const;

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Status page')" />

    <form class="flex flex-col gap-5" @submit.prevent="save">
        <div
            v-if="!planAllows"
            class="ds-tile flex flex-wrap items-center justify-between gap-3 px-5 py-4"
        >
            <p class="text-[13px]/5 text-(--ds-text-secondary)">
                {{
                    ' ' +
                    $t(
                        'One status page for every machine comes with Business. On Pro, each machine can have its own, from its Settings.',
                    ) +
                    ' '
                }}
            </p>
            <Link
                v-if="upgrade"
                :href="upgrade.href"
                :class="appButtonClasses('primary', 'sm')"
            >
                {{ ' ' + $t('See plans') + ' ' }}
            </Link>
        </div>

        <fieldset
            class="ds-tile"
            :disabled="!editable"
            :class="{ 'opacity-60': !editable }"
        >
            <legend class="sr-only">{{ $t('Fleet status page') }}</legend>
            <div class="ds-tile-rows">
                <SettingsLine
                    :label="$t('Status page')"
                    :hint="
                        page.live
                            ? $t(
                                  'Live at :host/status/:slug, with a section for each machine.',
                                  {
                                      host: String(host),
                                      slug: String(page.slug),
                                  },
                              )
                            : $t(
                                  'One page for your whole fleet, a section for each machine. Off until you turn it on.',
                              )
                    "
                >
                    <div class="flex h-9 items-center gap-3">
                        <ToggleSwitch
                            v-model="form.enabled"
                            :label="$t('Fleet status page on')"
                        />
                        <span class="text-[13px]/5 text-(--ds-text-muted)">
                            {{ form.enabled ? $t('On') : $t('Off') }}
                        </span>
                        <a
                            v-if="page.live"
                            :href="url"
                            target="_blank"
                            rel="noopener"
                            class="ml-1 flex items-center gap-1 text-[13px]/5 font-medium text-(--ds-blue) hover:underline"
                        >
                            {{ ' ' + $t('Open') }}
                            <ArrowUpRight class="size-3.5" :stroke-width="2" />
                        </a>
                    </div>
                    <InputError :message="form.errors.enabled" />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Visibility')"
                    :hint="
                        form.public
                            ? $t('Anyone with the link, no sign-in.')
                            : $t('Only members of this workspace, signed in.')
                    "
                >
                    <div
                        class="ds-segments w-fit"
                        role="group"
                        :aria-label="$t('Visibility')"
                    >
                        <button
                            type="button"
                            class="cursor-pointer"
                            :class="focusRing"
                            :aria-pressed="form.public"
                            @click="form.public = true"
                        >
                            {{ ' ' + $t('Public') + ' ' }}
                        </button>
                        <button
                            type="button"
                            class="cursor-pointer"
                            :class="focusRing"
                            :aria-pressed="!form.public"
                            @click="form.public = false"
                        >
                            {{ ' ' + $t('Workspace only') + ' ' }}
                        </button>
                    </div>
                </SettingsLine>

                <SettingsLine :label="$t('Address')" input-id="fleet-slug">
                    <div class="flex max-w-xl items-center gap-2">
                        <div
                            class="flex h-9 min-w-0 grow items-center overflow-hidden rounded-md border border-input bg-(--ds-field) focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50"
                        >
                            <span
                                class="shrink-0 pl-3 font-ui-mono text-[13px] text-(--ds-text-faint)"
                                >{{ $t(':host/status/', { host }) }}</span
                            >
                            <input
                                id="fleet-slug"
                                v-model="form.slug"
                                class="h-full min-w-0 grow bg-transparent pr-3 font-ui-mono text-[13px] text-(--ds-text) outline-none"
                                maxlength="60"
                                spellcheck="false"
                                autocomplete="off"
                                @blur="tidySlug"
                            />
                        </div>
                        <AppButton
                            size="sm"
                            variant="quiet"
                            :aria-label="$t('Copy the address')"
                            @click="copy"
                        >
                            <Check
                                v-if="copied"
                                class="mi-pop size-3.5 text-(--ds-green)"
                                :stroke-width="2.25"
                            />
                            <Copy
                                v-else
                                class="mi-copy size-3.5"
                                :stroke-width="1.75"
                            />
                        </AppButton>
                    </div>
                    <InputError :message="form.errors.slug" />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Name')"
                    :hint="
                        $t('At the top of the page. Defaults to :name.', {
                            name: String(workspace.name),
                        })
                    "
                    input-id="fleet-title"
                >
                    <Input
                        id="fleet-title"
                        v-model="form.title"
                        maxlength="80"
                        class="max-w-md"
                        :placeholder="workspace.name"
                    />
                    <InputError :message="form.errors.title" />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Page title')"
                    :hint="
                        $t(
                            'In the browser tab and link previews. Defaults to the name.',
                        )
                    "
                    input-id="fleet-page-title"
                >
                    <Input
                        id="fleet-page-title"
                        v-model="form.page_title"
                        maxlength="120"
                        class="max-w-md"
                        :placeholder="form.title || workspace.name"
                    />
                    <InputError :message="form.errors.page_title" />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Icon')"
                    :hint="
                        $t('Beside the name. Defaults to the workspace logo.')
                    "
                >
                    <ImageUploadField
                        field="icon"
                        :label="$t('Icon')"
                        hide-label
                        preserve-state
                        max-size="2 MB"
                        :image-url="page.iconUrl"
                        :fallback="
                            (form.title || workspace.name)
                                .charAt(0)
                                .toUpperCase()
                        "
                        upload-url="/settings/status-page/icon"
                        remove-url="/settings/status-page/icon"
                        :disabled="!editable"
                    />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Favicon')"
                    :hint="$t('The little picture in the browser tab.')"
                >
                    <ImageUploadField
                        field="favicon"
                        :label="$t('Favicon')"
                        hide-label
                        preserve-state
                        accept="image/png,image/x-icon,image/vnd.microsoft.icon,image/jpeg,image/webp,image/gif"
                        :hint="
                            $t(
                                'A square PNG or ICO, up to 1 MB. 64 by 64 or bigger looks sharpest.',
                            )
                        "
                        :image-url="page.faviconUrl"
                        fallback=""
                        upload-url="/settings/status-page/favicon"
                        remove-url="/settings/status-page/favicon"
                        :disabled="!editable"
                    />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Description')"
                    :hint="$t('A line under the name.')"
                    input-id="fleet-description"
                >
                    <Input
                        id="fleet-description"
                        v-model="form.description"
                        maxlength="280"
                        class="max-w-xl"
                        :placeholder="$t('Everything we run, in one place')"
                    />
                    <InputError :message="form.errors.description" />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Notice')"
                    :hint="
                        $t('Shown at the top of the page until you clear it.')
                    "
                    input-id="fleet-notice"
                >
                    <div
                        class="ds-segments w-fit"
                        role="group"
                        :aria-label="$t('Kind of notice')"
                    >
                        <button
                            v-for="kind in noticeKinds"
                            :key="kind.value"
                            type="button"
                            class="cursor-pointer"
                            :class="focusRing"
                            :aria-pressed="form.notice_kind === kind.value"
                            @click="form.notice_kind = kind.value"
                        >
                            {{ kind.label }}
                        </button>
                    </div>
                    <textarea
                        id="fleet-notice"
                        v-model="form.notice"
                        rows="2"
                        maxlength="500"
                        class="mt-2 w-full max-w-xl rounded-md border border-input bg-(--ds-field) px-3 py-2 text-[13px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus:border-ring focus:ring-[3px] focus:ring-ring/50"
                        placeholder="Nothing to say right now."
                    />
                    <InputError :message="form.errors.notice" />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Uptime history')"
                    :hint="$t('90 days of uptime bars for each service.')"
                >
                    <div class="flex h-9 items-center gap-3">
                        <ToggleSwitch
                            v-model="form.show_uptime"
                            :label="$t('Show uptime history')"
                        />
                    </div>
                </SettingsLine>

                <SettingsLine
                    :label="$t('Machines')"
                    :hint="
                        $t(
                            'Which services each one shows is set on its own status page, in the machine’s Settings. It doesn’t need to be turned on.',
                        )
                    "
                >
                    <label
                        class="flex cursor-pointer items-center gap-2.5 text-[13px]/5 select-none"
                    >
                        <TickBox v-model="form.every_machine" />
                        {{
                            $t(
                                'Every machine, including ones connected later',
                            ) + ' '
                        }}
                    </label>
                    <div class="mt-3 flex flex-wrap items-center gap-2.5">
                        <span class="text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                $t('A machine with no page of its own shows as')
                            }}
                        </span>
                        <div
                            class="ds-segments"
                            role="group"
                            :aria-label="
                                $t('A machine with no page of its own shows as')
                            "
                        >
                            <button
                                type="button"
                                class="cursor-pointer px-2.5! py-1! text-[13px]!"
                                :class="focusRing"
                                :aria-pressed="form.new_machines === 'machine'"
                                @click="form.new_machines = 'machine'"
                            >
                                {{ $t('One row') }}
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer px-2.5! py-1! text-[13px]!"
                                :class="focusRing"
                                :aria-pressed="form.new_machines === 'services'"
                                @click="form.new_machines = 'services'"
                            >
                                {{ $t('Its running services') }}
                            </button>
                        </div>
                    </div>
                    <div
                        v-if="!form.every_machine && groups.length"
                        class="mt-3 max-w-xl"
                    >
                        <p class="mb-1.5 text-[13px]/5 text-(--ds-text-muted)">
                            {{
                                $t(
                                    'And every machine in these groups, including ones added to them later:',
                                )
                            }}
                        </p>
                        <div class="flex flex-wrap gap-x-4 gap-y-1.5">
                            <label
                                v-for="group in groups"
                                :key="group.id"
                                class="flex cursor-pointer items-center gap-2 text-[13px]/5 select-none"
                            >
                                <TickBox
                                    :model-value="
                                        form.machine_groups.includes(group.id)
                                    "
                                    @update:model-value="
                                        (on: boolean) => pickGroup(group.id, on)
                                    "
                                />
                                {{ group.name }}
                            </label>
                        </div>
                    </div>
                    <ul
                        v-if="!form.every_machine"
                        class="mt-3 max-w-xl divide-y divide-(--ds-divider) overflow-hidden rounded-[10px] border border-(--ds-border)"
                    >
                        <li v-for="machine in machines" :key="machine.id">
                            <label
                                class="flex cursor-pointer items-center gap-3 px-3.5 py-2 text-[13px]/5 hover:bg-(--ds-row-hover)"
                            >
                                <TickBox
                                    :model-value="
                                        form.machines.includes(machine.id)
                                    "
                                    @update:model-value="
                                        (on: boolean) => pick(machine.id, on)
                                    "
                                />
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="
                                        machine.online
                                            ? 'bg-(--ds-green)'
                                            : 'bg-(--ds-text-faint)'
                                    "
                                    aria-hidden="true"
                                />
                                <span class="grow truncate font-medium">{{
                                    machine.name
                                }}</span>
                                <Link
                                    :href="`/machines/${machine.id}/settings/status`"
                                    class="shrink-0 text-xs text-(--ds-text-muted) hover:text-(--ds-text) hover:underline"
                                    @click.stop
                                >
                                    {{
                                        machine.hasOwnSettings
                                            ? $t('Pick services')
                                            : form.new_machines === 'services'
                                              ? $t('Running services')
                                              : $t('One row')
                                    }}
                                </Link>
                            </label>
                        </li>
                        <li
                            v-if="machines.length === 0"
                            class="px-3.5 py-3 text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{ ' ' + $t('No machines connected yet.') + ' ' }}
                        </li>
                    </ul>
                    <InputError :message="form.errors.machines" />
                </SettingsLine>

                <SettingsLine
                    :label="$t('Layout')"
                    :hint="
                        $t(
                            'Sections of your own come first and can take services from any machine, like Game nodes · London. The rest show under their machine, with the heading and order you give.',
                        )
                    "
                >
                    <p
                        v-if="!page.exists"
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{ $t('Save the page first, then arrange it here.') }}
                    </p>
                    <div v-else class="flex max-w-xl flex-col gap-4">
                        <div
                            v-for="(section, index) in form.layout.sections"
                            :key="index"
                            class="flex flex-col gap-2.5 rounded-[10px] border border-(--ds-border) p-3"
                        >
                            <div class="flex items-center gap-2">
                                <Input
                                    v-model="section.name"
                                    maxlength="60"
                                    class="h-8 grow text-[13px]"
                                    :placeholder="$t('Section name')"
                                    :aria-label="$t('Section name')"
                                />
                                <button
                                    type="button"
                                    :class="
                                        appButtonClasses(
                                            'quiet',
                                            'sm',
                                            'px-1.5!',
                                        )
                                    "
                                    :aria-label="$t('Move up')"
                                    :disabled="index === 0"
                                    @click="moveSection(index, -1)"
                                >
                                    <ArrowUp class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    :class="
                                        appButtonClasses(
                                            'quiet',
                                            'sm',
                                            'px-1.5!',
                                        )
                                    "
                                    :aria-label="$t('Move down')"
                                    :disabled="
                                        index ===
                                        form.layout.sections.length - 1
                                    "
                                    @click="moveSection(index, 1)"
                                >
                                    <ArrowDown class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    :class="
                                        appButtonClasses(
                                            'quiet',
                                            'sm',
                                            'px-1.5!',
                                        )
                                    "
                                    :aria-label="$t('Remove section')"
                                    @click="
                                        form.layout.sections.splice(index, 1)
                                    "
                                >
                                    <X class="size-3.5" />
                                </button>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <span
                                    v-for="(key, at) in section.components"
                                    :key="key"
                                    class="inline-flex items-center gap-1 rounded-md bg-(--ds-hover) py-0.5 pr-1 pl-2 text-[12.5px]/5"
                                >
                                    {{ componentByKey.get(key)?.label ?? key }}
                                    <span class="text-(--ds-text-faint)">
                                        · {{ componentByKey.get(key)?.machine }}
                                    </span>
                                    <button
                                        type="button"
                                        class="flex size-4 cursor-pointer items-center justify-center rounded text-(--ds-text-faint) hover:text-(--ds-text)"
                                        :aria-label="$t('Remove')"
                                        @click="
                                            section.components.splice(at, 1)
                                        "
                                    >
                                        <X class="size-3" />
                                    </button>
                                </span>
                                <select
                                    class="h-7 cursor-pointer rounded-md border border-(--ds-border) bg-(--ds-field) px-2 text-[12.5px]/5 text-(--ds-text-muted)"
                                    :aria-label="$t('Add to this section')"
                                    @change="addComponent(index, $event)"
                                >
                                    <option value="">
                                        {{ $t('Add…') }}
                                    </option>
                                    <optgroup
                                        v-for="[machine, list] in available"
                                        :key="machine"
                                        :label="machine"
                                    >
                                        <option
                                            v-for="component in list"
                                            :key="component.key"
                                            :value="component.key"
                                        >
                                            {{ component.label }}
                                        </option>
                                    </optgroup>
                                </select>
                            </div>
                        </div>
                        <div>
                            <AppButton size="sm" @click="addSection">
                                {{ $t('Add a section') }}
                            </AppButton>
                        </div>

                        <p
                            v-if="form.layout.order.length"
                            class="-mb-2 text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{ $t('Machines: their headings and order') }}
                        </p>
                        <ul
                            v-if="form.layout.order.length"
                            class="divide-y divide-(--ds-divider) overflow-hidden rounded-[10px] border border-(--ds-border)"
                        >
                            <li
                                v-for="(id, index) in form.layout.order"
                                :key="id"
                                class="flex items-center gap-2 px-3 py-2"
                            >
                                <Input
                                    v-model="form.layout.headings[String(id)]"
                                    maxlength="60"
                                    class="h-8 grow text-[13px]"
                                    :placeholder="machineNames.get(id)"
                                    :aria-label="
                                        $t('Heading for :name', {
                                            name: String(
                                                machineNames.get(id) ?? '',
                                            ),
                                        })
                                    "
                                />
                                <button
                                    type="button"
                                    :class="
                                        appButtonClasses(
                                            'quiet',
                                            'sm',
                                            'px-1.5!',
                                        )
                                    "
                                    :aria-label="$t('Move up')"
                                    :disabled="index === 0"
                                    @click="moveMachine(index, -1)"
                                >
                                    <ArrowUp class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    :class="
                                        appButtonClasses(
                                            'quiet',
                                            'sm',
                                            'px-1.5!',
                                        )
                                    "
                                    :aria-label="$t('Move down')"
                                    :disabled="
                                        index === form.layout.order.length - 1
                                    "
                                    @click="moveMachine(index, 1)"
                                >
                                    <ArrowDown class="size-3.5" />
                                </button>
                            </li>
                        </ul>
                        <InputError :message="form.errors.layout" />
                    </div>
                </SettingsLine>

                <SettingsLine
                    :label="$t('Websites')"
                    :hint="
                        $t(
                            'Uptime monitors that aren’t on a machine, like a site hosted somewhere else. They show first, with their own uptime.',
                        )
                    "
                >
                    <ul
                        v-if="monitors.length"
                        class="max-w-xl divide-y divide-(--ds-divider) overflow-hidden rounded-[10px] border border-(--ds-border)"
                    >
                        <li v-for="monitor in monitors" :key="monitor.id">
                            <label
                                class="flex cursor-pointer items-center gap-3 px-3.5 py-2 text-[13px]/5 hover:bg-(--ds-row-hover)"
                            >
                                <TickBox
                                    :model-value="
                                        form.monitors.includes(monitor.id)
                                    "
                                    @update:model-value="
                                        (on: boolean) =>
                                            pickMonitor(monitor.id, on)
                                    "
                                />
                                <span class="grow truncate font-medium">{{
                                    monitor.name
                                }}</span>
                                <span
                                    class="shrink-0 truncate text-xs text-(--ds-text-muted)"
                                    >{{
                                        monitor.paused
                                            ? $t('Paused')
                                            : monitor.target
                                    }}</span
                                >
                            </label>
                        </li>
                    </ul>
                    <p v-else class="text-[13px]/5 text-(--ds-text-muted)">
                        {{ ' '
                        }}{{ $t('No uptime monitors outside a machine yet.') }}
                        <Link
                            href="/monitors"
                            class="text-(--ds-text) underline-offset-2 hover:underline"
                            >{{ $t('Add one') }}</Link
                        >
                    </p>
                    <InputError :message="form.errors.monitors" />
                </SettingsLine>

                <SettingsLine
                    v-if="page.live && page.public"
                    :label="$t('Subscribers')"
                    :hint="
                        $t(
                            'Visitors can ask for an email when something goes wrong and when it’s fixed, from Get updates on the page.',
                        )
                    "
                >
                    <p class="text-[13px]/5">
                        {{
                            page.subscribers === 1
                                ? $t('1 person gets email updates')
                                : $t(':count people get email updates', {
                                      count: String(page.subscribers),
                                  })
                        }}
                    </p>
                </SettingsLine>

                <SettingsLine
                    v-if="page.live && page.public"
                    :label="$t('Embed')"
                    :hint="
                        $t(
                            'Show it on your own site as a widget, put a live badge in a README, or read the same status as JSON.',
                        )
                    "
                >
                    <EmbedOptions
                        :url="`https://${host}/status/${page.slug}`"
                        :title="page.title || workspace.name"
                    />
                </SettingsLine>
            </div>
        </fieldset>

        <div v-if="canManage" class="flex justify-end">
            <SubmitButton
                :label="$t('Save')"
                busy-label="Saving…"
                :processing="form.processing"
                :disabled="!editable || !form.isDirty"
            />
        </div>
    </form>
</template>
