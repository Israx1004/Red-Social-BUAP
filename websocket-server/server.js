/**
 * UniLink — websocket-server/server.js
 * Real-time events using Socket.io + Redis adapter
 * Handles: moderation broadcasts, notifications, live feed
 */

const { createServer } = require('http');
const { Server }       = require('socket.io');
const { createClient } = require('redis');
const { createAdapter } = require('@socket.io/redis-adapter');
const jwt              = require('jsonwebtoken');
const fs               = require('fs');
const path             = require('path');

const PORT      = process.env.PORT      || 3001;
const REDIS_URL = process.env.REDIS_URL || 'redis://localhost:6379';

// Mismo secreto que PHP: variable JWT_SECRET o el archivo que genera backend/shared/helpers.php
function loadJwtSecret() {
  if (process.env.JWT_SECRET) return process.env.JWT_SECRET;
  const file = path.join(__dirname, '..', 'backend', 'shared', '.jwt_secret');
  try {
    const s = fs.readFileSync(file, 'utf8').trim();
    if (s.length >= 32) return s;
  } catch { /* no existe todavía */ }
  console.error('[UniLink WS] No hay JWT_SECRET. Abre la app PHP una vez (genera backend/shared/.jwt_secret) o define la variable JWT_SECRET.');
  process.exit(1);
}
const JWT_SECRET = loadJwtSecret();

const DEFAULT_ORIGINS = ['http://localhost', 'http://localhost:80', 'http://localhost:8012', 'http://localhost:8080',
                         'http://127.0.0.1', 'http://127.0.0.1:8012'];

// ---- HTTP server ----
const httpServer = createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
      status: 'ok',
      connections: io.engine.clientsCount,
      timestamp: new Date().toISOString(),
    }));
    return;
  }
  res.writeHead(200, { 'Content-Type': 'text/plain' });
  res.end('UniLink WebSocket Server');
});

// ---- Socket.io ----
const io = new Server(httpServer, {
  cors: {
    origin: process.env.ALLOWED_ORIGINS?.split(',').map(s => s.trim()) || DEFAULT_ORIGINS,
    methods: ['GET', 'POST'],
    credentials: true,
  },
  pingTimeout: 30000,
  pingInterval: 10000,
});

// ---- Redis adapter (enables horizontal scaling) ----
async function setupRedis() {
  // Redis es opcional: tras 3 intentos fallidos se sigue sin él (un solo servidor)
  const pubClient = createClient({
    url: REDIS_URL,
    socket: { reconnectStrategy: retries => (retries >= 3 ? new Error('Redis no disponible') : 1000) },
  });
  const subClient = pubClient.duplicate();

  pubClient.on('error', err => console.error('[Redis pub]', err.message));
  subClient.on('error', err => console.error('[Redis sub]', err.message));

  await Promise.all([pubClient.connect(), subClient.connect()]);
  io.adapter(createAdapter(pubClient, subClient));
  console.log('[UniLink WS] Redis adapter connected');

  // Subscribe to PHP-side events (moderation actions, notifications)
  await subClient.subscribe('unilink:broadcast', (message) => {
    try {
      const event = JSON.parse(message);
      handleBroadcast(event);
    } catch (e) {
      console.error('[Redis sub] Invalid message:', e.message);
    }
  });
}

// ---- Auth middleware ----
io.use((socket, next) => {
  const token = socket.handshake.auth?.token || socket.handshake.headers?.authorization?.split(' ')[1];

  if (!token) {
    return next(new Error('Authentication required'));
  }

  try {
    const payload = jwt.verify(token, JWT_SECRET, { algorithms: ['HS256'] });
    socket.user   = payload;
    next();
  } catch (err) {
    next(new Error('Invalid or expired token'));
  }
});

// ---- Connection handler ----
io.on('connection', (socket) => {
  const user = socket.user;
  console.log(`[WS] Connected: user_id=${user.user_id} role=${user.role}`);

  // ---- Join rooms ----
  // Las salas se deciden con los datos del TOKEN verificado, nunca con lo que mande el cliente
  socket.on('join_room', () => {
    const { user_id, faculty_id: faculty, role } = socket.user;
    socket.join(`user:${user_id}`);
    if (faculty) socket.join(`faculty:${faculty}`);
    socket.join(`role:${role}`);
    if (role === 'moderator') socket.join('moderators');
    if (role === 'admin')     socket.join('admins');
    if ((role === 'admin' || role === 'moderator') && faculty) {
      socket.join(`mod_faculty:${faculty}`);
    }
    console.log(`[WS] user:${user_id} joined rooms: user, faculty:${faculty}, role:${role}`);
  });

  // ---- Moderator: moderate a post (broadcast to all users) ----
  socket.on('moderate_post', ({ post_id, reason } = {}) => {
    if (!['admin', 'moderator'].includes(socket.user.role)) {
      return socket.emit('error', { message: 'Not authorized' });
    }
    // Broadcast to everyone — post disappears from all feeds
    const id = parseInt(post_id, 10);
    if (!id) return;
    io.emit('post_removed', { post_id: id, reason: String(reason || 'moderated').slice(0, 100) });
    console.log(`[WS] Post ${post_id} moderated by user:${socket.user.user_id}`);
  });

  // ---- User sends a like (broadcast count update to others viewing same post) ----
  socket.on('like_post', ({ post_id, likes_count } = {}) => {
    const id = parseInt(post_id, 10), n = parseInt(likes_count, 10);
    if (!id || isNaN(n) || n < 0) return;
    socket.broadcast.emit('like_update', { post_id: id, likes_count: n });
  });

  // ---- Join group chat room ----
  // (la pertenencia al grupo la valida PHP; aquí solo se aceptan IDs numéricos)
  socket.on('join_group', ({ group_id } = {}) => {
    const id = parseInt(group_id, 10);
    if (id) socket.join(`group:${id}`);
  });

  // ---- Group message ----
  socket.on('group_message', ({ group_id, message, sender_name } = {}) => {
    const id = parseInt(group_id, 10);
    if (!id || !socket.rooms.has(`group:${id}`)) return;
    io.to(`group:${id}`).emit('group_message', {
      group_id: id,
      message:     String(message || '').slice(0, 1000),
      sender_id:   socket.user.user_id,
      sender_name: String(sender_name || 'Usuario').slice(0, 100),
      timestamp:   new Date().toISOString(),
    });
  });

  // ---- Typing indicator ----
  socket.on('typing', ({ group_id } = {}) => {
    const id = parseInt(group_id, 10);
    if (!id || !socket.rooms.has(`group:${id}`)) return;
    socket.to(`group:${id}`).emit('user_typing', {
      user_id:   socket.user.user_id,
      group_id: id,
    });
  });

  socket.on('disconnect', (reason) => {
    console.log(`[WS] Disconnected: user_id=${user.user_id} reason=${reason}`);
  });

  socket.on('error', (err) => {
    console.error(`[WS] Socket error user:${user.user_id}:`, err.message);
  });
});

// ---- Handle broadcasts from PHP (via Redis pub/sub) ----
function handleBroadcast(event) {
  switch (event.type) {
    case 'notification':
      // Send to specific user
      io.to(`user:${event.user_id}`).emit('notification', event.data);
      break;

    case 'post_removed':
      io.emit('post_removed', event.data);
      break;

    case 'new_post':
      // Send to faculty room
      if (event.faculty_id) {
        io.to(`faculty:${event.faculty_id}`).emit('new_post', event.data);
      } else {
        io.emit('new_post', event.data);
      }
      break;

    case 'panic_alert':
      // Notify all moderators and admins
      io.to('moderators').to('admins').emit('panic_alert', event.data);
      io.to(`mod_faculty:${event.faculty_id}`).emit('panic_alert', event.data);
      break;

    case 'reports_update':
      io.to('moderators').to('admins').emit('reports_update', event.data);
      break;

    case 'system_alert':
      io.emit('system_alert', event.data);
      break;

    default:
      console.warn('[WS] Unknown broadcast type:', event.type);
  }
}

// ---- Start ----
setupRedis().catch(err => {
  console.warn('[Redis] Could not connect, running without Redis adapter:', err.message);
});

httpServer.listen(PORT, () => {
  console.log(`[UniLink WS] Server running on port ${PORT}`);
});
