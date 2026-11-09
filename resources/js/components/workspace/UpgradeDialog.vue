<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import { planNames, planPrices, planRows } from '@/lib/planLocks';
import type { LockedPlan, PlanKey } from '@/lib/planLocks';
import type { Workspace } from '@/types';

// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

/**
 * Asks to move up a plan for something the workspace's plan doesn't
 * include: one line on why, the two plans side by side, and the way to
 * Billing.
 */
const open = defineModel<boolean>('open', { default: false });

const { feature, plan = 'pro' } = defineProps<{
    /** What's locked, as a plural phrase: "Workflows". */
    feature: string;
    /** The cheapest plan that includes it. */
    plan?: LockedPlan;
}>();

const page = usePage<{ workspace: Workspace }>();
const current = computed<PlanKey | null>(() => {
    const value = page.props.workspace.billing.plan;
    return value === 'starter' || value === 'pro' || value === 'business'
        ? value
        : null;
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-1 px-5 pt-5 pb-4">
                <DialogTitle class="text-base/6 font-semibold">
                    {{
                        ' ' +
                        $t('Upgrade to :value', { value: planNames[plan] })
                    }}
                </DialogTitle>
                <DialogDescription class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        $t(':feature come with :valueValue, :value2 a month.', {
                            feature,
                            valueValue: planNames[plan],
                            value2: planPrices[plan],
                        }) + ' '
                    }}
                </DialogDescription>
            </div>

            <table class="w-full border-y border-(--ds-divider) text-[13px]/5">
                <thead>
                    <tr class="text-xs/4 text-(--ds-text-muted)">
                        <th class="px-5 py-2 text-left font-normal"></th>
                        <th
                            v-if="current"
                            class="px-3 py-2 text-left font-normal"
                        >
                            {{
                                $t(':value (now)', {
                                    value: planNames[current],
                                }) + ' '
                            }}
                        </th>
                        <th
                            class="px-5 py-2 text-left font-medium text-(--ds-text)"
                        >
                            {{ planNames[plan] }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in planRows"
                        :key="row.label"
                        class="border-t border-(--ds-divider)"
                    >
                        <td class="px-5 py-2 text-(--ds-text-muted)">
                            {{ row.label }}
                        </td>
                        <td
                            v-if="current"
                            class="px-3 py-2 text-(--ds-text-secondary)"
                        >
                            {{ row.values[current] }}
                        </td>
                        <td
                            class="px-5 py-2"
                            :class="
                                current &&
                                row.values[current] !== row.values[plan]
                                    ? 'font-medium text-(--ds-text)'
                                    : 'text-(--ds-text-secondary)'
                            "
                        >
                            {{ row.values[plan] }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="flex items-center justify-between gap-3 px-5 py-4">
                <span class="text-xs/4 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t('Already paying? You’re charged the difference.') +
                        ' '
                    }}
                </span>
                <div class="flex gap-2">
                    <AppButton variant="quiet" @click="open = false">
                        {{ ' ' + $t('Not now') + ' ' }}
                    </AppButton>
                    <AppButton
                        v-if="upgrade"
                        variant="primary"
                        :href="upgrade.href"
                    >
                        {{ ' ' + $t('Upgrade') + ' ' }}
                    </AppButton>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
