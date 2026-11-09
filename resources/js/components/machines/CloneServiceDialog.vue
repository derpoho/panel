<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';

/**
 * A copy of a container to try things on: the same image, command and
 * environment under a new name, on its own port or domain, with its data
 * copied or starting empty. The original is only read. The clone runs as a
 * job; `started` hands its id to the page to follow.
 */
const open = defineModel<boolean>('open', { required: true });
const props = defineProps<{
    machineId: number;
    serviceKey: string;
    displayName: string;
    /** What the dialog starts with: a free name and port. */
    defaults: { name: string; port: number };
}>();
const emit = defineEmits<{ started: [jobId: string, name: string] }>();

const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';

const name = ref('');
const reach = ref<'port' | 'domain'>('port');
const port = ref<number | undefined>(undefined);
const domain = ref('');
const copyData = ref(true);
const errors = ref<Record<string, string>>({});
const processing = ref(false);

watch(open, (isOpen) => {
    if (!isOpen) return;
    name.value = props.defaults.name;
    reach.value = 'port';
    port.value = props.defaults.port;
    domain.value = '';
    copyData.value = true;
    errors.value = {};
});

async function submit(): Promise<void> {
    processing.value = true;
    errors.value = {};
    try {
        const response = await fetch(
            `/machines/${props.machineId}/services/${encodeURIComponent(props.serviceKey)}/clone`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
                body: JSON.stringify({
                    name: name.value.trim(),
                    port: reach.value === 'port' ? (port.value ?? null) : null,
                    domain:
                        reach.value === 'domain' ? domain.value.trim() : null,
                    copy_data: copyData.value,
                }),
            },
        );
        const body = await response.json().catch(() => ({}));
        if (response.status === 422) {
            errors.value = Object.fromEntries(
                Object.entries(
                    (body.errors ?? {}) as Record<string, string[]>,
                ).map(([key, messages]) => [key, messages[0]]),
            );
            return;
        }
        if (!response.ok) {
            errors.value = {
                name: body.error ?? trans('That didn’t work. Try again.'),
            };
            return;
        }
        emit('started', String(body.jobId), name.value.trim());
        open.value = false;
    } catch {
        errors.value = { name: trans('That didn’t work. Try again.') };
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal max-h-[90dvh] gap-0 overflow-y-auto p-0 font-ui text-foreground sm:max-w-lg"
        >
            <form class="flex flex-col" @submit.prevent="submit">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">
                        {{ $t('Clone :name', { name: displayName }) }}
                    </DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'A copy to try things on, with the same image, command and environment. The original keeps running as it is.',
                            )
                        }}
                    </DialogDescription>
                </div>

                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1.5">
                        <label for="clone-name" :class="labelClass">{{
                            $t('Name')
                        }}</label>
                        <Input
                            id="clone-name"
                            v-model="name"
                            maxlength="63"
                            autocomplete="off"
                            spellcheck="false"
                            required
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Reach it on') }}</span>
                        <div
                            class="ds-segments self-start"
                            role="group"
                            :aria-label="$t('Reach it on')"
                        >
                            <button
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="reach === 'port'"
                                @click="reach = 'port'"
                            >
                                {{ $t('A port') }}
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="reach === 'domain'"
                                @click="reach = 'domain'"
                            >
                                {{ $t('A domain') }}
                            </button>
                        </div>
                        <template v-if="reach === 'port'">
                            <Input
                                v-model.number="port"
                                type="number"
                                min="1024"
                                max="65535"
                                class="w-32"
                                :aria-label="$t('Port')"
                            />
                            <InputError :message="errors.port" />
                        </template>
                        <template v-else>
                            <Input
                                v-model="domain"
                                autocomplete="off"
                                spellcheck="false"
                                :placeholder="$t('test.example.com')"
                                :aria-label="$t('Domain')"
                                required
                            />
                            <p class="text-xs/4 text-(--ds-text-muted)">
                                {{
                                    $t(
                                        'Point its DNS at this machine first. HTTPS is set up for you.',
                                    )
                                }}
                            </p>
                            <InputError :message="errors.domain" />
                        </template>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Data') }}</span>
                        <div
                            class="ds-segments self-start"
                            role="group"
                            :aria-label="$t('Data')"
                        >
                            <button
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="copyData"
                                @click="copyData = true"
                            >
                                {{ $t('Copy its data') }}
                            </button>
                            <button
                                type="button"
                                class="cursor-pointer"
                                :class="focusRing"
                                :aria-pressed="!copyData"
                                @click="copyData = false"
                            >
                                {{ $t('Start empty') }}
                            </button>
                        </div>
                        <p class="text-xs/4 text-(--ds-text-muted)">
                            {{
                                copyData
                                    ? $t(
                                          'Its volumes and folders are copied as they are now. Databases are copied with their own dump tools.',
                                      )
                                    : $t(
                                          'Fresh volumes. Config files it mounts are still copied, so it can start.',
                                      )
                            }}
                        </p>
                    </div>

                    <p
                        class="rounded-md bg-(--ds-active) px-3 py-2 text-xs/4 text-(--ds-text-secondary)"
                    >
                        {{
                            $t(
                                'It joins the same networks, so it reaches the same databases and services as the original. If it shouldn’t write to them, change its settings once it’s running.',
                            )
                        }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <span class="grow" />
                    <AppButton size="sm" variant="quiet" @click="open = false">
                        {{ $t('Cancel') }}
                    </AppButton>
                    <SubmitButton
                        :label="$t('Clone')"
                        busy-label="Cloning…"
                        :processing="processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
