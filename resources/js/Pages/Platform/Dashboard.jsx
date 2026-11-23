import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

const tk = (n) => `৳${Number(n || 0).toLocaleString('en-IN')}`;

function Kpi({ label, value, sub }) {
    return (
        <div className="card">
            <div style={{ fontSize: 11, letterSpacing: '.06em', color: 'var(--muted)' }}>{label}</div>
            <div style={{ fontSize: 26, fontWeight: 700, marginTop: 4 }}>{value}</div>
            {sub ? <div style={{ fontSize: 12, color: 'var(--muted)' }}>{sub}</div> : null}
        </div>
    );
}

export default function SellerDashboard({ stats, recentPayments, topSellers, expiring, packages }) {
    const maxPkg = Math.max(1, ...packages.map((p) => p.sellers));

    return (
        <AppLayout>
            <Head title={t('Seller Dashboard')} />
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <h3 style={{ fontSize: 19 }}>{t('Seller Dashboard')}</h3>
                <span style={{ marginLeft: 'auto', display: 'flex', gap: 8 }}>
                    <Link className="btn-ghost" href="/settings?group=packages">{t('Packages')}</Link>
                    <Link className="btn-primary" href="/admin/sellers">{t('All Sellers')}</Link>
                </span>
            </div>

            <div className="plat-kpis" style={{ display: 'grid', gridTemplateColumns: 'repeat(5,1fr)', gap: 12, margin: '14px 0' }}>
                <Kpi label={t('TOTAL SELLERS')} value={stats.sellers} sub={t('Shops using SkaleUp')} />
                <Kpi label={t('ACTIVE SELLERS')} value={stats.active} sub={`${stats.expired} ${t('expired')}`} />
                <Kpi label={t('PACKAGE INCOME')} value={tk(stats.income_total)} sub={t('All time, from sellers')} />
                <Kpi label={t('INCOME THIS MONTH')} value={tk(stats.income_month)} sub={t('Package payments')} />
                <Kpi label={t('EXPIRING SOON')} value={stats.expiring_soon} sub={t('Within 7 days')} />
            </div>

            <div className="plat-kpis" style={{ display: 'grid', gridTemplateColumns: 'repeat(5,1fr)', gap: 12, margin: '0 0 14px' }}>
                <Kpi label={t('PENDING APPROVALS')} value={stats.pending_approvals} sub={t('Shops waiting for approval')} />
                <Kpi label={t('NEW SELLERS TODAY')} value={stats.new_today} sub={t('Joined today')} />
                <Kpi label={t('TOTAL ORDERS')} value={stats.orders_total} sub={t('Across all shops')} />
                <Kpi label={t('TOTAL SALES')} value={tk(stats.sales_total)} sub={t('Across all shops')} />
                <Kpi label={t('POINTS SOLD')} value={Number(stats.points_sold || 0).toLocaleString('en-IN')} sub={t('Points bought by sellers')} />
            </div>

            <div className="plat-2col" style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12, alignItems: 'start' }}>
                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Top sellers by sales')}</h4>
                    <div className="tbl-wrap">
                        <table className="tbl">
                            <thead><tr><th>{t('Seller')}</th><th>{t('Package')}</th><th>{t('Orders')}</th><th>{t('Sales')}</th></tr></thead>
                            <tbody>
                                {topSellers.map((s) => (
                                    <tr key={s.id}><td><Link href={`/admin/sellers/${s.id}`} style={{ fontWeight: 600 }}>{s.name}</Link></td><td>{s.package || '-'}</td><td>{s.orders}</td><td style={{ fontWeight: 600 }}>{tk(s.revenue)}</td></tr>
                                ))}
                                {topSellers.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No sellers yet')}</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Recent payments from sellers')}</h4>
                    <div className="tbl-wrap">
                        <table className="tbl">
                            <thead><tr><th>{t('Seller')}</th><th>{t('Amount')}</th><th>{t('Method')}</th><th>{t('When')}</th></tr></thead>
                            <tbody>
                                {recentPayments.map((p) => (
                                    <tr key={p.id}><td style={{ fontWeight: 600 }}>{p.seller}</td><td style={{ fontWeight: 600 }}>{tk(p.amount)}</td><td>{p.method}</td><td>{p.when}</td></tr>
                                ))}
                                {recentPayments.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('No payments yet')}</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="card">
                    <h4 style={{ fontSize: 15, marginBottom: 10 }}>{t('Sellers by package')}</h4>
                    {packages.map((p) => (
                        <div key={p.name} style={{ marginBottom: 10 }}>
                            <div style={{ display: 'flex', fontSize: 13 }}><span style={{ fontWeight: 600 }}>{p.name}</span><span style={{ marginLeft: 'auto' }}>{p.sellers} {t('sellers')} · {tk(p.price)}</span></div>
                            <div style={{ height: 8, background: 'var(--surface2, #EFE9F2)', borderRadius: 99, marginTop: 4 }}>
                                <div style={{ height: 8, width: `${Math.round(p.sellers / maxPkg * 100)}%`, background: '#411550', borderRadius: 99 }} />
                            </div>
                        </div>
                    ))}
                    {packages.length === 0 && <div style={{ color: 'var(--muted)', fontSize: 13 }}>{t('No packages yet')}</div>}
                </div>

                <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                    <h4 style={{ fontSize: 15, padding: '14px 14px 6px' }}>{t('Expiring within 7 days')}</h4>
                    <div className="tbl-wrap">
                        <table className="tbl">
                            <thead><tr><th>{t('Seller')}</th><th>{t('Package')}</th><th>{t('Expires')}</th><th>{t('Days left')}</th></tr></thead>
                            <tbody>
                                {expiring.map((s) => (
                                    <tr key={s.id}><td><Link href={`/admin/sellers/${s.id}`} style={{ fontWeight: 600 }}>{s.name}</Link></td><td>{s.package}</td><td>{s.expires_at}</td><td><span className="pill amber">{s.days_left} {t('days')}</span></td></tr>
                                ))}
                                {expiring.length === 0 && <tr><td colSpan={4} style={{ textAlign: 'center', padding: 24, color: 'var(--muted)' }}>{t('Nobody is expiring soon')}</td></tr>}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
