<?php

declare(strict_types=1);

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @var array
     */
    public $message;

    /** @var array<int, int> */
    private array $participantIds;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message)
    {
        // We broadcast the resource to ensure the frontend receives the exact same structure as the API
        $this->message = (new MessageResource($message))->resolve();
        $this->participantIds = $message->conversation->participants()->pluck('users.id')->all();
    }

    /**
     * Conversation channel (open chat) + every participant's user channel (inbox refresh).
     */
    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel('conversation.'.$this->message['conversation_id'])];

        foreach ($this->participantIds as $userId) {
            $channels[] = new PrivateChannel('App.Models.User.'.$userId);
        }

        return $channels;
    }
}
