# Patrones de diseño y código limpio — Nexus Messenger

## Backend (Laravel)

| Patrón | Dónde | Para qué |
|---|---|---|
| **Service Layer** | `backend/app/Services/ChatService.php`, `GroupService.php`, `AttachmentService.php`, `PresenceService.php` | La lógica de negocio vive en servicios; los controllers solo reciben, delegan y responden. |
| **Dependency Injection** | constructores de `ChatController`, `ConversationController`, `ChatService` | El contenedor de Laravel inyecta las dependencias (sin `new` manual en los controllers). |
| **Policy / Authorization** | `app/Policies/ConversationPolicy.php`, `MessagePolicy.php` (+ `Gate::authorize` en los servicios) | Reglas de "quién puede qué" centralizadas, no repartidas en `if`s. |
| **Form Request (validación)** | `app/Http/Requests/SendMessageRequest.php`, `CreateGroupRequest.php`, `UploadAttachmentRequest.php` | La validación de entrada sale del controller. |
| **Resource / Transformer (DTO de salida)** | `app/Http/Resources/MessageResource.php`, `ConversationResource.php`, … | Contrato único de la API; el mismo formato en REST y en WebSocket. |
| **Observer / Event Broadcasting** | `app/Events/MessageSent.php`, `UserTyping.php` | Tiempo real: el evento se publica en canales privados (Reverb) y los clientes se suscriben. |
| **Idempotency Key** | `request_id` en `ChatService::sendIdempotent`, `GroupService::createGroup` | Un reintento no duplica mensajes ni grupos. |
| **Unit of Work / Transacción** | `DB::transaction` + `DB::afterCommit` en `ChatService` | Los efectos secundarios (broadcast, jobs) ocurren solo si la transacción confirmó. |
| **Lock pesimista** | `ChatService::getPrivateConversation` (`pg_advisory_xact_lock`) | Evita que dos envíos simultáneos creen dos chats iguales. |
| **Cache-Aside** | `PresenceService` (`Cache::remember`) | Contador de no leídos y presencia sin golpear la BD. |
| **Queue / Background Job** | `app/Jobs/ProcessMessageAttachments.php` | Trabajo pesado fuera de la petición. |
| **Middleware (Chain of Responsibility)** | `app/Http/Middleware/UpdateUserPresence.php` | Actualiza presencia en cada petición autenticada. |
| **Domain Exception** | `app/Exceptions/BusinessException.php` | Errores de negocio con su propia respuesta HTTP. |

## Frontend (Angular 21)

| Patrón | Dónde | Para qué |
|---|---|---|
| **Singleton Service** | `core/services/*` (`providedIn: 'root'`) | Un único `AuthService`, `ChatService`, `WebSocketService`. |
| **Facade** | `core/services/websocket.service.ts` | Esconde Laravel Echo/Pusher detrás de `listenToConversation`, `listenToInbox`, etc. |
| **Interceptor (Chain of Responsibility)** | `core/interceptors/auth.interceptor.ts`, `error.interceptor.ts` | Añade el token y maneja errores HTTP en un solo lugar. |
| **Route Guard** | `core/guards/auth.guard.ts` (`authGuard`, `guestGuard`) | Protege rutas; devuelven `UrlTree` en vez de navegar a mano. |
| **Container / Presentational components** | `features/chat/chat.component.ts` (container) → `components/sidebar`, `chat-header`, `message-list`, `message-input` | El contenedor tiene estado; los hijos solo `@Input`/`@Output`. |
| **Observer (Signals + RxJS)** | `signal`, `computed`, `effect` en `ChatComponent` | Estado reactivo; la UI se actualiza sola. |
| **Adapter / Mapper** | `mapConversation`, `mapMessage` en `chat.component.ts` + `shared/models/UI*` | Convierte el modelo de la API al modelo de la vista. |
| **Lazy Loading** | `app.routes.ts` (`loadChildren`), `import('laravel-echo')` | Carga el código solo cuando se necesita. |

## Código limpio aplicado

- Controllers delgados; una responsabilidad por clase.
- Métodos duplicados eliminados (`sendMessageToUser` ahora delega en `sendMessageToConversation`).
- Sin promesas sin manejar, sin `Math.random` para ids, inputs accesibles (`label`/`aria-label`).
- `declare(strict_types=1)` y tipos de retorno en el backend.
- 26 tests (Pest): flujo de auth, grupos, chat, permisos, tiempo real, idempotencia.

## Resultado en SonarQube (http://localhost:9100/dashboard?id=nexus-messenger)

| Métrica | Primer análisis | Final |
|---|---|---|
| Fiabilidad | C (8 bugs) | **A** (0) |
| Seguridad | C (2 vulnerabilidades) | **A** (0) |
| Mantenibilidad | A (5 code smells) | **A** (0) |
| Duplicación | 1.6 % | **0 %** |
| Cobertura (backend) | 0 % | **92 %** |
| Quality Gate | — | **Passed** |

Limitación: el frontend aún no tiene reporte de cobertura (excluido de la métrica en `sonar-project.properties`).
