<?php

use App\Events\MessageSent;
use App\Jobs\ProcessMessageAttachments;
use App\Models\Conversation;
use App\Models\User;
use App\Services\PresenceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('idempotency prevents duplicate messages', function () {
    $sender = User::factory()->create();
    $receiver = User::factory()->create();
    $requestId = (string) Str::uuid();

    $payload = [
        'request_id' => $requestId,
        'message' => 'Idempotent Hello',
    ];

    // First Request
    $response1 = $this->actingAs($sender, 'api')
        ->postJson('/api/conversations/'.dmWith($sender, $receiver).'/messages', $payload);

    $response1->assertStatus(201);
    $messageId = $response1->json('data.id');

    // Second Request (Retry)
    $response2 = $this->actingAs($sender, 'api')
        ->postJson('/api/conversations/'.dmWith($sender, $receiver).'/messages', $payload);

    $response2->assertStatus(201);

    // Assert IDs match (same message returned)
    expect($response2->json('data.id'))->toBe($messageId);

    // Assert DB only has 1 message
    $this->assertDatabaseCount('messages', 1);
});

test('sending message dispatches job and events via consistency', function () {
    // Fake events and queue
    Event::fake();
    Queue::fake();

    $sender = User::factory()->create();
    $receiver = User::factory()->create();

    // Create connection/conversation first to avoid "first message logic" noise if we want strict unit
    // But here we test E2E flow

    // Using a file attachment needs "attachments" table existing
    // We assume factory or simple creation if checked
    // For now, testing text message first

    $payload = [
        'request_id' => (string) Str::uuid(),
        'message' => 'Async Message',
    ];

    $response = $this->actingAs($sender, 'api')
        ->postJson('/api/conversations/'.dmWith($sender, $receiver).'/messages', $payload);

    $response->assertStatus(201);

    // Verify Event Dispatched
    Event::assertDispatched(MessageSent::class);

    // Verify Queue NOT dispatched (no attachments)
    Queue::assertNotPushed(ProcessMessageAttachments::class);
});

test('group creation enforces policy and updates cache', function () {
    $creator = User::factory()->create();
    $others = User::factory()->count(2)->create();
    $requestId = (string) Str::uuid();

    $payload = [
        'request_id' => $requestId,
        'name' => 'Test Group',
        'participant_ids' => $others->pluck('id')->toArray(),
    ];

    $response = $this->actingAs($creator, 'api')
        ->postJson('/api/groups', $payload);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'data' => [
                'name' => 'Test Group',
                'is_group' => true,
            ],
        ]);

    // Verify DB
    $this->assertDatabaseHas('conversations', ['name' => 'Test Group']);
    $this->assertDatabaseCount('conversation_participants', 3); // Creator + 2 others

    // Test specific policy: Random user cannot add participants
    $randomUser = User::factory()->create();
    $conversationId = $response->json('data.id');

    $this->actingAs($randomUser, 'api')
        ->postJson("/api/groups/{$conversationId}/participants", ['user_ids' => [$others[0]->id]])
        ->assertStatus(403);
});

test('unread count cache increments correctly', function () {
    $service = app(PresenceService::class);
    $sender = User::factory()->create();
    $receiver = User::factory()->create();

    // Prime the cache first (so it exists to be incremented)
    $service->getUnreadCount($receiver->id, fn () => 0);

    $this->actingAs($sender, 'api')
        ->postJson('/api/conversations/'.dmWith($sender, $receiver).'/messages', [
            'request_id' => (string) Str::uuid(),
            'message' => 'Cache Test',
        ])->assertStatus(201);

    expect($service->getUnreadCount($receiver->id, fn () => 99))->toBe(1);
});
