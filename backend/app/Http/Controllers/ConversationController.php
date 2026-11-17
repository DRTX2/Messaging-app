<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SendMessageRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationController extends Controller
{
    public function __construct(protected ChatService $chatService) {}

    /**
     * Get list of my conversations (Inbox)
     */
    public function index(Request $request): JsonResource
    {
        $conversations = $this->chatService->getConversations((int) auth()->id(), $this->perPage($request));

        return ConversationResource::collection($conversations);
    }

    /**
     * Get or create the private conversation with a user.
     */
    public function direct(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        $conversation = $this->chatService
            ->getPrivateConversation((int) auth()->id(), (int) $data['user_id'])
            ->load(['latestMessage', 'participants']);

        return response()->json(['success' => true, 'data' => new ConversationResource($conversation)]);
    }

    /**
     * Messages of a conversation (works for DMs and groups).
     */
    public function messages(Request $request, Conversation $conversation): JsonResource
    {
        $userId = (int) auth()->id();
        $messages = $this->chatService->getConversationMessages($userId, $conversation, $this->perPage($request));
        $this->chatService->markAsRead($userId, $conversation->id);

        return MessageResource::collection($messages)->additional(['success' => true]);
    }

    /**
     * Send a message to a conversation (works for DMs and groups).
     */
    public function send(SendMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $this->chatService->sendIdempotent(
            (int) auth()->id(),
            $conversation,
            (string) $request->validated('message', ''),
            $request->validated('attachment_ids', []),
            (string) $request->validated('request_id')
        );

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully',
            'data' => new MessageResource($message),
        ], 201);
    }

    public function clear(Conversation $conversation): JsonResponse
    {
        $this->chatService->clearConversation((int) auth()->id(), $conversation);

        return response()->json(['success' => true, 'message' => 'Conversation cleared']);
    }
}
