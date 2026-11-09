/**
 * Documents staff send customers from /admin (the DPA, SLA and so on):
 * what a send looks like on the pages, and the colour of each kind's pill.
 */
export type DocumentKind = 'dpa' | 'sla' | 'security' | 'other' | 'upload';

export type DocumentSendRow = {
    id: number;
    workspace: { id: number; name: string } | null;
    sentBy: string;
    recipients: string[];
    bcc: string[];
    subject: string;
    message: string;
    documents: {
        name: string;
        kind: DocumentKind;
        templateId: number | null;
        filename: string;
        size: number;
        url: string;
    }[];
    createdAt: string;
};

/** A workspace's company, as staff fill it in for agreements. */
export type CompanyDetails = {
    legal_name: string | null;
    number: string | null;
    tax_id: string | null;
    address: string | null;
    country: string | null;
    contact_name: string | null;
    contact_title: string | null;
    contact_email: string | null;
};

export const documentKindTone: Record<DocumentKind, string> = {
    dpa: 'bg-(--ds-blue)/12 text-(--ds-blue)',
    sla: 'bg-(--ds-green)/12 text-(--ds-green)',
    security: 'bg-(--ds-amber)/14 text-(--ds-amber)',
    other: 'bg-(--ds-hover) text-(--ds-text-secondary)',
    upload: 'bg-(--ds-hover) text-(--ds-text-muted)',
};

/** "84 KB". */
export function fileSize(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.max(1, Math.round(bytes / 1024))} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}
