/*
 * The web installer's calls that answer in JSON rather than with a page:
 * trying database and email settings, checking the address, and setting
 * up the tables (a line of JSON as each one is done).
 */

export type Check = { status: 'ok' | 'warn' | 'fail'; text: string };

function headers(): Record<string, string> {
    const xsrf = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);
    const meta =
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? '';

    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(xsrf
            ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
            : { 'X-CSRF-TOKEN': meta }),
    };
}

export async function postJson<T>(
    url: string,
    body: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        headers: headers(),
        body: JSON.stringify(body),
        credentials: 'same-origin',
    });

    try {
        return (await response.json()) as T;
    } catch {
        throw new Error(`The panel answered ${response.status}.`);
    }
}

export type MigrateLine =
    | { total: number }
    | { done: number }
    | { finished: true }
    | { error: string };

/** Posts, then hands over each line of JSON as it arrives. */
export async function postLines(
    url: string,
    onLine: (line: MigrateLine) => void,
): Promise<void> {
    const response = await fetch(url, {
        method: 'POST',
        headers: headers(),
        body: '{}',
        credentials: 'same-origin',
    });
    if (!response.ok || !response.body) {
        let message = `The panel answered ${response.status}.`;
        try {
            message =
                ((await response.json()) as { message?: string }).message ??
                message;
        } catch {
            // Not JSON: the status will do.
        }
        throw new Error(message);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    for (;;) {
        const { done, value } = await reader.read();
        buffer += decoder.decode(value, { stream: !done });
        const lines = buffer.split('\n');
        buffer = lines.pop() ?? '';
        for (const line of lines) {
            if (line.trim()) onLine(JSON.parse(line) as MigrateLine);
        }
        if (done) break;
    }
    if (buffer.trim()) onLine(JSON.parse(buffer) as MigrateLine);
}
