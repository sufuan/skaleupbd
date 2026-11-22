import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export default function VerifyRegistration({ shopName = '', channels = 'email', maskedEmail = '', maskedPhone = '', cooldown = 0 }) {
    const { flash } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({ code: '' });
    const [wait, setWait] = useState(cooldown || 0);
    const [resending, setResending] = useState(false);

    useEffect(() => { setWait(cooldown || 0); }, [cooldown]);
    useEffect(() => {
        if (wait <= 0) return undefined;
        const id = setInterval(() => setWait((w) => Math.max(0, w - 1)), 1000);
        return () => clearInterval(id);
    }, [wait > 0]);

    const submit = (e) => {
        e.preventDefault();
        post(route('registration.verify.store'));
    };

    const resend = () => {
        setResending(true);
        router.post(route('registration.verify.resend'), {}, {
            preserveScroll: true,
            onFinish: () => setResending(false),
        });
    };

    const sentTo = channels === 'both'
        ? t('We sent a 6-digit code to your email :email and your mobile :phone.', { email: maskedEmail, phone: maskedPhone })
        : channels === 'sms'
            ? t('We sent a 6-digit code to your mobile :phone.', { phone: maskedPhone })
            : t('We sent a 6-digit code to your email :email.', { email: maskedEmail });

    return (
        <GuestLayout>
            <Head title={t('Enter your code')} />
            <h1 className="auth-title">{t('Enter your code')}</h1>
            <p className="auth-sub">
                {shopName ? t('One last step for :shop - check that the shop is really yours.', { shop: shopName }) : t('One last step - check that the account is really yours.')}
            </p>
            <p style={{ fontSize: 14.5, marginBottom: 16 }}>{sentTo}</p>

            {flash?.success && <div style={{ background: '#E9F7EE', color: '#166534', borderRadius: 8, padding: '10px 12px', fontSize: 14, marginBottom: 14 }}>{flash.success}</div>}
            {flash?.error && <div style={{ background: '#FDECEC', color: '#B91C1C', borderRadius: 8, padding: '10px 12px', fontSize: 14, marginBottom: 14 }}>{flash.error}</div>}

            <form onSubmit={submit}>
                <div className="frow">
                    <label className="fl" htmlFor="code">{t('Code from the message')}</label>
                    <input
                        id="code"
                        type="text"
                        name="code"
                        value={data.code}
                        className="inp"
                        autoComplete="one-time-code"
                        inputMode="numeric"
                        autoFocus
                        maxLength={6}
                        placeholder="------"
                        style={{ fontSize: 24, letterSpacing: 8, textAlign: 'center', padding: '13px 12px', fontWeight: 700 }}
                        onChange={(e) => setData('code', e.target.value.replace(/\D/g, ''))}
                    />
                    {errors.code && <div className="auth-err">{errors.code}</div>}
                </div>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '13px 16px', minHeight: 48, marginTop: 6, fontSize: 15.5 }} disabled={processing || data.code.length !== 6}>
                    {t('Verify and continue')}
                </button>
            </form>

            <div style={{ textAlign: 'center', marginTop: 18 }}>
                {wait > 0 ? (
                    <span className="sm mut">{t('You can ask for a new code in :count seconds.', { count: wait })}</span>
                ) : (
                    <button type="button" className="auth-link" style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 14.5, minHeight: 44, padding: '10px 14px' }} onClick={resend} disabled={resending}>
                        {t('Send a new code')}
                    </button>
                )}
            </div>
            <p className="sm mut" style={{ textAlign: 'center', marginTop: 8 }}>
                {t('The code works for 10 minutes. Never share it with anyone.')}
            </p>
            <div style={{ textAlign: 'center', marginTop: 14 }}>
                <button type="button" className="auth-link" style={{ background: 'none', border: 'none', cursor: 'pointer', fontSize: 13.5 }} onClick={() => router.post(route('logout'))}>
                    {t('Log out')}
                </button>
            </div>
        </GuestLayout>
    );
}
