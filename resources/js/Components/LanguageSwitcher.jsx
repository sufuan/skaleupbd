import { router, usePage } from '@inertiajs/react';

/** EN | বাংলা segmented switch — posts to the locale route (TPDA pattern). */
export default function LanguageSwitcher({ className = '' }) {
    const { locale } = usePage().props;
    const current = locale || 'en';

    const choose = (next) => {
        if (next !== current) {
            router.post(`/locale/${next}`, {}, { preserveScroll: true });
        }
    };

    return (
        <div className={`lang-switch ${className}`} role="group" aria-label="Language">
            <button type="button" className={current === 'en' ? 'on' : ''} onClick={() => choose('en')}>EN</button>
            <button type="button" className={current === 'bn' ? 'on' : ''} onClick={() => choose('bn')}>বাংলা</button>
        </div>
    );
}
