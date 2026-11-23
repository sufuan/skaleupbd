import { useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { t } from '@/lib/i18n';

export default function UpdatePasswordForm() {
    const passwordInput = useRef();
    const currentPasswordInput = useRef();

    const {
        data,
        setData,
        errors,
        put,
        reset,
        processing,
        recentlySuccessful,
    } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const updatePassword = (e) => {
        e.preventDefault();

        put(route('password.update'), {
            preserveScroll: true,
            onSuccess: () => reset(),
            onError: (errors) => {
                if (errors.password) {
                    reset('password', 'password_confirmation');
                    passwordInput.current.focus();
                }

                if (errors.current_password) {
                    reset('current_password');
                    currentPasswordInput.current.focus();
                }
            },
        });
    };

    const err = (msg) =>
        msg ? (
            <div style={{ color: 'var(--bad)', fontSize: 12.5, marginTop: 4 }}>
                {msg}
            </div>
        ) : null;

    return (
        <section className="card">
            <h3>{t('Update Password')}</h3>
            <div className="card-sub">
                {t('Ensure your account is using a long, random password to stay secure.')}
            </div>

            <form onSubmit={updatePassword}>
                <div className="frow">
                    <label className="fl" htmlFor="current_password">
                        {t('Current Password')}
                    </label>
                    <input
                        id="current_password"
                        ref={currentPasswordInput}
                        type="password"
                        className="inp"
                        value={data.current_password}
                        onChange={(e) =>
                            setData('current_password', e.target.value)
                        }
                        autoComplete="current-password"
                    />
                    {err(errors.current_password)}
                </div>

                <div className="frow mt16">
                    <label className="fl" htmlFor="password">
                        {t('New Password')}
                    </label>
                    <input
                        id="password"
                        ref={passwordInput}
                        type="password"
                        className="inp"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        autoComplete="new-password"
                    />
                    {err(errors.password)}
                </div>

                <div className="frow mt16">
                    <label className="fl" htmlFor="password_confirmation">
                        {t('Confirm Password')}
                    </label>
                    <input
                        id="password_confirmation"
                        type="password"
                        className="inp"
                        value={data.password_confirmation}
                        onChange={(e) =>
                            setData('password_confirmation', e.target.value)
                        }
                        autoComplete="new-password"
                    />
                    {err(errors.password_confirmation)}
                </div>

                <div
                    style={{
                        display: 'flex',
                        alignItems: 'center',
                        gap: 12,
                        marginTop: 18,
                    }}
                >
                    <button className="btn pri" disabled={processing}>
                        {t('Save')}
                    </button>
                    {recentlySuccessful && (
                        <span
                            style={{
                                fontSize: 13.5,
                                fontWeight: 600,
                                color: 'var(--ok)',
                            }}
                        >
                            {t('Saved.')}
                        </span>
                    )}
                </div>
            </form>
        </section>
    );
}
