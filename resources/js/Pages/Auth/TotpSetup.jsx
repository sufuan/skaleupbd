import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/react';

export default function TotpSetup({ secret, qrSvg }) {
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('totp.setup.confirm'));
    };

    return (
        <GuestLayout>
            <Head title={t('Set up two-factor login')} />
            <h1 className="auth-title">{t('Set up two-factor login')}</h1>
            <p className="auth-sub">{t('Open Google Authenticator on your phone, scan this code, then type the 6-digit code the app shows.')}</p>
            <div className="qr-box" dangerouslySetInnerHTML={{ __html: qrSvg }} />
            <p className="sm mut" style={{ textAlign: 'center', margin: '12px 0 4px' }}>{t('Cannot scan? Type this key in the app:')}</p>
            <div className="secret-code">{secret}</div>
            <form onSubmit={submit} style={{ marginTop: 18 }}>
                <div className="frow">
                    <label className="fl" htmlFor="code">{t('6-digit code')}</label>
                    <input id="code" type="text" inputMode="numeric" autoComplete="one-time-code" className="inp code-inp" value={data.code} autoFocus onChange={(e) => setData('code', e.target.value)} placeholder="••••••" />
                    {errors.code && <div className="auth-err" style={{ textAlign: 'center' }}>{errors.code}</div>}
                </div>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px' }} disabled={processing}>
                    {t('Turn on two-factor login')}
                </button>
            </form>
            <p className="sm mut" style={{ textAlign: 'center', marginTop: 18 }}>
                <Link href={route('logout')} method="post" as="button" className="auth-link">{t('Log out')}</Link>
            </p>
        </GuestLayout>
    );
}
