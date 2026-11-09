import { trans } from 'laravel-vue-i18n';
export type Environment = 'production' | 'staging' | 'development';

export type Machine = {
    /** Present for machines loaded from the database. */
    id?: number;
    name: string;
    ip: string;
    environment: Environment;
    provider: string;
    /** Set by hand: detection leaves it alone. */
    providerManual?: boolean;
    region?: string | null;
    regionManual?: boolean;
    tier?: string | null;
    /** The workspace's own labels, key to value. */
    labels?: Record<string, string>;
    services: number;
    memory: string;
    /** Memory used, for adding up across a group. */
    memoryMb?: number | null;
    cpu: number;
    /** The machine group it's in, if any. */
    groupId?: number | null;
    lastBackup: string;
    /** Still being set up: shows an animated Installing badge instead of the environment. */
    installing?: boolean;
    /** A new server's setup stage, while it's setting up. */
    setup?: 'connecting' | 'discovering' | 'securing' | null;
    /** Scan findings, shortly after setup finishes. */
    setupFindings?: number | null;
    uid?: string | null;
    /** An icon someone uploaded for the machine. */
    iconUrl?: string | null;
    connection?: 'demo' | 'connecting' | 'online' | 'degraded' | 'offline';
    daemonVersion?: string | null;
    lastSeen?: string | null;
    /** Live usage per mount, fullest first. */
    disks?: { mount: string; used: number; total: number }[];
    diskPercent?: number | null;
    /** Planned work under way: alerts are held until then. */
    maintenance?: {
        until: string;
        reason: string | null;
        public: boolean;
    } | null;
    /** Maintenance planned ahead, soonest first, and one under way. */
    plannedMaintenance?: PlannedMaintenance[];
    facts?: {
        os?: string;
        os_version?: string;
        kernel?: string;
        arch?: string;
        cpu_model?: string;
        cpu_cores?: number;
        memory_bytes?: number;
        disks?: {
            mount: string;
            device: string;
            fs_type: string;
            total_bytes: number;
        }[];
        interfaces?: {
            name: string;
            addresses: string[];
            mac?: string | null;
        }[];
        hosting?: {
            provider?: string | null;
            region?: string | null;
            zone?: string | null;
            instance_type?: string | null;
            source?: string | null;
        };
    } | null;
    inventory?: {
        scanned_at: number;
        complete: boolean;
        services: {
            key: string;
            name: string;
            /** Apps ServerOS deployed from code: the app's name, which stays
             * the same across versions (its page is app:<name>). */
            app?: string | null;
            /** A name someone gave it in the panel, shown instead. */
            label?: string | null;
            /** What a container runs, when its own name is a UUID (Pterodactyl's). */
            found?: string | null;
            /** An icon someone uploaded for it. */
            icon?: string | null;
            /** Hidden from the panel's lists by someone; still on the machine. */
            hidden?: boolean;
            /** Its log lines aren't kept (the service page sets this). */
            logsMuted?: boolean;
            kind: string;
            manager: string;
            status: string;
            ports: number[];
            confidence: number;
            version?: string | null;
            working_dir?: string | null;
            user?: string | null;
            exec?: string | null;
            config_paths?: string[];
            data_dir?: string | null;
            capabilities?: string[];
            origin?: 'discovered' | 'created';
            details?: Record<string, string>;
        }[];
        listeners: {
            proto: string;
            address: string;
            port: number;
            pid: number | null;
            exe: string | null;
        }[];
        unknown: { port: number; exe: string | null; note: string }[];
        warnings: string[];
    } | null;
    /** The firewall and what it lets in, from the last scan. */
    firewall?: FirewallView | null;
};

/** Whether the internet can reach a port; null from daemons that don't say. */
export type FirewallExposure =
    | 'open'
    | 'restricted'
    | 'blocked'
    | 'docker'
    | 'unknown';

export type FirewallView = {
    /** False from daemons that don't read the firewall yet. */
    supported: boolean;
    backend: string | null;
    installed: boolean;
    active: boolean;
    defaultIncoming: 'allow' | 'limit' | 'deny' | 'reject' | null;
    /** Why ports can't be closed here, or null. */
    unavailable: string | null;
    ports: {
        port: number;
        proto: string;
        process: string | null;
        exposure: FirewallExposure | null;
        because: string | null;
        /** A database or cache that shouldn't face the internet. */
        what: string | null;
        ssh: boolean;
    }[];
    rules: {
        verdict: 'allow' | 'limit' | 'deny' | 'reject';
        ports: string;
        proto: string | null;
        source: string | null;
        ipv6: boolean;
        managed: boolean;
        /** Matches ServerOS can't read: shown, not acted on. */
        opaque: boolean;
        text: string;
    }[];
    unreadable: number;
    notes: string[];
    presets: { label: string; port: number; proto: string }[];
};

export const environmentLabels: Record<Environment, string> = {
    production: trans('Production'),
    staging: trans('Staging'),
    development: trans('Development'),
};

export type MachineColumnKey =
    | 'machine'
    | 'environment'
    | 'provider'
    | 'services'
    | 'memory'
    | 'cpu'
    | 'lastBackup'
    | 'actions';

/** Width classes per column, shared by the table header and rows. */
export type MachineColumns = Record<MachineColumnKey, string>;

export const columnLabels: Record<
    Exclude<MachineColumnKey, 'actions'>,
    string
> = {
    machine: trans('Machine'),
    environment: trans('Environment'),
    provider: trans('Provider'),
    services: trans('Services'),
    memory: trans('Memory'),
    cpu: 'CPU',
    lastBackup: trans('Last backup'),
};

/** Widths used by the workspace machines table. */
export const tableColumns: MachineColumns = {
    machine: 'min-w-62.5 flex-1',
    environment: 'w-40 shrink-0',
    provider: 'w-35 shrink-0',
    services: 'w-22.5 shrink-0',
    memory: 'w-25 shrink-0',
    cpu: 'w-47.5 shrink-0',
    lastBackup: 'w-37.5 shrink-0',
    actions: 'w-15.5 shrink-0',
};

export type PlannedMaintenance = {
    id: number;
    startsAt: string;
    endsAt: string;
    reason: string | null;
    state: 'scheduled' | 'active' | 'done' | 'cancelled';
    action: 'none' | 'reboot' | 'updates';
    public: boolean;
    /** While its work runs: updating, rebooting, then back. */
    phase: 'updating' | 'rebooting' | 'back' | null;
    outcome: string | null;
    by: string | null;
};
