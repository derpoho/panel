<script setup lang="ts">
import { Form, Link, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { appButtonClasses } from '@/lib/appButton';
import { postJson, postLines } from '@/lib/install';
import StatusIcon from './StatusIcon.vue';
import StepHeading from './StepHeading.vue';
import { stepUrl } from './types';
import type { DatabaseDriver, DatabaseProps, Status } from './types';

/*
 * The database: already set up (the Docker install, or one made by hand)
 * goes straight on; connected but empty sets up its tables, with a bar as
 * they go; otherwise settings to try and save.
 */
const props = defineProps<{ database: DatabaseProps; docker: boolean }>();

const status = computed(() => props.database.status);
const choosing = ref(!status.value.connected && props.database.editable);
// Saved and connected: on to its tables (the page stays, with new props).
watch(
    () => status.value.connected,
    (connected) => {
        if (connected) choosing.value = false;
    },
);

const driver = ref<DatabaseDriver>(
    props.database.drivers[props.database.settings.driver]
        ? props.database.settings.driver
        : ((['pgsql', 'mysql', 'sqlite'] as const).find(
              (name) => props.database.drivers[name],
          ) ?? 'pgsql'),
);
const drivers: { key: DatabaseDriver; label: string }[] = [
    { key: 'pgsql', label: 'PostgreSQL' },
    { key: 'mysql', label: trans('MySQL or MariaDB') },
    { key: 'sqlite', label: 'SQLite' },
];

/* Settings from .env only when it's the kind picked: a fresh install's are Laravel's. */
const current = computed(() =>
    props.database.settings.driver === driver.value
        ? props.database.settings
        : null,
);
const defaults = computed(() => ({
    host: current.value?.host ?? '127.0.0.1',
    port:
        current.value?.port ??
        (driver.value === 'pgsql'
            ? '5432'
            : driver.value === 'mysql'
              ? '3306'
              : ''),
    database:
        driver.value === 'sqlite'
            ? (current.value?.database ?? props.database.sqlitePath)
            : (current.value?.database ?? 'serveros'),
    username: current.value?.username ?? 'serveros',
}));

const inner = ref<HTMLElement | null>(null);
const testing = ref(false);
const tested = ref<{ status: Status; text: string } | null>(null);
function clearTested(): void {
    tested.value = null;
}

function fields(): Record<string, unknown> {
    const form = inner.value?.closest('form') ?? undefined;

    return Object.fromEntries(new FormData(form).entries());
}

async function test(): Promise<void> {
    testing.value = true;
    tested.value = null;
    try {
        const result = await postJson<{ ok: boolean; message: string }>(
            '/install/database/test',
            fields(),
        );
        tested.value = {
            status: result.ok ? 'ok' : 'fail',
            text: result.message,
        };
    } catch (error) {
        tested.value = { status: 'fail', text: (error as Error).message };
    } finally {
        testing.value = false;
    }
}

/* Setting up the tables: how many of how many, and any error. */
const migrating = ref(false);
const total = ref(0);
const done = ref(0);
const migrateError = ref<string | null>(null);

async function migrate(): Promise<void> {
    migrating.value = true;
    migrateError.value = null;
    done.value = 0;
    let finished = false;
    try {
        await postLines('/install/database/migrate', (line) => {
            if ('total' in line) total.value = line.total;
            if ('done' in line) done.value = line.done;
            if ('finished' in line) finished = true;
            if ('error' in line) migrateError.value = line.error;
        });
    } catch (error) {
        migrateError.value = (error as Error).message;
    }
    migrating.value = false;
    if (finished && !migrateError.value) router.visit('/install');
}

const percent = computed(() =>
    total.value === 0 ? 0 : Math.round((done.value / total.value) * 100),
);
</script>

<template>
    <StepHeading
        :title="$t('Database')"
        :description="
            $t(
                'Where ServerOS keeps your workspaces, machines and everything else.',
            )
        "
    />

    <!-- Already there, with its tables -->
    <div v-if="status.ready && !choosing" class="flex flex-col gap-6">
        <div
            class="flex gap-3 rounded-[10px] border border-(--ds-border) px-3.5 py-3 text-[13.5px]/5"
        >
            <StatusIcon status="ok" class="mt-0.5" />
            <p>
                <span class="font-medium">{{ $t('Already set up.') }}</span>
                <span class="text-(--ds-text-muted)">
                    {{
                        ' ' +
                        (database.settings.driver === 'sqlite'
                            ? $t(':driver, in :file.', {
                                  driver: database.settings.label,
                                  file: String(
                                      database.settings.database ?? '',
                                  ),
                              })
                            : $t(':driver, :database on :host.', {
                                  driver: database.settings.label,
                                  database: String(
                                      database.settings.database ?? '',
                                  ),
                                  host: String(database.settings.host ?? ''),
                              }))
                    }}
                </span>
            </p>
        </div>
        <div class="flex gap-2">
            <Link
                :href="stepUrl('check')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                v-if="database.editable"
                type="button"
                :class="appButtonClasses('quiet', 'md')"
                @click="choosing = true"
            >
                {{ $t('Use another database') }}
            </button>
            <Link
                :href="stepUrl('address')"
                :class="appButtonClasses('primary', 'md', 'grow')"
                >{{ $t('Continue') }}</Link
            >
        </div>
    </div>

    <!-- Connected, without its tables yet -->
    <div v-else-if="status.connected && !choosing" class="flex flex-col gap-6">
        <div
            class="flex flex-col gap-3 rounded-[10px] border border-(--ds-border) px-3.5 py-3 text-[13.5px]/5"
        >
            <p class="flex gap-3">
                <StatusIcon status="ok" class="mt-0.5" />
                <span>
                    <span class="font-medium">{{
                        $t('Connected to :driver.', {
                            driver: database.settings.label,
                        })
                    }}</span>
                    <span class="text-(--ds-text-muted)">
                        {{
                            ' ' +
                            $t(
                                'Next, ServerOS sets up its tables (:count steps). It takes a minute or so.',
                                { count: String(status.pending) },
                            )
                        }}
                    </span>
                </span>
            </p>
            <div v-if="migrating || done > 0" class="flex flex-col gap-1.5">
                <div
                    class="h-1.5 overflow-hidden rounded-full bg-(--ds-border-strong)"
                    role="progressbar"
                    :aria-valuenow="percent"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >
                    <div
                        class="h-full rounded-full bg-(--ds-blue) transition-[width] duration-200"
                        :style="{ width: `${percent}%` }"
                    />
                </div>
                <p class="text-[12.5px]/4 text-(--ds-text-muted)">
                    {{
                        $t(':done of :total done', {
                            done: String(done),
                            total: String(total),
                        })
                    }}
                </p>
            </div>
            <InputError :message="migrateError ?? undefined" />
        </div>
        <div class="flex gap-2">
            <Link
                :href="stepUrl('check')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                v-if="database.editable"
                type="button"
                :class="appButtonClasses('quiet', 'md')"
                :disabled="migrating"
                @click="choosing = true"
            >
                {{ $t('Use another database') }}
            </button>
            <button
                type="button"
                :class="appButtonClasses('primary', 'md', 'grow')"
                :disabled="migrating"
                @click="migrate"
            >
                <Spinner v-if="migrating" />
                {{ migrateError ? $t('Try again') : $t('Set up the tables') }}
            </button>
        </div>
    </div>

    <!-- Can't reach it, and the installer can't change it -->
    <div v-else-if="!database.editable" class="flex flex-col gap-6">
        <div
            class="flex gap-3 rounded-[10px] border border-(--ds-border) px-3.5 py-3 text-[13.5px]/5"
        >
            <StatusIcon status="fail" class="mt-0.5" />
            <div class="flex flex-col gap-1.5">
                <p>{{ status.error }}</p>
                <p class="text-(--ds-text-muted)">
                    {{
                        docker
                            ? $t(
                                  'The install sets the database itself. See what the db container says: serveros-panel logs db',
                              )
                            : $t(
                                  'The database settings come from outside the panel, so fix them there (DB_* settings), then check again.',
                              )
                    }}
                </p>
            </div>
        </div>
        <div class="flex gap-2">
            <Link
                :href="stepUrl('check')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                type="button"
                :class="appButtonClasses('primary', 'md', 'grow')"
                @click="router.reload()"
            >
                {{ $t('Check again') }}
            </button>
        </div>
    </div>

    <!-- Settings to try and save -->
    <Form
        v-else
        action="/install/database"
        method="post"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-5"
        @before="clearTested"
    >
        <div ref="inner" class="contents">
            <div
                class="ds-segments w-full!"
                role="group"
                :aria-label="$t('Database')"
            >
                <button
                    v-for="option in drivers"
                    :key="option.key"
                    type="button"
                    class="flex-1 cursor-pointer justify-center px-0! text-[13px]!"
                    :aria-pressed="driver === option.key"
                    :disabled="!database.drivers[option.key]"
                    :title="
                        database.drivers[option.key]
                            ? undefined
                            : $t(
                                  'PHP can’t talk to this one yet: see the server check.',
                              )
                    "
                    @click="driver = option.key"
                >
                    {{ option.label }}
                </button>
            </div>
            <input type="hidden" name="driver" :value="driver" />

            <template v-if="driver === 'sqlite'">
                <div class="grid gap-2">
                    <Label for="database">{{ $t('Database file') }}</Label>
                    <Input
                        id="database"
                        :key="`database-${driver}`"
                        name="database"
                        required
                        :default-value="defaults.database"
                        class="font-ui-mono text-[12.5px]"
                    />
                    <p class="text-[12.5px]/4 text-(--ds-text-muted)">
                        {{
                            $t(
                                'A file on this server, made if it isn’t there. Fine for trying it out; use PostgreSQL for a team.',
                            )
                        }}
                    </p>
                    <InputError :message="errors.database" />
                </div>
            </template>
            <template v-else>
                <div class="grid grid-cols-[1fr_6rem] gap-3">
                    <div class="grid gap-2">
                        <Label for="host">{{ $t('Host') }}</Label>
                        <Input
                            id="host"
                            :key="`host-${driver}`"
                            name="host"
                            required
                            :default-value="defaults.host"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="port">{{ $t('Port') }}</Label>
                        <Input
                            id="port"
                            :key="`port-${driver}`"
                            name="port"
                            inputmode="numeric"
                            :default-value="String(defaults.port)"
                        />
                    </div>
                </div>
                <InputError :message="errors.host ?? errors.port" />
                <div class="grid gap-2">
                    <Label for="database">{{ $t('Database name') }}</Label>
                    <Input
                        id="database"
                        :key="`database-${driver}`"
                        name="database"
                        required
                        :default-value="defaults.database"
                    />
                    <InputError :message="errors.database" />
                </div>
                <div class="grid gap-2">
                    <Label for="username">{{ $t('Username') }}</Label>
                    <Input
                        id="username"
                        :key="`username-${driver}`"
                        name="username"
                        autocomplete="off"
                        :default-value="defaults.username"
                    />
                    <InputError :message="errors.username" />
                </div>
                <div class="grid gap-2">
                    <Label for="db_password">{{ $t('Password') }}</Label>
                    <PasswordInput
                        id="db_password"
                        name="db_password"
                        autocomplete="new-password"
                        :placeholder="
                            database.hasPassword
                                ? $t('Leave empty to keep the saved one')
                                : ''
                        "
                    />
                    <InputError :message="errors.db_password" />
                </div>
            </template>
        </div>

        <div
            v-if="tested || errors.connection"
            class="flex gap-3 rounded-[10px] border border-(--ds-border) px-3.5 py-3 text-[13.5px]/5"
        >
            <StatusIcon
                :status="
                    errors.connection ? 'fail' : (tested?.status ?? 'fail')
                "
                class="mt-0.5"
            />
            <p>{{ errors.connection ?? tested?.text }}</p>
        </div>

        <div class="flex gap-2">
            <Link
                :href="stepUrl('check')"
                :class="appButtonClasses('secondary', 'md')"
                >{{ $t('Back') }}</Link
            >
            <button
                type="button"
                :class="appButtonClasses('secondary', 'md')"
                :disabled="testing || processing"
                @click="test"
            >
                <Spinner v-if="testing" />
                {{ $t('Test connection') }}
            </button>
            <button
                type="submit"
                :class="appButtonClasses('primary', 'md', 'grow')"
                :disabled="processing || testing"
            >
                <Spinner v-if="processing" />
                {{ $t('Save') }}
            </button>
        </div>
    </Form>
</template>
