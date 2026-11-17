<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Message;
use App\Models\User;

class MessagePolicy
{
    public function update(User $user, Message $message): bool
    {
        return $user->id === $message->sender_id;
    }

    /** Authors can delete their own messages; group admins can delete any message in the group. */
    public function delete(User $user, Message $message): bool
    {
        if ($user->id === $message->sender_id) {
            return true;
        }

        return (bool) $message->conversation?->is_group && $this->participant($user, $message, adminOnly: true);
    }

    /** Favorite is a flag on the message itself, so any participant may toggle it. */
    public function favorite(User $user, Message $message): bool
    {
        return $this->participant($user, $message);
    }

    private function participant(User $user, Message $message, bool $adminOnly = false): bool
    {
        $query = $message->conversation->participants()->where('user_id', $user->id);

        return $adminOnly ? $query->where('is_admin', true)->exists() : $query->exists();
    }
}
