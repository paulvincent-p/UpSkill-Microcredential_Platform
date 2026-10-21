<?php

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('notification preview includes announcement content for opening the announcement modal', function () {
    $user = User::factory()->create(['role_id' => User::ROLE_STUDENT]);
    $announcement = Announcement::create([
        'title' => 'Platform update',
        'body' => '<p>Important <strong>announcement</strong>.</p>',
        'audience' => ['public'],
        'created_by' => User::factory()->admin()->create()->id,
        'is_published' => true,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->getJson(route('notifications.preview'))
        ->assertOk()
        ->assertJsonPath('notifications.0.type', 'announcement')
        ->assertJsonPath('notifications.0.announcement_id', $announcement->id)
        ->assertJsonPath('notifications.0.title', 'Platform update')
        ->assertJsonPath('notifications.0.content', '<p>Important <strong>announcement</strong>.</p>');
});

test('opening a visible announcement marks it read and updates the bell unread count', function () {
    $user = User::factory()->create([
        'role_id' => User::ROLE_STUDENT,
        'notifications_read_at' => now()->subDay(),
    ]);
    $announcement = Announcement::create([
        'title' => 'Platform update',
        'body' => '<p>Important update.</p>',
        'audience' => ['student'],
        'created_by' => User::factory()->admin()->create()->id,
        'is_published' => true,
        'published_at' => now()->subHour(),
    ]);

    $this->actingAs($user)
        ->postJson(route('notifications.announcements.read', $announcement))
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    expect($user->fresh()->notifications_read_at->equalTo($announcement->published_at))->toBeTrue();

    $this->getJson(route('notifications.preview'))
        ->assertOk()
        ->assertJsonPath('notifications.0.unread', false);
});

test('users cannot mark announcements outside their audience as read', function () {
    $user = User::factory()->create([
        'role_id' => User::ROLE_STUDENT,
        'notifications_read_at' => null,
    ]);
    $announcement = Announcement::create([
        'title' => 'Faculty update',
        'body' => '<p>Faculty only.</p>',
        'audience' => ['faculty'],
        'created_by' => User::factory()->admin()->create()->id,
        'is_published' => true,
        'published_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('notifications.announcements.read', $announcement))
        ->assertNotFound();

    expect($user->fresh()->notifications_read_at)->toBeNull();
});
