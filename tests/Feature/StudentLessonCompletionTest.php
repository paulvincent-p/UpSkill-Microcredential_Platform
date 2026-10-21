<?php

use App\Models\Course;
use App\Models\CourseLesson;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\LessonActivity;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function lessonTrackingFixture(): array
{
    $faculty = User::factory()->faculty()->create();
    $student = User::factory()->create();
    $course = Course::create([
        'title' => 'Lesson Tracking Course',
        'slug' => 'lesson-tracking-'.uniqid(),
        'description' => 'Test course',
        'category' => 'Development',
        'level' => 'Beginner',
        'is_published' => true,
        'created_by' => $faculty->id,
    ]);
    $module = CourseModule::create([
        'course_id' => $course->id,
        'title' => 'Module One',
        'order' => 1,
    ]);
    $lesson = CourseLesson::create([
        'course_id' => $course->id,
        'module_id' => $module->id,
        'title' => 'Lesson One',
        'type' => 'Text',
        'order' => 1,
        'content' => 'Lesson content',
    ]);

    return [$student, $course, $lesson];
}

test('progress endpoint ignores browser claims that lessons are complete', function () {
    [$student, $course, $lesson] = lessonTrackingFixture();
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'progress_state' => [],
    ]);

    $this->actingAs($student)
        ->postJson(route('courses.progress', $course->id), [
            'completed_lessons' => [(string) $lesson->id],
        ])
        ->assertOk();

    expect(DB::table('lesson_completions')->where('user_id', $student->id)->count())->toBe(0)
        ->and($student->enrollments()->first()->progress_state['completed_lessons'])->toBe([]);
});

test('lesson completion requires enrollment and a preceding lesson start', function () {
    [$student, $course, $lesson] = lessonTrackingFixture();

    $this->actingAs($student)
        ->postJson(route('courses.progress', $course->id), [
            'completed_lessons' => [(string) $lesson->id],
        ])
        ->assertNotFound();

    expect($student->enrollments()->exists())->toBeFalse();

    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'progress_state' => [],
    ]);

    $this->actingAs($student)
        ->postJson(route('courses.lessons.start', [$course->id, $lesson->id]))
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->travel(6)->seconds();

    $this->postJson(route('courses.lessons.complete', [$course->id, $lesson->id]))
        ->assertOk()
        ->assertJson(['ok' => true, 'evaluation_eligible' => true]);

    expect(DB::table('lesson_completions')
        ->where('user_id', $student->id)
        ->where('lesson_id', $lesson->id)
        ->whereNotNull('server_verified_at')
        ->exists())->toBeTrue();
});

test('reopening the course restores lessons from server-verified completions, not a stale progress snapshot', function () {
    [$student, $course, $completedLesson] = lessonTrackingFixture();
    $module = $completedLesson->module;
    $nextLesson = CourseLesson::create([
        'course_id' => $course->id,
        'module_id' => $module->id,
        'title' => 'Lesson Two',
        'type' => 'Text',
        'order' => 2,
        'content' => 'Next lesson content',
    ]);
    $enrollment = Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'progress_state' => ['completed_lessons' => [], 'module_scores' => []],
    ]);

    DB::table('lesson_completions')->insert([
        'user_id' => $student->id,
        'lesson_id' => $completedLesson->id,
        'completed_at' => now(),
        'server_verified_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->actingAs($student)
        ->get(route('courses.learn', $course->id))
        ->assertOk()
        ->assertSee('course-completion-dialog');

    expect($response->viewData('saved_progress')['completed_lessons'])
        ->toBe([(string) $completedLesson->id])
        ->and($response->viewData('saved_progress')['completed_lessons'])
        ->not->toContain((string) $nextLesson->id)
        ->and($enrollment->fresh()->progress_state['completed_lessons'])
        ->toBe([(string) $completedLesson->id]);
});

test('the learning welcome page includes module description content without the about card', function () {
    [$student, $course, $lesson] = lessonTrackingFixture();
    $lesson->module->update(['description' => '<p>Practice organizing data with arrays.</p>']);
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'progress_state' => [],
    ]);

    $this->actingAs($student)
        ->get(route('courses.learn', $course->id))
        ->assertOk()
        ->assertSee('id="wm-description"', false)
        ->assertSee('Practice organizing data with arrays.')
        ->assertSee(route('courses.show', $course->id))
        ->assertSee('id="lesson-next-action">Next</strong>', false)
        ->assertSee('id="lesson-back-target"', false)
        ->assertSee('id="lesson-step-back" type="button" onclick="goToPreviousLearningStep()" hidden', false)
        ->assertDontSee('About this module');
});

test('the next lesson remains locked until the preceding lesson is server-verified', function () {
    [$student, $course, $firstLesson] = lessonTrackingFixture();
    $secondLesson = CourseLesson::create([
        'course_id' => $course->id,
        'module_id' => $firstLesson->module_id,
        'title' => 'Lesson Two',
        'type' => 'Text',
        'order' => 2,
        'content' => 'Next lesson content',
    ]);
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'progress_state' => [],
    ]);

    $this->actingAs($student)
        ->postJson(route('courses.lessons.start', [$course->id, $secondLesson->id]))
        ->assertForbidden();
});

test('the course player can open the selected reflection and lesson quiz from the sidebar', function () {
    [$student, $course, $lesson] = lessonTrackingFixture();
    $activity = LessonActivity::create([
        'lesson_id' => $lesson->id,
        'title' => 'Reflection',
        'activity_type' => 'reflection',
        'instructions' => 'Reflect on the lesson.',
        'is_required' => false,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $quiz = Quiz::create([
        'course_id' => $course->id,
        'lesson_id' => $lesson->id,
        'title' => 'Lesson Quiz',
        'passing_score' => 70,
        'attempts' => 'Unlimited',
        'is_active' => true,
        'sort_order' => 2,
    ]);
    QuizQuestion::create([
        'quiz_id' => $quiz->id,
        'question' => 'A question?',
        'type' => 'Multiple Choice',
        'options' => ['A', 'B'],
        'correct_answer' => 'A',
        'points' => 1,
    ]);
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'progress_state' => [],
    ]);

    $this->actingAs($student)
        ->get(route('courses.learn', $course->id))
        ->assertOk()
        ->assertSee('openLessonAssessmentFromSidebar(0, 0, this.href)', false)
        ->assertSee('data-step-title="Reflection"', false)
        ->assertSee('data-step-title="Lesson Quiz"', false)
        ->assertSee(route('lesson-activities.show', $activity->id), false)
        ->assertSee(route('quiz.show', $quiz->id), false);

    $this->postJson(route('courses.lessons.start', [$course->id, $lesson->id]))
        ->assertOk();
    $this->travel(6)->seconds();
    $this->postJson(route('courses.lessons.complete', [$course->id, $lesson->id]))
        ->assertOk()
        ->assertJson(['ok' => true]);

    $activityResponse = $this->getJson(route('lesson-activities.show', $activity->id))
        ->assertOk();
    expect($activityResponse->json('html'))->toContain('Reflection');

    $submittedAnswer = 'I learned to model data structures.';
    $this->postJson(route('lesson-activities.submit', $activity->id), [
        'response_text' => $submittedAnswer,
    ])
        ->assertOk()
        ->assertJson([
            'ok' => true,
            'response_text' => $submittedAnswer,
            'file_submitted' => false,
            'file_url' => null,
        ]);

    $activityResponse = $this->getJson(route('lesson-activities.show', $activity->id))
        ->assertOk();
    expect($activityResponse->json('html'))
        ->toContain('Your submitted answer')
        ->toContain($submittedAnswer);

    $quizResponse = $this->getJson(route('quiz.show', $quiz->id))
        ->assertOk();
    expect($quizResponse->json('html'))->toContain('Lesson Quiz');
});

test('the lesson flow advances from required activities to its lesson quiz', function () {
    [$student, $course, $lesson] = lessonTrackingFixture();
    $reflection = LessonActivity::create([
        'lesson_id' => $lesson->id,
        'title' => 'Reflection',
        'activity_type' => 'reflection',
        'is_required' => true,
        'is_active' => true,
        'sort_order' => 1,
    ]);
    $practice = LessonActivity::create([
        'lesson_id' => $lesson->id,
        'title' => 'Practice',
        'activity_type' => 'practice',
        'is_required' => true,
        'is_active' => true,
        'sort_order' => 2,
    ]);
    $laterActivity = LessonActivity::create([
        'lesson_id' => $lesson->id,
        'title' => 'Follow-up practice',
        'activity_type' => 'practice',
        'is_required' => true,
        'is_active' => true,
        'sort_order' => 4,
    ]);
    $quiz = Quiz::create([
        'course_id' => $course->id,
        'lesson_id' => $lesson->id,
        'title' => 'Lesson Quiz',
        'passing_score' => 70,
        'attempts' => 'Unlimited',
        'is_active' => true,
        'sort_order' => 3,
    ]);
    QuizQuestion::create([
        'quiz_id' => $quiz->id,
        'question' => 'A question?',
        'type' => 'Multiple Choice',
        'options' => ['A', 'B'],
        'correct_answer' => 'A',
        'points' => 1,
    ]);
    Enrollment::create([
        'user_id' => $student->id,
        'course_id' => $course->id,
        'enrolled_at' => now(),
        'progress_percent' => 0,
        'progress_state' => [],
    ]);

    $this->actingAs($student)
        ->postJson(route('courses.lessons.start', [$course->id, $lesson->id]))
        ->assertOk();
    $this->travel(6)->seconds();

    $this->postJson(route('courses.lessons.complete', [$course->id, $lesson->id]))
        ->assertOk()
        ->assertJsonPath('next_url', route('lesson-activities.show', $reflection->id));

    $this->postJson(route('lesson-activities.submit', $reflection->id), ['response_text' => 'Reflection response'])
        ->assertOk();
    $this->postJson(route('courses.lessons.complete', [$course->id, $lesson->id]))
        ->assertOk()
        ->assertJsonPath('next_url', route('lesson-activities.show', $practice->id));

    $this->postJson(route('lesson-activities.submit', $practice->id), ['response_text' => 'Practice response'])
        ->assertOk();
    $this->postJson(route('courses.lessons.complete', [$course->id, $lesson->id]))
        ->assertOk()
        ->assertJsonPath('next_url', route('quiz.show', $quiz->id));

    $this->getJson(route('quiz.show', $quiz->id))
        ->assertOk()
        ->assertJsonStructure(['html']);

    $this->postJson(route('quiz.submit', $quiz->id), [
        'answers' => [(string) $quiz->questions->first()->id => 'A'],
    ])
        ->assertOk()
        ->assertJsonPath('passed', true)
        ->assertJsonPath('next_url', route('lesson-activities.show', $laterActivity->id));
});
