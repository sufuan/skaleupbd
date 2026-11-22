import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';

export default function AutoPick({ queues, autoOpen, myToday }) {
    return (
        <AppLayout>
            <Head title={t('Auto Pick Orders')} />
            <h1 className="page-h">{t('Auto Pick Orders')}</h1>
            <div className="page-sub">{t('Shared queues - open the next order in a status and process it yourself. Nothing is assigned in advance.')}</div>

            <div className="card mb12">
                <div className="flex">
                    <div>
                        <div className="b">{t('Open next automatically after status update')}</div>
                        <div className="sm mut">{t('When on, approving an order loads the next one in the same queue.')}</div>
                    </div>
                    <span className="spacer" />
                    <button className={`switch ${autoOpen ? 'on' : ''}`} aria-label="toggle"
                        onClick={() => router.post('/orders/autopick/toggle', { enabled: !autoOpen }, { preserveScroll: true })} />
                </div>
            </div>

            <div className="grid" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(230px,1fr))' }}>
                {queues.map((q) => (
                    <div className="card" key={q.status}>
                        <div className="sm mut">{t('Queue')}</div>
                        <h3 style={{ marginTop: 4 }}>{t(q.status)}</h3>
                        <div style={{ fontSize: 30, fontWeight: 800, margin: '6px 0' }}>{q.count}</div>
                        <button className="btn pri" disabled={q.count === 0}
                            onClick={() => router.get(`/orders/autopick/${encodeURIComponent(q.status)}/next`)}>{t('Open Next')}</button>
                    </div>
                ))}
            </div>

            <div className="card mt16">
                <h3>{t('My Daily Report')}</h3>
                <div className="card-sub">{t('What you actually processed today - counted from the processing history.')}</div>
                <div className="flex" style={{ gap: 14 }}>
                    <div className="stat-box" style={{ minWidth: 150 }}>
                        <div className="sm mut">{t('Orders approved by me today')}</div>
                        <div style={{ fontSize: 26, fontWeight: 800, color: '#5b2a86' }}>{myToday}</div>
                    </div>
                    <Link href="/orders" className="btn">{t('Open the Order List')}</Link>
                </div>
            </div>
        </AppLayout>
    );
}
