<script setup lang="ts" generic="Row extends { id: number }">
import { Link } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';

/**
 * The admin lists: filter pills and a search in the page header row, and
 * a table whose rows the page draws. The page says how a row matches.
 */
const props = defineProps<{
    rows: Row[];
    /** Label, then a test; the first is "all". */
    filters: { value: string; label: string; test: (row: Row) => boolean }[];
    /** The text a search matches against. */
    searchText: (row: Row) => string;
    placeholder: string;
    columns: string;
    headings: string[];
    empty: string;
    /** Rows open this page when given. */
    href?: (row: Row) => string;
}>();

const filter = ref(props.filters[0]?.value ?? 'all');
const search = ref('');

const counts = computed(() =>
    Object.fromEntries(
        props.filters.map((f) => [f.value, props.rows.filter(f.test).length]),
    ),
);

const visible = computed(() => {
    const test =
        props.filters.find((f) => f.value === filter.value)?.test ??
        (() => true);
    const needle = search.value.trim().toLowerCase();

    return props.rows
        .filter(test)
        .filter(
            (row) =>
                !needle || props.searchText(row).toLowerCase().includes(needle),
        );
});

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <div class="max-w-full overflow-x-auto">
                <div class="ds-segments" role="group" aria-label="Filter">
                    <button
                        v-for="option in filters"
                        :key="option.value"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="filter === option.value"
                        @click="filter = option.value"
                    >
                        {{ option.label }}
                        <span
                            class="text-xs/4 tabular-nums"
                            :class="
                                filter === option.value
                                    ? 'text-(--ds-text-muted)'
                                    : 'text-(--ds-text-faint)'
                            "
                            >{{ counts[option.value] }}</span
                        >
                    </button>
                </div>
            </div>
            <label
                class="ds-raised-button ml-auto w-full cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring) sm:w-72"
            >
                <Search
                    class="size-3.5 shrink-0 text-(--ds-text-muted)"
                    :stroke-width="1.6"
                />
                <span class="sr-only">{{ placeholder }}</span>
                <input
                    v-model="search"
                    type="search"
                    :placeholder="placeholder"
                    class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                />
            </label>
            <!-- Buttons for the whole list, like adding one. -->
            <slot name="actions" />
        </div>

        <div class="ds-tile overflow-x-auto" role="table">
            <div
                class="ds-tile-head hidden h-9.5 items-center gap-x-4 border-b border-(--ds-divider) px-4 text-xs/4 font-medium text-(--ds-text-muted) md:grid"
                :class="columns"
                role="row"
            >
                <span
                    v-for="heading in headings"
                    :key="heading"
                    role="columnheader"
                    >{{ heading }}</span
                >
            </div>
            <component
                :is="href ? Link : 'div'"
                v-for="row in visible"
                :key="row.id"
                :href="href?.(row)"
                class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 border-b border-(--ds-divider) px-4 py-2.5 text-[13px]/4 last:border-b-0"
                :class="[
                    columns,
                    href
                        ? 'transition-colors hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)'
                        : '',
                ]"
                role="row"
            >
                <slot name="row" :row="row" />
            </component>
            <p
                v-if="visible.length === 0"
                class="px-4 py-8 text-center text-[13px]/5 text-(--ds-text-muted)"
            >
                {{ rows.length ? 'Nothing matches.' : empty }}
            </p>
        </div>
    </div>
</template>
