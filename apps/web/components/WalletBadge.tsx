"use client";
import { useEffect, useState } from 'react';

export default function WalletBadge() {
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