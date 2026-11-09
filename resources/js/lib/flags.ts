/** Each language's flag, from flagcdn.com (English shows the UK's). */
const FLAGS: Record<string, string> = {
    en: 'gb',
    es: 'es',
    de: 'de',
    hi: 'in',
};

export function flagUrl(locale: string): string {
    return `https://flagcdn.com/${FLAGS[locale] ?? locale}.svg`;
}
