<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\MessageSent;
use App\Exceptions\BusinessException;
use App\Jobs\ProcessMessageAttachments;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class ChatService
{
    public function __construct(
        private readonly PresenceService $presenceService,
        private readonly AttachmentService $attachmentService
    ) {}

    /**
     * Get user's inbox (list of conversations)
     * This mimics WhatsApp/Messenger home screen
     */
    public function getConversations(int $userId, int $perPage = 20): LengthAwarePaginator
    {
        return Conversation::query()
            ->whereHas('participants', function (Builder $query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->with(['latestMessage', 'participants'])
            ->orderByDesc('last_message_at')
            ->paginate($perPage);
    }

    /**
     * Get or Create a private conversation between two users.
     */
    public function getPrivateConversation(int $authUserId, int $otherUserId): Conversation
    {
        if ($authUserId === $otherUserId) {
            throw new BusinessException('Cannot chat with yourself', 400);
        }

        // Serialize concurrent first-messages of the same pair (prevents duplicate chats)
        return DB::transaction(function () use ($authUserId, $otherUserId) {
            $pairKey = min($authUserId, $otherUserId).':'.max($authUserId, $otherUserId);
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["dm:{$pairKey}"]);

            $existing = $this->findPrivateConversation($authUserId, $otherUserId);
            if ($existing) {
                return $existing;
            }

            $conversation = Conversation::create([
                'uuid' => (string) Str::uuid(),
                'is_group' => false,
                'created_by' => $authUserId,
                'last_message_at' => now(),
            ]);

            // Add both users
            $conversation->participants()->attach([
                $authUserId => ['formatted_last_read_at' => now()],
                $otherUserId => ['formatted_last_read_at' => null], // Hasn't seen it yet
            ]);

            return $conversation;
        });
    }

    private function findPrivateConversation(int $userA, int $userB): ?Conversation
    {
        return Conversation::where('is_group', false)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userA))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userB))
            ->first();
    }

    /**
     * Get messages for a specific conversation (Thread View)
     */
    public function getConversationMessages(int $userId, Conversation $conversation, int $perPage = 50): LengthAwarePaginator
    {
        // Security check via Policy
        Gate::authorize('view', $conversation);

        $clearedAt = $conversation->participants()->where('users.id', $userId)->first()?->pivot?->cleared_at;

        return $conversation->messages()
            ->when($clearedAt, fn ($q) => $q->where('created_at', '>', $clearedAt))
            ->with(['sender:id,name,email', 'attachments', 'parent'])
            ->orderByDesc('created_at') // Latest first for chat UI (usually reversed in frontend)
            ->paginate($perPage);
    }

    /**
     * Send a message to a specific conversation (Unified method)
     */
    public function sendMessageToConversation(int $senderId, Conversation $conversation, string $content, array $attachmentIds = []): Message
    {
        Gate::authorize('message', $conversation);

        return DB::transaction(function () use ($conversation, $senderId, $content, $attachmentIds) {
            $message = $conversation->messages()->create([
                'sender_id' => $senderId,
                'content' => $content,
                'type' => empty($attachmentIds) ? 'text' : 'file',
            ]);

            if (! empty($attachmentIds)) {
                $this->attachmentService->linkAttachmentsToMessage($message->id, $senderId, $attachmentIds);
            }

            $conversation->update(['last_message_at' => now()]);
            $message->load(['sender', 'conversation', 'attachments']);

            DB::afterCommit(function () use ($message, $attachmentIds, $senderId, $conversation) {
                MessageSent::dispatch($message);

                // Increment unread cache for all except sender
                $conversation->participants()
                    ->where('user_id', '!=', $senderId)
                    ->pluck('user_id')
                    ->each(fn ($id) => $this->presenceService->incrementUnreadCount((int) $id));

                // Background processing
                if (! empty($attachmentIds)) {
                    ProcessMessageAttachments::dispatch($message);
                }
            });

            return $message;
        });
    }

    /**
     * Mark conversation as read for a user
     */
    public function markAsRead(int $userId, int $conversationId): void
    {
        DB::table('conversation_participants')
            ->where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->update(['formatted_last_read_at' => now()]);

        // Invalidate cache
        $this->presenceService->clearUnreadCache($userId);
    }

    /**
     * Idempotent send: a retried request_id returns the original message.
     * The key is scoped to sender + conversation and guarded by an atomic lock,
     * so concurrent retries cannot double-send nor leak another user's message.
     */
    public function sendIdempotent(int $senderId, Conversation $conversation, string $content, array $attachmentIds, ?string $requestId): Message
    {
        if (! $requestId) {
            return $this->sendMessageToConversation($senderId, $conversation, $content, $attachmentIds);
        }

        $key = "msg_request:{$senderId}:{$conversation->id}:{$requestId}";

        return Cache::lock("{$key}:lock", 10)->block(5, function () use ($key, $senderId, $conversation, $content, $attachmentIds) {
            $existingId = Cache::get($key);
            if ($existingId) {
                return Message::where('sender_id', $senderId)
                    ->where('conversation_id', $conversation->id)
                    ->findOrFail($existingId);
            }

            $message = $this->sendMessageToConversation($senderId, $conversation, $content, $attachmentIds);
            Cache::put($key, $message->id, now()->addHours(24));

            return $message;
        });
    }

    public function getUsers(int $userId, int $perPage = 10, ?string $search = null): LengthAwarePaginator
    {
        return User::where('id', '!=', $userId)
            ->when($search, function ($query, $search) {
                return $query->where(function ($q) use ($search) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function getUnreadCount(int $userId): int
    {
        return $this->presenceService->getUnreadCount($userId, function () use ($userId) {
            // Count messages in all user conversations that were sent after user's last_read_at
            return Message::query()
                ->where('sender_id', '!=', $userId)
                ->whereIn('conversation_id', function ($query) use ($userId) {
                    $query->select('conversation_id')
                        ->from('conversation_participants')
                        ->where('user_id', $userId);
                })
                ->whereExists(function ($query) use ($userId) {
                    $query->select(DB::raw(1))
                        ->from('conversation_participants')
                        ->whereColumn('conversation_participants.conversation_id', 'messages.conversation_id')
                        ->where('conversation_participants.user_id', $userId)
                        ->where(function ($q) {
                            $q->whereNull('formatted_last_read_at')
                                ->orWhereColumn('messages.created_at', '>', 'formatted_last_read_at');
                        });
                })
                ->count();
        });
    }

    public function deleteMessage(int $messageId, int $userId): bool
    {
        $message = Message::findOrFail($messageId);

        Gate::authorize('delete', $message);

        return (bool) $message->delete();
    }

    /**
     * Hide the conversation history for this user only (the other side keeps theirs).
     */
    public function clearConversation(int $userId, Conversation $conversation): void
    {
        Gate::authorize('clear', $conversation);

        $conversation->participants()->updateExistingPivot($userId, ['cleared_at' => now()]);
        $this->presenceService->clearUnreadCache($userId);
    }

    public function toggleFavorite(int $messageId, int $userId): Message
    {
        $message = Message::findOrFail($messageId);

        // Authorization via MessagePolicy
        Gate::authorize('favorite', $message);

        $message->is_favorite = ! $message->is_favorite;
        $message->save();

        return $message;
    }
}
