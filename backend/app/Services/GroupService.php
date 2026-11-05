<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Conversation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class GroupService
{
    /**
     * Create a new group conversation.
     */
    public function createGroup(
        int $creatorId,
        string $name,
        array $participantIds,
        ?string $avatarUrl = null,
        ?string $requestId = null
    ): Conversation {
        if (! $requestId) {
            return $this->persistGroup($creatorId, $name, $participantIds, $avatarUrl);
        }

        $key = "group_request:{$creatorId}:{$requestId}";

        return Cache::lock("{$key}:lock", 10)->block(5, function () use ($key, $creatorId, $name, $participantIds, $avatarUrl) {
            if ($existingId = Cache::get($key)) {
                return Conversation::where('created_by', $creatorId)->findOrFail($existingId);
            }

            $group = $this->persistGroup($creatorId, $name, $participantIds, $avatarUrl);
            Cache::put($key, $group->id, now()->addHours(24));

            return $group;
        });
    }

    private function persistGroup(int $creatorId, string $name, array $participantIds, ?string $avatarUrl): Conversation
    {
        $others = array_values(array_unique(array_diff(array_map('intval', $participantIds), [$creatorId])));

        return DB::transaction(function () use ($creatorId, $name, $others, $avatarUrl) {
            $conversation = Conversation::create([
                'uuid' => Str::uuid()->toString(),
                'is_group' => true,
                'name' => $name,
                'avatar_url' => $avatarUrl,
                'created_by' => $creatorId,
                'last_message_at' => now(),
            ]);

            $conversation->participants()->attach($creatorId, ['is_admin' => true, 'formatted_last_read_at' => now()]);
            foreach ($others as $id) {
                $conversation->participants()->attach($id, ['is_admin' => false, 'formatted_last_read_at' => now()]);
            }

            return $conversation;
        });
    }

    /**
     * Add participants to an existing group.
     */
    public function addParticipants(
        Conversation $conversation,
        int $requesterId,
        array $userIds
    ): void {
        Gate::authorize('addParticipants', $conversation);
        $this->ensureIsGroup($conversation);

        $existingIds = $conversation->participants()->pluck('users.id')->toArray();

        foreach ($userIds as $userId) {
            if (! in_array($userId, $existingIds)) {
                $conversation->participants()->attach($userId, [
                    'is_admin' => false,
                    'formatted_last_read_at' => now(),
                ]);
            }
        }
    }

    /**
     * Remove a participant from a group.
     */
    public function removeParticipant(
        Conversation $conversation,
        int $requesterId,
        int $userIdToRemove
    ): void {
        Gate::authorize('removeParticipant', $conversation);
        $this->ensureIsGroup($conversation);

        if ($requesterId === $userIdToRemove) {
            throw new BusinessException('Use the leave endpoint to remove yourself.', 400);
        }

        $conversation->participants()->detach($userIdToRemove);
    }

    /**
     * Leave a group conversation.
     */
    public function leaveGroup(Conversation $conversation, int $userId): void
    {
        Gate::authorize('leave', $conversation);
        $this->ensureIsGroup($conversation);

        $wasAdmin = $conversation->participants()->where('user_id', $userId)->where('is_admin', true)->exists();
        $conversation->participants()->detach($userId);

        $remaining = $conversation->participants();
        if ($remaining->count() === 0) {
            $conversation->delete();
        } elseif ($wasAdmin && ! $remaining->where('is_admin', true)->exists()) {
            // Never leave a group without an admin: promote the longest-standing member
            $next = $conversation->participants()->orderBy('conversation_participants.id')->first();
            $conversation->participants()->updateExistingPivot($next->id, ['is_admin' => true]);
        }
    }

    /**
     * Update group details.
     */
    public function updateGroup(
        Conversation $conversation,
        int $requesterId,
        ?string $name = null,
        ?string $avatarUrl = null
    ): Conversation {
        Gate::authorize('update', $conversation);
        $this->ensureIsGroup($conversation);

        if ($name !== null) {
            $conversation->name = $name;
        }

        if ($avatarUrl !== null) {
            $conversation->avatar_url = $avatarUrl;
        }

        $conversation->save();

        return $conversation;
    }

    // --- Helpers ---

    private function ensureIsGroup(Conversation $conversation): void
    {
        if (! $conversation->is_group) {
            throw new BusinessException('This operation is only allowed for group conversations.', 400);
        }
    }
}
