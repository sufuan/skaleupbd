import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '@/Layouts/AppLayout';
import { t } from '@/lib/i18n';

// Work Inbox Settings - the module's OWN settings page (prototype panel
// wi-settings): all 37 rules with switch, time, priority and assignee,
// plus the notification behaviour switches.
export default function Settings({ groups, toggles, staff, priorities, assignees }) {
    const [rules, setRules] = useState(() => {
        const out = {};
        groups.forEach((g) => g.rules.forEach((r) => {
            out[r.key] = { enabled: r.enabled, value: r.value, unit: r.unit, priority: r.priority, assign: r.assign, assign_user: r.assign_user || '' };
        }));
        return out;
    });
    const [tg, setTg] = useState(toggles);

    const save = (nextRules, nextTg) => router.post('/work-inbox/settings', { rules: nextRules, toggles: nextTg }, { preserveScroll: true });
    const setRule = (key, patch, commit = true) => {
        const next = { ...rules, [key]: { ...rules[key], ...patch } };
        setRules(next);
        if (commit) save(next, tg);
    };
    const setToggle = (name, value) => {
        const next = { ...tg, [name]: value };
        setTg(next);
        save(rules, next);
    };

    return (
        <AppLayout>
            <Head title={t('Work Inbox Settings')} />
            <div className="wi">
                <div className="wi-head">
                    <div>
                        <h3 style={{ fontSize: 20 }}>{t('Work Inbox Settings')}</h3>
                        <p style={{ color: '#64748B', fontSize: 13.5, marginTop: 3 }}>{t('The system checks these rules every ~5 minutes. A rule that is off creates no tasks. Time, priority and assignee - everything can be changed from here.')}</p>
                    </div>
                </div>

                {groups.map((g) => (
                    <div key={g.key}>
                        <div className="rule-group">{t(g.label)} ({g.count} {t('rules')})</div>
                        {g.rules.map((r) => {
                            const c = rules[r.key];
                            return (
                                <div className="rule-row" key={r.key}>
                                    <button className={`switch${c.enabled ? ' on' : ''}`} onClick={() => setRule(r.key, { enabled: !c.enabled })}></button>
                                    <div className="rule-main">
                                        <div className="rule-name">{r.num} · {t(r.name)}</div>
                                        <div className="rule-desc">{t(r.desc)}</div>
                                    </div>
                                    {r.immediate ? (
                                        <span className="rule-fld">{t('Right away')}</span>
                                    ) : (
                                        <label className="rule-fld">{t('Time')}
                                            <input type="number" min="0" value={c.value}
                                                onChange={(e) => setRule(r.key, { value: e.target.value }, false)}
                                                onBlur={() => save(rules, tg)} />
                                            <select value={c.unit} onChange={(e) => setRule(r.key, { unit: e.target.value })}>
                                                <option value="minutes">{t('Minutes')}</option>
                                                <option value="hours">{t('Hours')}</option>
                                                <option value="days">{t('Days')}</option>
                                            </select>
                                        </label>
                                    )}
                                    <label className="rule-fld">{t('Priority')}
                                        <select value={c.priority} onChange={(e) => setRule(r.key, { priority: e.target.value })}>
                                            {Object.entries(priorities).map(([k, v]) => <option key={k} value={k}>{t(v)}</option>)}
                                        </select>
                                    </label>
                                    <label className="rule-fld">{t('Assign to')}
                                        <select value={c.assign} onChange={(e) => setRule(r.key, { assign: e.target.value })}>
                                            {Object.entries(assignees).map(([k, v]) => <option key={k} value={k}>{t(v)}</option>)}
                                        </select>
                                    </label>
                                    {c.assign === 'staff' && (
                                        <label className="rule-fld">{t('Staff')}
                                            <select value={c.assign_user} onChange={(e) => setRule(r.key, { assign_user: e.target.value ? Number(e.target.value) : '' })}>
                                                <option value="">{t('Select')}</option>
                                                {staff.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
                                            </select>
                                        </label>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                ))}

                <div className="rule-group">{t('Notifications')}</div>
                <div className="rule-row">
                    <button className={`switch${tg.notify_new ? ' on' : ''}`} onClick={() => setToggle('notify_new', !tg.notify_new)}></button>
                    <div className="rule-main">
                        <div className="rule-name">{t('Bell notification when a new task arrives')}</div>
                        <div className="rule-desc">{t('It goes to the assigned person right away; the sidebar also shows a count next to Work Inbox')}</div>
                    </div>
                </div>
                <div className="rule-row">
                    <button className={`switch${tg.notify_overdue ? ' on' : ''}`} onClick={() => setToggle('notify_overdue', !tg.notify_overdue)}></button>
                    <div className="rule-main">
                        <div className="rule-name">{t('Remind again when a task is overdue')}</div>
                        <div className="rule-desc">{t('When the time runs out, the assigned person is reminded once more; if it is very urgent, the supervisor gets it too')}</div>
                    </div>
                </div>
                <div className="rule-row">
                    <button className={`switch${tg.auto_close ? ' on' : ''}`} onClick={() => setToggle('auto_close', !tg.auto_close)}></button>
                    <div className="rule-main">
                        <div className="rule-name">{t('Task closes itself when the problem is fixed')}</div>
                        <div className="rule-desc">{t('For example, when an order is Delivered, the "Shipped stuck" task closes on its own - the reason stays in the history')}</div>
                    </div>
                </div>
                <div className="rule-row">
                    <button className={`switch${tg.my_work_enabled ? ' on' : ''}`} onClick={() => setToggle('my_work_enabled', !tg.my_work_enabled)}></button>
                    <div className="rule-main">
                        <div className="rule-name">{t('Turn on the My Work page')}</div>
                        <div className="rule-desc">{t('When on, each staff member sees their own work on a separate My Work page; when off, all work is done from Work Control')}</div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
