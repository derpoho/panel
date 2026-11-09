export type WorkspaceBilling = {
    /** The plan whose limits apply: chosen, trial, or null after a trial ends. */
    plan: PlanKey | null;
    planLabel: string | null;
    interval: BillingInterval | null;
    subscriptionStatus: string | null;
    /** A renewal didn't go through: the plan ends then unless it's paid. */
    graceUntil?: string | null;
    hasBillingPortal: boolean;
    onTrial: boolean;
    trialEndsAt: string | null;
    trialDaysLeft: number | null;
    machineCount: number;
    /** Null means unlimited; 0 means a plan is needed first. */
    machineLimit: number | null;
    canAddMachine: boolean;
    /** Enterprise, Hosting Partner or MSP, agreed with sales: Business, billed its own way. */
    contract?: WorkspaceContract | null;
    /** Only on a self-hosted install: active with no plan, and nothing to pay. */
    selfHosted?: true;
};

export type WorkspaceContract = {
    key: string;
    /** Like "Enterprise (yearly)". */
    label: string;
    machines: number;
    included: number;
    extra: number;
    /** Pence for the interval. */
    price: number;
    /** Pence for each machine past the included ones. */
    extraPrice: number;
    interval: BillingInterval;
};

export type Workspace = {
    id: number;
    name: string;
    slug: string;
    /** The workspace's hostname, e.g. acme.serveros.com. */
    host: string;
    logoUrl: string | null;
    billing: WorkspaceBilling;
};

export type WorkspaceRoleKey = 'owner' | 'admin' | 'member' | 'viewer';

export type WorkspacePermission =
    | 'manage-billing'
    | 'manage-settings'
    | 'manage-members'
    | 'manage-machines';

/** The signed-in user's role in the current workspace. */
export type WorkspaceAccess = {
    role: WorkspaceRoleKey;
    permissions: WorkspacePermission[];
};

export type WorkspaceRoleOption = {
    value: WorkspaceRoleKey;
    label: string;
    description: string;
    /** Staff roles (Member, Viewer) need Pro or Business. */
    staff: boolean;
    permissions: WorkspacePermission[];
};

export type WorkspaceMember = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    role: WorkspaceRoleKey;
    joinedAt: string | null;
    isYou: boolean;
};

export type WorkspaceInvitation = {
    id: number;
    email: string;
    role: WorkspaceRoleKey;
    invitedBy: string | null;
    expiresAt: string;
    expired: boolean;
};

/** A workspace as listed in the workspace switcher. */
export type WorkspaceSummary = {
    id: number;
    name: string;
    slug: string;
    host: string;
    /** Absolute URL of the workspace home on its subdomain. */
    url: string;
    /** Lands in the workspace with a "Switched to" toast. */
    switchUrl: string;
    logoUrl: string | null;
    /** When the user pinned it to their sidebar, or null. */
    pinnedAt: string | null;
};

export type PlanKey = 'starter' | 'pro' | 'business';

export type BillingInterval = 'monthly' | 'annual';

export type Plan = {
    value: PlanKey;
    label: string;
    summary: string;
    /** Pence. */
    monthlyPrice: number;
    /** Pence per year. */
    annualPrice: number;
    machineLimit: number | null;
    /** AI tool calls a month. */
    mcpCallLimit: number;
    monitorLimit: number;
    /** Seconds between uptime checks, at the fastest. */
    minMonitorInterval: number;
    logRetentionDays: number;
    /** Log lines kept a day across the workspace. */
    logLinesPerDay: number;
    /** Days of activity kept. */
    activityRetentionDays: number;
    features: string[];
};

/** AI tool calls this month, as McpUsage::summary() sends them. */
export type McpCallsUsage = {
    used: number;
    /** The plan's (or a deal's) calls a month; null for unlimited. */
    included: number | null;
    /** Calls from packs bought this month. */
    extra: number;
    /** Included plus extra; null for unlimited. */
    limit: number | null;
    unlimited: boolean;
    resetsAt: string;
    packCalls: number;
    /** In the currency's smallest unit. */
    packPrice: number;
    /** What packPrice is in: GBP, USD or EUR. */
    currency: string;
    maxPacks: number;
};

export type OnboardingStepKey =
    | 'workspace'
    | 'machine'
    | 'service'
    | 'backups'
    | 'plan';

export type OnboardingStep = {
    key: OnboardingStepKey;
    done: boolean;
};

/** The setup guide in the top bar; null once it's done or hidden. */
export type SetupGuide = {
    percent: number;
    groups: {
        key: string;
        title: string;
        /** Why it can't be started yet, or null. */
        locked: string | null;
        steps: {
            key: string;
            title: string;
            body: string;
            href: string;
            done: boolean;
        }[];
    }[];
};

export type WorkspaceStats = {
    machines: number;
    environments: Record<'production' | 'staging' | 'development', number>;
    services: number;
    averageCpu: number;
    busy: number;
    backedUp: number;
    /** Average CPU over the last hour, in 5-minute steps, oldest first. */
    cpuHistory: (number | null)[];
};
