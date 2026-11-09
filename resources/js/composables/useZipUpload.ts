import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';

/**
 * Uploading a project .zip to a machine, in the pieces the panel asks for
 * (DeploySourceController), then having it unpacked and detected. Resolves
 * with the panel's answer: the repository URL and commit to deploy from,
 * and what kind of project it is.
 */
export type UploadedSource = {
    detected: boolean;
    template: string | null;
    framework: string | null;
    type: string | null;
    repo: string;
    branch: string;
    commit: string;
    reasons: string[];
    upload: { filename: string; files: number; expiresAt: string };
};

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function post(url: string, body: BodyInit, type: string) {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': type,
            'X-CSRF-TOKEN': csrf(),
        },
        body,
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(
            json.errors?.file?.[0] ??
                json.errors?.filename?.[0] ??
                json.errors?.size?.[0] ??
                json.error ??
                json.message ??
                trans('That upload didn’t work. Try again.'),
        );
    }
    return json;
}

export function useZipUpload() {
    const uploading = ref(false);
    /** 0 to 1 while sending; 1 while the panel unpacks it. */
    const progress = ref(0);
    const error = ref<string | null>(null);

    async function upload(
        machineId: number,
        file: File | undefined,
    ): Promise<UploadedSource | null> {
        if (!file || uploading.value) return null;
        if (!file.name.toLowerCase().endsWith('.zip')) {
            error.value = trans('Upload a .zip of your project folder.');
            return null;
        }

        uploading.value = true;
        progress.value = 0;
        error.value = null;
        const base = `/machines/${machineId}/sources`;

        try {
            const start = await post(
                base,
                JSON.stringify({ filename: file.name, size: file.size }),
                'application/json',
            );
            for (let offset = 0; offset < file.size; offset += start.chunk) {
                await post(
                    `${base}/${start.id}/chunk?offset=${offset}`,
                    file.slice(offset, offset + start.chunk),
                    'application/octet-stream',
                );
                progress.value = Math.min(
                    1,
                    (offset + start.chunk) / file.size,
                );
            }

            return (await post(
                `${base}/${start.id}/finish`,
                '{}',
                'application/json',
            )) as UploadedSource;
        } catch (cause) {
            error.value =
                cause instanceof Error
                    ? cause.message
                    : trans('That upload didn’t work. Try again.');
            return null;
        } finally {
            uploading.value = false;
        }
    }

    return { uploading, progress, error, upload, post };
}
