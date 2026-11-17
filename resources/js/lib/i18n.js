// Tiny i18n store (same pattern as TPDA e-Sheba). Translations arrive as
// Inertia shared props (decoded server-side from lang/{locale}.json) and
// are cached in localStorage keyed by translations_version, so the full
// file is not re-downloaded on every Inertia response.

let current = { locale: 'en', translations: {} };

export function initI18n({ locale, translations, translations_version }) {
    const cachedVersion = localStorage.getItem('skaleup_translations_version');
    const cachedLocale = localStorage.getItem('skaleup_translations_locale');

    if (
        translations &&
        translations_version &&
        (translations_version !== cachedVersion || locale !== cachedLocale)
    ) {
        localStorage.setItem('skaleup_translations', JSON.stringify(translations));
        localStorage.setItem('skaleup_translations_version', translations_version);
        localStorage.setItem('skaleup_translations_locale', locale);
        current = { locale, translations };
        return;
    }

    if (translations) {
        current = { locale, translations };
        return;
    }

    try {
        const cached = JSON.parse(localStorage.getItem('skaleup_translations') || '{}');
        current = { locale: cachedLocale || locale || 'en', translations: cached };
    } catch {
        current = { locale: locale || 'en', translations: {} };
    }
}

export function getLocale() {
    return current.locale;
}

export function t(key, replacements = {}) {
    let value = current.translations[key] ?? key;
    for (const [name, replacement] of Object.entries(replacements)) {
        value = value.replaceAll(`:${name}`, String(replacement));
    }
    return value;
}
