# Nexus Messenger - Enterprise Edition

![Nexus Banner](docs/imgs/Screenshot_20260106_001740.png)

> **A next-generation real-time messaging platform engineered for performance, scalability, and seamless user experience.**

Nexus Messenger is a full-stack enterprise communication solution that bridges the gap between modern design and robust backend architecture. Built with the bleeding-edge **Angular 21** and **Laravel 12**, it delivers sub-millisecond real-time interactions via native WebSockets.

---

## 🛠️ Tech Stack & Tools

We utilize a cutting-edge technology stack to ensure scalability, security, and developer productivity.

### **Frontend (Client)**
*   **Framework**: [Angular 21](https://angular.io/) - Utilizing the latest Signals-based reactive architecture.
*   **Styling**: [Tailwind CSS v4](https://tailwindcss.com/) - High-performance utility-first CSS utilizing the new engine.
*   **Real-time Client**: `laravel-echo` + `pusher-js` - For seamless WebSocket event listening.
*   **State Management**: RxJS + Angular Signals.
*   **Testing**: [Vitest](https://vitest.dev/) - Blazing fast unit testing.

### **Backend (API)**
*   **Core Framework**: [Laravel 12](https://laravel.com/) - The PHP framework for web artisans.
*   **Real-time Server**: [Laravel Reverb](https://laravel.com/docs/reverb) - First-party WebSocket server for extreme operational scale.
*   **Authentication**: [JWT Auth](https://github.com/PHP-Open-Source-Saver/jwt-auth) - Stateless, secure token-based authentication.
*   **Testing**: [Pest PHP](https://pestphp.com/) - Elegant testing framework.
*   **Code Quality**: Laravel Pint (Style) & Laravel Pail (Logs).

### **Infrastructure & DevOps**
*   **Containerization**: Docker & Docker Compose.
*   **Web Server**: Nginx (Production-ready configuration).
*   **Database**: PostgreSQL - Reliable, ACID-compliant relational data storage.
*   **Asset Bundling**: Vite - Next-generation frontend tooling.

---

## ✨ Key Features

Nexus isn't just a chat app; it's a complete communication suite.

*   **⚡ Instant Messaging**: Native WebSocket integration creates a "live" feel with zero latency.
*   **📞 Voice & Video Calls**: 1:1 calls over WebRTC. The API only relays signalling (offer/answer/ICE) through the callee's private Reverb channel, so a call rings from any screen and media flows peer to peer. Accept, decline, mute, camera off, busy and missed-call handling.
*   **👥 Group Implementation**: Robust many-to-many relationship handling for scalable group chats.
*   **📁 Enterprise File Sharing**: Drag-and-drop uploads for images and documents with glassmorphic previews.
*   **🔐 Military-Grade Auth**: Full JWT implementation with intercepted HTTP requests and auto-refresh mechanisms.
*   **🧠 Smart UI/UX**:
    *   **Auto-Scroll**: Intelligent viewport management keeps you in the conversation.
    *   **Micro-Interactions**: Smooth transitions and hover effects using vanilla CSS & Tailwind.
    *   **Dark Mode**: A deeply integrated, eye-friendly dark theme.

---

## 🏗️ Architecture Overview

The system follows a **Service-Oriented Architecture (SOA)** approach:

1.  **Client Layer**: Angular SPA serving as the presentation layer, consuming REST APIs and listening to WebSocket channels.
2.  **API Layer**: Laravel REST API handling business logic, validation, and persistence.
3.  **Real-time Layer**: Laravel Reverb server broadcasting events (Messages, Presence, Typing) directly to clients.
4.  **Data Layer**: PostgreSQL storing relational data (Users, Conversations, Messages, Attachments).

---

## 🚀 Getting Started

### Prerequisites
*   **Docker Desktop** (Recommended)
*   *Or for manual runs:* PHP 8.2+, Node.js 20+, PostgreSQL 16+

### 🐳 Quick Start (Docker)

Get the entire stack running in under 2 minutes.

```bash
# 1. Clone the repository
git clone https://github.com/yourusername/messenger.git
cd messenger

# 2. Start services (Background mode)
docker-compose up -d --build

# 3. Initialize Backend (First time only)
docker-compose exec backend composer install
docker-compose exec backend php artisan migrate --seed
docker-compose exec backend php artisan jwt:secret
```

Access the application:
*   **Frontend**: `http://localhost:4200`
*   **Backend API**: `http://localhost:8000`
*   **Reverb Console**: `http://localhost:8080`

### 🔧 Manual Development Setup

If you prefer running services locally on metal:

**Backend:**
```bash
cd backend
composer install
cp .env.example .env
# Update .env with your local DB credentials
php artisan key:generate
php artisan migrate
php artisan serve:all # Runs API + Reverb + Queue
```

**Frontend:**
```bash
cd frontend
npm install
npm start # Runs on port 4200
```

---

## 📸 Visual Tour

| **Zero State** | **Active Conversation** |
|:---:|:---:|
| ![Empty State](docs/imgs/Screenshot_20260106_001740.png) | ![Mobile View](docs/imgs/Screenshot_20260106_001800.png) |
| *Clean, distraction-free startup* | *Responsive mobile layout with attachments* |

| **Incoming call** | **Video call** |
|:---:|:---:|
| ![Incoming call](docs/imgs/call-incoming.webp) | ![Video call](docs/imgs/call-active.webp) |
| *Rings even when that chat isn't open* | *Peer-to-peer media, signalling over Reverb* |

### Calls: how it works and its limits
- `POST /api/conversations/{id}/calls/signal` validates the payload, checks the caller is a participant of a direct conversation, and broadcasts `CallSignal` on `private-App.Models.User.{callee}`. Only whitelisted SDP/ICE fields are relayed.
- Covered by `backend/tests/Feature/CallSignalingTest.php` (relay, authorization, groups rejected, validation) and verified end to end with two Chrome instances using fake cameras.
- Uses a public STUN server only. Peers behind symmetric NAT need a TURN server: add it as `iceServers` in `frontend/src/environments`. Group calls would need an SFU and are not implemented.

---

## 📜 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
