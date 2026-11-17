import GuestLayout from '@/Layouts/GuestLayout';
import { t } from '@/lib/i18n';
import { Head, useForm } from '@inertiajs/react';
import { useEffect } from 'react';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });

    useEffect(() => () => reset('password'), []);

    const submit = (e) => {
        e.preventDefault();
        post(route('password.confirm'), { onFinish: () => reset() });
    };

    return (
        <GuestLayout>
            <Head title={t('Confirm password')} />
            <h1 className="auth-title">{t('Confirm password')}</h1>
            <p className="auth-sub">{t('This is a secure area. Please confirm your password before continuing.')}</p>
            <form onSubmit={submit}>
                <div className="frow">
                    <label className="fl" htmlFor="password">{t('Password')}</label>
                    <input id="password" type="password" name="password" value={data.password} className="inp" autoComplete="current-password" autoFocus onChange={(e) => setData('password', e.target.value)} />
                    {errors.password && <div className="auth-err">{errors.password}</div>}
                </div>
                <button type="submit" className="btn pri" style={{ width: '100%', justifyContent: 'center', padding: '11px 16px' }} disabled={processing}>
                    {t('Confirm')}
                </button>
            </form>
        </GuestLayout>
    );
}
