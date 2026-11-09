<script setup lang="ts">
/**
 * The dashboard page frame: a large title and description, then a row with
 * segmented tabs on the left and actions on the right, just above the page
 * content.
 */
type Props = {
    title: string;
    description?: string;
    /** The tabs row is a toolbar for the list below, so it sits closer to it. */
    toolbar?: boolean;
};

defineProps<Props>();
</script>

<template>
    <div
        class="workspace-panel @container mx-auto flex min-h-full w-full max-w-[1320px] flex-col px-4 pt-9 pb-12 font-ui text-(--ds-text) sm:px-8"
        :class="toolbar ? 'gap-4' : 'gap-6'"
    >
        <header class="flex flex-col gap-5">
            <!-- Without tabs, the actions sit beside the title, not on a row of their own -->
            <div
                class="flex flex-wrap items-end justify-between gap-x-6 gap-y-4"
            >
                <div class="flex min-w-0 flex-1 basis-80 flex-col gap-1">
                    <slot name="title">
                        <h1
                            class="text-[21px]/7 font-semibold tracking-[-0.01em]"
                        >
                            {{ title }}
                        </h1>
                    </slot>
                    <p
                        v-if="description || $slots.description"
                        class="text-[14px]/5 text-(--ds-text-muted)"
                    >
                        <slot name="description">{{ description }}</slot>
                    </p>
                </div>
                <div
                    v-if="$slots.actions && !$slots.tabs"
                    class="flex flex-wrap items-center gap-2"
                >
                    <slot name="actions" />
                </div>
            </div>

            <!-- The actions sit beside the tabs whenever they fit; on wide
                 screens they give way (a search box narrows) before anything
                 wraps, whatever the language. Wrapped, the actions get a full
                 row and the search box fills what the buttons leave. -->
            <div
                v-if="$slots.tabs"
                class="flex flex-wrap items-center gap-2 @min-[1190px]:flex-nowrap"
            >
                <div class="max-w-full min-w-0 @min-[1190px]:shrink-0">
                    <slot name="tabs" />
                </div>
                <div
                    v-if="$slots.actions"
                    class="ml-auto flex grow flex-wrap items-center justify-end gap-2 @min-[1190px]:min-w-0 @min-[1190px]:flex-nowrap @min-[1190px]:[&>*:not(label)]:shrink-0 [&>label]:grow @min-[1190px]:[&>label]:grow-0"
                >
                    <slot name="actions" />
                </div>
            </div>
        </header>

        <slot />
    </div>
</template>
