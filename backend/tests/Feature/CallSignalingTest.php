<?php

use App\Events\CallSignal;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function directConversation(User $a, User $b): Conversation
{
    $conv = Conversation::create(['uuid' => (string) Str::uuid(), 'is_group' => false, 'created_by' => $a->id, 'last_message_at' => now()]);
    $conv->participants()->attach([$a->id, $b->id]);

    return $conv;
}

test('an offer is relayed to the other participant on their private channel', function () {
    Event::fake([CallSignal::class]);
    [$a, $b] = User::factory()->count(2)->create();
    $conv = directConversation($a, $b);
    $callId = (string) Str::uuid();

    $this->actingAs($a, 'api')->postJson("/api/conversations/{$conv->id}/calls/signal", [
        'call_id' => $callId, 'type' => 'offer', 'media' => 'video',
        'data' => ['type' => 'offer', 'sdp' => "v=0\r\n", 'injected' => '<script>'],
    ])->assertOk();

    Event::assertDispatched(CallSignal::class, function (CallSignal $e) use ($a, $b, $conv, $callId) {
        return $e->toUserId === $b->id
            && $e->broadcastOn()[0]->name === "private-App.Models.User.{$b->id}"
            && $e->broadcastWith()['call_id'] === $callId
            && $e->broadcastWith()['conversation_id'] === $conv->id
            && $e->broadcastWith()['from'] == ['id' => $a->id, 'name' => $a->name]
            && $e->broadcastWith()['data'] == ['type' => 'offer', 'sdp' => "v=0\r\n"]; // unknown keys are dropped, the SDP's trailing CRLF survives
    });
});

test('ice candidates, hangup and reject need no sdp', function (string $type, ?array $data) {
    Event::fake([CallSignal::class]);
    [$a, $b] = User::factory()->count(2)->create();
    $conv = directConversation($a, $b);

    $this->actingAs($b, 'api')->postJson("/api/conversations/{$conv->id}/calls/signal", array_filter([
        'call_id' => (string) Str::uuid(), 'type' => $type, 'media' => 'audio', 'data' => $data,
    ]))->assertOk();

    Event::assertDispatched(CallSignal::class, fn (CallSignal $e) => $e->toUserId === $a->id && $e->type === $type);
})->with([
    ['candidate', ['candidate' => 'candidate:1 1 udp 2122260223 192.0.2.1 54321 typ host', 'sdpMid' => '0', 'sdpMLineIndex' => 0]],
    ['hangup', null],
    ['reject', null],
    ['busy', null],
]);

test('outsiders cannot signal into a conversation', function () {
    Event::fake([CallSignal::class]);
    [$a, $b, $outsider] = User::factory()->count(3)->create();
    $conv = directConversation($a, $b);

    $this->actingAs($outsider, 'api')->postJson("/api/conversations/{$conv->id}/calls/signal", [
        'call_id' => (string) Str::uuid(), 'type' => 'hangup', 'media' => 'audio',
    ])->assertForbidden();

    Event::assertNotDispatched(CallSignal::class);
});

test('group conversations cannot be called', function () {
    Event::fake([CallSignal::class]);
    [$a, $b, $c] = User::factory()->count(3)->create();
    $group = Conversation::create(['uuid' => (string) Str::uuid(), 'is_group' => true, 'name' => 'G', 'created_by' => $a->id, 'last_message_at' => now()]);
    $group->participants()->attach([$a->id, $b->id, $c->id]);

    $this->actingAs($a, 'api')->postJson("/api/conversations/{$group->id}/calls/signal", [
        'call_id' => (string) Str::uuid(), 'type' => 'hangup', 'media' => 'audio',
    ])->assertStatus(422);

    Event::assertNotDispatched(CallSignal::class);
});

test('invalid signalling payloads are rejected', function (array $payload) {
    [$a, $b] = User::factory()->count(2)->create();
    $conv = directConversation($a, $b);

    $this->actingAs($a, 'api')->postJson("/api/conversations/{$conv->id}/calls/signal", $payload)->assertStatus(422);
})->with([
    'unknown type' => [['call_id' => '6f1c1b1e-0000-4000-8000-000000000001', 'type' => 'eval', 'media' => 'audio']],
    'offer without sdp' => [['call_id' => '6f1c1b1e-0000-4000-8000-000000000001', 'type' => 'offer', 'media' => 'audio']],
    'bad call id' => [['call_id' => 'nope', 'type' => 'hangup', 'media' => 'audio']],
    'bad media' => [['call_id' => '6f1c1b1e-0000-4000-8000-000000000001', 'type' => 'hangup', 'media' => 'hologram']],
]);

test('signalling requires authentication', function () {
    $this->postJson('/api/conversations/1/calls/signal', [])->assertUnauthorized();
});
