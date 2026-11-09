/**
 * A problem WorkspaceHealth found: on Home for the whole workspace, and on
 * a machine's page for that machine. `ask` is set when Ask AI can look into
 * it and fix it with the person (Fix with Ask AI).
 */
export type HealthIssue = {
    id: string;
    severity: 'critical' | 'warning' | 'notice';
    title: string;
    detail: string;
    href: string;
    action: string;
    since: string | null;
    machineId: number | null;
    ask?: string | null;
};
