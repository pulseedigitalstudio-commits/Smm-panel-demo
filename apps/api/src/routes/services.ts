import { FastifyInstance } from 'fastify';
import { prisma } from '../lib/prisma.js';

export async function registerServiceRoutes(server: FastifyInstance) {
  server.get('/', async () => {
    const services = await prisma.service.findMany({
      where: { isActive: true },
      include: { provider: true },
      orderBy: [{ category: 'asc' }, { name: 'asc' }],
    });
    return services;
  });

  server.get('/categories', async () => {
    const categories = await prisma.service.groupBy({ by: ['category'], _count: { _all: true } });
    return categories.map((c) => ({ category: c.category, count: c._count._all }));
  });
}