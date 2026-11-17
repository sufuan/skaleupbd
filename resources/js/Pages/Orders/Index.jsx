import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { StatusPill, SuccessRing, fmt } from './ui';

const TABS = ['All', 'Pending', 'Processing', 'Shipped', 'Delivered', 'Returned', 'Cancelled'];

export default function Index({ rows, counts, filters, pagination, pendingCount }) {
    const [search, setSearch] = useState(filters.search || '');
    const go = (patch) => router.get('/orders', { status: filters.status, search: filters.search, per_page: filters.per_page, ...patch }, { preserveState: true, replace: true });

    return (
        <AppLayout>
            <Head title={t('Orders')} />
            <div className="flex" style={{ flexWrap: 'wrap' }}>
                <div>
                    <h1 className="page-h">{t('Orders')}</h1>
                    <div className="page-sub">{t('Every order in one place — pending, processing, shipped and delivered.')}</div>
                </div>
                <span className="spacer" />
                <div className="flex">
                    <input className="inp" style={{ width: 240 }} placeholder={t('Search invoice, phone or name')} value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && go({ search, page: 1 })} />
                    <Link href="/orders/create" className="btn pri">{t('New Order')}</Link>
                    <button className="btn" disabled={pendingCount === 0} onClick={() => router.get('/orders/next')}>
                        {t('Open Next Order')} ({pendingCount})
                    </button>
                </div>
            </div>

            <div className="ptabs">
                {TABS.map((s) => (
                    <span key={s} className={`ptab ${filters.status === s ? 'act' : ''}`} onClick={() => go({ status: s, page: 1 })}>
                        {t(s)} <span className="pill" style={{ marginLeft: 4 }}>{counts[s] || 0}</span>
                    </span>
                ))}
            </div>

            <div className="card" style={{ padding: 0, overflow: 'hidden' }}>
                <div className="tbl-wrap">
                    <table className="tbl ord-tbl">
                        <thead><tr>
                            <th>{t('Invoice')}</th><th>{t('Auto Call')}</th><th>{t('Customer')}</th><th>{t('Order Items')}</th>
                            <th>{t('Success Rate')}</th><th>{t('Tags')}</th><th>{t('Site')}</th><th>{t('Status')}</th>
                            <th>{t('Processed By')}</th><th style={{ textAlign: 'right' }}>{t('Actions')}</th>
                        </tr></thead>
                        <tbody>
                            {rows.map((o) => (
                                <tr key={o.id}>
                                    <td><div className="b">{o.invoice}</div><div className="sm mut">{o.created} · {o.created_time}</div></td>
                                    <td><span className="pill">{t(o.auto_call === 'off' ? 'Off' : o.auto_call)}</span></td>
                                    <td>
                                        <div className="b">{o.name}</div>
                                        <div className="sm">{o.phone}</div>
                                        <div className="sm mut" style={{ maxWidth: 190 }}>{o.address}</div>
                                        <div className="flex" style={{ gap: 6, marginTop: 4 }}>
                                            <a className="mini-link" href={`tel:${o.phone}`}>{t('Call')}</a>
                                            <a className="mini-link" href={`https://wa.me/88${o.phone}`} target="_blank" rel="noreferrer">WhatsApp</a>
                                            <button className="mini-link" onClick={() => navigator.clipboard?.writeText(`${o.name}\n${o.phone}\n${o.address || ''}`)}>{t('Copy')}</button>
                                        </div>
                                    </td>
                                    <td style={{ maxWidth: 210 }}><div>{o.items_text}</div><div className="sm mut">{fmt(o.total)}</div></td>
                                    <td><SuccessRing fraud={o.fraud} /></td>
                                    <td>{(o.tags || []).map((tag) => <span key={tag} className="pill" style={{ marginRight: 4 }}>{tag}</span>)}</td>
                                    <td>{o.site || o.source}</td>
                                    <td><StatusPill status={o.status} />{o.is_preorder && <div className="sm mut mt8">{t('Preorder')}</div>}</td>
                                    <td>{o.processed_by || <span className="mut">—</span>}</td>
                                    <td style={{ textAlign: 'right', whiteSpace: 'nowrap' }}>
                                        <Link href={`/orders/${o.id}`} className="btn" style={{ padding: '6px 12px' }}>{t('Open')}</Link>{' '}
                                        <Link href={`/orders/${o.id}/invoice`} className="btn" style={{ padding: '6px 12px' }}>{t('Download Invoice')}</Link>
                                    </td>
                                </tr>
                            ))}
                            {rows.length === 0 && (
                                <tr><td colSpan="10" style={{ textAlign: 'center', padding: 34 }}>
                                    <div className="b">{t('No orders here')}</div>
                                    <div className="sm mut mt8">{t('There are no orders with this status yet. New orders land in Pending.')}</div>
                                </td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            <div className="flex mt16">
                <span className="sm mut">{t('Page')} {pagination.current} {t('of')} {pagination.last} · {pagination.total} {t('orders')}</span>
                <span className="spacer" />
                <select className="inp" style={{ width: 90 }} value={filters.per_page} onChange={(e) => go({ per_page: e.target.value, page: 1 })}>
                    {[20, 50, 100].map((n) => <option key={n} value={n}>{n} / {t('page')}</option>)}
                </select>
                <button className="btn" disabled={pagination.current <= 1} onClick={() => go({ page: pagination.current - 1 })}>{t('Previous')}</button>
                <button className="btn" disabled={pagination.current >= pagination.last} onClick={() => go({ page: pagination.current + 1 })}>{t('Next')}</button>
            </div>
        </AppLayout>
    );
}
