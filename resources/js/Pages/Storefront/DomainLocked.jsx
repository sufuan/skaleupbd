import { Link } from '@inertiajs/react';
import { t } from '@/lib/i18n';

/** Rule 2 lock banner: shown on Storefront pages until the seller's
 *  first pack payment is confirmed. */
export default function DomainLocked() {
    return (
        <div style={{ background: 'var(--note-bg)', border: '1px solid var(--lock-line)', color: 'var(--lock-ink)', borderRadius: 10, padding: '14px 16px', marginBottom: 14, fontSize: 14.5, display: 'flex', gap: 12, alignItems: 'center', flexWrap: 'wrap' }}>
            <span style={{ flex: 1, minWidth: 220 }}>{t('Your shop address unlocks after your first pack payment is confirmed.')}</span>
            <Link className="btn pri" href="/billing" style={{ textDecoration: 'none' }}>{t('Go to Points & Billing')}</Link>
        </div>
    );
}
