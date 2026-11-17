import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, Link, useForm } from '@inertiajs/react';

export default function TotpChallenge() {
    const { data, setData, post, processing, errors } = useForm({ code: '' });

    const submit = (e) => {
        e.preventDefault();
        post(route('totp.challenge.verify'));
    };

    return (
        <GuestLayout>
            <Head title={t('Two-factor check')} />
            <h1 className="auth-title">{t('Two-factor check')}</h1>
            <p className="auth-sub">{t('Type the 6-digit code from your Google Authenticator app.')}</p>
            <form onSubmit={submit}>
                <div className="frow">
                    <label className="fl" htmlFor="code">{t('6-digit code')}</label>
                    <input id="code" type="text" inputMode="numeric" autoComplete="one-time-code" className="inp code-inp" value={data.code} autoFocus onChange={(e) => setData('code', e.target.value)} placeholder="••••••" />
                    {errors.code && <div className="auth-err" style={{ textAlign: 'center' }}>{errors.code}</div>}
                </div>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px' }} disabled={processing}>
                    {t('Verify and continue')}
                </button>
            </form>
            <p className="sm mut" style={{ textAlign: 'center', marginTop: 18 }}>
                <Link href={route('logout')} method="post" as="button" className="auth-link">{t('Log out')}</Link>
            </p>
        </GuestLayout>
    );
}
