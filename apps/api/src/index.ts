import Fastify from 'fastify';
import cors from '@fastify/cors';
import fastifyJwt from '@fastify/jwt';
import { config } from 'dotenv';
import { registerAuthRoutes } from './routes/auth.js';
import { registerServiceRoutes } from './routes/services.js';
import { registerOrderRoutes } from './routes/orders.js';
import { prisma } from './lib/prisma.js';

config();

const server = Fastify({ logger: true });

await server.register(cors, { origin: true, credentials: true });
await server.register(fastifyJwt, { secret: process.env.JWT_SECRET || 'dev-secret' });

server.get('/health', async () => ({ status: 'ok' }));

await server.register(registerAuthRoutes, { prefix: '/auth' });
await server.register(registerServiceRoutes, { prefix: '/services' });
await server.register(registerOrderRoutes, { prefix: '/orders' });

const port = Number(process.env.PORT || 4000);
const host = '0.0.0.0';

const start = async () => {
  try {
    await server.listen({ port, host });
    server.log.info(`API listening on http://${host}:${port}`);
  } catch (err) {
    server.log.error(err);
    await prisma.$disconnect();
    process.exit(1);
  }
};

start();