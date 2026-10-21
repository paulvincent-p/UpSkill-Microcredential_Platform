<?php

use App\Models\Complaint;
use App\Models\ComplaintReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin complaint inbox uses the admin CKEditor preset for replies', function () {
    $admin = User::factory()->admin()->create();
    $student = User::factory()->create();
    $complaint = Complaint::create([
        'user_id' => $student->id,
        'source' => 'student',
        'subject' => 'Help request',
        'message' => 'Please help.',
        'status' => 'open',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.complaints', ['thread' => $complaint->id]))
        ->assertOk()
        ->assertSee('data-ckeditor', false)
        ->assertSee('data-editor-preset="admin-reply"', false);
});

test('admin rich text replies are sanitized and displayed in the student inbox', function () {
    $admin = User::factory()->admin()->create();
    $student = User::factory()->create();
    $complaint = Complaint::create([
        'user_id' => $student->id,
        'source' => 'student',
        'subject' => 'Help request',
        'message' => 'Please help.',
        'status' => 'open',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.complaints.reply', $complaint->id), [
            'body' => '<p><strong>Helpful reply</strong></p><script>alert("xss")</script>',
        ])
        ->assertRedirect(route('admin.complaints', ['thread' => $complaint->id]));

    $reply = ComplaintReply::firstOrFail();

    expect($reply->body)->toContain('<strong>Helpful reply</strong>')
        ->and($reply->body)->not->toContain('<script');

    $this->actingAs($student)
        ->get(route('inbox.index', ['thread' => $complaint->id]))
        ->assertOk()
        ->assertSee('<strong>Helpful reply</strong>', false)
        ->assertDontSee('&lt;strong&gt;Helpful reply&lt;/strong&gt;', false);
});
