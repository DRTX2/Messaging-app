# Nexus Messenger Project

An advanced **enterprise-grade real-time messaging** application built with Angular 18 and Laravel 12.

![Dashboard](frontend/public/assets/nexus-logo.png)

## Description

Nexus is a premium full-stack messaging platform that enables seamless real-time communication. It features a modern, responsive UI with glassmorphism effects, secure authentication, and a robust backend powered by Laravel Reverb for instant interactions.

## Features

-   **Real-time Communication**: Powered by **Laravel Reverb (WebSockets)** for instant message delivery.
-   **Enterprise Groups**: Create and manage group conversations with multiple participants.
-   **Rich Media**: Support for image previews and file attachments with professional UI.
-   **Authentication**: Secure Login and Registration using JWT (JSON Web Tokens).
-   **Advanced UI/UX**:
    -   Smooth auto-scroll to latest messages.
    -   Custom minimalist scrollbars.
    -   Typing indicators ("User is typing...").
    -   Online/Offline presence status.
-   **Message Management**:
    -   Favorite messages (starred).
    -   Delete specific messages.
    -   Clear entire conversations.
-   **Responsive Design**: Sleek dark mode interface using Tailwind CSS v4.

## Architecture

### Frontend
-   **Framework**: Angular 18+ (Signals for reactive state management).
-   **Styling**: Tailwind CSS v4 + Vanilla CSS for custom animations.
-   **Real-time**: Laravel Echo + Pusher (via Reverb).
-   **Structure**: Features-based modular architecture.

### Backend
-   **Framework**: Laravel 12.
-   **Real-time Server**: Laravel Reverb.
-   **Database**: PostgreSQL.
-   **Authentication**: JWT Auth.

## Installation

### Prerequisites
-   Docker & Docker Compose
-   Or manually: PHP 8.2+, Node.js 20+, PostgreSQL.

### 🚀 Running the Development stack

The project includes a custom Artisan command to start both the API and WebSocket servers simultaneously.

#### Backend
1.  Navigate to `backend/`.
2.  Install dependencies: `composer install`.
3.  Configure `.env` (copy from `.env.example`).
4.  Run migrations: `php artisan migrate`.
5.  **Start everything:**
    ```bash
    php artisan serve:all
    ```
    *This starts the API on port 8000 and Reverb on port 8080.*

#### Frontend
1.  Navigate to `frontend/`.
2.  Install: `pnpm install` or `npm install`.
3.  Start: `pnpm start` or `npm start`.

---

### Using Docker (Production-ready)

1.  **Start containers:**
    ```bash
    docker-compose up -d --build
    ```
2.  **Initialize database:**
    ```bash
    docker-compose exec backend php artisan migrate
    docker-compose exec backend php artisan jwt:secret
    ```

## 🛠 Development Commands

-   **Start API + WebSockets**: `php artisan serve:all`
-   **Frontend Dev**: `npm run start`
-   **Build Production**: `npm run build`
