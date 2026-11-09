<template>
    <div
        class="flex min-h-full flex-col pb-10"
        aria-busy="true"
        :aria-label="$t('Loading workspace')"
    >
        <div class="flex items-center gap-2.5 px-4 pt-4.5 pb-4 sm:px-6">
            <span class="shimmer h-6 w-40 rounded-md" />
            <span class="shimmer h-4 w-36 rounded-[5px]" />
            <span class="shimmer ml-auto h-8 w-36 rounded-[6px]" />
        </div>

        <div class="flex flex-col gap-8 px-4 sm:px-6">
            <div class="grid items-start gap-4 lg:grid-cols-2">
                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="tile in 6"
                        :key="tile"
                        class="flex flex-col gap-3 rounded-[10px] border border-(--ds-border) bg-(--ds-frame) px-4 py-4"
                    >
                        <span class="shimmer h-3 w-20 rounded-[4px]" />
                        <span class="shimmer h-4 w-28 rounded-[4px]" />
                    </div>
                </div>

                <div
                    class="flex flex-col overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-card)"
                >
                    <div
                        class="flex h-13 items-center border-b border-(--ds-border) px-4"
                    >
                        <span class="shimmer h-4 w-24 rounded-[4px]" />
                    </div>
                    <div
                        v-for="row in 4"
                        :key="row"
                        class="flex items-center gap-3 border-b border-(--ds-divider) px-4 py-3.5 last:border-b-0"
                    >
                        <span class="shimmer size-4.5 rounded-full" />
                        <span class="shimmer h-3.5 w-48 rounded-[4px]" />
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <span class="shimmer h-4 w-32 rounded-[4px]" />
                <div
                    class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6"
                >
                    <div
                        v-for="option in 6"
                        :key="option"
                        class="flex items-start gap-3 rounded-[10px] border border-(--ds-border) bg-(--ds-frame) p-3.5"
                    >
                        <span class="shimmer size-8 shrink-0 rounded-lg" />
                        <div class="flex grow flex-col gap-2 pt-0.5">
                            <span class="shimmer h-3.5 w-20 rounded-[4px]" />
                            <span class="shimmer h-3 w-28 rounded-[4px]" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
