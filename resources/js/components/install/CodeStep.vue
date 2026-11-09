<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import CommandLine from './CommandLine.vue';
import StepHeading from './StepHeading.vue';

/* The setup code first: only someone who can get into the server has it. */
defineProps<{ command: string; docker: boolean }>();
</script>

<template>
    <StepHeading
        :title="$t('Set up ServerOS')"
        :description="
            $t(
                'Enter the setup code to start. Only someone who can get into this server has it.',
            )
        "
    />

    <Form
        action="/install/code"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-2">
            <Label for="code">{{ $t('Setup code') }}</Label>
            <Input
                id="code"
                name="code"
                required
                v-focus
                autocomplete="off"
                autocapitalize="characters"
                spellcheck="false"
                placeholder="ABCD-EFGH-JKMN"
                class="font-ui-mono tracking-[0.06em] uppercase"
                :aria-invalid="!!(errors.code || errors.step)"
            />
            <InputError :message="errors.code ?? errors.step" />
        </div>

        <button
            type="submit"
            :class="appButtonClasses('primary', 'md', 'w-full')"
            :disabled="processing"
        >
            <Spinner v-if="processing" />
            {{ $t('Continue') }}
        </button>
    </Form>

    <div
        class="mt-6 flex flex-col gap-2.5 border-t border-(--ds-divider) pt-5 text-[13px]/5 text-(--ds-text-muted)"
    >
        <p v-if="docker">
            {{
                $t(
                    'The install script showed it when it finished, and the web container prints it as it starts. To see it again, run this on the server:',
                )
            }}
        </p>
        <p v-else>
            {{
                $t(
                    'To see it, run this on the server, in the panel’s folder, as the web server’s user:',
                )
            }}
        </p>
        <CommandLine :command="command" />
    </div>
</template>
