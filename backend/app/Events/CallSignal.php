<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * WebRTC signalling for 1:1 calls. The server only relays; media flows peer to peer.
 * Sent on the callee's own private channel so an incoming call rings even when that
 * conversation is not open.
 */
class CallSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly int $toUserId,
        public readonly int $conversationId,
        public readonly string $callId,
        public readonly string $type,
        public readonly string $media,
        public readonly array $from,
        public readonly ?array $data = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->toUserId}")];
    }

    public function broadcastAs(): string
    {
        return 'CallSignal';
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'conversation_id' => $this->conversationId,
            'type' => $this->type,
            'media' => $this->media,
            'from' => $this->from,
            'data' => $this->data,
        ];
    }
}
