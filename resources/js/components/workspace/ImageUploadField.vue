<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import AppButton from '@/components/workspace/AppButton.vue';

type Props = {
    /** Form field the server expects the file in. */
    field: string;
    label: string;
    imageUrl: string | null;
    /** Shown when there's no image, e.g. an initial. */
    fallback: string;
    uploadUrl: string;
    removeUrl: string;
    shape?: 'square' | 'round';
    disabled?: boolean;
    /** Hide the label when the surrounding row already shows it. */
    hideLabel?: boolean;
    /** The largest file the server takes, as shown, e.g. "2 MB". */
    maxSize?: string;
    accept?: string;
    /** In place of the usual note on file types and size. */
    hint?: string;
    /** Keep what's typed in the rest of the page's form across the upload. */
    preserveState?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    shape: 'square',
    disabled: false,
    hideLabel: false,
    maxSize: '5 MB',
    accept: 'image/png,image/jpeg,image/webp,image/gif',
    hint: undefined,
    preserveState: false,
});

const page = usePage();
const fileInput = useTemplateRef<HTMLInputElement>('fileInput');
const uploading = ref(false);

function upload(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    router.post(
        props.uploadUrl,
        { [props.field]: file },
        {
            forceFormData: true,
            preserveScroll: true,
            preserveState: props.preserveState,
            onStart: () => (uploading.value = true),
            onFinish: () => {
                uploading.value = false;

                if (fileInput.value) {
                    fileInput.value.value = '';
                }
            },
        },
    );
}

function remove(): void {
    router.delete(props.removeUrl, {
        preserveScroll: true,
        preserveState: props.preserveState,
    });
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <span
            :class="
                hideLabel
                    ? 'sr-only'
                    : 'text-[13px]/4 font-medium text-(--ds-text-secondary)'
            "
            >{{ label }}</span
        >
        <div class="flex items-center gap-4">
            <div
                class="flex size-16 shrink-0 items-center justify-center overflow-hidden border border-(--ds-border-strong) bg-(--ds-hover) text-xl font-semibold text-(--ds-text-secondary)"
                :class="shape === 'round' ? 'rounded-full' : 'rounded-[10px]'"
            >
                <img
                    v-if="imageUrl"
                    :src="imageUrl"
                    alt=""
                    class="size-full object-cover"
                />
                <span v-else aria-hidden="true">{{ fallback }}</span>
            </div>

            <div v-if="!disabled" class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <label
                        :class="[
                            appButtonClasses('secondary', 'xs'),
                            'focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring)',
                        ]"
                    >
                        <Spinner v-if="uploading" />
                        {{ imageUrl ? $t('Replace') : $t('Upload') }}
                        <input
                            ref="fileInput"
                            type="file"
                            :name="field"
                            :accept="accept"
                            class="sr-only"
                            :disabled="uploading"
                            @change="upload"
                        />
                    </label>
                    <AppButton
                        v-if="imageUrl"
                        variant="danger"
                        size="xs"
                        @click="remove"
                    >
                        {{ ' ' + $t('Remove') + ' ' }}
                    </AppButton>
                </div>
                <p class="text-xs/4 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        (hint ??
                            $t('PNG, JPG, WebP or GIF, up to :maxSize.', {
                                maxSize,
                            })) +
                        ' '
                    }}
                </p>
            </div>
        </div>
        <InputError :message="page.props.errors[field]" />
    </div>
</template>
