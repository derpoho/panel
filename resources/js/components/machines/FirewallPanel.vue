<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import {
    SolarShieldAlert as ShieldAlert,
    SolarShieldCheck as ShieldCheck,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, ref } from 'vue';
import { toast } from '@/lib/toast';
import type {
    FirewallExposure,
    FirewallView,
} from '@/components/machines/machines';
import AppButton from '@/components/workspace/AppButton.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    machineId: number;
    machineName: string;
    firewall: FirewallView | null;
    warnings: string[];
    canManage: boolean;
    online: boolean;
}>();

type Port = FirewallView['ports'][number];

const exposures: Record<
    FirewallExposure | 'bound',
    { label: string; tone: string }
> = {
    open: { label: trans('Open to the internet'), tone: 'neutral' },
    restricted: { label: trans('Some addresses only'), tone: 'good' },
    blocked: { label: trans('Closed by the firewall'), tone: 'good' },
    docker: { label: trans('Published by Docker'), tone: 'warn' },
    unknown: { label: trans('May be open'), tone: 'warn' },
    bound: { label: trans('Listening publicly'), tone: 'neutral' },
};

function exposure(port: Port): { label: string; tone: string } {
    const found = exposures[port.exposure ?? 'bound'];
    const reachable = ['open', 'docker', 'unknown', null].includes(
        port.exposure,
    );

    return port.what && reachable ? { ...found, tone: 'bad' } : found;
}

const toneClasses: Record<string, string> = {
    bad: 'border-(--ds-red)/30 bg-(--ds-red-bg) text-(--ds-red)',
    warn: 'border-(--ds-amber-border) bg-(--ds-amber-bg) text-(--ds-amber-strong)',
    good: 'border-(--ds-green-border) bg-(--ds-green-bg) text-(--ds-green-strong)',
    neutral:
        'border-black/10 bg-(--ds-subtle) text-(--ds-text-muted) dark:border-white/10',
};

const summary = computed(() => {
    const f = props.firewall;
    if (!f?.supported) return null;
    if (!f.installed || !f.active || f.backend === 'none') {
        return {
            safe: false,
            text: f.unavailable ?? trans('No firewall is on.'),
        };
    }
    if (f.defaultIncoming === null) {
        // Its rules couldn't be read; the daemon's note says so.
        return {
            safe: false,
            text:
                f.notes[0] ??
                trans('Couldn’t read :backend’s rules.', {
                    backend: String(f.backend),
                }),
        };
    }
    const blocks = f.defaultIncoming !== 'allow';

    return {
        safe: blocks,
        // iptables and nftables have no off switch; ufw does.
        text: trans(
            f.backend === 'ufw'
                ? blocks
                    ? ':backend is on and blocks incoming connections unless a rule says otherwise.'
                    : ':backend is on and allows incoming connections unless a rule says otherwise.'
                : blocks
                  ? ':backend blocks incoming connections unless a rule says otherwise.'
                  : ':backend allows incoming connections unless a rule says otherwise.',
            { backend: String(f.backend ?? '') },
        ),
    };
});

const canChange = computed(
    () => props.canManage && props.online && props.firewall?.supported === true,
);

/* A change runs as a job on the machine; the page follows it to the end. */
const busy = ref<string | null>(null);
let poll: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(poll));

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function change(
    action: 'open' | 'close',
    port: number,
    proto: string,
): Promise<void> {
    if (busy.value) return;
    const key = `${port}/${proto}`;
    busy.value = key;

    try {
        const response = await fetch(`/machines/${props.machineId}/firewall`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ action, port, proto }),
        });
        const body = await response.json().catch(() => ({}));
        if (response.status !== 202) {
            throw new Error(
                body.error ??
                    body.message ??
                    trans('That didn’t work. Try again.'),
            );
        }
        await follow(body.jobId, key, action);
    } catch (e) {
        toast.error(e instanceof Error ? e.message : String(e));
    } finally {
        busy.value = null;
    }
}

function follow(
    jobId: string,
    key: string,
    action: 'open' | 'close',
): Promise<void> {
    let tries = 0;

    return new Promise((resolve, reject) => {
        const check = async () => {
            try {
                const response = await fetch(
                    `/machines/${props.machineId}/firewall/${jobId}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                const job = await response.json();
                if (job.state === 'succeeded') {
                    toast.success(
                        action === 'open'
                            ? trans(':key is open to the internet.', {
                                  key: String(key),
                              })
                            : trans(':key is closed to the internet.', {
                                  key: String(key),
                              }),
                    );
                    router.reload({
                        only: ['machine'],
                        onFinish: () => resolve(),
                    });

                    return;
                }
                if (
                    [
                        'failed',
                        'refused',
                        'timed_out',
                        'cancelled',
                        'stale',
                    ].includes(job.state)
                ) {
                    // A failed change may still have changed some rules; the
                    // panel asks the machine for a fresh scan, shown here.
                    setTimeout(
                        () => router.reload({ only: ['machine'] }),
                        8000,
                    );
                    reject(
                        new Error(
                            job.error?.message ??
                                trans('The machine didn’t change :key.', {
                                    key: String(key),
                                }),
                        ),
                    );

                    return;
                }
                if (++tries >= 60) {
                    reject(
                        new Error(
                            'The machine is taking a while. The change will show here when it’s done.',
                        ),
                    );

                    return;
                }
                poll = setTimeout(check, 1500);
            } catch (e) {
                reject(e);
            }
        };
        poll = setTimeout(check, 1000);
    });
}

/* Closing cuts off whoever reaches it from outside: ask first. */
const closing = ref<Port | null>(null);
const closeOpen = computed({
    get: () => closing.value !== null,
    set: (value) => {
        if (!value) closing.value = null;
    },
});

function confirmClose(): void {
    const port = closing.value;
    closing.value = null;
    if (port) void change('close', port.port, port.proto);
}

function closeRefusal(port: Port): string | null {
    if (port.ssh) return trans('SSH stays open so nobody gets locked out.');
    if (port.exposure === 'docker') {
        return trans(
            'Docker publishes this port, so the firewall can’t close it. Publish it on 127.0.0.1 instead.',
        );
    }

    return props.firewall?.unavailable ?? null;
}

/* Any port, not just what's listening now. */
const customPort = ref<number | null>(null);
const customProto = ref('tcp');
const customValid = computed(
    () =>
        customPort.value !== null &&
        Number.isInteger(customPort.value) &&
        customPort.value >= 1 &&
        customPort.value <= 65535,
);

function closeCustom(): void {
    if (!customValid.value) return;
    const port = customPort.value as number;
    const listening = props.firewall?.ports.find(
        (p) => p.port === port && p.proto === customProto.value,
    );
    const candidate = listening ?? {
        port,
        proto: customProto.value,
        process: null,
        exposure: null,
        because: null,
        what: null,
        ssh: port === 22,
    };
    const refusal = closeRefusal(candidate);
    if (refusal) {
        toast.error(refusal);

        return;
    }
    closing.value = candidate;
}

const showRules = ref(false);
</script>

<template>
    <div class="space-y-3">
        <div v-if="summary" class="ds-tile flex items-start gap-3 p-4 text-sm">
            <component
                :is="summary.safe ? ShieldCheck : ShieldAlert"
                class="mt-0.5 size-4 shrink-0"
                :class="
                    summary.safe
                        ? 'text-(--ds-green)'
                        : 'text-(--ds-amber-strong)'
                "
            />
            <p>{{ summary.text }}</p>
        </div>
        <p
            v-else-if="firewall && !firewall.supported"
            class="ds-tile p-4 text-sm text-(--ds-text-muted)"
        >
            {{
                $t(
                    ':unavailable Until then, every port listening publicly is shown as reachable.',
                    { unavailable: String(firewall.unavailable ?? '') },
                ) + ' '
            }}
        </p>

        <h2 class="pt-1 font-medium">{{ $t('Listening ports') }}</h2>
        <div
            v-if="firewall?.ports.length"
            class="ds-tile divide-y-2 divide-black/5 dark:divide-black/30"
        >
            <div
                v-for="port in firewall.ports"
                :key="`${port.port}/${port.proto}`"
                class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-4 py-3 text-sm"
            >
                <div class="min-w-0">
                    <p>
                        <span class="font-ui-mono"
                            >{{ port.port }}/{{ port.proto }}</span
                        >
                        <span class="text-xs text-(--ds-text-muted)">
                            ·
                            {{
                                port.what ??
                                port.process ??
                                $t('Unknown process')
                            }}</span
                        >
                    </p>
                    <p
                        v-if="port.because"
                        class="mt-0.5 truncate text-xs text-(--ds-text-faint)"
                        :title="port.because"
                    >
                        {{ port.because }}
                    </p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <span
                        class="rounded-md border px-1.5 py-0.5 text-[11px]/4"
                        :class="toneClasses[exposure(port).tone]"
                        >{{ exposure(port).label }}</span
                    >
                    <template v-if="canChange">
                        <LoaderCircle
                            v-if="busy === `${port.port}/${port.proto}`"
                            class="size-4 animate-spin text-(--ds-text-muted)"
                        />
                        <AppButton
                            v-else-if="
                                port.exposure === 'blocked' ||
                                port.exposure === 'restricted'
                            "
                            size="xs"
                            :disabled="busy !== null"
                            @click="change('open', port.port, port.proto)"
                            >{{ $t('Open') }}</AppButton
                        >
                        <AppButton
                            v-else-if="port.exposure !== 'docker'"
                            size="xs"
                            :variant="port.what ? 'primary' : 'secondary'"
                            :disabled="
                                busy !== null || closeRefusal(port) !== null
                            "
                            :title="closeRefusal(port) ?? undefined"
                            @click="closing = port"
                            >{{ $t('Close') }}</AppButton
                        >
                    </template>
                </div>
            </div>
        </div>
        <p v-else class="ds-tile p-5 text-sm text-(--ds-text-muted)">
            {{
                ' ' +
                $t('Nothing is listening beyond the machine itself.') +
                ' '
            }}
        </p>

        <div
            v-if="canChange"
            class="ds-tile flex flex-wrap items-center gap-2 p-4 text-sm"
        >
            <span class="mr-1 text-(--ds-text-muted)">{{ $t('Open') }}</span>
            <AppButton
                v-for="preset in firewall?.presets ?? []"
                :key="preset.label"
                size="xs"
                :disabled="busy !== null"
                @click="change('open', preset.port, preset.proto)"
                >{{ preset.label }} ({{ preset.port }})</AppButton
            >
            <span class="mx-1 h-4 w-px bg-black/10 dark:bg-white/10" />
            <label class="sr-only" for="firewall-port">{{ $t('Port') }}</label>
            <input
                id="firewall-port"
                v-model.number="customPort"
                type="number"
                min="1"
                max="65535"
                :placeholder="$t('Port')"
                class="h-7 w-24 rounded-md border border-input bg-transparent px-2 text-[13px]/4 text-(--ds-text) shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            />
            <label class="sr-only" for="firewall-proto">{{
                $t('Protocol')
            }}</label>
            <select
                id="firewall-proto"
                v-model="customProto"
                class="ds-select h-7 cursor-pointer rounded-md border border-input bg-transparent px-1.5 text-[13px]/4 text-(--ds-text) shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
            >
                <option value="tcp">{{ $t('tcp') }}</option>
                <option value="udp">{{ $t('udp') }}</option>
            </select>
            <AppButton
                size="xs"
                :disabled="!customValid || busy !== null"
                @click="change('open', customPort as number, customProto)"
                >{{ $t('Open') }}</AppButton
            >
            <AppButton
                size="xs"
                :disabled="
                    !customValid || busy !== null || !!firewall?.unavailable
                "
                :title="firewall?.unavailable ?? undefined"
                @click="closeCustom"
                >{{ $t('Close') }}</AppButton
            >
        </div>

        <div v-if="firewall?.supported && firewall.rules.length" class="pt-1">
            <button
                type="button"
                class="text-sm font-medium"
                :aria-expanded="showRules"
                @click="showRules = !showRules"
            >
                {{
                    $t(':value the firewall’s rules (:length)', {
                        value: showRules ? $t('Hide') : $t('Show'),
                        length: String(firewall.rules.length ?? ''),
                    }) + ' '
                }}
            </button>
            <div
                v-if="showRules"
                class="ds-tile mt-2 divide-y-2 divide-black/5 dark:divide-black/30"
            >
                <div
                    v-for="(rule, index) in firewall.rules"
                    :key="index"
                    class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm"
                >
                    <span class="font-ui-mono text-xs break-all">{{
                        rule.text
                    }}</span>
                    <span
                        class="flex shrink-0 items-center gap-2 text-xs text-(--ds-text-muted)"
                    >
                        <span v-if="rule.managed">{{
                            $t('Added by ServerOS')
                        }}</span>
                        <span v-if="rule.ipv6">{{ $t('IPv6 only') }}</span>
                        <span
                            v-if="rule.opaque"
                            :title="
                                $t(
                                    'This rule depends on a network interface or another chain that ServerOS doesn’t check, so ServerOS can’t tell which connections it allows. ServerOS never changes it, and ports it could open show as “May be open”.',
                                )
                            "
                            >{{ $t('Partly checked') }}</span
                        >
                        <span
                            class="rounded-md border px-1.5 py-0.5 text-[11px]/4"
                            :class="
                                toneClasses[
                                    rule.verdict === 'allow' ||
                                    rule.verdict === 'limit'
                                        ? 'neutral'
                                        : 'good'
                                ]
                            "
                            >{{ rule.verdict }}</span
                        >
                    </span>
                </div>
            </div>
        </div>

        <p
            v-if="firewall?.unreadable"
            class="text-xs"
            :class="
                firewall.ports.some((p) => p.exposure === 'unknown')
                    ? 'text-(--ds-amber-strong)'
                    : 'text-(--ds-text-muted)'
            "
        >
            {{
                firewall.unreadable === 1
                    ? $t(
                          '1 rule depends on a network interface or another chain that ServerOS doesn’t check. Ports that rule could open show as “May be open”.',
                      )
                    : $t(
                          ':unreadable rules depend on a network interface or another chain that ServerOS doesn’t check. Ports those rules could open show as “May be open”.',
                          { unreadable: String(firewall.unreadable) },
                      )
            }}
        </p>
        <p
            v-for="note in [...(firewall?.notes ?? []), ...warnings]"
            :key="note"
            class="text-xs text-(--ds-text-muted)"
        >
            {{ note }}
        </p>

        <Dialog v-model:open="closeOpen">
            <DialogContent
                class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
            >
                <DialogHeader>
                    <DialogTitle>{{
                        $t('Close :port/:proto on :machineName?', {
                            port: String(closing?.port ?? ''),
                            proto: String(closing?.proto ?? ''),
                            machineName,
                        })
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            ' ' +
                            $t(
                                'Anything that reaches :value from outside this machine is cut off: other servers, your own computer, visitors. Programs on the machine itself can still connect.',
                                {
                                    value:
                                        closing?.what ??
                                        closing?.process ??
                                        'it',
                                },
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </DialogHeader>
                <div class="flex justify-end gap-2">
                    <AppButton @click="closing = null">{{
                        $t('Cancel')
                    }}</AppButton>
                    <AppButton variant="danger" @click="confirmClose">{{
                        $t('Close port')
                    }}</AppButton>
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
