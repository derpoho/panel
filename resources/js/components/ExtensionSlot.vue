<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';

/**
 * A place on a panel page where the private packages (panel-ee,
 * serveros.com) can put their own parts, like the SCIM tile on Members.
 * A package puts components in resources/js/slots/{name}/*.vue;
 * each one here renders in file name order, with whatever this slot is
 * given passed on as props. They read the page's props, including any the
 * package adds to the page (App\Support\Extensions\Extensions::addPageProps),
 * with usePage(). A build without the packages renders nothing here.
 *
 * The page finds its own slots' components, with an eager import.meta.glob
 * of vendor/serveroshq/{star}/resources/js/slots/{page}/, and passes them as
 * `parts`: that keeps each in the page's own chunk, rendered with it, as if
 * it were part of the page.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps<{
    name: string;
    /** The page's import.meta.glob of its slots' components (eager, default import). */
    parts: Record<string, Component>;
}>();

const SLOT_FILE = /\/resources\/js\/slots\/(.+)\/([^/]+)\.vue$/;

const found = computed(() =>
    Object.entries(props.parts)
        .map(([path, component]) => {
            const match = SLOT_FILE.exec(path);

            return match?.[1] === props.name
                ? { key: path, file: match[2], component }
                : null;
        })
        .filter((part) => part !== null)
        .sort((a, b) => a.file.localeCompare(b.file)),
);
</script>

<template>
    <component
        :is="part.component"
        v-for="part in found"
        :key="part.key"
        v-bind="$attrs"
    />
</template>
