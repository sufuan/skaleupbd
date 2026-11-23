import ConfirmHost from '@/Components/ConfirmHost';
import LanguageSwitcher from '@/Components/LanguageSwitcher';
import { t } from '@/lib/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import { createContext, useContext, useEffect, useMemo, useRef, useState } from 'react';

// Live counts for sidebar/topbar badges, refreshed by polling /sidebar-counts.
const CountsContext = createContext({});

// Badge keys backed by the server-side seen watermark (visiting the
// owning sub-menu page clears the badge until something new arrives).
// messages_inbox is NOT here: it is the real unread count.
const WATERMARKED_KEYS = ['orders_list', 'delivery_ready', 'purchases_toreceive', 'work_control', 'sellers_pending'];

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
    store: 'M4 7l1.5-4h13L20 7M4 7h16M4 7v13a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V7M9.5 21v-6h5v6M2.5 7a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 4-1.5',
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

// ধাপ ২: the sidebar structure arrives server-built in props.nav
// (RouteRegistry::menuFor), already filtered by the user's module
// ticks. Quick Search derives its index from those same sections,
// so a page the user cannot open is never offered here either.
function buildSearchIndex(sections) {
    return (sections || []).flatMap((g) => g.items.flatMap((item) => [
        { label: item.label, href: item.href, group: g.sec || 'SkaleUp BD' },
        ...(item.children || []).map((c) => item.settings
            ? { label: c.label, href: c.href || `/settings?group=${c.g}`, group: 'Settings' }
            : typeof c === 'object'
                ? { label: c.label, href: c.href, group: item.label }
                : { label: c, href: item.href, group: item.label }),
    ]));
}

const ASK_TOPICS = [
    { q: 'How do I connect a courier?', a: 'Go to Settings → API Connection → Courier Integration. Open your courier (Pathao, Steadfast and others), put in the account details from your own courier account, and save. Delivery charges are set on the same card.', href: '/settings?group=api', link: 'Open Courier Integration' },
    { q: 'How do I change the default courier?', a: 'Settings → API Connection → Courier Integration - the Default Courier choice at the bottom of the page. New orders suggest this courier first in Ready to Ship.', href: '/settings?group=api', link: 'Open Courier Integration' },
    { q: 'How does AI reply work?', a: 'Settings → AI & Auto Reply is where everything is set: Rule Brain holds your ready answers, AI Reply sets how much the AI may do alone, and Price & Negotiation sets the discount steps and the lowest price. Messages is only for daily work.', href: '/settings?group=ai', link: 'Open AI & Auto Reply' },
    { q: 'How do I connect WhatsApp?', a: 'Settings → API Connection → Messaging Channels. WhatsApp connects through the guided sign-up - you do not need to type any long keys. One WhatsApp account per shop.', href: '/settings?group=api', link: 'Open Messaging Channels' },
    { q: 'Where do I see low stock?', a: 'Inventory → Inventory Dashboard has a Low Stock tab with every product that is running out, so you can purchase or transfer stock in time.', href: '/inventory/dashboard', link: 'Open Inventory' },
    { q: 'An order is not going to the courier', a: 'Open Delivery → Ready to Ship. Approved orders wait there. Pick the courier and press Send to Courier - if a booking fails, the reason shows on the row and you can press Retry.', href: '/delivery/ready', link: 'Open Ready to Ship' },
];

const KIND_ICON = { order: 'box', delivery: 'truck', stock: 'layers', task: 'check', info: 'bell' };

// Groups the user opened by hand. Every page remounts its own AppLayout
// on an Inertia visit, so useState alone forgets manual opens; the JS
// bundle itself survives visits, so a module-level Set remembers them.
const OPEN_GROUPS = new Set();

// Same trick for the sidebar's own scroll position: kept across the
// per-page remounts so a long sidebar never jumps back to the top.
let SB_SCROLL_TOP = 0;

// Badge counts live on the SUB-MENU item they belong to (each child may
// carry a badgeKey into the counts object). A parent with children shows
// the SUM of its own + its children's counts, but only while collapsed —
// once the dropdown opens, the badge moves down to the owning child.
function NavGroup({ item, current }) {
    const page = usePage();
    const pageProps = page.props;
    const counts = useContext(CountsContext);
    const val = (k) => (k ? Number(counts[k] ?? pageProps.sidebarCounts?.[k] ?? 0) : 0);
    const childVal = (c) => (c && typeof c === 'object' ? val(c.badgeKey) : 0);
    const ownVal = val(item.badgeKey);
    const total = ownVal + (item.children || []).reduce((s, c) => s + childVal(c), 0);
    // Child-aware active detection: the group is active when the current
    // path matches the parent href OR any child href. Among matching
    // children the LONGEST href wins (same rule as the badge
    // seen-watcher), so /orders/create highlights New Order, not the
    // /orders prefix of Order List.
    const activeChild = (item.children || [])
        .filter((c) => c && typeof c === 'object' && c.href && (current === c.href || current.startsWith(c.href + '/')))
        .sort((a, b) => b.href.length - a.href.length)[0] || null;
    // Settings children are query-driven (/settings?group=api); the
    // server defaults a missing group to 'home'. Only on the settings
    // page itself - otherwise every page would match group 'home'.
    const settingsGroup = item.settings && current.startsWith('/settings')
        ? (new URLSearchParams((page.url || '').split('?')[1] || '').get('group') || 'home')
        : null;
    const activeSettingsChild = settingsGroup
        ? (item.children || []).find((c) => c.g === settingsGroup) || null
        : null;
    const active = current.startsWith(item.href) || !!activeChild || !!activeSettingsChild;
    // Open on arrival when this group holds the current page (even if it
    // was closed by hand earlier in the session) or when the user opened
    // it manually before; toggling keeps the Set in sync.
    const [open, setOpenState] = useState(() => active || OPEN_GROUPS.has(item.label));
    const toggleOpen = () => {
        setOpenState((prev) => {
            const next = !prev;
            if (next) OPEN_GROUPS.add(item.label); else OPEN_GROUPS.delete(item.label);
            return next;
        });
    };
    if (!item.children) {
        return (
            <Link href={item.href} className={`nav-item ${active ? 'act' : ''}`}>
                <I d={P[item.icon]} />{t(item.label)}
                {ownVal > 0 && <span className="badge">{ownVal}</span>}
            </Link>
        );
    }
    return (
        <>
            <div className={`nav-item ${open ? 'open' : ''} ${active ? 'act' : ''}`} onClick={toggleOpen}>
                <I d={P[item.icon]} />{t(item.label)}
                {item.badge && <span className="badge">{item.badge}</span>}
                {!open && total > 0 && <span className="badge">{total}</span>}
                <span className="chev">›</span>
            </div>
            {open && (
                <div className="sub-items">
                    {item.children.map((c) => item.settings ? (
                        c.href ? (
                            <Link key={c.label} href={c.href} className={`nav-item ${activeChild === c ? 'act' : ''}`}>{t(c.label)}</Link>
                        ) : (
                            <Link key={c.g} href={`/settings?group=${c.g}`} className={`nav-item ${activeSettingsChild === c ? 'act' : ''}`}>{t(c.label)}</Link>
                        )
                    ) : typeof c === 'object' ? (
                        <Link key={c.label} href={c.href} className={`nav-item ${activeChild === c ? 'act' : ''}`}>{t(c.label)}{childVal(c) > 0 && <span className="badge">{childVal(c)}</span>}</Link>
                    ) : (
                        <Link key={c} href={item.href} className="nav-item">{t(c)}</Link>
                    ))}
                </div>
            )}
        </>
    );
}

function SearchModal({ close, index }) {
    const [q, setQ] = useState('');
    const [sel, setSel] = useState(0);
    const inputRef = useRef(null);
    useEffect(() => { inputRef.current?.focus(); }, []);
    const results = useMemo(() => {
        const needle = q.trim().toLowerCase();
        const list = needle
            ? index.filter((x) => t(x.label).toLowerCase().includes(needle) || x.label.toLowerCase().includes(needle))
            : index;
        return list.slice(0, 9);
    }, [q, index]);
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
    // The bell badge is driven by the polled /sidebar-counts payload, not
    // by page props - after a mark-read lands, tell the shell to refetch
    // the counts at once instead of waiting for the next 15s poll.
    const countsRefresh = () => window.dispatchEvent(new Event('skaleup:counts-refresh'));
    const openItem = (n) => {
        close();
        if (!n.read) {
            // Chain, never race: starting the link visit immediately
            // would cancel this POST in flight and the notification
            // would stay unread - navigate only once the POST finishes.
            router.post(`/notifications/${n.id}/read`, {}, {
                preserveScroll: true,
                onSuccess: countsRefresh,
                onFinish: () => { if (n.link) router.visit(n.link); },
            });
        } else if (n.link) {
            router.visit(n.link);
        }
    };
    return (
        <div className="notif-panel">
            <div className="notif-head">
                <b>{t('Notifications')}</b><span className="spacer" />
                <button className="auth-link" onClick={() => router.post('/notifications/read-all', {}, { preserveScroll: true, onSuccess: countsRefresh })}>{t('Mark all read')}</button>
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
    const [userMenuOpen, setUserMenuOpen] = useState(false);
    const userMenuRef = useRef(null);
    const [askOpen, setAskOpen] = useState(false);
    const [theme, setTheme] = useState(() => (typeof document !== 'undefined' && document.documentElement.dataset.theme) || 'light');
    const [toast, setToast] = useState(null);
    const [liveCounts, setLiveCounts] = useState(null);
    const counts = {
        orders_list: liveCounts?.orders_list ?? props.sidebarCounts?.orders_list ?? 0,
        delivery_ready: liveCounts?.delivery_ready ?? props.sidebarCounts?.delivery_ready ?? 0,
        notifications_unread: liveCounts?.notifications_unread ?? props.notifications?.unread ?? 0,
        messages_inbox: liveCounts?.messages_inbox ?? props.sidebarCounts?.messages_inbox ?? 0,
        purchases_toreceive: liveCounts?.purchases_toreceive ?? props.sidebarCounts?.purchases_toreceive ?? 0,
        work_control: liveCounts?.work_control ?? props.sidebarCounts?.work_control ?? 0,
        sellers_pending: liveCounts?.sellers_pending ?? props.sidebarCounts?.sellers_pending ?? 0,
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
        // Instant refresh when something in the shell changes a count
        // (e.g. the notifications panel marks everything read).
        window.addEventListener('skaleup:counts-refresh', poll);
        return () => { stop = true; clearInterval(id); window.removeEventListener('skaleup:counts-refresh', poll); };
    }, []);
    // Profile menu: close on navigation and on any click outside it.
    useEffect(() => { setUserMenuOpen(false); }, [url]);
    useEffect(() => {
        if (!userMenuOpen) return undefined;
        const onDown = (e) => { if (userMenuRef.current && !userMenuRef.current.contains(e.target)) setUserMenuOpen(false); };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [userMenuOpen]);
    const userName = props.auth?.user?.name || 'User';
    const isPlatform = !!props.auth?.user?.is_platform_admin;
    const perms = props.auth?.permissions || [];
    // ধাপ ৩: the shell context arrives from the server (auth.context):
    // 'platform' renders the platform shell (labelled band, platform
    // menu only); a view-as session (props.viewAs) renders the viewed
    // shop's shell under the read-only banner.
    const viewAs = props.viewAs || null;
    const shellContext = props.auth?.context || ((isPlatform && !viewAs) ? 'platform' : 'shop');
    // ধাপ ২: sections arrive server-built and permission-filtered in
    // props.nav (RouteRegistry::menuFor). The only client-side merge
    // left is the Storefront sub-menu, whose state depends on the
    // shop's store (StorefrontService::menuState in props.sfMenu):
    // no sub-menu before a store exists, three calm items after,
    // Connect Website instead for own-website shops. Platform admins
    // get ONE extra child here - the old separate Platform
    // "Storefronts" menu was merged into this one so the name
    // appears only once (owner order).
    const navGroups = (props.nav?.sections || []).map((sec) => ({ ...sec, items: sec.items.map((it) => {
        if (it.label !== 'Storefront') return it;
        let kids = it.children;
        if (props.sfMenu && Array.isArray(props.sfMenu.menu)) {
            const SF_LBL = { overview: ['Overview', '/storefront'], pages: ['My Pages', '/storefront/pages'], settings: ['Store Settings', '/storefront/settings'], connect: ['Connect Website', '/storefront/connect'] };
            kids = props.sfMenu.menu.map((k) => ({ label: SF_LBL[k][0], href: SF_LBL[k][1] }));
        }
        if (isPlatform && !viewAs) {
            kids = [...(kids || []), { label: 'All Storefronts', href: '/admin/storefronts' }];
        }
        return { ...it, children: kids };
    }) }));
    const searchIndex = useMemo(() => buildSearchIndex(navGroups), [navGroups]);
    const topbar = props.nav?.topbar || {};
    const initials = userName.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();

    useEffect(() => {
        const onKey = (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setSearchOpen(true); }
            if (e.key === 'Escape') { setSearchOpen(false); setAskOpen(false); setNotifOpen(false); }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    // Depend on the flash OBJECT, not the message string: every response
    // rebuilds it, so two identical messages in a row ("Category created"
    // twice) still toast twice. Depending on the string swallowed every
    // repeat - the second action looked like it did nothing.
    useEffect(() => {
        if (props.flash?.success) {
            setToast(props.flash.success);
            const id = setTimeout(() => setToast(null), 4200);
            return () => clearTimeout(id);
        }
    }, [props.flash]);

    const [toastErr, setToastErr] = useState(null);
    useEffect(() => {
        if (props.flash?.error) {
            setToastErr(props.flash.error);
            const id = setTimeout(() => setToastErr(null), 6000);
            return () => clearTimeout(id);
        }
    }, [props.flash]);

    useEffect(() => { setSbOpen(false); }, [current]);

    // Sidebar scroll: save on scroll, restore on mount, and when the
    // active item lands outside the visible area after a navigation,
    // bring it into view (block: 'nearest', so an already-visible item
    // never moves the position the user left).
    const sbRef = useRef(null);
    const sbCountsSettled = useRef(false);
    const settleSidebarScroll = () => {
        const sb = sbRef.current;
        if (!sb) return;
        sb.scrollTop = SB_SCROLL_TOP;
        const box = sb.getBoundingClientRect();
        if (box.right <= 0 || box.left >= window.innerWidth) return; // off-canvas (mobile drawer closed)
        const act = sb.querySelector('.sub-items .nav-item.act') || sb.querySelector('.nav-item.act');
        if (!act) return;
        const r = act.getBoundingClientRect();
        if (r.top < box.top || r.bottom > box.bottom) act.scrollIntoView({ block: 'nearest' });
    };
    useEffect(() => {
        const sb = sbRef.current;
        if (!sb) return;
        settleSidebarScroll();
        const onScroll = () => { SB_SCROLL_TOP = sb.scrollTop; };
        sb.addEventListener('scroll', onScroll, { passive: true });
        return () => sb.removeEventListener('scroll', onScroll);
    }, []);
    // Badges arriving with the first live counts can shift row heights;
    // settle once more then so the restored position stays accurate.
    useEffect(() => {
        if (liveCounts === null || sbCountsSettled.current) return;
        sbCountsSettled.current = true;
        settleSidebarScroll();
    }, [liveCounts]);

    // Badge clearing: when the current page belongs to a watermarked
    // sub-menu item, post the seen watermark and adopt the fresh counts.
    // The LONGEST matching child href decides which page this is, so
    // /orders/create (New Order) never clears the Order List badge,
    // while /orders and /orders/123 do. Posted once per count value —
    // the response zeroes the key, so this cannot loop.
    const seenPosted = useRef({});
    useEffect(() => {
        const kids = [];
        navGroups.forEach((g) => g.items.forEach((it) => (it.children || []).forEach((c) => {
            if (c && typeof c === 'object' && c.href) kids.push(c);
        })));
        const match = kids
            .filter((c) => current === c.href || current.startsWith(c.href + '/'))
            .sort((a, b) => b.href.length - a.href.length)[0];
        if (!match?.badgeKey || !WATERMARKED_KEYS.includes(match.badgeKey)) return;
        const key = match.badgeKey;
        const value = Number(counts[key] ?? 0);
        if (value <= 0 || seenPosted.current[key] === value) return;
        seenPosted.current[key] = value;
        (async () => {
            try {
                const res = await fetch('/sidebar-counts/seen', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: JSON.stringify({ key }),
                });
                if (res.ok) setLiveCounts(await res.json());
            } catch { /* the next poll reconciles */ }
        })();
    }, [current, counts.orders_list, counts.delivery_ready, counts.purchases_toreceive, counts.work_control, counts.sellers_pending]);

    const toggleTheme = () => {
        const next = theme === 'dark' ? 'light' : 'dark';
        document.documentElement.dataset.theme = next;
        try { localStorage.setItem('skaleup_theme', next); } catch { /* ignore */ }
        setTheme(next);
    };

    // Workspace header: platform context is labelled as the platform
    // (never a shop); view mode names the viewed shop and carries the
    // read-only marker as a second signal under the banner.
    const wsName = shellContext === 'platform' ? t('Platform Admin') : (props.auth?.business?.name || userName);
    const wsCaption = shellContext === 'platform' ? t('Not a shop') : viewAs ? t('Read-only view') : t('Workspace');
    const wsInitial = shellContext === 'platform' ? 'P' : (props.auth?.business?.name || userName).trim().charAt(0).toUpperCase();

    return (
        <CountsContext.Provider value={counts}>
        <div className={`shell ${shellContext === 'platform' ? 'shell-platform' : ''}`}>
            {sbOpen && <div className="sb-overlay" onClick={() => setSbOpen(false)} />}
            <aside ref={sbRef} className={`sb ${sbOpen ? 'open' : ''}`}>
                <div className="sb-ws">
                    <div className="sb-ava">{wsInitial}</div>
                    <div><div className="nm">{wsName}</div><div className="rl">{wsCaption}</div></div>
                    <span className="chev" style={{ marginLeft: 'auto', color: '#b3a6cc' }}>⌄</span>
                </div>
                <button type="button" className="sb-search" style={{ width: 'calc(100% - 28px)', textAlign: 'left' }} onClick={() => setSearchOpen(true)}>
                    <I d={P.search} size={14} /> {t('Quick Search')} <kbd>CTRL K</kbd>
                </button>
                {navGroups.map((group, gi) => (
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
                    <button type="button" className="tb-item" onClick={() => setSearchOpen(true)}><I d={P.search} size={15} /><span className="tb-hide-sm">{t('Search')}</span></button>
                    {topbar.new_order && <Link href="/orders/create" className="tb-item tb-hide-sm"><I d={P.cart} size={15} /> {t('New Order')}</Link>}
                    {topbar.order_list && <Link href="/orders" className="tb-item tb-hide-sm"><I d={P.file} size={15} /> {t('Order List')}</Link>}
                    {topbar.send_message && <Link href="/messages/inbox" className="tb-item tb-hide-sm"><I d={P.send} size={15} /> {t('Send Message')}</Link>}
                    <div className="tb-right">
                        <LanguageSwitcher />
                        <button className="ask-btn" onClick={() => setAskOpen(true)}>✦ {t('Ask SkaleUp')}</button>
                        <Link href={mod('support')} className="icon-btn" aria-label={t('Support')}><I d={P.bulb} size={17} /></Link>
                        <span style={{ position: 'relative' }}>
                            <button type="button" className="icon-btn" aria-label={t('Notifications')} onClick={() => { setNotifOpen(!notifOpen); setUserMenuOpen(false); }}>
                                <I d={P.bell} size={17} />
                                {unread > 0 && <span className="dot-badge">{unread}</span>}
                            </button>
                            {notifOpen && <NotificationsPanel close={() => setNotifOpen(false)} />}
                        </span>
                        <button type="button" className="icon-btn" aria-label={t('Theme')} onClick={toggleTheme}><I d={theme === 'dark' ? P.sun : P.moon} size={17} /></button>
                        <span style={{ position: 'relative' }} ref={userMenuRef}>
                            <button type="button" className="tb-ava" title={userName} aria-label={t('My Profile')} aria-haspopup="menu" aria-expanded={userMenuOpen} onClick={() => { setUserMenuOpen(!userMenuOpen); setNotifOpen(false); }}>{initials}</button>
                            {userMenuOpen && (
                                <div className="user-menu" role="menu">
                                    <div className="user-menu-head">
                                        <div className="b" style={{ fontSize: 14 }}>{userName}</div>
                                        {props.auth?.user?.email && <div className="sm mut">{props.auth.user.email}</div>}
                                    </div>
                                    <Link href="/profile" className="user-menu-item" onClick={() => setUserMenuOpen(false)}>{t('My Profile')}</Link>
                                    {perms.includes('billing') && props.auth?.business && !viewAs && <Link href="/billing" className="user-menu-item" onClick={() => setUserMenuOpen(false)}>{t('Points & Billing')}</Link>}
                                    <Link href={route('logout')} method="post" as="button" className="user-menu-item danger" onClick={() => setUserMenuOpen(false)}>{t('Log out')}</Link>
                                </div>
                            )}
                        </span>
                    </div>
                </header>
                {viewAs && (
                    <div className="viewas-banner">
                        <span><b>{viewAs.shop_name}</b> - {t('You are viewing this shop read-only')}</span>
                        <span style={{ opacity: .85 }}>{Math.max(1, Math.ceil((viewAs.seconds_left || 0) / 60))} {t('minutes left')}</span>
                        <span style={{ marginLeft: 'auto' }}>
                            <button type="button" className="btn-exit" onClick={() => router.post('/admin/view-as/exit')}>{t('Exit view')}</button>
                        </span>
                    </div>
                )}
                {props.tokenGate?.blocked && !props.tokenGate?.pending && (
                    <div style={{ background: '#FDECEC', borderBottom: '1px solid #F3B4B4', color: '#8B1E1E', padding: '10px 18px', fontSize: 13.5, display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                        <span><b>{t('Points finished - your shop is view-only.')}</b> {t('New orders and changes are paused until you top up.')}</span>
                        <Link href="/billing" style={{ fontWeight: 700, color: '#8B1E1E', textDecoration: 'underline' }}>{t('See Points & Billing')}</Link>
                    </div>
                )}
                {props.tokenGate && !props.tokenGate.blocked && !props.tokenGate.pending && props.tokenGate.low && (
                    <div style={{ background: '#FFF6E0', borderBottom: '1px solid #F0D48A', color: '#7A5200', padding: '10px 18px', fontSize: 13.5, display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                        <span><b>{t('Points are running low')}</b> ({props.tokenGate.balance} {t('left')}). {t('Top up soon so your orders never stop.')}</span>
                        <Link href="/billing" style={{ fontWeight: 700, color: '#7A5200', textDecoration: 'underline' }}>{t('See Points & Billing')}</Link>
                    </div>
                )}
                <main className="content">{children}</main>
            </div>
            {searchOpen && <SearchModal close={() => setSearchOpen(false)} index={searchIndex} />}
            {askOpen && <AskDrawer close={() => setAskOpen(false)} />}
            <ConfirmHost />
            {toast && <div className="toast">{t(toast)}</div>}
            {toastErr && <div className="toast err">{t(toastErr)}</div>}
        </div>
        </CountsContext.Provider>
    );
}
