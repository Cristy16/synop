<?php

use App\Actions\Education\AddClassSession;
use App\Actions\Education\ArchiveSemester;
use App\Actions\Education\CreateActivity;
use App\Actions\Education\CreateCourse;
use App\Actions\Education\CreateSemester;
use App\Actions\Education\DeleteActivity;
use App\Actions\Education\DeleteCourse;
use App\Actions\Education\DeleteSemester;
use App\Actions\Education\RemoveClassSession;
use App\Actions\Education\SetActiveSemester;
use App\Actions\Education\UpdateActivity;
use App\Actions\Education\UpdateClassSession;
use App\Actions\Education\UpdateCourse;
use App\Actions\Education\UpdateSemester;
use App\Actions\Tasks\CompleteTask;
use App\Actions\Tasks\DeleteTask;
use App\Enums\Frequency;
use App\Models\Activity;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\ScheduleRule;
use App\Models\Semester;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->semesterOf = fn (string $name = '1st Sem', string $from = '2026-08-10', string $to = '2026-12-18') => app(CreateSemester::class)->handle($this->user, [
        'name' => $name, 'starts_on' => $from, 'ends_on' => $to,
    ]);
    $this->sem = ($this->semesterOf)();
    $this->course = app(CreateCourse::class)->handle($this->user, [
        'semester_id' => $this->sem->id, 'code' => 'CS 101', 'title' => 'Intro to Programming', 'instructor' => 'Prof. Cruz', 'room' => '301',
    ]);
    $this->session = fn (array $o = []) => app(AddClassSession::class)->handle($this->user, $this->course, [
        'start_time' => '08:00', 'end_time' => '10:00', 'weekdays' => [1, 3, 5], ...$o,
    ]);
    $this->activity = fn (array $o = []) => app(CreateActivity::class)->handle($this->user, [
        'course_id' => $this->course->id, 'type' => 'quiz', 'title' => 'Chapter 3 quiz', 'due_on' => '2026-11-20', ...$o,
    ]);
    $this->taskOf = fn (Activity $a) => Task::where('source_type', 'activity')->where('source_id', $a->id)->first();
});

// Semesters

test('the first semester becomes active; later ones don\'t', function () {
    $second = ($this->semesterOf)('2nd Sem', '2027-01-10', '2027-05-20');

    expect($this->sem->fresh()->is_active)->toBeTrue()->and($second->is_active)->toBeFalse();
});

test('only one semester is active at a time', function () {
    $second = ($this->semesterOf)('2nd Sem', '2027-01-10', '2027-05-20');

    app(SetActiveSemester::class)->handle($this->user, $second);

    expect($this->sem->fresh()->is_active)->toBeFalse()
        ->and($second->fresh()->is_active)->toBeTrue()
        ->and(Semester::where('is_active', true)->count())->toBe(1);
});

test('archiving turns "active" off, and an archived semester can\'t be made active', function () {
    app(ArchiveSemester::class)->handle($this->user, $this->sem);

    expect($this->sem->fresh())->is_active->toBeFalse()->archived_at->not->toBeNull()
        ->and(fn () => app(SetActiveSemester::class)->handle($this->user, $this->sem->fresh()))->toThrow(ValidationException::class);

    app(ArchiveSemester::class)->handle($this->user, $this->sem->fresh(), archived: false);
    expect($this->sem->fresh()->archived_at)->toBeNull();
});

test('invalid semesters are rejected', function (array $input, string $field) {
    expect(fn () => app(CreateSemester::class)->handle($this->user, $input))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });
})->with([
    'no name' => [['name' => '', 'starts_on' => '2027-01-01', 'ends_on' => '2027-05-01'], 'name'],
    'ends before it starts' => [['name' => 'X', 'starts_on' => '2027-05-01', 'ends_on' => '2027-01-01'], 'ends_on'],
]);

test('changing a semester\'s end date moves its class schedules\' end too', function () {
    $session = ($this->session)();

    app(UpdateSemester::class)->handle($this->user, $this->sem, ['name' => '1st Sem', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-23']);

    expect($session->latestRule()->ends_on->toDateString())->toBe('2026-12-23');
});

test('a future semester\'s class schedules move completely with it', function () {
    $next = ($this->semesterOf)('2nd Sem', '2027-01-10', '2027-05-20');
    $course = app(CreateCourse::class)->handle($this->user, ['semester_id' => $next->id, 'title' => 'Calculus']);
    $session = app(AddClassSession::class)->handle($this->user, $course, ['start_time' => '13:00', 'end_time' => '14:30', 'weekdays' => [2, 4]]);

    app(UpdateSemester::class)->handle($this->user, $next, ['name' => '2nd Sem', 'starts_on' => '2027-01-17', 'ends_on' => '2027-05-27']);

    expect($session->latestRule())
        ->starts_on->toDateString()->toBe('2027-01-17')
        ->ends_on->toDateString()->toBe('2027-05-27');
});

// Courses and class times

test('a class time repeats on its weekdays over the semester\'s dates', function () {
    $session = ($this->session)(['label' => 'Lecture']);

    $rule = ScheduleRule::where('schedulable_type', 'class_session')->sole();

    expect($rule->schedulable_id)->toBe($session->id)
        ->and($rule->frequency)->toBe(Frequency::Weekly)
        ->and($rule->weekdays)->toBe([1, 3, 5])
        ->and($rule->starts_on->toDateString())->toBe('2026-08-10')
        ->and($rule->ends_on->toDateString())->toBe('2026-12-18')
        ->and($session->timeRange())->toBe('8:00–10:00 AM')
        ->and($session->roomOr($this->course))->toBe('301');
});

test('invalid class times are rejected', function (array $o, string $field) {
    expect(fn () => ($this->session)($o))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(ClassSession::count())->toBe(0);
})->with([
    'ends before it starts' => [['start_time' => '10:00', 'end_time' => '09:00'], 'end_time'],
    'ends when it starts' => [['start_time' => '10:00', 'end_time' => '10:00'], 'end_time'],
    'no days' => [['weekdays' => []], 'weekdays'],
    'day 8' => [['weekdays' => [8]], 'weekdays.0'],
]);

test('changing a class time\'s days keeps past weeks (versioned)', function () {
    $session = ($this->session)();

    app(UpdateClassSession::class)->handle($this->user, $session, ['start_time' => '08:00', 'end_time' => '10:00', 'weekdays' => [2, 4]]);

    $rules = ScheduleRule::where('schedulable_type', 'class_session')->orderBy('starts_on')->get();
    expect($rules)->toHaveCount(2)
        ->and($rules[0]->ends_on->toDateString())->toBe('2026-11-18')
        ->and($rules[1]->weekdays)->toBe([2, 4])
        ->and($rules[1]->ends_on->toDateString())->toBe('2026-12-18');
});

test('removing a class time removes its schedule', function () {
    $session = ($this->session)();

    app(RemoveClassSession::class)->handle($this->user, $session);

    expect(ClassSession::count())->toBe(0)->and(ScheduleRule::count())->toBe(0);
});

// Activities and their task

test('an activity creates its task, due with it', function () {
    $activity = ($this->activity)(['due_time' => '13:00']);

    expect(($this->taskOf)($activity))
        ->title->toBe('Quiz: Chapter 3 quiz (CS 101)')
        ->due_on->toDateString()->toBe('2026-11-20')
        ->and(substr((string) ($this->taskOf)($activity)->due_time, 0, 5))->toBe('13:00');
});

test('editing an activity updates its task, and its done state is kept', function () {
    $activity = ($this->activity)();
    app(CompleteTask::class)->handle($this->user, ($this->taskOf)($activity));

    app(UpdateActivity::class)->handle($this->user, $activity, [
        'course_id' => $this->course->id, 'type' => 'exam', 'title' => 'Midterm', 'due_on' => '2026-11-27',
    ]);

    expect(($this->taskOf)($activity))
        ->title->toBe('Exam: Midterm (CS 101)')
        ->due_on->toDateString()->toBe('2026-11-27')
        ->completed_at->not->toBeNull();
});

test('renaming a course renames its activities\' tasks', function () {
    $activity = ($this->activity)();

    app(UpdateCourse::class)->handle($this->user, $this->course, ['code' => 'CS 102', 'title' => 'Programming 2']);

    expect(($this->taskOf)($activity)->title)->toBe('Quiz: Chapter 3 quiz (CS 102)');
});

test('an activity\'s task can\'t be deleted directly; deleting the activity removes it', function () {
    $activity = ($this->activity)();

    expect(fn () => app(DeleteTask::class)->handle($this->user, ($this->taskOf)($activity)))->toThrow(AuthorizationException::class);

    app(DeleteActivity::class)->handle($this->user, $activity);
    expect(Activity::count())->toBe(0)->and(Task::count())->toBe(0);
});

test('invalid activities are rejected', function (array $o, string $field) {
    expect(fn () => ($this->activity)($o))
        ->toThrow(function (ValidationException $e) use ($field) {
            expect($e->errors())->toHaveKey($field);
        });

    expect(Activity::count())->toBe(0)->and(Task::count())->toBe(0);
})->with([
    'unknown type' => [['type' => 'recitation'], 'type'],
    'no title' => [['title' => ''], 'title'],
    'no due date' => [['due_on' => ''], 'due_on'],
    'bad time' => [['due_time' => '7pm'], 'due_time'],
]);

// Deleting cleans up (DATABASE §1.5)

test('deleting a course removes its class times, schedules, activities and tasks', function () {
    ($this->session)();
    ($this->activity)();

    app(DeleteCourse::class)->handle($this->user, $this->course);

    expect(Course::count())->toBe(0)
        ->and(ClassSession::count())->toBe(0)
        ->and(ScheduleRule::count())->toBe(0)
        ->and(Activity::count())->toBe(0)
        ->and(Task::count())->toBe(0);
});

test('deleting a semester removes everything under it', function () {
    ($this->session)();
    ($this->activity)();
    Task::factory()->create(['title' => 'Unrelated']);

    app(DeleteSemester::class)->handle($this->user, $this->sem);

    expect(Semester::count())->toBe(0)
        ->and(Course::count())->toBe(0)
        ->and(ScheduleRule::count())->toBe(0)
        ->and(Task::pluck('title')->all())->toBe(['Unrelated']);
});
