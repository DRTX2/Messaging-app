<?php

use App\Models\User;
use Illuminate\Support\Str;

test('authenticated user can send a message', function () {
    [$sender, $receiver] = User::factory()->count(2)->create();

    $this->actingAs($sender, 'api')
        ->postJson('/api/conversations/'.dmWith($sender, $receiver).'/messages', [
            'message' => 'Hello World',
            'request_id' => (string) Str::uuid(),
        ])
        ->assertStatus(201)
        ->assertJson(['success' => true, 'message' => 'Message sent successfully']);

    $this->assertDatabaseHas('messages', ['sender_id' => $sender->id, 'content' => 'Hello World']);
});

test('user cannot open a chat with themselves', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')
        ->postJson('/api/conversations/direct', ['user_id' => $user->id])
        ->assertStatus(400)
        ->assertJson(['success' => false, 'message' => 'Cannot chat with yourself']);
});

test('can retrieve conversation messages', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $id = dmWith($a, $b);
    $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/messages", ['message' => 'Hi there', 'request_id' => (string) Str::uuid()]);

    $this->actingAs($b, 'api')->getJson("/api/conversations/{$id}/messages")
        ->assertOk()
        ->assertJsonStructure(['data' => ['*' => ['id', 'content', 'sender']], 'success']);
});
