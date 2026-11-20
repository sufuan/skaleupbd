import { Head } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

export default function Limits() {
    return (
        <AppLayout>
            <Head title={t('Reports — My Limits')} />
            <div style={{ maxWidth: 560, background: '#fff', border: '1px solid #E7EAF0', borderRadius: 16, padding: 26 }}>
                <h3 style={{ fontSize: 19, marginBottom: 8 }}>{t('My Limits — not here')}</h3>
                <p style={{ color: '#64748B', fontSize: 14, lineHeight: 1.6 }}>{t('Plan limits and balances (SMS, Auto Call) are now seen in')} <b>{t('Settings')}</b>{t('-page, and the SMS balance is on the Marketing page too — the same thing is not kept in two places. This page is therefore kept closed.')}</p>
                <p style={{ color: '#94A3B8', fontSize: 13, marginTop: 12 }}>{t('If you come by a direct link you will see this very text; My Limits is no longer in the menu.')}</p>
            </div>
        </AppLayout>
    );
}
