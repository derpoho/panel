<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import StepHeading from './StepHeading.vue';
import { stepUrl } from './types';
import type { Summary } from './types';

/* What's set up, then finishing: the account is made and the installer goes. */
defineProps<{ summary: Summary }>();
</script>

<template>
    <StepHeading
        :title="$t('Ready to finish')"
        :description="
            $t(
                'Finishing makes your account and workspace, and closes this installer for good. The setup code stops working.',
            )
        "
    />

    <dl
        class="flex flex-col divide-y divide-(--ds-divider) rounded-[10px] border border-(--ds-border) text-[13.5px]/5"
    >
        <div class="flex gap-4 px-3.5 py-2.5">
            <dt class="w-24 shrink-0 text-(--ds-text-muted)">
                {{ $t('Address') }}
            </dt>
            <dd class="min-w-0 break-words">{{ summary.address }}</dd>
        </div>
        <div class="flex gap-4 px-3.5 py-2.5">
            <dt class="w-24 shrink-0 text-(--ds-text-muted)">
                {{ $t('Database') }}
            </dt>
            <dd class="min-w-0 break-words">{{ summary.database }}</dd>
        </div>
        <div class="flex gap-4 px-3.5 py-2.5">
            <dt class="w-24 shrink-0 text-(--ds-text-muted)">
                {{ $t('Email') }}
            </dt>
            <dd class="min-w-0 break-words">
                {{ summary.mail ?? $t('Not set up: emails go to the log') }}
            </dd>
        </div>
        <div class="flex gap-4 px-3.5 py-2.5">
            <dt class="w-24 shrink-0 text-(--ds-text-muted)">
                {{ $t('Owner') }}
            </dt>
            <dd class="min-w-0 break-words">
                {{ summary.owner.name }}
                <span class="text-(--ds-text-muted)">{{
                    ' ' + summary.owner.email
                }}</span>
            </dd>
        </div>
        <div class="flex gap-4 px-3.5 py-2.5">
            <dt class="w-24 shrink-0 text-(--ds-text-muted)">
                {{ $t('Workspace') }}
            </dt>
            <dd class="min-w-0 break-words">
                {{ summary.workspace.name }}
                <span class="text-(--ds-text-muted)">{{
                    ' ' + summary.workspace.address
                }}</span>
            </dd>
        </div>
    </dl>

    <Form
        action="/install/finish"
        method="post"
        v-slot="{ errors, processing }"
        class="mt-6 flex flex-col gap-3"
    >
        <InputError :message="errors.email ?? errors.step" />
        <div class="flex gap-2">
            <Link
                :href="stepUrl('owner')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'grow')"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                {{ $t('Finish setup') }}
            </button>
        </div>
    </Form>
</template>
