<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import { appButtonClasses } from '@/lib/appButton';
import { SolarDownload as Download } from '@/lib/solarIcons';
import { timeAgo } from '@/lib/timeAgo';

export type PanelUpdateState = {
    current: string;
    docker: boolean;
    latest: {
        version: string;
        url: string | null;
        publishedAt: string | null;
    } | null;
    available: boolean;
    checkedAt: string | null;
    reached: boolean;
    updater: boolean;
    update: {
        id: number;
        version: string;
        from: string;
        status: 'requested' | 'running' | 'done' | 'failed';
        error: string | null;
        by: string | null;
        requestedAt: string;
        finishedAt: string | null;
    } | null;
};

const props = defineProps<{ state: PanelUpdateState }>();

const state = ref<PanelUpdateState>(props.state);
const error = ref<string | null>(null);
const busy = ref(false);

watch(
    () => props.state,
    (next) => (state.value = next),
);

const pending = computed(
    () =>
        state.value.update?.status === 'requested' ||
        state.value.update?.status === 'running',
);

const canUpdate = computed(
    () =>
        state.value.available &&
        state.value.docker &&
        state.value.updater &&
        !pending.value,
);

const headline = computed(() => {
    const { current, latest, update } = state.value;
    if (pending.value && update) {
        return trans('Updating to :version', { version: update.version });
    }
    if (state.value.available && latest) {
        return trans('ServerOS :version is out', { version: latest.version });
    }

    return current === 'dev'
        ? trans('A development build')
        : trans('ServerOS :version', { version: current });
});

const details = computed(() => {
    const { current, latest, update, checkedAt, reached, docker, updater } =
        state.value;
    if (pending.value) {
        return [
            update?.status === 'running'
                ? trans(
                      'Backing up, then restarting on the new version. This page may stop answering for a minute or two, then picks up again by itself.',
                  )
                : trans(
                      'The server starts it within a minute. It backs up first, then the panel restarts.',
                  ),
        ];
    }

    const lines: string[] = [];
    if (current === 'dev') {
        lines.push(trans('This build isn’t updated from here.'));
    } else if (state.value.available) {
        lines.push(trans('This install runs :version.', { version: current }));
    } else if (latest) {
        lines.push(trans('Up to date.'));
    }

    if (checkedAt) {
        lines.push(
            reached
                ? trans('Checked :time.', { time: timeAgo(checkedAt) })
                : trans(
                      'Couldn’t reach GitHub to check :time, so this may be out of date.',
                      { time: timeAgo(checkedAt) },
                  ),
        );
    } else if (current !== 'dev') {
        lines.push(trans('Not checked yet.'));
    }

    if (state.value.available && !docker) {
        lines.push(
            trans(
                'Update it the way it was installed, as in Upgrading in the self-hosting guide.',
            ),
        );
    } else if (state.value.available && !updater) {
        lines.push(
            trans(
                'To update from here, run serveros-panel upgrade && serveros-panel schedule on the server once.',
            ),
        );
    } else if (state.value.available) {
        lines.push(
            trans(
                'Updating backs up first, then the panel restarts, so it’s unavailable for a minute or two. Your machines get the newest daemon once it’s back.',
            ),
        );
    }

    if (update?.status === 'done' && update.finishedAt) {
        lines.push(
            trans('Updated from :from :time.', {
                from: update.from,
                time: timeAgo(update.finishedAt),
            }),
        );
    } else if (update?.status === 'failed') {
        lines.push(
            trans('The update to :version failed :time: :error', {
                version: update.version,
                time: timeAgo(update.finishedAt ?? update.requestedAt),
                error: update.error ?? '',
            }),
        );
    }

    return lines;
});

async function poll(): Promise<void> {
    const response = await fetch('/settings/panel-update', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    }).catch(() => null);
    if (
        response?.ok &&
        response.headers.get('content-type')?.includes('application/json')
    ) {
        state.value = (await response.json()) as PanelUpdateState;
    }
}

function post(url: string): void {
    router.post(
        url,
        {},
        {
            preserveScroll: true,
            only: ['panelUpdate'],
            onStart: () => {
                busy.value = true;
                error.value = null;
            },
            onError: (errors) => (error.value = errors.update ?? null),
            onFinish: () => (busy.value = false),
        },
    );
}

function update(): void {
    if (
        !window.confirm(
            trans(
                'Update ServerOS to :version? It backs up first, and the panel is unavailable for a minute or two while it restarts.',
                { version: state.value.latest?.version ?? '' },
            ),
        )
    ) {
        return;
    }
    post('/settings/panel-update');
}

let timer: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    timer = setInterval(() => {
        if (pending.value && document.visibilityState === 'visible') {
            void poll();
        }
    }, 5_000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Tile
        id="updates"
        :title="$t('Updates')"
        :description="
            $t('This install of ServerOS, and the daemon on your machines.')
        "
        :icon="Download"
    >
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex max-w-xl flex-col gap-1">
                <h3 class="text-[13px]/5 font-medium">{{ headline }}</h3>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{ details.join(' ') }}
                </p>
            </div>
            <div v-if="!pending" class="flex items-center gap-1">
                <a
                    v-if="state.available && state.latest?.url"
                    :href="state.latest.url"
                    target="_blank"
                    rel="noopener"
                    :class="appButtonClasses('quiet', 'sm')"
                >
                    {{ $t('What’s new') }}
                </a>
                <AppButton
                    v-if="canUpdate"
                    size="sm"
                    variant="primary"
                    :disabled="busy"
                    @click="update"
                >
                    {{ $t('Update') }}
                </AppButton>
                <AppButton
                    v-else-if="state.current !== 'dev'"
                    size="sm"
                    :disabled="busy"
                    @click="post('/settings/panel-update/check')"
                >
                    {{ $t('Check now') }}
                </AppButton>
            </div>
        </div>
        <InputError :message="error ?? undefined" class="mt-3" />
    </Tile>
</template>
