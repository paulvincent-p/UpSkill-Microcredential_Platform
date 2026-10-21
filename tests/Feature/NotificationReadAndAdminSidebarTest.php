<?php

use App\Models\Course;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('clicking an unread notification marks it read before following its target', function () {
    $user = User::factory()->create(['role_id' => User::ROLE_STUDENT]);
    $faculty = User::factory()->faculty()->create();
    $course = Course::create([
        'title' => 'Notification target course',
        'slug' => 'notification-target-course',
        'created_by' => $faculty->id,
        'approval_status' => 'approved',
    ]);
    $notification = Notification::create([
        'user_id' => $user->id,
        'title' => 'Course update',
        'message' => 'A course is ready.',
        'type' => 'course',
        'entity_type' => 'course',
        'entity_id' => $course->id,
        'is_read' => false,
    ]);

    $this->actingAs($user)
        ->get(route('notifications.preview'))
        ->assertOk()
        ->assertJsonPath('notifications.0.id', $notification->id)
        ->assertJsonPath('notifications.0.unread', true);

    $this->postJson(route('notifications.read', $notification))
        ->assertOk()
        ->assertJsonPath('unread_count', 0);

    expect($notification->fresh()->is_read)->toBeTrue();
});

test('a user cannot mark another user notification as read', function () {
    $owner = User::factory()->create(['role_id' => User::ROLE_STUDENT]);
    $otherUser = User::factory()->create(['role_id' => User::ROLE_STUDENT]);
    $notification = Notification::create([
        'user_id' => $owner->id,
        'title' => 'Private notification',
        'message' => 'Private message.',
        'type' => 'system',
        'is_read' => false,
    ]);

    $this->actingAs($otherUser)
        ->postJson(route('notifications.read', $notification))
        ->assertNotFound();

    expect($notification->fresh()->is_read)->toBeFalse();
});

test('read notifications appear below unread notifications in the preview', function () {
    $user = User::factory()->create(['role_id' => User::ROLE_STUDENT]);
    $olderUnreadNotification = Notification::create([
        'user_id' => $user->id,
        'title' => 'Older unread notification',
        'message' => 'Still needs attention.',
        'type' => 'system',
        'is_read' => false,
        'created_at' => now()->subDay(),
    ]);
    $newerReadNotification = Notification::create([
        'user_id' => $user->id,
        'title' => 'Newer read notification',
        'message' => 'Already read.',
        'type' => 'system',
        'is_read' => false,
        'created_at' => now(),
    ]);

    $this->actingAs($user)
        ->postJson(route('notifications.read', $newerReadNotification))
        ->assertOk();

    $this->actingAs($user)
        ->getJson(route('notifications.preview'))
        ->assertOk()
        ->assertJsonPath('notifications.0.id', $olderUnreadNotification->id)
        ->assertJsonPath('notifications.1.id', $newerReadNotification->id);
});

test('admin sidebar shows an alert dot when a course needs review', function () {
    $admin = User::factory()->admin()->create();
    $faculty = User::factory()->faculty()->create();
    Course::create([
        'title' => 'Course awaiting review',
        'slug' => 'course-awaiting-review',
        'created_by' => $faculty->id,
        'approval_status' => 'pending',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.courses'))
        ->assertOk()
        ->assertSee('aria-label="Pending course reviews"', false);
});
