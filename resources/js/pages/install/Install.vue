<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import AddressStep from '@/components/install/AddressStep.vue';
import CheckStep from '@/components/install/CheckStep.vue';
import CodeStep from '@/components/install/CodeStep.vue';
import DatabaseStep from '@/components/install/DatabaseStep.vue';
import DoneStep from '@/components/install/DoneStep.vue';
import MailStep from '@/components/install/MailStep.vue';
import OwnerStep from '@/components/install/OwnerStep.vue';
import type {
    AddressProps,
    CheckItem,
    DatabaseProps,
    InstallStep,
    MailProps,
    OwnerProps,
    Summary,
} from '@/components/install/types';

/*
 * A self-hosted install's web installer (App\Http\Controllers\InstallController):
 * the setup code, then one plain card per step. A utility surface: the
 * sign-up pages' card and mark, none of their decoration.
 */
const props = defineProps<{
    step: InstallStep;
    current: InstallStep;
    progress: Record<InstallStep, boolean>;
    docker: boolean;
    codeCommand: string;
    checks?: CheckItem[];
    database?: DatabaseProps;
    address?: AddressProps;
    mail?: MailProps;
    owner?: OwnerProps;
    summary?: Summary;
}>();

const steps = computed<{ key: InstallStep; label: string }[]>(() => [
    { key: 'check', label: trans('Server') },
    { key: 'database', label: trans('Database') },
    { key: 'address', label: trans('Address') },
    { key: 'mail', label: trans('Email') },
    { key: 'owner', label: trans('Account') },
    { key: 'done', label: trans('Finish') },
]);

const index = computed(() =>
    steps.value.findIndex((step) => step.key === props.step),
);
</script>

<template>
    <Head :title="$t('Set up ServerOS')" />
    <div
        class="ds-root flex min-h-svh flex-col bg-(--ds-frame) font-ui text-(--ds-text) antialiased"
    >
        <main class="flex grow justify-center px-4 py-12 sm:items-center">
            <div class="w-full max-w-[480px]">
                <span
                    class="mx-auto mb-6 flex w-fit items-center gap-2.5 text-(--ds-text-strong)"
                >
                    <span
                        aria-hidden="true"
                        class="size-[20px] bg-current [mask:url(/images/landing/serveros-logo.svg)_center/contain_no-repeat]"
                    />
                    <span class="text-[17px]/6 font-semibold tracking-[-0.02em]"
                        >ServerOS</span
                    >
                </span>

                <div
                    class="ds-modal workspace-panel overflow-hidden rounded-[12px]! px-6 pt-6 pb-6 sm:px-8 sm:pt-7"
                >
                    <!-- Where you are, once the code is in -->
                    <div
                        v-if="step !== 'code'"
                        class="mb-6 flex items-center gap-1.5"
                    >
                        <span
                            v-for="(item, position) in steps"
                            :key="item.key"
                            class="h-[3px] flex-1 rounded-full transition-colors duration-300"
                            :class="
                                position <= index
                                    ? 'bg-(--ds-blue)'
                                    : 'bg-(--ds-border-strong)'
                            "
                        />
                        <span
                            class="pl-1.5 text-[12.5px]/4 whitespace-nowrap text-(--ds-text-muted)"
                        >
                            {{
                                $t('Step :step of :total', {
                                    step: String(index + 1),
                                    total: String(steps.length),
                                })
                            }}
                        </span>
                    </div>

                    <CodeStep
                        v-if="step === 'code'"
                        :command="codeCommand"
                        :docker="docker"
                    />
                    <CheckStep
                        v-else-if="step === 'check'"
                        :checks="checks ?? []"
                    />
                    <DatabaseStep
                        v-else-if="step === 'database' && database"
                        :database="database"
                        :docker="docker"
                    />
                    <AddressStep
                        v-else-if="step === 'address' && address"
                        :address="address"
                        :docker="docker"
                    />
                    <MailStep
                        v-else-if="step === 'mail' && mail"
                        :mail="mail"
                        :docker="docker"
                    />
                    <OwnerStep
                        v-else-if="step === 'owner' && owner"
                        :owner="owner"
                    />
                    <DoneStep
                        v-else-if="step === 'done' && summary"
                        :summary="summary"
                    />
                </div>
            </div>
        </main>
    </div>
</template>
