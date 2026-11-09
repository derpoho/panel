<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import { appButtonClasses } from '@/lib/appButton';
import { dashboard } from '@/routes';

/**
 * Where someone lands after signing in with Google on a company's domain
 * whose workspace asks people to request access: it's waiting on an admin.
 */
const props = defineProps<{
    /** Workspaces they're waiting to join. */
    workspaces: string[];
}>();

const title = computed(() =>
    props.workspaces.length
        ? trans('Asked to join :workspace', {
              workspace: props.workspaces.join(', '),
          })
        : trans('Nothing waiting'),
);

defineOptions({
    layout: {
        title: '',
    },
});
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-5 text-center">
        <div class="flex flex-col gap-1.5">
            <h2 class="text-[15px]/5 font-medium text-(--ds-text)">
                {{ title }}
            </h2>
            <p class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    workspaces.length
                        ? $t(
                              'An admin will let you in. Once they do, it shows up in your workspaces.',
                          )
                        : $t('You’re not waiting to join any workspace.')
                }}
            </p>
        </div>

        <Link
            :href="dashboard()"
            :class="appButtonClasses('secondary', 'md', 'w-full')"
        >
            {{ $t('Go to your dashboard') }}
        </Link>
    </div>
</template>
