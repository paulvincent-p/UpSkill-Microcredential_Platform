<?php

use App\Models\Course;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('faculty can see admin denial feedback while managing a returned course', function () {
    $faculty = User::factory()->faculty()->create();
    $course = Course::create([
        'title' => 'Returned Course',
        'slug' => 'returned-course',
        'short_description' => 'Course summary.',
        'category' => 'Information Technology',
        'created_by' => $faculty->id,
        'approval_status' => 'denied',
        'denial_feedback' => 'Please update the assessment criteria.',
    ]);

    $this->actingAs($faculty)
        ->get(route('faculty.courses.manage', $course->id))
        ->assertOk()
        ->assertSeeText('Feedback from admin')
        ->assertSeeText('Please update the assessment criteria.');
});
