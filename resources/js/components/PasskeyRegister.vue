<script setup lang="ts">
import { usePasskeyRegister } from '@laravel/passkeys/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Plus } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';

const emit = defineEmits<{
    success: [];
}>();

const getDefaultPasskeyName = () => {
    const ua = navigator.userAgent;

    const browser = [
        { pattern: /Edg|Edge/, name: 'Edge' },
        { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
        { pattern: /Firefox|FxiOS/, name: 'Firefox' },
        { pattern: /Chrome|CriOS/, name: 'Chrome' },
        { pattern: /Safari/, name: 'Safari' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    const os = [
        { pattern: /iPhone/, name: 'iPhone' },
        { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
        { pattern: /Android/, name: 'Android' },
        { pattern: /Mac/, name: 'Mac' },
        { pattern: /Windows/, name: 'Windows' },
    ].find(({ pattern }) => pattern.test(ua))?.name;

    return [browser, os].filter(Boolean).join(' on ') || '';
};

const name = ref(getDefaultPasskeyName());
const showForm = ref(false);

const { register, isLoading, error, isSupported } = usePasskeyRegister({
    onSuccess: () => {
        name.value = '';
        showForm.value = false;
        emit('success');
    },
});

const handleSubmit = async (event: Event) => {
    event.preventDefault();

    if (!name.value.trim()) {
        return;
    }

    await register(name.value);
};

const handleCancel = () => {
    showForm.value = false;
    name.value = '';
};
</script>

<template>
    <p v-if="!isSupported" class="text-[13px]/5 text-(--ds-text-muted)">
        {{ ' ' + $t('This browser can’t use passkeys.') + ' ' }}
    </p>

    <AppButton v-else-if="!showForm" @click="showForm = true">
        <Plus class="size-3.5" :stroke-width="2" />
        {{ $t('Add a passkey') + ' ' }}
    </AppButton>

    <form v-else class="flex flex-col gap-3" @submit="handleSubmit">
        <div class="flex flex-col gap-1.5">
            <label for="passkey-name" class="text-[13px]/5 font-medium">{{
                $t('Name')
            }}</label>
            <Input
                id="passkey-name"
                v-model="name"
                type="text"
                :placeholder="$t('MacBook Pro, iPhone')"
                class="max-w-sm"
                v-focus
            />
            <p class="text-xs text-(--ds-text-muted)">
                {{
                    ' ' + $t('So you can tell your passkeys apart later.') + ' '
                }}
            </p>
        </div>

        <InputError v-if="error" :message="error" />

        <div class="flex gap-2">
            <AppButton
                type="submit"
                variant="primary"
                :disabled="isLoading || !name.trim()"
            >
                {{
                    isLoading
                        ? $t('Waiting for your device…')
                        : $t('Add passkey')
                }}
            </AppButton>
            <AppButton variant="quiet" @click="handleCancel">{{
                $t('Cancel')
            }}</AppButton>
        </div>
    </form>
</template>
