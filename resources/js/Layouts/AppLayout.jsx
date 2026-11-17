import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

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
    plus: 'M12 5v14M5 12h14',
    file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
    logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
};

const mod = (slug) => `/module/${slug}`;

const NAV = [
    { sec: null, items: [
        { label: 'Dashboard', icon: 'grid', href: '/dashboard' },
        { label: 'Search', icon: 'search', href: mod('search') },
    ]},
    { sec: 'OPERATIONS', items: [
        { label: 'Orders', icon: 'box', children: ['New Order', 'Order List', 'Auto Pick Orders', 'Auto Call Center', 'Order Block List', 'Preorders', 'Orders Dashboard'], href: mod('orders') },
        { label: 'Delivery', icon: 'truck', badge: '2', children: ['Ready to Ship', 'Courier Management', 'Scan To Update', 'Courier Invoice Upload', 'Courier Ledger'], href: mod('delivery') },
        { label: 'Inventory', icon: 'layers', children: ['Inventory Dashboard', 'Products', 'Stock Ledger', 'Branches', 'Warranty'], href: mod('inventory') },
        { label: 'Messages', icon: 'chat', children: ['Inbox', 'Comments', 'AI Activity'], href: mod('messages') },
        { label: 'Purchase', icon: 'cart', children: ['Purchase Dashboard', 'Purchase List', 'New Purchase', 'Receive Purchase', 'Supplier Ledger', 'Suppliers', 'Ask for Quotation'], href: mod('purchase') },
        { label: 'Task & Follow-up', icon: 'check', children: ['Task Board', 'Agent Cockpit', 'Follow-up Management', 'My Follow-ups', 'Follow-up Performance'], href: mod('task') },
        { label: 'Call Center', icon: 'phone', children: ['Dashboard', 'Reports', 'Contacts'], href: mod('call-center') },
        { label: 'Work Inbox', icon: 'inbox', children: ['Work Control', 'Rule Settings', 'My Work (off)'], href: mod('work-inbox') },
    ]},
    { sec: 'GROWTH', items: [
        { label: 'Ads', icon: 'chart', children: ['Ads Dashboard', 'Meta Ads', 'TikTok Ads'], href: mod('ads') },
        { label: 'Marketing', icon: 'megaphone', children: ['Automation', 'SMS Marketing'], href: mod('marketing') },
        { label: 'Customers', icon: 'users', children: ['Customer List', 'Bulk Upload'], href: mod('customers') },
    ]},
    { sec: 'BUSINESS CONTROL', items: [
        { label: 'HRM', icon: 'briefcase', children: ['HR Dashboard', 'Attendance', 'Departments & SOP', 'User & Role', 'Settings'], href: mod('hrm') },
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

function NavGroup({ item, current }) {
    const active = current.startsWith(item.href);
    const [open, setOpen] = useState(active);
    if (!item.children) {
        return <Link href={item.href} className={`nav-item ${active ? 'act' : ''}`}><I d={P[item.icon]} />{item.label}</Link>;
    }
    return (
        <>
            <div className={`nav-item ${open ? 'open' : ''} ${active ? 'act' : ''}`} onClick={() => setOpen(!open)}>
                <I d={P[item.icon]} />{item.label}
                {item.badge && <span className="badge">{item.badge}</span>}
                <span className="chev">›</span>
            </div>
            {open && (
                <div className="sub-items">
                    {item.children.map((c) => item.settings ? (
                        <Link key={c.g} href={`/settings?group=${c.g}`} className="nav-item">{c.label}</Link>
                    ) : (
                        <Link key={c} href={item.href} className="nav-item">{c}</Link>
                    ))}
                </div>
            )}
        </>
    );
}

export default function AppLayout({ children }) {
    const { url } = usePage();
    const current = url.split('?')[0];
    return (
        <div className="shell">
            <aside className="sb">
                <div className="sb-ws">
                    <div className="sb-ava">D</div>
                    <div><div className="nm">Demo Shop</div><div className="rl">Workspace</div></div>
                    <span className="chev" style={{ marginLeft: 'auto', color: '#b3a6cc' }}>⌄</span>
                </div>
                <div className="sb-search"><I d={P.search} size={14} /> Quick Search <kbd>CTRL K</kbd></div>
                {NAV.map((group, gi) => (
                    <div key={gi}>
                        {group.sec && <div className="nav-sec">{group.sec}</div>}
                        {group.items.map((item) => <NavGroup key={item.label} item={item} current={current} />)}
                    </div>
                ))}
                <div style={{ marginTop: 'auto', padding: '10px 8px', borderTop: '1px solid var(--line)' }}>
                    <Link href={route('logout')} method="post" as="button" className="nav-item" style={{ width: '100%' }}><I d={P.logout} /> Log out</Link>
                </div>
            </aside>
            <div className="main">
                <header className="topbar">
                    <span className="tb-item"><I d={P.grid} size={16} /></span>
                    <span className="tb-item"><I d={P.search} size={15} /> Search</span>
                    <span className="tb-item"><I d={P.cart} size={15} /> New Order</span>
                    <span className="tb-item"><I d={P.file} size={15} /> Order List</span>
                    <span className="tb-item"><I d={P.send} size={15} /> Send Message</span>
                    <div className="tb-right">
                        <span className="tb-item" style={{ fontWeight: 600 }}>BN</span>
                        <button className="ask-btn">✦ Ask SkaleUp</button>
                        <span className="tb-item"><I d={P.bulb} size={17} /></span>
                        <span className="tb-item"><I d={P.bell} size={17} /></span>
                        <span className="tb-item"><I d={P.sun} size={17} /></span>
                        <div className="tb-ava">JD</div>
                    </div>
                </header>
                <main className="content">{children}</main>
            </div>
        </div>
    );
}
