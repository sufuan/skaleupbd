import { Head, Link } from '@inertiajs/react';

// Minimal public front page. Its main job is to show the legal business
// name and address on the website (required for Meta Business
// Verification), plus a way into the seller login. Kept deliberately
// plain — not a marketing homepage.
export default function Landing() {
    return (
        <div style={{
            minHeight: '100vh',
            display: 'flex',
            flexDirection: 'column',
            background: '#faf8fb',
            color: '#241a2b',
            fontFamily: 'inherit',
            boxSizing: 'border-box',
        }}>
            <Head title="Scalenow Bd" />

            <main style={{
                flex: 1,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                justifyContent: 'center',
                textAlign: 'center',
                padding: '48px 20px',
                boxSizing: 'border-box',
                width: '100%',
            }}>
                <div style={{
                    width: 56,
                    height: 56,
                    borderRadius: 14,
                    background: '#411550',
                    color: '#fff',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 28,
                    fontWeight: 700,
                    marginBottom: 16,
                }}>S</div>
                <div style={{ fontSize: 30, fontWeight: 700, color: '#411550', lineHeight: 1.2 }}>Scalenow Bd</div>
                <p style={{ fontSize: 16, lineHeight: 1.6, maxWidth: 460, margin: '14px 0 26px' }}>
                    This is the official website of the Scalenow Bd seller platform for online sellers in Bangladesh.
                </p>
                <Link
                    href="/admin/login"
                    style={{
                        background: '#411550',
                        color: '#fff',
                        padding: '12px 34px',
                        borderRadius: 10,
                        fontSize: 16,
                        fontWeight: 600,
                        textDecoration: 'none',
                        display: 'inline-block',
                    }}
                >
                    Log in
                </Link>
            </main>

            <footer style={{
                borderTop: '1px solid #e6ddea',
                padding: '22px 20px 26px',
                textAlign: 'center',
                fontSize: 14,
                lineHeight: 1.7,
                color: '#4c4257',
                boxSizing: 'border-box',
                width: '100%',
            }}>
                <div style={{ fontWeight: 700, color: '#411550', fontSize: 15 }}>Scalenow Bd</div>
                <div>Ward No-07, Pekherchar, Barurbat, Post Office: Pekherchar, Narsingdi Sadar, Narsingdi 1600, Bangladesh</div>
                <div>Phone: +8801410033782</div>
                <div style={{ marginTop: 8, color: '#8a7f96' }}>© 2026 Scalenow Bd</div>
            </footer>
        </div>
    );
}
