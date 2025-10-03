// Import required modules
const express = require('express');
const http = require('http');
const socketIo = require('socket.io');
const { config } = require('dotenv')
const morgan = require('morgan'); // Import morgan

// Initialize express app and HTTP server
const app = express();
const server = http.createServer(app);

const PORT = process.env.PORT || 8000; 

// Use morgan middleware
// 'dev' is a predefined format string that provides concise output
app.use(morgan('dev'));

// Initialize socket.io with the HTTP server
// const io = socketIo(server);
const io = socketIo(server, {
//   path: '/app4/', // Custom path if necessary
  cors: {
    origin: '*',
    methods: ['GET', 'POST'],
  },
});

// Serve static files (if you have any assets like JS, CSS, images)
app.use('/app4/public', express.static('public'));
app.use(express.static('frontend'));

// const io = socketIo(8000)
// Route to serve your HTML file
app.get('/', (req, res) => {
   console.log("== ")

   res.sendFile(__dirname + '/frontend/index.html'); // Make sure to replace with your actual HTML file
});

app.get('/app4', (req, res) => {
   console.log("/app4 == ")
    res.sendFile(__dirname + '/frontend/index.html'); // Make sure to replace with your actual HTML file
});
app.get('/app4/', (req, res) => {
   console.log("/app4/ == ")
    res.sendFile(__dirname + '/frontend/index.html'); // Make sure to replace with your actual HTML file
});


// Initialize an empty users object to store users and their socket IDs
const users = {};

// Handle socket.io connections
io.on('connection', socket =>{
   console.log('New connection: ', socket.id);

    // Handle new user joining
   socket.on('new-user-joined', name =>{
      // console.log("New User  joined", name)
      users[socket.id] = name;
      socket.broadcast.emit('user-joined', name);
   });

   // Handle sending and receiving messages
   socket.on('send', message =>{
      console.log("sending message --->")
      socket.broadcast.emit('receive', {message: message, name: users[socket.id]})
   });

   // Handle user disconnecting
   socket.on('disconnect', message =>{
      socket.broadcast.emit('leave', users[socket.id])
      delete users[socket.id];
   });
});

// app.get('*', (req, res) => {
//     res.sendFile(__dirname + '/frontend/index.html'); // Make sure to replace with your actual HTML file
// });


// Start the server on a specific port (e.g., 8000)
server.listen(PORT, () => {
    console.log('Server running on PORT: ', PORT);
});