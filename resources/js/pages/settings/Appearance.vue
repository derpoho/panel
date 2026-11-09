<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import NavLayoutTabs from '@/components/NavLayoutTabs.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: trans('Appearance settings'),
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head :title="$t('Appearance settings')" />

    <h1 class="sr-only">{{ $t('Appearance settings') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="$t('Appearance settings')"
            :description="$t('Update the appearance settings for your account')"
        />
        <AppearanceTabs />
    </div>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="$t('Navigation')"
            :description="
                $t(
                    'Where the dashboard’s sections go: tabs along the top, or a sidebar down the left. Phones always use the menu.',
                )
            "
        />
        <NavLayoutTabs />
    </div>
</template>
