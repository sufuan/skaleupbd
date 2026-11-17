import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link } from '@inertiajs/react';

export default function ModulePlaceholder({ title }) {
    return (
        <AppLayout>
            <Head title={title} />
            <h1 className="page-h">{title}</h1>
            <div className="card" style={{ textAlign: 'center', padding: '52px 18px' }}>
                <h3>{t('Module is not developed yet')}</h3>
                <p className="mut" style={{ marginTop: 8 }}>{t('Coming Soon')}</p>
                <div style={{ marginTop: 18 }}>
                    <Link href="/dashboard" className="btn pri">{t('Back to Dashboard')}</Link>
                </div>
            </div>
        </AppLayout>
    );
}
