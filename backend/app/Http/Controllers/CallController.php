<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\CallSignal;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CallController extends Controller
{
    /** offer/answer carry an SDP, candidate carries an ICE candidate, the rest carry nothing. */
    private const TYPES = ['offer', 'answer', 'candidate', 'reject', 'busy', 'hangup'];

    /**
     * Relay one signalling message to the other participant of a direct conversation.
     */
    public function signal(Request $request, Conversation $conversation): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'call_id' => ['required', 'uuid'],
            'type' => ['required', 'in:' . implode(',', self::TYPES)],
            'media' => ['required', 'in:audio,video'],
            'data' => ['nullable', 'array'],
            'data.sdp' => ['required_if:type,offer,answer', 'string', 'max:20000'],
            'data.type' => ['required_if:type,offer,answer', 'in:offer,answer'],
            'data.candidate' => ['nullable', 'string', 'max:2000'],
            'data.sdpMid' => ['nullable', 'string', 'max:64'],
            'data.sdpMLineIndex' => ['nullable', 'integer', 'min:0', 'max:64'],
            'data.usernameFragment' => ['nullable', 'string', 'max:256'],
        ]);

        $participantIds = $conversation->participants()->pluck('users.id');

        if (!$participantIds->contains($user->id)) {
            return response()->json(['success' => false, 'message' => 'Not a participant in this conversation.'], 403);
        }

        if ($conversation->is_group || $participantIds->count() !== 2) {
            return response()->json(['success' => false, 'message' => 'Calls are only available in direct conversations.'], 422);
        }

        // Only whitelisted keys are relayed: the payload goes straight to another user's browser.
        $data = isset($validated['data'])
            ? array_intersect_key($validated['data'], array_flip(['sdp', 'type', 'candidate', 'sdpMid', 'sdpMLineIndex', 'usernameFragment']))
            : null;

        broadcast(new CallSignal(
            toUserId: (int) $participantIds->first(fn ($id) => (int) $id !== (int) $user->id),
            conversationId: $conversation->id,
            callId: $validated['call_id'],
            type: $validated['type'],
            media: $validated['media'],
            from: ['id' => $user->id, 'name' => $user->name],
            data: $data,
        ));

        return response()->json(['success' => true]);
    }
}
