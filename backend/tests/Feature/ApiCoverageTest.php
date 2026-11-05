<?php

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function makeGroup(User $admin, array $members = []): Conversation
{
    $g = Conversation::create(['uuid' => (string) Str::uuid(), 'is_group' => true, 'name' => 'G', 'created_by' => $admin->id, 'last_message_at' => now()]);
    $g->participants()->attach($admin->id, ['is_admin' => true]);
    foreach ($members as $m) {
        $g->participants()->attach($m->id, ['is_admin' => false]);
    }

    return $g;
}

function directConv(User $a, User $b): int
{
    return test()->actingAs($a, 'api')->postJson('/api/conversations/direct', ['user_id' => $b->id])->json('data.id');
}

// ---------- Auth ----------
test('register, login, me, refresh and logout flow', function () {
    $this->postJson('/api/register', ['name' => 'Ann', 'email' => 'ann@test.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
        ->assertSuccessful();

    $login = $this->postJson('/api/login', ['email' => 'ann@test.com', 'password' => 'secret123'])->assertOk();
    $token = $login->json('access_token');
    expect($token)->not->toBeEmpty();

    $this->withToken($token)->getJson('/api/me')->assertOk();
    $this->withToken($token)->postJson('/api/refresh')->assertOk();
    $this->withToken($token)->postJson('/api/logout')->assertOk();
});

test('login rejects bad credentials and register validates', function () {
    User::factory()->create(['email' => 'bob@test.com']);
    $this->postJson('/api/login', ['email' => 'bob@test.com', 'password' => 'wrong'])->assertStatus(401);
    $this->postJson('/api/register', ['name' => 'x'])->assertStatus(422);
});

// ---------- Groups ----------
test('group lifecycle: create, rename, add, remove, leave', function () {
    [$admin, $b, $c] = User::factory()->count(3)->create();
    $rid = (string) Str::uuid();

    $created = $this->actingAs($admin, 'api')->postJson('/api/groups', ['request_id' => $rid, 'name' => 'Team', 'participant_ids' => [$b->id]])->assertSuccessful();
    $id = $created->json('data.id');
    // idempotent retry returns the same group
    expect($this->actingAs($admin, 'api')->postJson('/api/groups', ['request_id' => $rid, 'name' => 'Team', 'participant_ids' => [$b->id]])->json('data.id'))->toBe($id);

    $this->actingAs($admin, 'api')->putJson("/api/groups/{$id}", ['name' => 'Renamed'])->assertOk();
    $this->actingAs($admin, 'api')->postJson("/api/groups/{$id}/participants", ['user_ids' => [$c->id]])->assertSuccessful();
    expect(Conversation::find($id)->participants)->toHaveCount(3);

    $this->actingAs($admin, 'api')->deleteJson("/api/groups/{$id}/participants/{$c->id}")->assertSuccessful();
    $this->actingAs($b, 'api')->deleteJson("/api/groups/{$id}/leave")->assertSuccessful();
    expect(Conversation::find($id)->participants)->toHaveCount(1);
});

test('only group admins manage members', function () {
    [$admin, $member, $other] = User::factory()->count(3)->create();
    $g = makeGroup($admin, [$member]);

    $this->actingAs($member, 'api')->postJson("/api/groups/{$g->id}/participants", ['user_ids' => [$other->id]])->assertForbidden();
    $this->actingAs($member, 'api')->putJson("/api/groups/{$g->id}", ['name' => 'Hack'])->assertForbidden();
    $this->actingAs($member, 'api')->deleteJson("/api/groups/{$g->id}/participants/{$admin->id}")->assertForbidden();
});

test('group endpoints reject direct conversations', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();
    $id = directConv($a, $b);

    $this->actingAs($a, 'api')->postJson("/api/groups/{$id}/participants", ['user_ids' => [$c->id]])->assertForbidden();
});

// ---------- Chat actions ----------
test('users list, search and unread count', function () {
    [$a] = $users = User::factory()->count(3)->create();

    $this->actingAs($a, 'api')->getJson('/api/chat')->assertOk()->assertJsonCount(2, 'data');
    $this->actingAs($a, 'api')->getJson('/api/chat?search='.$users[1]->email)->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($a, 'api')->getJson('/api/chat/unread-count')->assertOk()->assertJsonStructure(['unread_count']);
});

test('message endpoints: list, favorite, delete, per-user clear', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $id = directConv($a, $b);
    $msgId = $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/messages", ['request_id' => (string) Str::uuid(), 'message' => 'hey'])->json('data.id');

    $this->actingAs($b, 'api')->getJson("/api/conversations/{$id}/messages")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($b, 'api')->postJson("/api/chat/messages/{$msgId}/favorite")->assertOk();
    $this->actingAs($a, 'api')->deleteJson("/api/chat/messages/{$msgId}")->assertOk();

    $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/messages", ['request_id' => (string) Str::uuid(), 'message' => 'again']);
    $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/clear")->assertOk();

    // clear hides history only for the caller; the other participant keeps it
    $this->actingAs($a, 'api')->getJson("/api/conversations/{$id}/messages")->assertJsonCount(0, 'data');
    $this->actingAs($b, 'api')->getJson("/api/conversations/{$id}/messages")->assertJsonCount(1, 'data');
    expect(Conversation::find($id)->messages()->count())->toBe(1);
});

test('outsider cannot favorite, delete or clear', function () {
    [$a, $b, $x] = User::factory()->count(3)->create();
    $id = directConv($a, $b);
    $msgId = $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/messages", ['request_id' => (string) Str::uuid(), 'message' => 'secret'])->json('data.id');

    $this->actingAs($x, 'api')->postJson("/api/chat/messages/{$msgId}/favorite")->assertForbidden();
    $this->actingAs($x, 'api')->deleteJson("/api/chat/messages/{$msgId}")->assertForbidden();
    $this->actingAs($x, 'api')->postJson("/api/conversations/{$id}/clear")->assertForbidden();
});

test('sending file message links attachments', function () {
    Storage::fake('public');
    [$a, $b] = User::factory()->count(2)->create();
    $id = directConv($a, $b);

    $att = $this->actingAs($a, 'api')->postJson('/api/attachments', ['file' => UploadedFile::fake()->image('p.png')])->assertSuccessful();
    $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/messages", ['request_id' => (string) Str::uuid(), 'message' => '', 'attachment_ids' => [$att->json('data.id')]])
        ->assertStatus(201)->assertJsonCount(1, 'data.attachments');
});

test('attachment upload rejects disallowed types', function () {
    $a = User::factory()->create();
    $this->actingAs($a, 'api')->postJson('/api/attachments', ['file' => UploadedFile::fake()->create('x.exe', 10)])->assertStatus(422);
});

// ---------- Typing ----------
test('typing is broadcast for participants and denied for outsiders', function () {
    Event::fake();
    [$a, $b, $x] = User::factory()->count(3)->create();
    $id = directConv($a, $b);

    $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/typing", ['is_typing' => true])->assertOk();
    Event::assertDispatched(App\Events\UserTyping::class);
    $this->actingAs($x, 'api')->postJson("/api/conversations/{$id}/typing")->assertForbidden();

    $evt = new App\Events\UserTyping($id, $a->id, $a->name, true);
    expect($evt->broadcastAs())->toBe('UserTyping')->and($evt->broadcastWith())->toHaveKey('is_typing');
});

// ---------- Policies ----------
test('message policy: sender and admins may delete, others may not', function () {
    [$admin, $sender, $other] = User::factory()->count(3)->create();
    $g = makeGroup($admin, [$sender, $other]);
    $msg = $g->messages()->create(['sender_id' => $sender->id, 'content' => 'x', 'type' => 'text']);

    expect(Gate::forUser($sender)->allows('delete', $msg))->toBeTrue()
        ->and(Gate::forUser($other)->allows('delete', $msg))->toBeFalse()
        ->and(Gate::forUser($sender)->allows('update', $msg))->toBeTrue()
        ->and(Gate::forUser($other)->allows('update', $msg))->toBeFalse();
});

test('unauthenticated requests are rejected', function () {
    $this->getJson('/api/conversations')->assertUnauthorized();
});
