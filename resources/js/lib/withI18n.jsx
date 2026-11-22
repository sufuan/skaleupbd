import { usePage } from '@inertiajs/react';
import { initI18n } from './i18n';

const cache = new WeakMap();

/**
 * Wrap a page component so the i18n store syncs from the CURRENT page
 * props during the wrapper's render - BEFORE the wrapped page's own
 * function body runs. Without this, after a language switch the layout
 * would render in the new language while the page content still showed
 * the previous one until an unrelated re-render (TPDA pattern).
 */
export function withI18n(Page) {
    if (cache.has(Page)) {
        return cache.get(Page);
    }

    function I18nSyncedPage(props) {
        const { props: pageProps } = usePage();
        initI18n({
            locale: pageProps.locale,
            translations: pageProps.translations,
            translations_version: pageProps.translations_version,
        });
        return <Page {...props} />;
    }

    cache.set(Page, I18nSyncedPage);
    return I18nSyncedPage;
}
