// Import required modules
const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const { config } = require('dotenv');
const morgan = require('morgan');

config();

// Initialize express app and HTTP server
const app = express();
const server = http.createServer(app);

const PORT = process.env.PORT || 8000;

app.use(morgan('dev'));

const io = socketIo(server, {
  cors: {
    origin: '*',
    methods: ['GET', 'POST'],
  },
});

app.get('/health', (req, res) => {
  res.json({ status: 'ok' });
});

// Initialize an empty users object to store users and their socket IDs
const users = {};

// Handle socket.io connections
io.on('connection', socket => {
  console.log('New connection: ', socket.id);

  socket.on('new-user-joined', name => {
    users[socket.id] = name;
    socket.broadcast.emit('user-joined', name);
  });

  socket.on('send', message => {
    console.log('sending message --->');
    socket.broadcast.emit('receive', { message: message, name: users[socket.id] });
  });

  socket.on('disconnect', () => {
    socket.broadcast.emit('leave', users[socket.id]);
    delete users[socket.id];
  });
});

server.listen(PORT, () => {
  console.log('Server running on PORT: ', PORT);
});
