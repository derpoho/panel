<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import {
    SolarCloud as Cloud,
    SolarHardDrive as HardDrive,
} from '@/lib/solarIcons';
import {
    siBackblaze,
    siCloudflare,
    siDigitalocean,
    siHetzner,
    siWasabi,
} from 'simple-icons';
import type { SimpleIcon } from 'simple-icons';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import MorphHeight from '@/components/workspace/MorphHeight.vue';
import PickerMenu from '@/components/workspace/PickerMenu.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';

export type BackupDestination = {
    id: number;
    name: string;
    type: 's3' | 'machine';
    where: string;
    machineId: number | null;
    status: 'ready' | 'setting_up' | 'failed';
    statusMessage: string | null;
    machines: number;
    settings: {
        endpoint: string;
        region: string;
        bucket: string;
        prefix: string;
        pathStyle: boolean;
        accessKey: string;
    } | null;
};

/**
 * Add or edit a place backups go: an S3-compatible bucket (checked with a
 * test upload when saved) or another of the workspace's machines.
 */
const props = defineProps<{
    machines: { id: number; name: string; online: boolean }[];
    editing?: BackupDestination | null;
    /** Opens on this kind when adding. */
    initialType?: 's3' | 'machine';
}>();

const open = defineModel<boolean>('open', { default: false });

type Provider = {
    value: string;
    label: string;
    region: string;
    endpoint: (region: string) => string;
    pathStyle: boolean;
    regionHint?: string;
    /** Its logo, where simple-icons has one. */
    logo?: SimpleIcon;
    /** A shorter name for its tile, when the full one doesn't fit. */
    tile?: string;
};

const providers: Provider[] = [
    {
        value: 'aws',
        label: trans('Amazon S3'),
        region: 'eu-west-2',
        endpoint: (r) => `s3.${r}.amazonaws.com`,
        pathStyle: false,
    },
    {
        value: 'r2',
        logo: siCloudflare,
        label: trans('Cloudflare R2'),
        region: 'auto',
        endpoint: () => '',
        pathStyle: true,
    },
    {
        value: 'b2',
        logo: siBackblaze,
        label: trans('Backblaze B2'),
        region: 'us-west-004',
        endpoint: (r) => `s3.${r}.backblazeb2.com`,
        pathStyle: true,
    },
    {
        value: 'hetzner',
        logo: siHetzner,
        label: trans('Hetzner'),
        region: 'fsn1',
        endpoint: (r) => `${r}.your-objectstorage.com`,
        pathStyle: true,
        regionHint: trans('fsn1, nbg1 or hel1'),
    },
    {
        value: 'spaces',
        logo: siDigitalocean,
        label: trans('DigitalOcean Spaces'),
        tile: trans('DigitalOcean'),
        region: 'ams3',
        endpoint: (r) => `${r}.digitaloceanspaces.com`,
        pathStyle: false,
    },
    {
        value: 'wasabi',
        logo: siWasabi,
        label: trans('Wasabi'),
        region: 'eu-central-1',
        endpoint: (r) => `s3.${r}.wasabisys.com`,
        pathStyle: false,
    },
    {
        value: 'other',
        label: trans('Other'),
        region: 'us-east-1',
        endpoint: () => '',
        pathStyle: true,
    },
];

const type = ref<'s3' | 'machine'>('s3');
const provider = ref('aws');
const form = ref({
    name: '',
    endpoint: '',
    region: '',
    bucket: '',
    access_key: '',
    secret_key: '',
    prefix: '',
    path_style: false,
    machine_id: null as number | null,
});
const errors = ref<Record<string, string>>({});
/** Endpoint and path-style, which the provider fills in, shown when asked. */
const showEndpoint = ref(false);
const processing = ref(false);

const preset = computed(
    () => providers.find((p) => p.value === provider.value) ?? providers[0],
);

watch(open, (isOpen) => {
    if (!isOpen) return;

    errors.value = {};
    showEndpoint.value = false;
    const editing = props.editing;
    type.value = editing?.type ?? props.initialType ?? 's3';

    if (editing?.settings) {
        provider.value = 'other';
        form.value = {
            ...form.value,
            name: editing.name,
            endpoint: editing.settings.endpoint,
            region: editing.settings.region,
            bucket: editing.settings.bucket,
            access_key: editing.settings.accessKey,
            secret_key: '',
            prefix: editing.settings.prefix,
            path_style: editing.settings.pathStyle,
        };
    } else if (editing) {
        form.value = { ...form.value, name: editing.name };
    } else {
        provider.value = 'aws';
        form.value = {
            name: '',
            endpoint: '',
            region: '',
            bucket: '',
            access_key: '',
            secret_key: '',
            prefix: '',
            path_style: false,
            machine_id: props.machines.find((m) => m.online)?.id ?? null,
        };
        choosePreset();
    }
});

/** Fill in what the provider fixes; keep what the person typed. */
function choosePreset(): void {
    form.value.region = preset.value.region;
    form.value.endpoint = preset.value.endpoint(preset.value.region);
    form.value.path_style = preset.value.pathStyle;
}

/** R2 and other stores need their own endpoint; the rest follow the region. */
const endpointNeeded = computed(
    () =>
        !!props.editing ||
        showEndpoint.value ||
        ['r2', 'other'].includes(provider.value),
);

const machineOptions = computed(() =>
    props.machines.map((machine) => ({
        value: machine.id as number | null,
        label: machine.name,
        hint: machine.online ? undefined : trans('Offline'),
        disabled: !machine.online,
    })),
);

function pickProvider(value: string): void {
    provider.value = value;
    choosePreset();
}

function onRegion(): void {
    const endpoint = preset.value.endpoint(form.value.region.trim());
    if (endpoint) form.value.endpoint = endpoint;
}

const chosenMachine = computed(() =>
    props.machines.find((m) => m.id === form.value.machine_id),
);

/** A sensible name, unless one was typed. */
const placeholderName = computed(() =>
    type.value === 's3'
        ? `${preset.value.value === 'other' ? 'Bucket' : preset.value.label}${form.value.bucket ? ` (${form.value.bucket})` : ''}`
        : chosenMachine.value
          ? `${chosenMachine.value.name} backups`
          : trans('Backup machine'),
);

function submit(): void {
    const editing = props.editing;
    const payload = {
        ...form.value,
        type: type.value,
        name: form.value.name.trim() || placeholderName.value,
    };
    const options = {
        preserveScroll: true,
        preserveState: true,
        onStart: () => (processing.value = true),
        onFinish: () => (processing.value = false),
        onError: (bag: Record<string, string>) => (errors.value = bag),
        onSuccess: () => (open.value = false),
    };

    if (editing) {
        router.put(`/backup-destinations/${editing.id}`, payload, options);
    } else {
        router.post('/backup-destinations', payload, options);
    }
}

const labelClass = 'text-[12.5px]/4 text-(--ds-text-muted)';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal max-h-[90vh] gap-0 overflow-y-auto p-0 font-ui text-foreground sm:max-w-lg"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{
                            editing
                                ? $t('Edit :name', {
                                      name: String(editing.name),
                                  })
                                : $t('Add a destination')
                        }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                "Somewhere off the machine, so a dead disk or a bad deploy doesn't take your backups with it.",
                            )
                        }}
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-4 p-5">
                    <div
                        v-if="!editing"
                        class="ds-segments w-full!"
                        role="radiogroup"
                        :aria-label="$t('Kind of destination')"
                    >
                        <button
                            v-for="option in [
                                {
                                    value: 's3' as const,
                                    label: $t('S3 bucket'),
                                    icon: Cloud,
                                },
                                {
                                    value: 'machine' as const,
                                    label: $t('Another machine'),
                                    icon: HardDrive,
                                },
                            ]"
                            :key="option.value"
                            type="button"
                            role="radio"
                            :aria-checked="type === option.value"
                            :aria-pressed="type === option.value"
                            class="flex-1 cursor-pointer justify-center gap-2"
                            @click="type = option.value"
                        >
                            <component
                                :is="option.icon"
                                class="size-3.5 opacity-70"
                                :stroke-width="1.75"
                            />
                            {{ option.label }}
                        </button>
                    </div>

                    <MorphHeight>
                        <Transition name="morph" mode="out-in">
                            <div
                                v-if="type === 's3'"
                                key="s3"
                                class="flex flex-col gap-4"
                            >
                                <div
                                    v-if="!editing"
                                    class="flex flex-col gap-1.5"
                                >
                                    <span :class="labelClass">{{
                                        $t('Provider')
                                    }}</span>
                                    <div
                                        class="grid grid-cols-2 gap-1.5 sm:grid-cols-3"
                                        role="radiogroup"
                                        :aria-label="$t('Provider')"
                                    >
                                        <button
                                            v-for="option in providers"
                                            :key="option.value"
                                            type="button"
                                            role="radio"
                                            :aria-checked="
                                                provider === option.value
                                            "
                                            class="flex h-9 cursor-pointer items-center gap-2 rounded-[8px] border px-2.5 text-left text-[13px]/4 transition-colors"
                                            :class="
                                                provider === option.value
                                                    ? 'border-(--ds-text-muted) bg-(--ds-subtle) text-(--ds-text)'
                                                    : 'border-(--ds-border) text-(--ds-text-secondary) hover:border-(--ds-border-hover) hover:text-(--ds-text)'
                                            "
                                            @click="pickProvider(option.value)"
                                        >
                                            <svg
                                                v-if="option.logo"
                                                viewBox="0 0 24 24"
                                                class="size-3.5 shrink-0"
                                                :style="{
                                                    color: `#${option.logo.hex}`,
                                                }"
                                                fill="currentColor"
                                                aria-hidden="true"
                                            >
                                                <path :d="option.logo.path" />
                                            </svg>
                                            <Cloud
                                                v-else
                                                class="size-3.5 shrink-0 text-(--ds-text-muted)"
                                                :stroke-width="1.75"
                                                aria-hidden="true"
                                            />
                                            <span class="truncate">{{
                                                option.tile ?? option.label
                                            }}</span>
                                        </button>
                                    </div>
                                </div>

                                <div
                                    class="grid gap-4"
                                    :class="
                                        endpointNeeded
                                            ? 'sm:grid-cols-[1fr_9rem]'
                                            : ''
                                    "
                                >
                                    <div
                                        v-if="endpointNeeded"
                                        class="flex flex-col gap-1.5"
                                    >
                                        <label
                                            for="dest-endpoint"
                                            :class="labelClass"
                                            >{{ $t('Endpoint') }}</label
                                        >
                                        <Input
                                            id="dest-endpoint"
                                            v-model="form.endpoint"
                                            :placeholder="
                                                provider === 'r2'
                                                    ? '<account id>.r2.cloudflarestorage.com'
                                                    : 's3.example.com'
                                            "
                                            autocomplete="off"
                                            spellcheck="false"
                                            class="font-ui-mono text-[13px]"
                                            required
                                        />
                                        <InputError
                                            :message="errors.endpoint"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            for="dest-region"
                                            :class="labelClass"
                                            >{{ $t('Region') }}</label
                                        >
                                        <Input
                                            id="dest-region"
                                            v-model="form.region"
                                            :placeholder="
                                                preset.regionHint ?? 'us-east-1'
                                            "
                                            autocomplete="off"
                                            spellcheck="false"
                                            class="font-ui-mono text-[13px]"
                                            required
                                            @change="onRegion"
                                        />
                                        <p
                                            v-if="!endpointNeeded"
                                            class="text-[12.5px]/4 text-(--ds-text-faint)"
                                        >
                                            {{ $t('Sends to') }}
                                            <span class="font-ui-mono">{{
                                                form.endpoint
                                            }}</span>
                                            ·
                                            <button
                                                type="button"
                                                class="cursor-pointer rounded-sm text-(--ds-text-muted) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                                @click="showEndpoint = true"
                                            >
                                                {{ $t('Change endpoint') }}
                                            </button>
                                        </p>
                                        <InputError :message="errors.region" />
                                    </div>
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            for="dest-bucket"
                                            :class="labelClass"
                                            >{{ $t('Bucket') }}</label
                                        >
                                        <Input
                                            id="dest-bucket"
                                            v-model="form.bucket"
                                            :placeholder="$t('my-backups')"
                                            autocomplete="off"
                                            spellcheck="false"
                                            class="font-ui-mono text-[13px]"
                                            required
                                        />
                                        <InputError :message="errors.bucket" />
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            for="dest-prefix"
                                            :class="labelClass"
                                        >
                                            {{ $t('Folder') }}
                                            <span
                                                class="text-(--ds-text-faint)"
                                                >{{ $t('optional') }}</span
                                            >
                                        </label>
                                        <Input
                                            id="dest-prefix"
                                            v-model="form.prefix"
                                            :placeholder="$t('serveros')"
                                            autocomplete="off"
                                            spellcheck="false"
                                            class="font-ui-mono text-[13px]"
                                        />
                                        <InputError :message="errors.prefix" />
                                    </div>
                                </div>

                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            for="dest-access"
                                            :class="labelClass"
                                            >{{ $t('Access key') }}</label
                                        >
                                        <Input
                                            id="dest-access"
                                            v-model="form.access_key"
                                            autocomplete="off"
                                            spellcheck="false"
                                            class="font-ui-mono text-[13px]"
                                            required
                                        />
                                        <InputError
                                            :message="errors.access_key"
                                        />
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label
                                            for="dest-secret"
                                            :class="labelClass"
                                            >{{ $t('Secret key') }}</label
                                        >
                                        <Input
                                            id="dest-secret"
                                            v-model="form.secret_key"
                                            type="password"
                                            autocomplete="new-password"
                                            :placeholder="
                                                editing ? $t('Unchanged') : ''
                                            "
                                            :required="!editing"
                                            class="font-ui-mono text-[13px]"
                                        />
                                        <InputError
                                            :message="errors.secret_key"
                                        />
                                    </div>
                                </div>

                                <label
                                    v-if="endpointNeeded"
                                    class="flex cursor-pointer items-center gap-2 text-[13px]/5 text-(--ds-text-secondary)"
                                >
                                    <input
                                        v-model="form.path_style"
                                        type="checkbox"
                                        class="size-3.5 accent-(--ds-text)"
                                    />
                                    {{ $t('Path-style URLs') }}
                                    <span class="text-(--ds-text-faint)">{{
                                        $t(
                                            'most stores other than AWS want this',
                                        )
                                    }}</span>
                                </label>

                                <p
                                    class="text-[12.5px]/4.5 text-(--ds-text-faint)"
                                >
                                    {{
                                        $t(
                                            'Use keys that can only write to this bucket. Saving uploads a small test file to check they work.',
                                        )
                                    }}
                                </p>
                            </div>

                            <div
                                v-else-if="!editing"
                                key="machine"
                                class="flex flex-col gap-4"
                            >
                                <div class="flex flex-col gap-1.5">
                                    <label
                                        for="dest-machine"
                                        :class="labelClass"
                                        >{{ $t('Machine') }}</label
                                    >
                                    <PickerMenu
                                        v-if="machines.length"
                                        id="dest-machine"
                                        v-model="form.machine_id"
                                        :options="machineOptions"
                                        :placeholder="
                                            machines.some((m) => m.online)
                                                ? $t('Pick a machine')
                                                : $t('No machines online')
                                        "
                                    />
                                    <InputError :message="errors.machine_id" />
                                </div>
                                <p
                                    class="text-[12.5px]/4.5 text-(--ds-text-faint)"
                                >
                                    {{ $t('ServerOS adds a') }}
                                    <code
                                        class="font-ui-mono text-(--ds-text-muted)"
                                        >serveros-backups</code
                                    >
                                    {{
                                        $t(
                                            'user to it that can only receive files over SFTP, into',
                                        )
                                    }}
                                    <code
                                        class="font-ui-mono text-(--ds-text-muted)"
                                        >/var/lib/serveros-backups</code
                                    >{{
                                        $t(
                                            '. Other machines send to it over SSH, so port 22 needs to be open to them.',
                                        )
                                    }}
                                </p>
                            </div>
                        </Transition>
                    </MorphHeight>

                    <div class="flex flex-col gap-1.5">
                        <label for="dest-name" :class="labelClass">{{
                            $t('Name')
                        }}</label>
                        <Input
                            id="dest-name"
                            v-model="form.name"
                            maxlength="60"
                            :placeholder="placeholderName"
                            autocomplete="off"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <p
                        v-if="errors.probe"
                        role="alert"
                        class="rounded-[10px] bg-(--ds-red)/8 px-3 py-2.5 text-[13px]/5 text-(--ds-red) ring-1 ring-(--ds-red)/20"
                    >
                        {{ errors.probe }}
                    </p>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton size="sm" variant="quiet" @click="open = false">
                        {{ ' ' + $t('Cancel') + ' ' }}
                    </AppButton>
                    <SubmitButton
                        :label="
                            editing
                                ? $t('Save')
                                : type === 's3'
                                  ? $t('Check and add')
                                  : $t('Set it up')
                        "
                        :busy-label="
                            type === 's3' ? $t('Checking…') : $t('Setting up…')
                        "
                        :processing="processing"
                        :disabled="
                            type === 'machine' && !editing && !form.machine_id
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
