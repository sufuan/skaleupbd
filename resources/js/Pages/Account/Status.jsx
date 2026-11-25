import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

export default function Status({ status, note, shopName }) {
    const rejected = status === 'rejected';
    return (
        <AppLayout>
            <Head title={rejected ? t('Account not approved') : t('Account under review')} />
            <div style={{ maxWidth: 640, margin: '24px auto' }}>
                <div className="card" style={{ textAlign: 'center', padding: '28px 22px' }}>
                    <div style={{ fontSize: 34, lineHeight: 1 }}>{rejected ? '✕' : '◷'}</div>
                    {rejected ? (
                        <>
                            <h3 style={{ fontSize: 20, margin: '12px 0 8px' }}>{t('Your account was not approved')}</h3>
                            <p style={{ color: 'var(--muted)', fontSize: 14, lineHeight: 1.6 }}>
                                {t('We could not approve')} <b>{shopName}</b> {t('right now.')}
                            </p>
                            {note && (
                                <div style={{ background: '#FDECEC', border: '1px solid #F3C2C2', borderRadius: 10, padding: '12px 14px', margin: '14px 0', fontSize: 14, textAlign: 'left' }}>
                                    <b>{t('Reason from our team')}:</b> {note}
                                </div>
                            )}
                            <p style={{ color: 'var(--muted)', fontSize: 13.5, lineHeight: 1.6 }}>
                                {t('If you think this is a mistake, contact us and we will look again.')}
                            </p>
                        </>
                    ) : (
                        <>
                            <h3 style={{ fontSize: 20, margin: '12px 0 8px' }}>{t('Your account is under review')}</h3>
                            <p style={{ color: 'var(--muted)', fontSize: 14, lineHeight: 1.6 }}>
                                {t('Thanks for joining Scalenow Bd. Our team is checking')} <b>{shopName}</b> {t('now. This usually takes a short time.')}
                            </p>
                            <div style={{ textAlign: 'left', margin: '16px auto 4px', maxWidth: 460, fontSize: 14, lineHeight: 1.9 }}>
                                <div><b>1.</b> {t('We check your shop details.')}</div>
                                <div><b>2.</b> {t('As soon as we approve, your full dashboard opens.')}</div>
                                <div><b>3.</b> {t('You can already buy a points pack, so your shop is ready the moment it is approved.')}</div>
                            </div>
                            <div style={{ display: 'flex', gap: 10, justifyContent: 'center', marginTop: 18, flexWrap: 'wrap' }}>
                                <Link href="/billing" className="btn-primary" style={{ textDecoration: 'none' }}>{t('Go to Points & Billing')}</Link>
                                <button className="btn" onClick={() => router.post('/logout')}>{t('Log out')}</button>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
