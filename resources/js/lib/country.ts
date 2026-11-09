const names = new Intl.DisplayNames(['en'], { type: 'region' });

/** A country code from Cloudflare as a name: "GB" is United Kingdom, T1 is Tor. */
export function countryName(code: string): string {
    if (code === 'T1') return 'Tor';
    try {
        return names.of(code) ?? code;
    } catch {
        return code;
    }
}

/** The country's flag, from flagcdn.com; none for Tor. */
export function countryFlagUrl(code: string): string | null {
    return /^[A-Z]{2}$/.test(code)
        ? `https://flagcdn.com/${code.toLowerCase()}.svg`
        : null;
}
