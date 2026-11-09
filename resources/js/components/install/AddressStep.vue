<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import { postJson } from '@/lib/install';
import type { Check } from '@/lib/install';
import CheckLines from './CheckLines.vue';
import StepHeading from './StepHeading.vue';
import { stepUrl } from './types';
import type { AddressProps } from './types';

/*
 * The panel's address, and whether it (and its subdomains, where
 * workspaces open) points here. The Docker install sets it itself.
 */
const props = defineProps<{ address: AddressProps; docker: boolean }>();

const url = ref(
    props.address.editable ? props.address.suggested : props.address.url,
);
const checking = ref(false);
const lines = ref<Check[] | null>(null);

async function check(): Promise<void> {
    checking.value = true;
    try {
        lines.value = (
            await postJson<{ lines: Check[] }>('/install/address/check', {
                url: url.value,
            })
        ).lines;
    } catch (error) {
        lines.value = [{ status: 'fail', text: (error as Error).message }];
    } finally {
        checking.value = false;
    }
}

onMounted(() => {
    if (!props.address.editable) void check();
});
</script>

<template>
    <StepHeading
        :title="$t('The panel’s address')"
        :description="
            $t(
                'Where you and your team open ServerOS. Workspaces open on its subdomains, like acme.panel.example.com.',
            )
        "
    />

    <Form
        action="/install/address"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
    >
        <div class="grid gap-2">
            <Label for="url">{{ $t('Address') }}</Label>
            <Input
                id="url"
                v-model="url"
                name="url"
                required
                inputmode="url"
                autocomplete="off"
                spellcheck="false"
                placeholder="https://panel.example.com"
                :readonly="!address.editable"
                @input="lines = null"
            />
            <p
                v-if="!address.editable"
                class="text-[12.5px]/4 text-(--ds-text-muted)"
            >
                {{
                    docker
                        ? $t(
                              'Set by the install. To change it, set SERVEROS_DOMAIN in /opt/serveros/.env, then run: cd /opt/serveros && docker compose up -d',
                          )
                        : $t(
                              'Set outside the panel (APP_URL), so it’s changed there.',
                          )
                }}
            </p>
            <InputError :message="errors.url" />
        </div>

        <CheckLines v-if="lines" :lines="lines" />

        <div class="flex gap-2">
            <Link
                :href="stepUrl('database')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                type="button"
                :class="appButtonClasses('secondary', 'md')"
                :disabled="checking || !url"
                @click="check"
            >
                <Spinner v-if="checking" />
                {{ $t('Check DNS') }}
            </button>
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'grow')"
                :disabled="processing"
            >
                <Spinner v-if="processing" />
                {{ address.editable ? $t('Save') : $t('Continue') }}
            </button>
        </div>
    </Form>
</template>
