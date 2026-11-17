<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    public function message(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    /** DMs: any participant. Groups: admins only. */
    public function update(User $user, Conversation $conversation): bool
    {
        return $conversation->is_group
            ? $this->isAdmin($user, $conversation)
            : $this->isParticipant($user, $conversation);
    }

    public function clear(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    public function addParticipants(User $user, Conversation $conversation): bool
    {
        return $conversation->is_group && $this->isAdmin($user, $conversation);
    }

    public function removeParticipant(User $user, Conversation $conversation): bool
    {
        return $this->addParticipants($user, $conversation);
    }

    public function leave(User $user, Conversation $conversation): bool
    {
        return $this->isParticipant($user, $conversation);
    }

    private function isParticipant(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('user_id', $user->id)->exists();
    }

    private function isAdmin(User $user, Conversation $conversation): bool
    {
        return $conversation->participants()->where('user_id', $user->id)->where('is_admin', true)->exists();
    }
}
