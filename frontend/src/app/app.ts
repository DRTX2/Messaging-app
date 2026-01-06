import { Component, signal, computed, effect } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterOutlet } from '@angular/router';

interface User {
  id: string;
  name: string;
  avatar: string;
  status: 'online' | 'offline' | 'away';
  lastSeen?: string;
}

interface Message {
  id: string;
  senderId: string;
  text: string;
  timestamp: Date;
  isRead: boolean;
  type: 'text' | 'image' | 'file';
  attachmentUrl?: string;
}

interface Conversation {
  id: string;
  user: User;
  lastMessage: Message;
  unreadCount: number;
}

@Component({
  selector: 'app-root',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterOutlet],
  templateUrl: './app.html',
  styleUrl: './app.css'
})
export class App {
  currentUser: User = {
    id: 'me',
    name: 'David',
    avatar: 'https://i.pravatar.cc/150?u=me',
    status: 'online'
  };

  // Mock Conversations
  conversations = signal<Conversation[]>([
    {
      id: '1',
      user: { id: 'u1', name: 'Alice Freeman', avatar: 'https://i.pravatar.cc/150?u=1', status: 'online' },
      lastMessage: { id: 'm1', senderId: 'u1', text: 'Hey! Are we still on for the meeting?', timestamp: new Date(Date.now() - 1000 * 60 * 5), isRead: false, type: 'text' },
      unreadCount: 2
    },
    {
      id: '2',
      user: { id: 'u2', name: 'Bob Smith', avatar: 'https://i.pravatar.cc/150?u=2', status: 'offline', lastSeen: '2h ago' },
      lastMessage: { id: 'm2', senderId: 'me', text: 'I sent you the files.', timestamp: new Date(Date.now() - 1000 * 60 * 60), isRead: true, type: 'text' },
      unreadCount: 0
    },
    {
      id: '3',
      user: { id: 'u3', name: 'Charlie Kim', avatar: 'https://i.pravatar.cc/150?u=3', status: 'away' },
      lastMessage: { id: 'm3', senderId: 'u3', text: 'Thanks for the update!', timestamp: new Date(Date.now() - 1000 * 60 * 60 * 24), isRead: true, type: 'text' },
      unreadCount: 0
    },
     {
      id: '4',
      user: { id: 'u4', name: 'Diana Prince', avatar: 'https://i.pravatar.cc/150?u=4', status: 'online' },
      lastMessage: { id: 'm4', senderId: 'me', text: 'Can you check the latest designs?', timestamp: new Date(Date.now() - 1000 * 60 * 60 * 48), isRead: true, type: 'text' },
      unreadCount: 0
    }
  ]);

  selectedConversationId = signal<string | null>('1');

  // Mock Messages for the selected conversation
  // In a real app, this would fetch from backend based on selectedConversationId
  currentMessages = signal<Message[]>([
    { id: 'msg1', senderId: 'u1', text: 'Hi David, how is it going?', timestamp: new Date(Date.now() - 1000 * 60 * 60), isRead: true, type: 'text' },
    { id: 'msg2', senderId: 'me', text: 'Pretty good! Working on the new frontend.', timestamp: new Date(Date.now() - 1000 * 60 * 55), isRead: true, type: 'text' },
    { id: 'msg3', senderId: 'u1', text: 'That sounds exciting! I wanted to ask about the timeline.', timestamp: new Date(Date.now() - 1000 * 60 * 10), isRead: true, type: 'text' },
    { id: 'msg4', senderId: 'u1', text: 'Hey! Are we still on for the meeting?', timestamp: new Date(Date.now() - 1000 * 60 * 5), isRead: false, type: 'text' }
  ]);

  messageInput = signal('');

  selectedConversation = computed(() => 
    this.conversations().find(c => c.id === this.selectedConversationId())
  );

  constructor() {}

  selectConversation(id: string) {
    this.selectedConversationId.set(id);
    // Here we would fetch messages for this conversation
    // For demo, we just shuffle or keep the same mock messages
    // but simplified not to overengineer the mock.
  }

  sendMessage() {
    const text = this.messageInput();
    if (!text.trim()) return;

    const newMessage: Message = {
      id: Date.now().toString(),
      senderId: 'me',
      text: text,
      timestamp: new Date(),
      isRead: false,
      type: 'text'
    };

    this.currentMessages.update(msgs => [...msgs, newMessage]);
    this.messageInput.set('');
    
    // Simulate reply
    setTimeout(() => {
        const reply: Message = {
            id: (Date.now() + 1).toString(),
            senderId: this.selectedConversation()?.user.id || 'unknown',
            text: 'That is great! I will take a look.',
            timestamp: new Date(),
            isRead: false,
            type: 'text'
        };
        this.currentMessages.update(msgs => [...msgs, reply]);
    }, 2000);
  }

  closeChat() {
    this.selectedConversationId.set(null);
  }
}
