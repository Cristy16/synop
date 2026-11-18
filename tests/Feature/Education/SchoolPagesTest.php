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
use App\Models\Activity;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\Semester;
use App\Models\Task;
use App\Models\User;
use App\Services\ClassSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(); // Asia/Manila
    $this->actingAs($this->user);
    // Thursday Nov 19, 2026, 9:00 am.
    $this->travelTo(CarbonImmutable::parse('2026-11-19 09:00:00', 'Asia/Manila'));

    $this->sem = app(CreateSemester::class)->handle($this->user, ['name' => '1st Sem', 'starts_on' => '2026-08-10', 'ends_on' => '2026-12-18']);
    $this->course = fn (Semester $s, string $code, string $title) => app(CreateCourse::class)->handle($this->user, ['semester_id' => $s->id, 'code' => $code, 'title' => $title, 'room' => '301']);
    $this->session = fn (Course $c, string $from, string $to, array $days, array $o = []) => app(AddClassSession::class)->handle($this->user, $c, [
        'start_time' => $from, 'end_time' => $to, 'weekdays' => $days, ...$o,
    ]);
    $this->classesOn = fn (string $date) => array_map(fn (ClassSession $s) => $s->course->code.' '.substr($s->start_time, 0, 5), app(ClassSchedule::class)->on($this->user, $date));
});

// Today → Classes

test('Today lists today\'s classes from non-archived semesters, in time order', function () {
    $cs = ($this->course)($this->sem, 'CS 101', 'Intro to Programming');
    $math = ($this->course)($this->sem, 'MATH 21', 'Calculus');
    ($this->session)($math, '13:00', '14:30', [2, 4]);                 // Tue, Thu
    ($this->session)($cs, '08:00', '10:00', [1, 3, 5]);                // MWF: not Thursday
    ($this->session)($cs, '10:30', '12:00', [4], ['label' => 'Lab', 'room' => 'Lab 2']); // Thu

    $summer = app(CreateSemester::class)->handle($this->user, ['name' => 'Summer', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
    ($this->session)(($this->course)($summer, 'PE 2', 'Swimming'), '07:00', '08:00', [4]);

    expect(($this->classesOn)('2026-11-19'))->toBe(['PE 2 07:00', 'CS 101 10:30', 'MATH 21 13:00']);

    app(ArchiveSemester::class)->handle($this->user, $summer);
    expect(($this->classesOn)('2026-11-19'))->toBe(['CS 101 10:30', 'MATH 21 13:00']);

    Livewire::test('today.classes')
        ->assertSeeInOrder(['10:30 AM–12:00 PM', 'CS 101 Intro to Programming', 'Rm Lab 2', 'Lab', '1:00–2:30 PM', 'MATH 21 Calculus', 'Rm 301'])
        ->assertSee('2 classes')
        ->assertDontSee('Swimming');
});

test('no classes outside the semester\'s dates', function () {
    ($this->session)(($this->course)($this->sem, 'CS 101', 'Intro'), '08:00', '10:00', [1, 2, 3, 4, 5, 6, 7]);

    expect(($this->classesOn)('2026-12-18'))->toHaveCount(1)
        ->and(($this->classesOn)('2026-12-19'))->toBe([])
        ->and(($this->classesOn)('2026-08-09'))->toBe([]);
});

test('the class happening now is marked, in the user\'s timezone', function () {
    ($this->session)(($this->course)($this->sem, 'CS 101', 'Intro'), '08:00', '10:00', [4]);

    Livewire::test('today.classes')->assertSee('Now');

    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'Asia/Manila'));
    Livewire::test('today.classes')->assertDontSee('data-test="class-now"', false);
});

test('Today shows a friendly empty state', function () {
    Livewire::test('today.classes')->assertSee('No classes today.');
});

// School pages

test('every School tab loads, is in the sidebar, and guests are sent to login', function (string $route) {
    $this->get(route($route))->assertOk()->assertSee(route('school.courses'));

    auth()->logout();
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['school.courses', 'school.activities', 'school.semesters']);

test('School opens on the active semester; the picker switches', function () {
    $next = app(CreateSemester::class)->handle($this->user, ['name' => '2nd Sem', 'starts_on' => '2027-01-10', 'ends_on' => '2027-05-20']);
    ($this->course)($this->sem, 'CS 101', 'Intro to Programming');
    ($this->course)($next, 'CS 102', 'Programming 2');

    Livewire::test('pages::school.courses')
        ->assertSee('CS 101 Intro to Programming')
        ->assertDontSee('CS 102')
        ->set('semester', $next->id)
        ->assertSee('CS 102 Programming 2')
        ->assertDontSee('CS 101 Intro');
});

test('a course and a class time are added from the Courses tab', function () {
    Livewire::test('pages::school.courses')
        ->call('createCourse')
        ->set('code', 'CS 101')
        ->set('title', 'Intro to Programming')
        ->set('instructor', 'Prof. Cruz')
        ->call('saveCourse')
        ->assertHasNoErrors()
        ->assertSee('Prof. Cruz');

    $course = Course::sole();

    Livewire::test('pages::school.courses')
        ->call('addSession', $course->id)
        ->set('weekdays', ['1', '3', '5'])
        ->set('start_time', '08:00')
        ->set('end_time', '10:00')
        ->set('label', 'Lecture')
        ->call('saveSession')
        ->assertHasNoErrors()
        ->assertSee('Mon, Wed, Fri · 8:00–10:00 AM')
        ->assertSee('Lecture');
});

test('class time errors show on the form', function () {
    $course = ($this->course)($this->sem, 'CS 101', 'Intro');

    Livewire::test('pages::school.courses')
        ->call('addSession', $course->id)
        ->set('start_time', '10:00')
        ->set('end_time', '09:00')
        ->call('saveSession')
        ->assertHasErrors(['end_time', 'weekdays']);
});

test('activities are added, ticked and deleted from the Activities tab', function () {
    $course = ($this->course)($this->sem, 'CS 101', 'Intro');

    $page = Livewire::test('pages::school.activities')
        ->call('create')
        ->assertSet('course_id', (string) $course->id)
        ->set('type', 'exam')
        ->set('title', 'Midterm')
        ->set('due_on', '2026-11-27')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Midterm')
        ->assertSee('Due Fri, Nov 27');

    $activity = Activity::sole();
    expect(Task::sole()->title)->toBe('Exam: Midterm (CS 101)');

    $page->call('toggle', $activity->id)->assertDontSee('Midterm');
    expect(Task::sole()->completed_at)->not->toBeNull();

    $page->set('showDone', true)->assertSee('Midterm')->call('edit', $activity->id)->call('delete');
    expect(Activity::count())->toBe(0)->and(Task::count())->toBe(0);
});

test('the Courses tab\'s "Add activity" opens the form for that course', function () {
    ($this->course)($this->sem, 'CS 101', 'Intro');
    $math = ($this->course)($this->sem, 'MATH 21', 'Calculus');

    Livewire::withQueryParams(['course' => $math->id])
        ->test('pages::school.activities')
        ->assertSet('course_id', (string) $math->id);
});

test('overdue activities are marked', function () {
    app(CreateActivity::class)->handle($this->user, ['course_id' => ($this->course)($this->sem, 'CS 101', 'Intro')->id, 'type' => 'assignment', 'title' => 'Essay', 'due_on' => '2026-11-15']);

    Livewire::test('pages::school.activities')->assertSee('Overdue · was due Nov 15');
});

test('semesters are managed from the Semesters tab', function () {
    $page = Livewire::test('pages::school.semesters')
        ->assertSee('1st Sem')
        ->assertSee('Active')
        ->call('create')
        ->set('name', '2nd Sem')
        ->set('starts_on', '2027-01-10')
        ->set('ends_on', '2027-05-20')
        ->call('save')
        ->assertHasNoErrors();

    $next = Semester::where('name', '2nd Sem')->sole();

    $page->call('setActive', $next->id)->assertSet('semester', $next->id);
    expect($next->fresh()->is_active)->toBeTrue();

    $page->call('archive', $this->sem->id)->assertSee('Archived');
    $page->call('edit', $this->sem->id)->call('delete', $this->sem->id);
    expect(Semester::pluck('name')->all())->toBe(['2nd Sem']);
});

// Cross-user

test('semesters, courses, class times and activities are invisible to another user', function (Closure $make) {
    assertInvisibleToOtherUser(fn (User $owner) => $make($owner));
})->with([
    'semester' => fn (User $u) => app(CreateSemester::class)->handle($u, ['name' => 'S', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01']),
    'course' => fn (User $u) => app(CreateCourse::class)->handle($u, ['semester_id' => app(CreateSemester::class)->handle($u, ['name' => 'S', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01'])->id, 'title' => 'C']),
    'class time' => fn (User $u) => app(AddClassSession::class)->handle($u, app(CreateCourse::class)->handle($u, ['semester_id' => app(CreateSemester::class)->handle($u, ['name' => 'S', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01'])->id, 'title' => 'C']), ['start_time' => '08:00', 'end_time' => '09:00', 'weekdays' => [1]]),
    'activity' => fn (User $u) => app(CreateActivity::class)->handle($u, ['course_id' => app(CreateCourse::class)->handle($u, ['semester_id' => app(CreateSemester::class)->handle($u, ['name' => 'S', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01'])->id, 'title' => 'C'])->id, 'type' => 'quiz', 'title' => 'Q', 'due_on' => '2026-11-20']),
]);

test('another user gets a 404 from every Education action', function (Closure $call) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $semester = app(CreateSemester::class)->handle($owner, ['name' => 'Theirs', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01']);
    $course = app(CreateCourse::class)->handle($owner, ['semester_id' => $semester->id, 'title' => 'C']);
    $session = app(AddClassSession::class)->handle($owner, $course, ['start_time' => '08:00', 'end_time' => '09:00', 'weekdays' => [1]]);
    $activity = app(CreateActivity::class)->handle($owner, ['course_id' => $course->id, 'type' => 'quiz', 'title' => 'Q', 'due_on' => '2026-11-20']);
    $records = compact('semester', 'course', 'session', 'activity');
    $this->actingAs($this->user);

    expect(fn () => $call($this->user, $records))->toThrow(function (AuthorizationException $e) {
        expect($e->status())->toBe(404);
    });

    $this->actingAs($owner);
    expect(Semester::count())->toBe(1)->and(Course::count())->toBe(1)->and(ClassSession::count())->toBe(1)->and(Activity::count())->toBe(1)->and(Task::count())->toBe(1);
})->with([
    'edit semester' => fn (User $u, array $r) => app(UpdateSemester::class)->handle($u, $r['semester'], ['name' => 'X', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01']),
    'set active' => fn (User $u, array $r) => app(SetActiveSemester::class)->handle($u, $r['semester']),
    'archive' => fn (User $u, array $r) => app(ArchiveSemester::class)->handle($u, $r['semester']),
    'delete semester' => fn (User $u, array $r) => app(DeleteSemester::class)->handle($u, $r['semester']),
    'edit course' => fn (User $u, array $r) => app(UpdateCourse::class)->handle($u, $r['course'], ['title' => 'X']),
    'delete course' => fn (User $u, array $r) => app(DeleteCourse::class)->handle($u, $r['course']),
    'add class time' => fn (User $u, array $r) => app(AddClassSession::class)->handle($u, $r['course'], ['start_time' => '08:00', 'end_time' => '09:00', 'weekdays' => [1]]),
    'edit class time' => fn (User $u, array $r) => app(UpdateClassSession::class)->handle($u, $r['session'], ['start_time' => '08:00', 'end_time' => '09:00', 'weekdays' => [2]]),
    'remove class time' => fn (User $u, array $r) => app(RemoveClassSession::class)->handle($u, $r['session']),
    'edit activity' => fn (User $u, array $r) => app(UpdateActivity::class)->handle($u, $r['activity'], ['course_id' => $r['course']->id, 'type' => 'quiz', 'title' => 'X', 'due_on' => '2026-11-20']),
    'delete activity' => fn (User $u, array $r) => app(DeleteActivity::class)->handle($u, $r['activity']),
]);

test('a course or activity can\'t go into another user\'s semester or course', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $theirSemester = app(CreateSemester::class)->handle($owner, ['name' => 'Theirs', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01']);
    $theirCourse = app(CreateCourse::class)->handle($owner, ['semester_id' => $theirSemester->id, 'title' => 'C']);
    $this->actingAs($this->user);

    expect(fn () => app(CreateCourse::class)->handle($this->user, ['semester_id' => $theirSemester->id, 'title' => 'Sneaky']))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateActivity::class)->handle($this->user, ['course_id' => $theirCourse->id, 'type' => 'quiz', 'title' => 'Sneaky', 'due_on' => '2026-11-20']))->toThrow(ValidationException::class);
});

test('another user\'s ids return 404 on the pages', function (string $page, string $method, string $what) {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $semester = app(CreateSemester::class)->handle($owner, ['name' => 'Theirs', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-01']);
    $course = app(CreateCourse::class)->handle($owner, ['semester_id' => $semester->id, 'title' => 'C']);
    $session = app(AddClassSession::class)->handle($owner, $course, ['start_time' => '08:00', 'end_time' => '09:00', 'weekdays' => [1]]);
    $activity = app(CreateActivity::class)->handle($owner, ['course_id' => $course->id, 'type' => 'quiz', 'title' => 'Q', 'due_on' => '2026-11-20']);
    $ids = ['semester' => $semester->id, 'course' => $course->id, 'session' => $session->id, 'activity' => $activity->id];
    $this->actingAs($this->user);

    Livewire::test($page)->call($method, $ids[$what])->assertNotFound();
})->with([
    ['pages::school.semesters', 'edit', 'semester'],
    ['pages::school.semesters', 'setActive', 'semester'],
    ['pages::school.semesters', 'archive', 'semester'],
    ['pages::school.courses', 'editCourse', 'course'],
    ['pages::school.courses', 'addSession', 'course'],
    ['pages::school.courses', 'editSession', 'session'],
    ['pages::school.courses', 'removeSession', 'session'],
    ['pages::school.activities', 'edit', 'activity'],
    ['pages::school.activities', 'toggle', 'activity'],
]);

test('another user\'s school records never show', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    $semester = app(CreateSemester::class)->handle($owner, ['name' => 'Their secret sem', 'starts_on' => '2026-08-01', 'ends_on' => '2026-12-31']);
    $course = app(CreateCourse::class)->handle($owner, ['semester_id' => $semester->id, 'title' => 'Their secret course']);
    app(AddClassSession::class)->handle($owner, $course, ['start_time' => '08:00', 'end_time' => '09:00', 'weekdays' => [4]]);
    $this->actingAs($this->user);

    foreach (['school.courses', 'school.activities', 'school.semesters', 'today'] as $route) {
        $this->get(route($route))->assertDontSee('Their secret');
    }
});

test('locked ids can\'t be changed from the browser', function (string $page, string $property) {
    Livewire::test($page)->set($property, 1);
})->with([
    ['pages::school.semesters', 'editingId'],
    ['pages::school.courses', 'editingCourseId'],
    ['pages::school.courses', 'sessionCourseId'],
    ['pages::school.courses', 'editingSessionId'],
    ['pages::school.activities', 'editingId'],
])->throws(CannotUpdateLockedPropertyException::class);
