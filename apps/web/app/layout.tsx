"use client";
import { useEffect, useState } from 'react';

export const metadata = {
  title: 'SMM Panel',
  description: 'Modern SMM panel',
};

function WalletBadge() {
  const [balance, setBalance] = useState<number | null>(null);
  useEffect(() => {
    const token = localStorage.getItem('token');
    if (!token) return;
    const base = process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost:4000';
    fetch(`${base}/auth/me`, { headers: { Authorization: `Bearer ${token}` } })
      .then((r) => (r.ok ? r.json() : null))
      .then((data) => {
        if (data?.wallet?.balance != null) setBalance(data.wallet.balance);
      })
      .catch(() => {});
  }, []);
  if (balance == null) return null;
  return <span style={{ marginLeft: 'auto' }}>Balance: ${balance.toFixed(2)}</span>;
}

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body style={{ fontFamily: 'Inter, system-ui, Arial, sans-serif', margin: 0 }}>
        <header style={{ padding: '12px 20px', borderBottom: '1px solid #eee', display: 'flex', gap: 16, alignItems: 'center' }}>
          <a href="/">SMM Panel</a>
          <nav style={{ display: 'flex', gap: 12 }}>
            <a href="/services">Services</a>
            <a href="/orders">Orders</a>
            <a href="/add-funds">Add Funds</a>
            <a href="/tickets">Tickets</a>
          </nav>
          <div style={{ display: 'flex', gap: 12, marginLeft: 'auto' }}>
            <a href="/login">Login</a>
            <a href="/register">Register</a>
          </div>
          <WalletBadge />
        </header>
        <main style={{ padding: 20 }}>{children}</main>
      </body>
    </html>
  );
}