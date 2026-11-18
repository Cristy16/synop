<?php

use App\Actions\Education\AddClassSession;
use App\Actions\Education\CreateCourse;
use App\Actions\Education\DeleteCourse;
use App\Actions\Education\RemoveClassSession;
use App\Actions\Education\UpdateClassSession;
use App\Actions\Education\UpdateCourse;
use App\Models\ClassSession;
use App\Models\Course;
use App\Models\ScheduleRule;
use App\Models\Semester;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('School')] class extends Component {
    /** The semester shown (kept in the address bar); empty = the active one. */
    #[Url]
    public ?int $semester = null;

    // Course window. Ids are locked against tampering.
    #[Locked]
    public ?int $editingCourseId = null;
    public string $code = '';
    public string $title = '';
    public string $instructor = '';
    public string $room = '';

    // Class time window.
    #[Locked]
    public ?int $sessionCourseId = null;
    #[Locked]
    public ?int $editingSessionId = null;
    public string $start_time = '08:00';
    public string $end_time = '10:00';
    /** @var list<string> */
    public array $weekdays = [];
    public string $session_room = '';
    public string $label = '';

    #[Computed]
    public function current(): ?Semester
    {
        return Semester::pick($this->semester);
    }

    /**
     * @return Collection<int, Semester>
     */
    #[Computed]
    public function semesters(): Collection
    {
        return Semester::orderByRaw('archived_at is not null')->latest('starts_on')->get();
    }

    /**
     * @return Collection<int, Course>
     */
    #[Computed]
    public function courses(): Collection
    {
        if ($this->current === null) {
            return new Collection;
        }

        $today = now(Auth::user()->timezone)->toDateString();

        return Course::where('semester_id', $this->current->id)
            ->with(['sessions', 'activities' => fn ($q) => $q->whereDate('due_on', '>=', $today)->limit(50)])
            ->orderBy('code')
            ->orderBy('title')
            ->get();
    }

    /**
     * Each class time's newest schedule, keyed by session id.
     *
     * @return \Illuminate\Support\Collection<int, ScheduleRule>
     */
    #[Computed]
    public function schedules(): \Illuminate\Support\Collection
    {
        return ScheduleRule::where('schedulable_type', 'class_session')->orderBy('starts_on')->get()->keyBy('schedulable_id');
    }

    // Courses

    public function createCourse(): void
    {
        $this->resetCourseForm();
        Flux::modal('course-form')->show();
    }

    public function editCourse(int $id): void
    {
        $course = Course::findOrFail($id);

        $this->resetCourseForm();
        $this->editingCourseId = $course->id;
        $this->code = $course->code ?? '';
        $this->title = $course->title;
        $this->instructor = $course->instructor ?? '';
        $this->room = $course->room ?? '';

        Flux::modal('course-form')->show();
    }

    public function saveCourse(CreateCourse $create, UpdateCourse $update): void
    {
        // Clear errors from an earlier try: the Actions validate, not this page.
        $this->resetValidation();
        $input = ['semester_id' => $this->current?->id, 'code' => $this->code, 'title' => $this->title, 'instructor' => $this->instructor, 'room' => $this->room];

        $this->editingCourseId === null
            ? $create->handle(Auth::user(), $input)
            : $update->handle(Auth::user(), Course::findOrFail($this->editingCourseId), $input);

        Flux::modal('course-form')->close();
        Flux::toast(variant: 'success', text: __('Course saved.'));
        $this->resetCourseForm();
    }

    public function deleteCourse(int $id, DeleteCourse $delete): void
    {
        $delete->handle(Auth::user(), Course::findOrFail($id));

        Flux::modal('course-form')->close();
        Flux::toast(text: __('Course deleted.'));
        $this->resetCourseForm();
    }

    // Class times

    public function addSession(int $courseId): void
    {
        $this->resetSessionForm();
        $this->sessionCourseId = Course::findOrFail($courseId)->id;

        Flux::modal('session-form')->show();
    }

    public function editSession(int $id): void
    {
        $session = ClassSession::findOrFail($id);
        $rule = $session->latestRule();

        $this->resetSessionForm();
        $this->editingSessionId = $session->id;
        $this->sessionCourseId = $session->course_id;
        $this->start_time = substr($session->start_time, 0, 5);
        $this->end_time = substr($session->end_time, 0, 5);
        $this->weekdays = array_map('strval', $rule->weekdays ?? []);
        $this->session_room = $session->room ?? '';
        $this->label = $session->label ?? '';

        Flux::modal('session-form')->show();
    }

    public function saveSession(AddClassSession $add, UpdateClassSession $update): void
    {
        $this->resetValidation();
        $input = [
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'weekdays' => array_map('intval', $this->weekdays),
            'room' => $this->session_room,
            'label' => $this->label,
        ];

        try {
            $this->editingSessionId === null
                ? $add->handle(Auth::user(), Course::findOrFail($this->sessionCourseId), $input)
                : $update->handle(Auth::user(), ClassSession::findOrFail($this->editingSessionId), $input);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // "weekdays.0" belongs to the weekdays field; the room field is named session_room here.
            throw \Illuminate\Validation\ValidationException::withMessages(collect($e->errors())->mapWithKeys(
                fn (array $messages, string $key) => [match (explode('.', $key)[0]) { 'room' => 'session_room', 'starts_on', 'ends_on' => 'weekdays', default => explode('.', $key)[0] } => $messages]
            )->all());
        }

        Flux::modal('session-form')->close();
        Flux::toast(variant: 'success', text: __('Class time saved.'));
        $this->resetSessionForm();
    }

    public function removeSession(int $id, RemoveClassSession $remove): void
    {
        $remove->handle(Auth::user(), ClassSession::findOrFail($id));

        Flux::toast(text: __('Class time removed.'));
    }

    private function resetCourseForm(): void
    {
        $this->resetValidation();
        $this->reset('editingCourseId', 'code', 'title', 'instructor', 'room');
    }

    private function resetSessionForm(): void
    {
        $this->resetValidation();
        $this->reset('sessionCourseId', 'editingSessionId', 'start_time', 'end_time', 'weekdays', 'session_room', 'label');
    }
}; ?>

<div class="mx-auto w-full max-w-3xl space-y-6">
    <x-school.tabs current="school.courses" :semester="$this->current" :semesters="$this->semesters" />

    @if ($this->current === null)
        <div class="rounded-xl border border-zinc-200 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
            {{ __('Add your semester first.') }}
            <div class="mt-3"><flux:button size="sm" :href="route('school.semesters')" wire:navigate>{{ __('Go to Semesters') }}</flux:button></div>
        </div>
    @else
        <div class="flex items-center justify-between gap-3">
            <flux:text class="text-sm">
                {{ $this->current->starts_on->format('M j') }} – {{ $this->current->ends_on->format('M j, Y') }}
                @if ($this->current->isArchived()) · {{ __('Archived: its classes are hidden from Today') }}@endif
            </flux:text>
            <flux:button variant="primary" icon="plus" wire:click="createCourse" data-test="add-course-button">{{ __('Add course') }}</flux:button>
        </div>

        @forelse ($this->courses as $course)
            <section wire:key="course-{{ $course->id }}" class="rounded-xl border border-zinc-200 dark:border-zinc-700" data-test="course">
                <header class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <button type="button" wire:click="editCourse({{ $course->id }})" class="block w-full text-start">
                        <flux:heading level="2">{{ $course->displayName() }}</flux:heading>
                        <flux:text class="text-xs">
                            {{ collect([$course->instructor, $course->room ? __('Rm :room', ['room' => $course->room]) : null])->filter()->implode(' · ') ?: __('No instructor or room yet') }}
                        </flux:text>
                    </button>
                </header>

                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($course->sessions as $session)
                        @php $rule = $this->schedules->get($session->id); @endphp
                        <li wire:key="session-{{ $session->id }}" class="flex items-center gap-3 px-4 py-2 text-sm">
                            <flux:icon name="clock" variant="mini" class="text-zinc-400" />
                            <button type="button" wire:click="editSession({{ $session->id }})" class="min-w-0 flex-1 text-start">
                                {{ $rule?->summary() }} · {{ $session->timeRange() }}
                                <span class="text-zinc-500 dark:text-zinc-400">
                                    @if ($session->roomOr($course)) · {{ __('Rm :room', ['room' => $session->roomOr($course)]) }}@endif
                                    @if ($session->label) · {{ $session->label }}@endif
                                </span>
                            </button>
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="removeSession({{ $session->id }})" wire:confirm="{{ __('Remove this class time?') }}" :aria-label="__('Remove class time')" />
                        </li>
                    @endforeach
                    @foreach ($course->activities->take(3) as $activity)
                        <li wire:key="course-activity-{{ $activity->id }}" class="flex items-center gap-3 px-4 py-2 text-sm">
                            <flux:icon name="pencil-square" variant="mini" class="text-zinc-400" />
                            <span class="min-w-0 flex-1">
                                {{ __($activity->type->label()) }}: {{ $activity->title }}
                                <span class="text-zinc-500 dark:text-zinc-400">· {{ __('due :date', ['date' => $activity->due_on->format('D, M j')]) }}</span>
                            </span>
                        </li>
                    @endforeach
                    <li class="flex flex-wrap gap-2 px-4 py-2">
                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="addSession({{ $course->id }})" data-test="add-session-{{ $course->id }}">{{ __('Add class time') }}</flux:button>
                        <flux:button size="sm" variant="ghost" icon="plus" :href="route('school.activities', ['semester' => $this->current->id, 'course' => $course->id])" wire:navigate>{{ __('Add activity') }}</flux:button>
                    </li>
                </ul>
            </section>
        @empty
            <div class="rounded-xl border border-zinc-200 px-4 py-8 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                {{ __('No courses in this semester yet.') }}
            </div>
        @endforelse
    @endif

    {{-- Course window --}}
    <flux:modal name="course-form" class="w-full max-w-md">
        <form wire:submit="saveCourse" class="space-y-5">
            <flux:heading size="lg">{{ $editingCourseId === null ? __('Add course') : __('Edit course') }}</flux:heading>
            <div class="grid grid-cols-3 gap-3">
                <flux:input wire:model="code" :label="__('Code')" :placeholder="__('CS 101')" />
                <div class="col-span-2"><flux:input wire:model="title" :label="__('Title')" required /></div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="instructor" :label="__('Instructor')" />
                <flux:input wire:model="room" :label="__('Room')" />
            </div>
            <flux:error name="semester_id" />
            <div class="flex flex-wrap items-center justify-between gap-3">
                @if ($editingCourseId !== null)
                    <flux:button variant="danger" wire:click="deleteCourse({{ $editingCourseId }})"
                        wire:confirm="{{ __('Delete this course with its class times and activities (and their tasks)?') }}">{{ __('Delete') }}</flux:button>
                @endif
                <div class="ms-auto flex gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-course-button">{{ __('Save') }}</flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Class time window --}}
    <flux:modal name="session-form" class="w-full max-w-md">
        <form wire:submit="saveSession" class="space-y-5">
            <flux:heading size="lg">{{ $editingSessionId === null ? __('Add class time') : __('Edit class time') }}</flux:heading>
            <flux:checkbox.group wire:model="weekdays" :label="__('Days')" class="flex flex-wrap gap-x-4 gap-y-2">
                @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $value => $name)
                    <flux:checkbox :value="(string) $value" :label="__($name)" />
                @endforeach
            </flux:checkbox.group>
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="start_time" type="time" :label="__('From')" required />
                <flux:input wire:model="end_time" type="time" :label="__('To')" required />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="session_room" :label="__('Room')" :placeholder="__('Course room if empty')" />
                <flux:input wire:model="label" :label="__('Label')" :placeholder="__('e.g. Lecture, Lab')" />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-session-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
