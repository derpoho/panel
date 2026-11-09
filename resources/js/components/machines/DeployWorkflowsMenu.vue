<script setup lang="ts">
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { router } from '@inertiajs/vue3';
import { ChevronDown } from '@lucide/vue';
import { SolarWorkflows as Workflows } from '@/lib/solarIcons';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

// Where to get more (serveros.com: Billing), when a package says.
const upgrade = useUpgradeLink();

/**
 * In the Deployments toolbar, beside Sort: the workflows that make deploys
 * run themselves, each one click from its ready-made template (it opens
 * switched off). Without workflows on the plan, it says so and links plans.
 */
const props = defineProps<{ available: boolean }>();

const templates = [
    {
        key: 'ci-deploy',
        label: 'Deploy when CI passes',
        detail: 'Your CI calls a webhook after tests pass',
    },
    {
        key: 'smoke-test',
        label: 'Check the site after each deploy',
        detail: 'Alert if it stops answering',
    },
    {
        key: 'deploy-notify',
        label: 'Post deploys to Slack or Discord',
        detail: 'Every deploy, and loudly when one fails',
    },
] as const;

function create(template: string): void {
    if (props.available) router.post('/workflows', { template });
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <button
                type="button"
                class="ds-raised-button cursor-pointer text-[13px]!"
            >
                <Workflows
                    class="size-3.5 text-(--ds-text-muted)"
                    :stroke-width="1.75"
                />
                <span class="font-medium">{{ $t('Automate') }}</span>
                <ChevronDown
                    class="size-2.5 text-(--ds-text-muted)"
                    :stroke-width="2"
                />
            </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent class="ds-modal w-72 font-ui" align="end">
            <DropdownMenuLabel
                class="text-xs/4 font-normal text-(--ds-text-muted)"
            >
                {{
                    available
                        ? $t('Start a workflow. It opens switched off.')
                        : $t('Workflows come with Pro and Business.')
                }}
            </DropdownMenuLabel>
            <DropdownMenuItem
                v-for="template in templates"
                :key="template.key"
                :disabled="!available"
                class="flex-col items-start gap-0"
                @select="create(template.key)"
            >
                <span>{{ $t(template.label) }}</span>
                <span class="text-xs/4 text-(--ds-text-muted)">{{
                    $t(template.detail)
                }}</span>
            </DropdownMenuItem>
            <template v-if="!available && upgrade">
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="router.visit(upgrade.href)">
                    {{ $t('See plans') }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
