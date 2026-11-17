<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\MessageResource;
use App\Http\Resources\UserResource;
use App\Models\Message;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatController extends Controller
{
    public function __construct(protected ChatService $chatService) {}

    /**
     * Get list of users for chat
     */
    public function users(Request $request): JsonResource
    {
        $users = $this->chatService->getUsers(
            (int) auth()->id(),
            $this->perPage($request, 10),
            $request->input('search')
        );

        return UserResource::collection($users)->additional(['success' => true]);
    }

    /**
     * Get unread message count for authenticated user
     */
    public function unreadCount(): JsonResponse
    {
        $count = $this->chatService->getUnreadCount((int) auth()->id());

        return response()->json([
            'success' => true,
            'unread_count' => $count,
        ]);
    }

    /**
     * Toggle favorite
     */
    public function toggleFavorite(Request $request, Message $message): JsonResponse
    {
        $updatedMessage = $this->chatService->toggleFavorite($message->id, (int) auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'Favorite toggled',
            'data' => new MessageResource($updatedMessage),
        ]);
    }

    /**
     * Delete a message
     */
    public function deleteMessage(Message $message): JsonResponse
    {
        $this->chatService->deleteMessage($message->id, (int) auth()->id());

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully',
        ]);
    }
}
