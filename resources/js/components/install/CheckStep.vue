<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import CommandLine from './CommandLine.vue';
import StatusIcon from './StatusIcon.vue';
import StepHeading from './StepHeading.vue';
import type { CheckItem } from './types';

const props = defineProps<{ checks: CheckItem[] }>();

const failed = computed(() =>
    props.checks.some((check) => check.status === 'fail'),
);

const reloading = ref(false);
function checkAgain(): void {
    router.reload({
        onStart: () => (reloading.value = true),
        onFinish: () => (reloading.value = false),
    });
}
</script>

<template>
    <StepHeading
        :title="$t('Check the server')"
        :description="
            $t(
                'What ServerOS needs from this server. Anything in red has to be fixed first; the rest is advice.',
            )
        "
    />

    <ul
        class="flex flex-col divide-y divide-(--ds-divider) rounded-[10px] border border-(--ds-border)"
    >
        <li
            v-for="check in checks"
            :key="check.key"
            class="flex gap-3 px-3.5 py-3"
        >
            <StatusIcon :status="check.status" class="mt-0.5" />
            <div class="flex min-w-0 grow flex-col gap-1.5">
                <p class="text-[13.5px]/5">
                    <span class="font-medium">{{ check.label }}</span>
                    <span class="text-(--ds-text-muted)">
                        {{ ' ' + check.detail }}</span
                    >
                </p>
                <p
                    v-if="check.fix"
                    class="text-[13px]/5 text-(--ds-text-secondary)"
                >
                    {{ check.fix }}
                </p>
                <CommandLine v-if="check.command" :command="check.command" />
            </div>
        </li>
    </ul>

    <Form
        action="/install/check"
        method="post"
        v-slot="{ errors, processing }"
        class="mt-6 flex flex-col gap-3"
    >
        <InputError :message="errors.check" />
        <div class="flex gap-2">
            <button
                type="button"
                :class="appButtonClasses('secondary', 'md')"
                :disabled="reloading"
                @click="checkAgain"
            >
                <Spinner v-if="reloading" />
                {{ $t('Check again') }}
            </button>
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'grow')"
                :disabled="processing || failed"
            >
                <Spinner v-if="processing" />
                {{ $t('Continue') }}
            </button>
        </div>
    </Form>
</template>
