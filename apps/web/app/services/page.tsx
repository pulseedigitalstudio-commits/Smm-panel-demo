async function getServices() {
  const base = process.env.NEXT_PUBLIC_API_BASE_URL || 'http://localhost:4000';
  const res = await fetch(`${base}/services`, { cache: 'no-store' });
  if (!res.ok) throw new Error('Failed to load services');
  return res.json();
}

export default async function ServicesPage() {
  const services = await getServices();
  return (
    <div>
      <h1>Services</h1>
      <ul>
        {services.map((s: any) => (
          <li key={s.id}>
            <strong>{s.category} / {s.name}</strong> — ${s.ratePerThousand}/1k (min {s.min}, max {s.max})
          </li>
        ))}
      </ul>
    </div>
  );
}