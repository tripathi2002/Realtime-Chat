// const socket = io('http://localhost:8000') 
const socket = io('https://vibhu-dev.xyz')
// const socket = io('https://vibhu-dev.xyz/app4') 
// const socket = io('https://vibhu-dev.xyz/app4') 
// const socket = io('https://vibhu-dev.xyz', {
//   transports: ['websocket'],  // Force WebSocket transport (this avoids polling)
// });

const form = document.getElementById('send-container'); 
const messageInput = document.getElementById('messageInp'); 
const messageContainer = document.querySelector(".container"); 

const append = (message, position)=>{
   const messageElement = document.createElement('div');
   messageElement.innerText = message;
   messageElement.classList.add('message');
   messageElement.classList.add(position);
   messageContainer.append(messageElement);
}

form.addEventListener('submit', (e)=>{
   e.preventDefault();
   const message = messageInput.value;
   append(`you: ${message}`, 'right');
   socket.emit('send', message);
   messageInput.value = '';
})

const name = prompt("Enter your name :"); 
socket.emit('new-user-joined', name); 

socket.on('user-joined', data =>{
   append(`${name} joine the chat`, 'right');
})

socket.on('receive', data =>{
   append(`${data.name}: ${data.message}`, 'left');
})

socket.on('leave', name =>{
   append(`${name}: left the chat`, 'left');
})
