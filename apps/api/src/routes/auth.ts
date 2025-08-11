import { FastifyInstance } from 'fastify';
import { z } from 'zod';
import bcrypt from 'bcryptjs';
import { prisma } from '../lib/prisma.js';

const registerSchema = z.object({
  email: z.string().email(),
  password: z.string().min(8),
});

export async function registerAuthRoutes(server: FastifyInstance) {
  server.post('/register', async (request, reply) => {
    const parsed = registerSchema.safeParse(request.body);
    if (!parsed.success) return reply.code(400).send(parsed.error.flatten());

    const existing = await prisma.user.findUnique({ where: { email: parsed.data.email } });
    if (existing) return reply.code(409).send({ message: 'Email already in use' });

    const saltRounds = Number(process.env.BCRYPT_SALT_ROUNDS || 10);
    const hash = await bcrypt.hash(parsed.data.password, saltRounds);

    const user = await prisma.user.create({ data: { email: parsed.data.email, passwordHash: hash, wallet: { create: {} } } });

    const token = server.jwt.sign({ sub: user.id, email: user.email });
    return { token, user: { id: user.id, email: user.email } };
  });

  server.post('/login', async (request, reply) => {
    const parsed = registerSchema.safeParse(request.body);
    if (!parsed.success) return reply.code(400).send(parsed.error.flatten());

    const user = await prisma.user.findUnique({ where: { email: parsed.data.email } });
    if (!user) return reply.code(401).send({ message: 'Invalid credentials' });

    const ok = await bcrypt.compare(parsed.data.password, user.passwordHash);
    if (!ok) return reply.code(401).send({ message: 'Invalid credentials' });

    const token = server.jwt.sign({ sub: user.id, email: user.email });
    return { token, user: { id: user.id, email: user.email } };
  });

  server.get('/me', async (request, reply) => {
    try {
      await request.jwtVerify();
    } catch {
      return reply.code(401).send({ message: 'Unauthorized' });
    }
    const userId = (request.user as any).sub as number;
    const user = await prisma.user.findUnique({ where: { id: userId }, include: { wallet: true } });
    if (!user) return reply.code(404).send({ message: 'User not found' });
    return { id: user.id, email: user.email, wallet: user.wallet };
  });
}