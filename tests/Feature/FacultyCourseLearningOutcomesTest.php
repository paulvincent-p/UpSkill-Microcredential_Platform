<?php

use App\Models\Course;
use App\Models\LearningOutcome;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('faculty course form uses a rich text editor for learning outcomes without competency mapping', function () {
    $faculty = User::factory()->faculty()->create();

    $this->actingAs($faculty)
        ->get(route('faculty.create'))
        ->assertOk()
        ->assertSee('name="learning_outcomes_content"', false)
        ->assertSee('data-ckeditor', false)
        ->assertDontSee('Competency unit')
        ->assertDontSee('No competency mapping');
});

test('faculty course creation saves sanitized rich text learning outcomes without competency mapping', function () {
    $faculty = User::factory()->faculty()->create();

    $this->actingAs($faculty)
        ->post(route('faculty.create.store'), [
            'title' => 'Accessible Web Design',
            'short_description' => 'Build accessible web pages.',
            'learning_outcomes_content' => '<h2>Apply accessibility</h2><ul><li onclick="alert(1)">Use semantic HTML</li></ul>',
        ])
        ->assertRedirect();

    $course = Course::query()->where('title', 'Accessible Web Design')->firstOrFail();
    $outcome = LearningOutcome::query()->where('course_id', $course->id)->firstOrFail();

    expect($outcome->description)
        ->toBe('<h2>Apply accessibility</h2><ul><li>Use semantic HTML</li></ul>')
        ->and($outcome->competency_unit_id)->toBeNull();
});
