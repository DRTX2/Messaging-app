<?php

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

test('a user cannot attach another user\'s orphaned upload', function () {
    Storage::fake('public');
    [$owner, $thief, $b] = User::factory()->count(3)->create();
    $id = dmWith($thief, $b);

    $upload = $this->actingAs($owner, 'api')
        ->post('/api/attachments', ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->assertCreated();
    $attachmentId = $upload->json('data.id');

    $this->actingAs($thief, 'api')->postJson("/api/conversations/{$id}/messages", [
        'request_id' => (string) Str::uuid(),
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    expect(Attachment::find($attachmentId)->message_id)->toBeNull();
});

test('owner can attach their own orphaned upload', function () {
    Storage::fake('public');
    [$a, $b] = User::factory()->count(2)->create();
    $id = dmWith($a, $b);

    $attachmentId = $this->actingAs($a, 'api')
        ->post('/api/attachments', ['file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
        ->json('data.id');

    $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/messages", [
        'request_id' => (string) Str::uuid(),
        'attachment_ids' => [$attachmentId],
    ])->assertCreated();

    expect(Attachment::find($attachmentId)->message_id)->not->toBeNull();
});

test('macro-capable office files and archives are rejected', function () {
    Storage::fake('public');
    $user = User::factory()->create();

    foreach (['evil.zip', 'legacy.xls', 'legacy.doc'] as $name) {
        $this->actingAs($user, 'api')
            ->withHeaders(['Accept' => 'application/json'])->post('/api/attachments', ['file' => UploadedFile::fake()->create($name, 10)])
            ->assertUnprocessable();
    }
});

test('same request_id from another sender does not return the first sender\'s message', function () {
    [$a, $b, $c] = User::factory()->count(3)->create();
    $id = dmWith($a, $b);
    $requestId = (string) Str::uuid();

    $first = $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/messages", ['request_id' => $requestId, 'message' => 'mine'])->json('data.id');

    $cId = dmWith($c, $b);
    $other = $this->actingAs($c, 'api')->postJson("/api/conversations/{$cId}/messages", ['request_id' => $requestId, 'message' => 'theirs']);

    $other->assertCreated();
    expect($other->json('data.id'))->not->toBe($first);
    expect($other->json('data.content'))->toBe('theirs');
});

test('empty message without attachments is rejected', function () {
    [$a, $b] = User::factory()->count(2)->create();
    $id = dmWith($a, $b);

    $this->actingAs($a, 'api')->postJson("/api/conversations/{$id}/messages", [
        'request_id' => (string) Str::uuid(),
        'message' => '',
    ])->assertUnprocessable();
});

test('per_page is clamped to a sane range', function () {
    $user = User::factory()->create();

    $this->actingAs($user, 'api')->getJson('/api/conversations?per_page=1000000')->assertOk();
    $this->actingAs($user, 'api')->getJson('/api/conversations?per_page=-5')->assertOk();
});

test('login is rate limited', function () {
    $user = User::factory()->create(['password' => bcrypt('secret123')]);

    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-pass']);
    }

    $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-pass'])->assertStatus(429);
});

test('last admin leaving promotes another member', function () {
    [$admin, $m1, $m2] = User::factory()->count(3)->create();
    $g = $this->actingAs($admin, 'api')->postJson('/api/groups', [
        'request_id' => (string) Str::uuid(),
        'name' => 'Team',
        'participant_ids' => [$m1->id, $m2->id],
    ])->json('data.id');

    $this->actingAs($admin, 'api')->deleteJson("/api/groups/{$g}/leave")->assertOk();

    expect(\App\Models\Conversation::find($g)->participants()->wherePivot('is_admin', true)->count())->toBe(1);
});
