import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { t } from '@/lib/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import { createContext, useContext, useEffect, useMemo, useRef, useState } from 'react';

// Live counts for sidebar/topbar badges, refreshed by polling /sidebar-counts.
const CountsContext = createContext({});

const I = ({ d, size = 17 }) => (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round"><path d={d} /></svg>
);
const P = {
    grid: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
    search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.3-4.3',
    box: 'M21 8l-9-5-9 5v8l9 5 9-5zM3 8l9 5 9-5M12 13v8',
    truck: 'M1 8h13v8H1zM14 11h4l3 3v2h-7zM5.5 20a1.8 1.8 0 1 0 0-3.6 1.8 1.8 0 0 0 0 3.6zM17.5 20a1.8 1.8 0 1 0 0-3.6 1.8 1.8 0 0 0 0 3.6z',
    layers: 'M12 2l9 5-9 5-9-5zM3 12l9 5 9-5M3 17l9 5 9-5',
    chat: 'M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.5 0-3-.4-4.2-1.1L3 20l1.1-5.3A8.5 8.5 0 1 1 21 11.5z',
    cart: 'M2.5 3h2l2.6 12.5a2 2 0 0 0 2 1.5h8.7a2 2 0 0 0 2-1.6L21.5 7H6M9 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM19 21a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
    check: 'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
    phone: 'M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.13.96.36 1.9.7 2.8a2 2 0 0 1-.45 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.45c.9.34 1.84.57 2.8.7A2 2 0 0 1 22 16.9z',
    inbox: 'M22 12h-6l-2 3h-4l-2-3H2M5.5 5.1L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.7 4H7.3a2 2 0 0 0-1.8 1.1z',
    chart: 'M3 3v18h18M8 17V9M13 17V5M18 17v-7',
    megaphone: 'M3 11l14-6v14L3 13v-2zM7 13v5a2 2 0 0 0 4 0v-4',
    users: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8',
    briefcase: 'M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2zM16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2',
    wallet: 'M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4z',
    report: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 15l2-2 2 2 3-4',
    gear: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 1.55-1 1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34h0a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55h0a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87v0a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.55 1z',
    help: 'M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0z',
    send: 'M22 2L11 13M22 2l-7 20-4-9-9-4z',
    bell: 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
    bulb: 'M9 18h6M10 22h4M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.4 1 2.3h6c0-.9.4-1.8 1-2.3A7 7 0 0 0 12 2z',
    sun: 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10zM12 1v2M12 21v2M4.2 4.2l1.4 1.4M18.4 18.4l1.4 1.4M1 12h2M21 12h2M4.2 19.8l1.4-1.4M18.4 5.6l1.4-1.4',
    moon: 'M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z',
    plus: 'M12 5v14M5 12h14',
    file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
    logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
    x: 'M18 6L6 18M6 6l12 12',
};

const mod = (slug) => `/module/${slug}`;

const NAV = [
    { sec: null, items: [
        { label: 'Dashboard', icon: 'grid', href: '/dashboard' },
    ]},
    { sec: 'Operations', items: [
        { label: 'Orders', icon: 'box', badgeKey: 'orders_pending', children: [{ label: 'New Order', href: '/orders/create' }, { label: 'Order List', href: '/orders' }, { label: 'Auto Pick Orders', href: '/orders/autopick' }, { label: 'Auto Call Center', href: '/orders/autocall' }, { label: 'Order Block List', href: '/orders/block-list' }, { label: 'Preorders', href: '/orders/preorders' }, { label: 'Orders Dashboard', href: '/orders/dashboard' }], href: '/orders' },
        { label: 'Delivery', icon: 'truck', badgeKey: 'delivery_waiting', children: [{ label: 'Ready to Ship', href: '/delivery/ready' }, { label: 'Courier Management', href: '/delivery/parcels' }, { label: 'Scan To Update', href: '/delivery/scan' }, { label: 'Courier Invoice Upload', href: '/delivery/invoice' }, { label: 'Courier Ledger', href: '/delivery/ledger' }], href: '/delivery/ready' },
        { label: 'Inventory', icon: 'layers', children: [{ label: 'Inventory Dashboard', href: '/inventory/dashboard' }, { label: 'Products', href: '/inventory/products' }, { label: 'Stock Ledger', href: '/inventory/ledger' }, { label: 'Branches', href: '/inventory/branches' }, { label: 'Warranty', href: '/inventory/warranty' }], href: '/inventory/dashboard' },
        { label: 'Messages', icon: 'chat', badgeKey: 'messages_unread', children: ['Inbox', 'Comments', 'AI Activity'], href: mod('messages') },
        { label: 'Purchase', icon: 'cart', children: ['Purchase Dashboard', 'Purchase List', 'New Purchase', 'Receive Purchase', 'Supplier Ledger', 'Suppliers', 'Ask for Quotation'], href: mod('purchase') },
        { label: 'Task & Follow-up', icon: 'check', children: ['Task Board', 'Agent Cockpit', 'Follow-up Management', 'My Follow-ups', 'Follow-up Performance'], href: mod('task') },
        { label: 'Call Center', icon: 'phone', children: ['Call Dashboard', 'Call Reports', 'Contacts'], href: mod('call-center') },
        { label: 'Work Inbox', icon: 'inbox', children: ['Work Control', 'Rule Settings', 'My Work (off)'], href: mod('work-inbox') },
    ]},
    { sec: 'Growth', items: [
        { label: 'Ads', icon: 'chart', children: ['Ads Dashboard', 'Meta Ads', 'TikTok Ads'], href: mod('ads') },
        { label: 'Marketing', icon: 'megaphone', children: ['Automation', 'SMS Marketing'], href: mod('marketing') },
        { label: 'Customers', icon: 'users', children: ['Customer List', 'Bulk Upload'], href: mod('customers') },
    ]},
    { sec: 'Business Control', items: [
        { label: 'HRM', icon: 'briefcase', children: ['HR Dashboard', 'Attendance', 'Departments & SOP', 'User & Role', 'HR Settings'], href: mod('hrm') },
        { label: 'Finance', icon: 'wallet', children: ['Finance Dashboard', 'Accounts & Bank', 'Income & Expense', 'Cash Flow', 'Asset Management'], href: mod('finance') },
        { label: 'Reports', icon: 'report', children: ['Profit & Loss', 'Order Report', 'Courier Report', 'Product Report', 'Employee Report', 'Call Center Report', 'Customer Report'], href: mod('reports') },
        { label: 'Settings', icon: 'gear', settings: true, children: [
            { label: 'Settings Dashboard', g: 'home' }, { label: 'Business Settings', g: 'business' },
            { label: 'API Connection', g: 'api' }, { label: 'Calls & Orders', g: 'calls' },
            { label: 'AI & Auto Reply', g: 'ai' }, { label: 'Security & Records', g: 'security' },
        ], href: '/settings' },
        { label: 'Support', icon: 'help', children: ['Help Center', 'Updates'], href: mod('support') },
    ]},
];

const SEARCH_INDEX = [
    { label: 'Dashboard', href: '/dashboard', group: 'SkaleUp BD' },
    ...NAV.flatMap((g) => g.items.flatMap((item) => [
        { label: item.label, href: item.href, group: g.sec || 'SkaleUp BD' },
        ...(item.children || []).map((c) => item.settings
            ? { label: c.label, href: `/settings?group=${c.g}`, group: 'Settings' }
            : typeof c === 'object'
                ? { label: c.label, href: c.href, group: item.label }
                : { label: c, href: item.href, group: item.label }),
    ])),
];

const ASK_TOPICS = [
    { q: 'How do I connect a courier?', a: 'Go to Settings → API Connection → Courier Integration. Open your courier (Pathao, Steadfast and others), put in the account details from your own courier account, and save. Delivery charges are set on the same card.', href: '/settings?group=api', link: 'Open Courier Integration' },
    { q: 'How do I change the default courier?', a: 'Settings → API Connection → Courier Integration — the Default Courier choice at the bottom of the page. New orders suggest this courier first in Ready to Ship.', href: '/settings?group=api', link: 'Open Courier Integration' },
    { q: 'How does AI reply work?', a: 'Settings → AI & Auto Reply is where everything is set: Rule Brain holds your ready answers, AI Reply sets how much the AI may do alone, and Price & Negotiation sets the discount steps and the lowest price. Messages is only for daily work.', href: '/settings?group=ai', link: 'Open AI & Auto Reply' },
    { q: 'How do I connect WhatsApp?', a: 'Settings → API Connection → Messaging Channels. WhatsApp connects through the guided sign-up — you do not need to type any long keys. One WhatsApp account per shop.', href: '/settings?group=api', link: 'Open Messaging Channels' },
    { q: 'Where do I see low stock?', a: 'Inventory → Inventory Dashboard has a Low Stock tab with every product that is running out, so you can purchase or transfer stock in time.', href: mod('inventory'), link: 'Open Inventory' },
    { q: 'An order is not going to the courier', a: 'Open Delivery → Ready to Ship. Approved orders wait there. Pick the courier and press Send to Courier — if a booking fails, the reason shows on the row and you can press Retry.', href: mod('delivery'), link: 'Open Ready to Ship' },
];

const KIND_ICON = { order: 'box', delivery: 'truck', stock: 'layers', task: 'check', info: 'bell' };

function NavGroup({ item, current }) {
    const pageProps = usePage().props;
    const counts = useContext(CountsContext);
    const badgeValue = item.badgeKey ? (counts[item.badgeKey] ?? pageProps[item.badgeKey] ?? 0) : 0;
    const active = current.startsWith(item.href);
    const [open, setOpen] = useState(active);
    if (!item.children) {
        return <Link href={item.href} className={`nav-item ${active ? 'act' : ''}`}><I d={P[item.icon]} />{t(item.label)}</Link>;
    }
    return (
        <>
            <div className={`nav-item ${open ? 'open' : ''} ${active ? 'act' : ''}`} onClick={() => setOpen(!open)}>
                <I d={P[item.icon]} />{t(item.label)}
                {item.badge && <span className="badge">{item.badge}</span>}
                {item.badgeKey && Number(badgeValue) > 0 && <span className="badge">{badgeValue}</span>}
                <span className="chev">›</span>
            </div>
            {open && (
                <div className="sub-items">
                    {item.children.map((c) => item.settings ? (
                        <Link key={c.g} href={`/settings?group=${c.g}`} className="nav-item">{t(c.label)}</Link>
                    ) : typeof c === 'object' ? (
                        <Link key={c.label} href={c.href} className="nav-item">{t(c.label)}</Link>
                    ) : (
                        <Link key={c} href={item.href} className="nav-item">{t(c)}</Link>
                    ))}
                </div>
            )}
        </>
    );
}

function SearchModal({ close }) {
    const [q, setQ] = useState('');
    const [sel, setSel] = useState(0);
    const inputRef = useRef(null);
    useEffect(() => { inputRef.current?.focus(); }, []);
    const results = useMemo(() => {
        const needle = q.trim().toLowerCase();
        const list = needle
            ? SEARCH_INDEX.filter((x) => t(x.label).toLowerCase().includes(needle) || x.label.toLowerCase().includes(needle))
            : SEARCH_INDEX;
        return list.slice(0, 9);
    }, [q]);
    useEffect(() => { setSel(0); }, [q]);
    const go = (item) => { if (item) { close(); router.visit(item.href); } };
    return (
        <div className="overlay" onClick={close}>
            <div className="search-box" onClick={(e) => e.stopPropagation()}>
                <div className="search-inp">
                    <I d={P.search} size={18} />
                    <input ref={inputRef} value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Search pages, orders, settings…')}
                        onKeyDown={(e) => {
                            if (e.key === 'ArrowDown') { e.preventDefault(); setSel((s) => Math.min(s + 1, results.length - 1)); }
                            if (e.key === 'ArrowUp') { e.preventDefault(); setSel((s) => Math.max(s - 1, 0)); }
                            if (e.key === 'Enter') { e.preventDefault(); go(results[sel]); }
                        }} />
                    <kbd style={{ fontSize: 10, border: '1px solid var(--line)', borderRadius: 5, padding: '1px 5px' }}>ESC</kbd>
                </div>
                <div className="search-list">
                    {results.length === 0 && <div className="sm mut" style={{ padding: '14px 12px' }}>{t('Nothing found for that search')}</div>}
                    {results.map((r, i) => (
                        <button key={`${r.label}-${r.href}-${i}`} className={`search-item ${i === sel ? 'sel' : ''}`} onMouseEnter={() => setSel(i)} onClick={() => go(r)}>
                            {t(r.label)}<small>{t(r.group)}</small>
                        </button>
                    ))}
                </div>
            </div>
        </div>
    );
}

function NotificationsPanel({ close }) {
    const { notifications } = usePage().props;
    const items = notifications?.items || [];
    const openItem = (n) => {
        if (!n.read) router.post(`/notifications/${n.id}/read`, {}, { preserveScroll: true });
        close();
        if (n.link) router.visit(n.link);
    };
    return (
        <div className="notif-panel">
            <div className="notif-head">
                <b>{t('Notifications')}</b><span className="spacer" />
                <button className="auth-link" onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true })}>{t('Mark all read')}</button>
            </div>
            {items.length === 0 && <div className="sm mut" style={{ padding: '16px' }}>{t('No notifications yet')}</div>}
            {items.map((n) => (
                <button key={n.id} className={`notif-item ${n.read ? '' : 'unread'}`} onClick={() => openItem(n)}>
                    <span className="notif-ic"><I d={P[KIND_ICON[n.kind] || 'bell']} size={16} /></span>
                    <span style={{ flex: 1 }}>
                        <span className="b" style={{ display: 'block', fontSize: 13.5 }}>{n.title}</span>
                        {n.body && <span className="sm mut" style={{ display: 'block', marginTop: 1 }}>{n.body}</span>}
                        <span className="sm mut">{n.time}</span>
                    </span>
                    {!n.read && <span style={{ width: 8, height: 8, borderRadius: '50%', background: 'var(--acc2)', flexShrink: 0, marginTop: 5 }} />}
                </button>
            ))}
        </div>
    );
}

function AskDrawer({ close }) {
    const [q, setQ] = useState('');
    const [answer, setAnswer] = useState(null);
    const topics = ASK_TOPICS.filter((x) => t(x.q).toLowerCase().includes(q.trim().toLowerCase()) || x.q.toLowerCase().includes(q.trim().toLowerCase()));
    return (
        <>
            <div className="overlay" style={{ padding: 0, background: 'rgba(24,10,38,.35)' }} onClick={close} />
            <div className="ask-drawer">
                <div className="ask-head">
                    <span className="notif-ic">✦</span>
                    <div style={{ flex: 1 }}><div className="b">{t('Ask SkaleUp')}</div><div className="sm mut">{t('Quick help, straight from your own settings')}</div></div>
                    <button className="icon-btn" onClick={close} aria-label="Close"><I d={P.x} size={18} /></button>
                </div>
                <div className="ask-body">
                    <div className="b sm" style={{ marginBottom: 9 }}>{t('Common questions')}</div>
                    <div>
                        {topics.map((x) => (
                            <button key={x.q} className="topic-chip" onClick={() => setAnswer(x)}>{t(x.q)}</button>
                        ))}
                        {topics.length === 0 && <span className="sm mut">{t('Nothing found for that search')}</span>}
                    </div>
                    {answer && (
                        <div className="ask-answer" style={{ marginTop: 12 }}>
                            <div className="b" style={{ marginBottom: 5 }}>{t(answer.q)}</div>
                            {t(answer.a)}
                            <div style={{ marginTop: 10 }}>
                                <Link href={answer.href} className="btn pri" onClick={close}>{t(answer.link)}</Link>
                            </div>
                        </div>
                    )}
                </div>
                <div className="ask-foot">
                    <input className="inp" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('Type a question…')} />
                </div>
            </div>
        </>
    );
}

export default function AppLayout({ children }) {
    const page = usePage();
    const { url, props } = page;
    const current = url.split('?')[0];
    const [searchOpen, setSearchOpen] = useState(false);
    const [sbOpen, setSbOpen] = useState(false);
    const [notifOpen, setNotifOpen] = useState(false);
    const [askOpen, setAskOpen] = useState(false);
    const [theme, setTheme] = useState(() => (typeof document !== 'undefined' && document.documentElement.dataset.theme) || 'light');
    const [toast, setToast] = useState(null);
    const [liveCounts, setLiveCounts] = useState(null);
    const counts = {
        orders_pending: liveCounts?.orders_pending ?? props.orders_pending ?? 0,
        delivery_waiting: liveCounts?.delivery_waiting ?? props.delivery_waiting ?? 0,
        notifications_unread: liveCounts?.notifications_unread ?? props.notifications?.unread ?? 0,
        messages_unread: liveCounts?.messages_unread ?? 0,
    };
    const unread = counts.notifications_unread;

    useEffect(() => {
        let stop = false;
        const poll = async () => {
            try {
                const res = await fetch('/sidebar-counts', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
                if (res.ok && !stop) setLiveCounts(await res.json());
            } catch { /* keep the last known counts */ }
        };
        poll();
        const id = setInterval(poll, 15000);
        return () => { stop = true; clearInterval(id); };
    }, []);
    const userName = props.auth?.user?.name || 'User';
    const initials = userName.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();

    useEffect(() => {
        const onKey = (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setSearchOpen(true); }
            if (e.key === 'Escape') { setSearchOpen(false); setAskOpen(false); setNotifOpen(false); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (props.flash?.success) {
            setToast(props.flash.success);
            const id = setTimeout(() => setToast(null), 4200);
            return () => clearTimeout(id);
        }
    }, [props.flash?.success]);

    const [toastErr, setToastErr] = useState(null);
    useEffect(() => {
        if (props.flash?.error) {
            setToastErr(props.flash.error);
            const id = setTimeout(() => setToastErr(null), 6000);
            return () => clearTimeout(id);
        }
    }, [props.flash?.error]);

    useEffect(() => { setSbOpen(false); }, [current]);

    const toggleTheme = () => {
        const next = theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem('skaleup_theme', next); } catch { /* ignore */ }
        setTheme(next);
    };

    return (
        <CountsContext.Provider value={counts}>
        <div className="shell">
            {sbOpen && <div className="sb-overlay" onClick={() => setSbOpen(false)} />}
            <aside className={`sb ${sbOpen ? 'open' : ''}`}>
                <div className="sb-ws">
                    <div className="sb-ava">D</div>
                    <div><div className="nm">Demo Shop</div><div className="rl">{t('Workspace')}</div></div>
                    <span className="chev" style={{ marginLeft: 'auto', color: '#b3a6cc' }}>⌄</span>
                </div>
                <button type="button" className="sb-search" style={{ width: 'calc(100% - 28px)', textAlign: 'left' }} onClick={() => setSearchOpen(true)}>
                    <I d={P.search} size={14} /> {t('Quick Search')} <kbd>CTRL K</kbd>
                </button>
                {NAV.map((group, gi) => (
                    <div key={gi}>
                        {group.sec && <div className="nav-sec">{t(group.sec).toUpperCase()}</div>}
                        {group.items.map((item) => <NavGroup key={item.label} item={item} current={current} />)}
                    </div>
                ))}
                <div style={{ marginTop: 'auto', padding: '10px 8px', borderTop: '1px solid var(--line)' }}>
                    <Link href={route('logout')} method="post" as="button" className="nav-item" style={{ width: '100%' }}><I d={P.logout} /> {t('Log out')}</Link>
                </div>
            </aside>
            <div className="main">
                <header className="topbar">
                    <button type="button" className="icon-btn burger" aria-label="Menu" onClick={() => setSbOpen(true)}>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    </button>
                    <span className="tb-item tb-hide-sm"><I d={P.grid} size={16} /></span>
                    <button type="button" className="tb-item" onClick={() => setSearchOpen(true)}><I d={P.search} size={15} /> {t('Search')}</button>
                    <Link href="/orders/create" className="tb-item tb-hide-sm"><I d={P.cart} size={15} /> {t('New Order')}</Link>
                    <Link href="/orders" className="tb-item tb-hide-sm"><I d={P.file} size={15} /> {t('Order List')}</Link>
                    <Link href={mod('messages')} className="tb-item tb-hide-sm"><I d={P.send} size={15} /> {t('Send Message')}</Link>
                    <div className="tb-right">
                        <LanguageSwitcher />
                        <button className="ask-btn" onClick={() => setAskOpen(true)}>✦ {t('Ask SkaleUp')}</button>
                        <Link href={mod('support')} className="icon-btn" aria-label={t('Support')}><I d={P.bulb} size={17} /></Link>
                        <span style={{ position: 'relative' }}>
                            <button type="button" className="icon-btn" aria-label={t('Notifications')} onClick={() => setNotifOpen(!notifOpen)}>
                                <I d={P.bell} size={17} />
                                {unread > 0 && <span className="dot-badge">{unread}</span>}
                            </button>
                            {notifOpen && <NotificationsPanel close={() => setNotifOpen(false)} />}
                        </span>
                        <button type="button" className="icon-btn" aria-label={t('Theme')} onClick={toggleTheme}><I d={theme === 'dark' ? P.sun : P.moon} size={17} /></button>
                        <div className="tb-ava" title={userName}>{initials}</div>
                    </div>
                </header>
                <main className="content">{children}</main>
            </div>
            {searchOpen && <SearchModal close={() => setSearchOpen(false)} />}
            {askOpen && <AskDrawer close={() => setAskOpen(false)} />}
            {toast && <div className="toast">{toast}</div>}
            {toastErr && <div className="toast err">{toastErr}</div>}
        </div>
        </CountsContext.Provider>
    );
}
