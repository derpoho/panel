<script setup lang="ts">
import { computed } from 'vue';

/**
 * A shell command, coloured like a terminal would: commands, flags, quoted
 * values and the pipe each in their own colour, a new line after each pipe
 * and every --option after a bare "--" on its own line. Display only;
 * copying uses the raw string.
 */
const props = defineProps<{ command: string }>();

type Kind = 'prompt' | 'command' | 'flag' | 'string' | 'operator' | 'text';
/** glue: sits against the token before it, like a value after its = */
type Token = { text: string; kind: Kind; glue?: boolean };

const lines = computed<Token[][]>(() => {
    const words = props.command.match(/'[^']*'|"[^"]*"|\S+/g) ?? [];
    const result: Token[][] = [[{ text: '$', kind: 'prompt' }]];
    let commandNext = true;
    let afterSeparator = false;

    for (const word of words) {
        const line = result[result.length - 1];

        if (afterSeparator && word.startsWith('--')) {
            line.push({ text: '\\', kind: 'operator' });
            result.push([]);
        }

        const current = result[result.length - 1];

        if (word === '|' || word === '&&' || word === ';') {
            // A pipe ends its line; what it feeds starts the next.
            current.push({ text: word, kind: 'operator' });
            result.push([]);
            commandNext = true;
            continue;
        }

        if (word === '--') {
            current.push({ text: word, kind: 'operator' });
            afterSeparator = true;
            continue;
        }

        const option = word.match(/^(--?[\w-]+=)(.+)$/);

        if (option) {
            current.push(
                { text: option[1], kind: 'flag' },
                { text: option[2], kind: 'string', glue: true },
            );
            continue;
        }

        if (commandNext || word === 'sudo') {
            current.push({ text: word, kind: 'command' });
            commandNext = word === 'sudo';
            continue;
        }

        current.push({
            text: word,
            kind: word.startsWith('-')
                ? 'flag'
                : /^['"]/.test(word)
                  ? 'string'
                  : 'text',
        });
    }

    return result;
});

const colours: Record<Kind, string> = {
    prompt: 'text-(--ds-text-faint) select-none',
    command: 'text-(--ds-blue-strong)',
    flag: 'text-(--ds-text-muted)',
    string: 'text-(--ds-green) break-all',
    operator: 'text-(--ds-text-faint)',
    text: 'text-(--ds-text)',
};
</script>

<template>
    <code
        class="block font-ui-mono text-[12.5px]/5.5 whitespace-pre-wrap"
        :aria-label="command"
    >
        <span
            v-for="(line, index) in lines"
            :key="index"
            class="block"
            :class="{ 'pl-6': index > 0 }"
            aria-hidden="true"
            ><template v-for="(token, tokenIndex) in line" :key="tokenIndex"
                >{{ tokenIndex > 0 && !token.glue ? ' ' : ''
                }}<span :class="colours[token.kind]">{{
                    token.text
                }}</span></template
            ></span
        >
    </code>
</template>
