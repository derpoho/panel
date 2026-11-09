/* What InstallController sends each step of the web installer. */

export type InstallStep =
    | 'code'
    | 'check'
    | 'database'
    | 'address'
    | 'mail'
    | 'owner'
    | 'done';

export type Status = 'ok' | 'warn' | 'fail';

export type CheckItem = {
    key: string;
    label: string;
    status: Status;
    detail: string;
    fix: string | null;
    /** A command that fixes it, to copy. */
    command: string | null;
};

export type DatabaseDriver = 'pgsql' | 'mysql' | 'sqlite';

export type DatabaseProps = {
    status: {
        connected: boolean;
        error: string | null;
        driver: string | null;
        pending: number;
        ready: boolean;
    };
    editable: boolean;
    settings: {
        driver: DatabaseDriver;
        label: string;
        host: string | null;
        port: string | number | null;
        database: string | null;
        username: string | null;
    };
    hasPassword: boolean;
    drivers: Record<DatabaseDriver, boolean>;
    sqlitePath: string;
};

export type AddressProps = {
    url: string;
    suggested: string;
    editable: boolean;
};

export type MailProps = {
    editable: boolean;
    saved: boolean;
    host: string | null;
    port: number | string | null;
    username: string | null;
    hasPassword: boolean;
    fromAddress: string | null;
    fromName: string;
};

export type OwnerProps = {
    passwordRules: string;
    saved: { name: string; email: string; workspace: string } | null;
};

export type Summary = {
    address: string;
    database: string;
    mail: string | null;
    owner: { name: string; email: string };
    workspace: { name: string; address: string };
};

/** The installer's own address for a step, to go back to it. */
export function stepUrl(step: InstallStep): string {
    return `/install?step=${step}`;
}
