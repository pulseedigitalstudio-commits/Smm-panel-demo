import { FastifyInstance } from 'fastify';
import { z } from 'zod';
import { prisma } from '../lib/prisma.js';

const createOrderSchema = z.object({
  serviceId: z.number().int(),
  link: z.string().url(),
  quantity: z.number().int().positive(),
});

export async function registerOrderRoutes(server: FastifyInstance) {
  server.addHook('onRequest', async (request, reply) => {
    try {
      await request.jwtVerify();
    } catch {
      return reply.code(401).send({ message: 'Unauthorized' });
    }
  });

  server.get('/', async (request) => {
    const userId = (request.user as any).sub as number;
    const orders = await prisma.order.findMany({ where: { userId }, include: { service: true } });
    return orders;
  });

  server.post('/', async (request, reply) => {
    const parsed = createOrderSchema.safeParse(request.body);
    if (!parsed.success) return reply.code(400).send(parsed.error.flatten());

    const userId = (request.user as any).sub as number;
    const service = await prisma.service.findUnique({ where: { id: parsed.data.serviceId } });
    if (!service || !service.isActive) return reply.code(404).send({ message: 'Service not found' });

    const cost = Number(service.ratePerThousand) * (parsed.data.quantity / 1000);

    // Deduct from wallet (simple check)
    const wallet = await prisma.wallet.findUnique({ where: { userId } });
    if (!wallet || wallet.balance < cost) return reply.code(402).send({ message: 'Insufficient balance' });

    const order = await prisma.$transaction(async (tx) => {
      await tx.wallet.update({ where: { userId }, data: { balance: { decrement: cost } } });
      const created = await tx.order.create({
        data: {
          userId,
          serviceId: service.id,
          link: parsed.data.link,
          quantity: parsed.data.quantity,
          cost,
          status: 'PENDING',
        },
      });
      return created;
    });

    // TODO: enqueue job to fulfill order via provider adapter

    return order;
  });
}