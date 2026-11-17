<?php

use Illuminate\Support\Facades\Event;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Str;

test('direct endpoint returns the same conversation every time', function () {
    [$a, $b] = User::factory()->count(2)->create();

    $id1 = $this->actingAs($a, 'api')->postJson('/api/conversations/direct', ['user_id' => $b->id])->json('data.id');
    $id2 = $this->actingAs($a, 'api')->postJson('/api/conversations/direct', ['user_id' => $b->id])->json('data.id');
    $id3 = $this->actingAs($b, 'api')->postJson('/api/conversations/direct', ['user_id' => $a->id])->json('data.id');

    expect($id2)->toBe($id1)->and($id3)->toBe($id1);
    expect(Conversation::where('is_group', false)->count())->toBe(1);
});

test('message to a group stays in the group and is broadcast to its channel', function () {
    Event::fake([MessageSent::class]);
    [$a, $b, $c] = User::factory()->count(3)->create();
    $group = Conversation::create(['uuid' => (string) Str::uuid(), 'is_group' => true, 'name' => 'G', 'created_by' => $a->id, 'last_message_at' => now()]);
    $group->participants()->attach([$a->id, $b->id, $c->id]);

    $res = $this->actingAs($a, 'api')->postJson("/api/conversations/{$group->id}/messages", [
        'request_id' => (string) Str::uuid(),
        'message' => 'hi group',
    ])->assertStatus(201);

    expect($res->json('data.conversation_id'))->toBe($group->id);
    expect(Conversation::where('is_group', false)->count())->toBe(0);
    Event::assertDispatched(MessageSent::class);
});

test('non participant cannot read or write a conversation', function () {
    [$a, $b, $outsider] = User::factory()->count(3)->create();
    $conv = $this->actingAs($a, 'api')->postJson('/api/conversations/direct', ['user_id' => $b->id])->json('data.id');

    $this->actingAs($outsider, 'api')->getJson("/api/conversations/{$conv}/messages")->assertForbidden();
    $this->actingAs($outsider, 'api')->postJson("/api/conversations/{$conv}/messages", [
        'request_id' => (string) Str::uuid(), 'message' => 'x',
    ])->assertForbidden();
});

test('broadcast event targets conversation and participant channels', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $conv = Conversation::create(['uuid' => (string) Str::uuid(), 'is_group' => false, 'created_by' => $a->id, 'last_message_at' => now()]);
    $conv->participants()->attach([$a->id, $b->id]);
    $msg = $conv->messages()->create(['sender_id' => $a->id, 'content' => 'x', 'type' => 'text']);

    $names = collect((new MessageSent($msg->load('conversation')))->broadcastOn())->map->name->all();

    expect($names)->toContain("private-conversation.{$conv->id}", "private-App.Models.User.{$b->id}");
});
