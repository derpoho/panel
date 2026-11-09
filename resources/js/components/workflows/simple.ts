import { trans } from 'laravel-vue-i18n';
import { inject, provide } from 'vue';
import type { InjectionKey, Ref } from 'vue';
import type { Options, PortId, RunStep, StepKind, WorkflowNode } from './kinds';

/** A path leaving a step some other way than its main one. */
export type SimpleSide = {
    port: PortId;
    label: string;
    chain: SimpleStep[];
    /** It joins a step already shown above. */
    jump: WorkflowNode | null;
    /** The step it leaves. */
    from: string;
    /** Steps run alongside the main path: nothing to add here. */
    parallel: boolean;
};

export type SimpleStep = {
    node: WorkflowNode;
    sides: SimpleSide[];
    jump: WorkflowNode | null;
};

/** What every card in easy mode shares. */
export type SimpleContext = {
    open: Ref<string | null>;
    toggle: (id: string) => void;
    add: (kind: StepKind, from: string, port: PortId) => void;
    remove: (node: WorkflowNode) => void;
    detail: (node: WorkflowNode) => string;
    mainPort: (node: WorkflowNode) => PortId;
    runStep: (id: string) => RunStep | null;
    problems: () => Record<string, string>;
    options: () => Options;
    webhookUrl: () => string | null;
    twoFactor: () => boolean;
    rotateWebhook: () => void;
};

const KEY: InjectionKey<SimpleContext> = Symbol('workflow-simple');

export function provideSimple(context: SimpleContext): void {
    provide(KEY, context);
}

export function useSimple(): SimpleContext {
    const context = inject(KEY);
    if (!context) throw new Error('No easy-mode context.');

    return context;
}

/** The add menu, in everyday words. */
export const PLAIN: Record<StepKind, string> = {
    trigger: trans('When…'),
    redeploy: trans('Deploy the latest code'),
    release: trans('Release an app from GitHub'),
    restart: trans('Restart an app'),
    start: trans('Start an app'),
    stop: trans('Stop an app'),
    command: trans('Run a command'),
    agent: trans('Ask a coding agent'),
    backup: trans('Back up a machine'),
    copy_data: trans('Copy data into Staging'),
    promote: trans('Promote Staging'),
    check_monitor: trans('Check a site is up'),
    http: trans('Call a URL'),
    alert: trans('Send an email'),
    wait: trans('Wait a few minutes'),
    branch: trans('Check something (yes or no)'),
};
