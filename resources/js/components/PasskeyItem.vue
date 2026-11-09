<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { SolarKeyRound as KeyRound } from '@/lib/solarIcons';
import { ref } from 'vue';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import type { Passkey } from '@/types/auth';

const props = defineProps<{
    passkey: Passkey;
}>();

const emit = defineEmits<{
    remove: [id: number, onError: () => void];
}>();

const isDeleting = ref(false);

const handleDelete = () => {
    isDeleting.value = true;
    emit('remove', props.passkey.id, () => {
        isDeleting.value = false;
    });
};
</script>

<template>
    <div class="flex items-center justify-between gap-4 px-4.5 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <span class="ds-icon-chip" aria-hidden="true">
                <KeyRound class="size-4" :stroke-width="2" />
            </span>
            <div class="flex min-w-0 flex-col gap-0.5">
                <div class="flex items-center gap-2">
                    <span class="truncate text-sm font-medium">{{
                        passkey.name
                    }}</span>
                    <span
                        v-if="passkey.authenticator"
                        class="rounded-md bg-(--ds-hover) px-1.5 py-0.5 text-[11px]/4 text-(--ds-text-secondary)"
                    >
                        {{ passkey.authenticator }}
                    </span>
                </div>
                <span class="text-xs text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t('Added :created_at_diff', {
                            created_at_diff: passkey.created_at_diff,
                        })
                    }}
                    <template v-if="passkey.last_used_at_diff">
                        {{
                            ' ' +
                            $t('· last used :last_used_at_diff', {
                                last_used_at_diff: passkey.last_used_at_diff,
                            })
                        }}
                    </template>
                </span>
            </div>
        </div>

        <Dialog>
            <DialogTrigger as-child>
                <AppButton variant="danger" size="sm">
                    <Trash2 class="size-3.5" :stroke-width="1.75" />
                    <span class="sr-only">{{
                        $t('Remove :name', { name: passkey.name })
                    }}</span>
                </AppButton>
            </DialogTrigger>

            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogTitle>{{
                    $t('Remove :name?', { name: passkey.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        ' ' +
                        $t('You won’t be able to sign in with it any more.') +
                        ' '
                    }}
                </DialogDescription>
                <div class="flex justify-end gap-2">
                    <DialogClose as-child>
                        <AppButton size="sm">{{ $t('Cancel') }}</AppButton>
                    </DialogClose>
                    <DangerButton
                        :label="$t('Remove passkey')"
                        busy-label="Removing…"
                        :processing="isDeleting"
                        @click="handleDelete"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
