<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Check } from '@lucide/vue';
import { computed } from 'vue';

/**
 * How strong a new password is: a four-step meter, and when the server has
 * rules (production), a checklist read from the same `passwordrules` string
 * the input gets, so it always matches what will be accepted.
 */
const props = defineProps<{
    password: string;
    /** e.g. "minlength: 12; required: lower; required: upper; …" */
    rules?: string;
}>();

const tests: Record<string, { label: string; test: (p: string) => boolean }> = {
    lower: { label: trans('a lowercase letter'), test: (p) => /[a-z]/.test(p) },
    upper: {
        label: trans('an uppercase letter'),
        test: (p) => /[A-Z]/.test(p),
    },
    digit: { label: trans('a number'), test: (p) => /\d/.test(p) },
    special: { label: trans('a symbol'), test: (p) => /[^A-Za-z0-9]/.test(p) },
};

const requirements = computed(() => {
    const rules = props.rules ?? '';
    const min = Number(/minlength:\s*(\d+)/.exec(rules)?.[1] ?? 0);
    const list: { label: string; met: boolean }[] = [];

    if (min > 0) {
        list.push({
            label: trans(':min or more characters', { min: String(min) }),
            met: props.password.length >= min,
        });
    }
    for (const [, key] of rules.matchAll(/required:\s*(\w+)/g)) {
        const rule = tests[key];
        if (rule)
            list.push({ label: rule.label, met: rule.test(props.password) });
    }

    return list;
});

/** 1 to 4. Up to five points (length 8, 12 and 16; two and three kinds of
 * character), scaled to four; held at Fair while a rule is still unmet. */
const score = computed(() => {
    const p = props.password;
    const kinds = Object.values(tests).filter((rule) => rule.test(p)).length;
    const points =
        Number(p.length >= 8) +
        Number(p.length >= 12) +
        Number(p.length >= 16) +
        Number(kinds >= 2) +
        Number(kinds >= 3);
    const scaled = Math.min(4, Math.max(1, Math.ceil((points * 4) / 5)));
    return requirements.value.some((rule) => !rule.met)
        ? Math.min(scaled, 2)
        : scaled;
});

const levels = [
    { label: trans('Weak'), tone: 'bg-(--ds-red)', text: 'text-(--ds-red)' },
    {
        label: trans('Fair'),
        tone: 'bg-(--ds-amber)',
        text: 'text-(--ds-amber)',
    },
    { label: trans('Good'), tone: 'bg-[#3B7BFF]', text: 'text-[#3B7BFF]' },
    {
        label: trans('Strong'),
        tone: 'bg-(--ds-green)',
        text: 'text-(--ds-green)',
    },
];

const level = computed(() => levels[score.value - 1]);
</script>

<template>
    <!--
        Opens when something is typed and closes when it's cleared. Only
        that change animates its height; each keystroke just moves the fills.
    -->
    <div
        class="grid transition-[grid-template-rows,opacity] duration-200 ease-out"
        :class="
            password
                ? 'grid-rows-[1fr] opacity-100'
                : 'grid-rows-[0fr] opacity-0'
        "
        aria-live="polite"
    >
        <div class="min-h-0 overflow-hidden">
            <div class="flex flex-col gap-2 pt-0.5">
                <div class="flex items-center gap-3">
                    <div class="flex grow gap-1" aria-hidden="true">
                        <span
                            v-for="step in 4"
                            :key="step"
                            class="h-1 flex-1 overflow-hidden rounded-full bg-(--ds-border-strong)"
                        >
                            <!-- Fills straight away, so it keeps up with typing -->
                            <span
                                class="block h-full origin-left rounded-full transition-[transform,background-color] duration-150 ease-out motion-reduce:transition-none"
                                :class="[
                                    level.tone,
                                    step <= score ? 'scale-x-100' : 'scale-x-0',
                                ]"
                            />
                        </span>
                    </div>
                    <span
                        class="w-12 shrink-0 text-right text-xs/4 font-medium"
                        :class="level.text"
                    >
                        {{ level.label }}
                    </span>
                </div>
                <ul
                    v-if="requirements.length"
                    class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs/4"
                >
                    <li
                        v-for="rule in requirements"
                        :key="rule.label"
                        class="flex items-center gap-1.5 transition-colors duration-150"
                        :class="
                            rule.met
                                ? 'text-(--ds-text-secondary)'
                                : 'text-(--ds-text-faint)'
                        "
                    >
                        <span
                            class="flex size-3.5 shrink-0 items-center justify-center rounded-full transition-[background-color,transform] duration-200 ease-[cubic-bezier(0.34,1.56,0.64,1)]"
                            :class="
                                rule.met
                                    ? 'scale-100 bg-(--ds-green)/15'
                                    : 'scale-90 bg-(--ds-hover)'
                            "
                        >
                            <Check
                                class="size-2.5 transition-[opacity,transform,color] duration-200 ease-[cubic-bezier(0.34,1.56,0.64,1)]"
                                :class="
                                    rule.met
                                        ? 'scale-100 text-(--ds-green) opacity-100'
                                        : 'scale-50 opacity-0'
                                "
                                :stroke-width="3"
                            />
                        </span>
                        {{ rule.label }}
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
