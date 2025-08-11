import { PrismaClient } from '@prisma/client';
import bcrypt from 'bcryptjs';

const prisma = new PrismaClient();

async function main() {
  const email = 'admin@example.com';
  const password = 'Admin1234!';
  const saltRounds = 10;
  const passwordHash = await bcrypt.hash(password, saltRounds);

  await prisma.user.upsert({
    where: { email },
    update: {},
    create: { email, passwordHash, role: 'ADMIN', wallet: { create: { balance: 100 } } },
  });

  const provider = await prisma.provider.upsert({
    where: { name: 'DummyProvider' },
    update: {},
    create: { name: 'DummyProvider', apiUrl: 'https://dummy.provider/api', apiKey: 'dummy' },
  });

  const services = [
    {
      name: 'Instagram Likes',
      category: 'Instagram',
      description: 'High quality likes',
      ratePerThousand: 1.2,
      min: 10,
      max: 50000,
      providerId: provider.id,
      providerServiceId: '1',
    },
    {
      name: 'YouTube Views',
      category: 'YouTube',
      description: 'Realistic views',
      ratePerThousand: 0.8,
      min: 100,
      max: 1000000,
      providerId: provider.id,
      providerServiceId: '2',
    },
  ];

  for (const svc of services) {
    const existing = await prisma.service.findFirst({
      where: { name: svc.name, providerId: svc.providerId },
    });
    if (!existing) {
      await prisma.service.create({ data: svc });
    }
  }

  console.log('Seeded admin and services. Admin login:', email, password);
}

main()
  .catch((e) => {
    console.error(e);
    process.exit(1);
  })
  .finally(async () => {
    await prisma.$disconnect();
  });