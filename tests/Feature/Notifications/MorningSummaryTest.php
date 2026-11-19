<?php

use App\Actions\Finance\CreateDebt;
use App\Actions\Notifications\RemovePushSubscription;
use App\Actions\Notifications\SavePushSubscription;
use App\Actions\Notifications\SendMorningSummaries;
use App\Actions\Notifications\UpdateNotificationSettings;
use App\Models\Contact;
use App\Models\Task;
use App\Models\User;
use App\Notifications\MorningSummary as MorningSummaryNotification;
use App\Services\MorningSummary;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use NotificationChannels\WebPush\PushSubscription;

function pushSub(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
{
    return ['endpoint' => $endpoint, 'keys' => ['p256dh' => str_repeat('B', 87), 'auth' => str_repeat('a', 22)]];
}

beforeEach(function () {
    config(['webpush.vapid.public_key' => 'test-public', 'webpush.vapid.private_key' => 'test-private']);
    Notification::fake();

    $this->user = User::factory()->create(['morning_summary_time' => '07:00:00']); // Asia/Manila
    app(SavePushSubscription::class)->handle($this->user, pushSub());
    $this->at = fn (string $manila) => $this->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila'));
    $this->send = fn () => app(SendMorningSummaries::class)->handle();
});

// When it's sent

test('it is sent once the user\'s local time has passed, once a day', function () {
    ($this->at)('2026-11-19 06:58');
    expect(($this->send)())->toBe(0);

    ($this->at)('2026-11-19 07:02');
    expect(($this->send)())->toBe(1);
    Notification::assertSentTo($this->user, MorningSummaryNotification::class);

    ($this->at)('2026-11-19 07:07');
    expect(($this->send)())->toBe(0, 'not twice');

    ($this->at)('2026-11-20 07:01');
    expect(($this->send)())->toBe(1, 'again the next day');
});

test('it follows each user\'s own timezone', function () {
    $tokyo = User::factory()->create(['timezone' => 'Asia/Tokyo', 'morning_summary_time' => '07:00:00']);
    app(SavePushSubscription::class)->handle($tokyo, pushSub('https://fcm.googleapis.com/fcm/send/tokyo'));

    // 7:01 am in Tokyo is 6:01 am in Manila.
    ($this->at)('2026-11-19 06:01');
    ($this->send)();

    Notification::assertSentTo($tokyo, MorningSummaryNotification::class);
    Notification::assertNotSentTo($this->user, MorningSummaryNotification::class);
});

test('a scheduler that was down doesn\'t send it hours late', function () {
    ($this->at)('2026-11-19 10:01');

    expect(($this->send)())->toBe(0);
});

test('nothing is sent when it\'s off, there\'s no device, or push isn\'t set up', function () {
    ($this->at)('2026-11-19 07:02');
    User::factory()->create(['morning_summary_time' => '07:00:00']); // no device
    $off = User::factory()->create(['morning_summary_time' => null]);
    app(SavePushSubscription::class)->handle($off, pushSub('https://fcm.googleapis.com/fcm/send/off'));

    config(['webpush.vapid.private_key' => null]);
    expect(($this->send)())->toBe(0);

    config(['webpush.vapid.private_key' => 'test-private']);
    expect(($this->send)())->toBe(1);
    Notification::assertSentTimes(MorningSummaryNotification::class, 1);
});

test('the scheduler runs the command every five minutes', function () {
    ($this->at)('2026-11-19 07:02');

    $this->artisan('synop:morning-summaries')->assertSuccessful();
    Notification::assertSentTo($this->user, MorningSummaryNotification::class);

    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'synop:morning-summaries'));
    expect($event?->expression)->toBe('*/5 * * * *');
});

// What it says

test('the summary counts today\'s things, without names or amounts', function () {
    ($this->at)('2026-11-19 07:02');
    $this->actingAs($this->user);
    Task::factory()->count(2)->create(['due_on' => '2026-11-19']);
    Task::factory()->create(['due_on' => '2026-11-19'])->forceFill(['completed_at' => now()])->save();
    $contact = Contact::factory()->create(['name' => 'Tita Mercedes']);
    app(CreateDebt::class)->handle($this->user, ['contact_id' => $contact->id, 'direction' => 'i_owe', 'principal' => '1,234', 'started_on' => '2026-11-01', 'due_on' => '2026-11-20']);
    Auth::logout();

    ($this->send)();

    Notification::assertSentTo($this->user, MorningSummaryNotification::class, function ($n) {
        expect($n->title)->toBe('Good morning! Your Thursday')
            ->and($n->body)->toBe('2 tasks · 1 payment due soon')
            ->and($n->body)->not->toContain('Mercedes')->not->toContain('1,234');

        return true;
    });
    expect(Auth::user())->toBeNull('no one is left logged in');
});

test('an empty day says so', function () {
    ($this->at)('2026-11-19 07:02');

    expect(app(MorningSummary::class)->for($this->user)['body'])->toBe('Nothing scheduled today.');
});

test('the push opens Today', function () {
    $message = (new MorningSummaryNotification('Hi', 'There'))->toWebPush($this->user, new MorningSummaryNotification('Hi', 'There'))->toArray();

    expect($message['data'])->toBe(['url' => '/today'])
        ->and($message['title'])->toBe('Hi');
});

// Devices

test('only real browser push services are accepted', function (string $endpoint, bool $ok) {
    expect(SavePushSubscription::isAllowedEndpoint($endpoint))->toBe($ok);
})->with([
    ['https://fcm.googleapis.com/fcm/send/x', true],
    ['https://updates.push.services.mozilla.com/wpush/v2/x', true],
    ['https://web.push.apple.com/x', true],
    ['https://wns2-par02p.notify.windows.com/w/?token=x', true],
    ['http://fcm.googleapis.com/fcm/send/x', false],
    ['https://fcm.googleapis.com:8443/x', false],
    ['https://user:pw@fcm.googleapis.com/x', false],
    ['https://fcm.googleapis.com.evil.test/x', false],
    ['https://169.254.169.254/latest/meta-data', false],
    ['https://localhost/x', false],
    ['https://notify.windows.com.evil.test/x', false],
]);

test('bad subscriptions are refused', function (array $sub) {
    expect(fn () => app(SavePushSubscription::class)->handle($this->user, $sub))->toThrow(ValidationException::class);
})->with([
    'internal address' => [['endpoint' => 'https://10.0.0.1/x', 'keys' => pushSub()['keys']]],
    'no keys' => [['endpoint' => 'https://fcm.googleapis.com/fcm/send/z']],
    'bad key' => [['endpoint' => 'https://fcm.googleapis.com/fcm/send/z', 'keys' => ['p256dh' => 'x<script>', 'auth' => str_repeat('a', 22)]]],
]);

test('a browser that another account used moves to this one', function () {
    $other = User::factory()->create();

    app(SavePushSubscription::class)->handle($other, pushSub());

    expect(PushSubscription::count())->toBe(1)
        ->and(PushSubscription::sole()->subscribable_id)->toBe($other->id);
});

test('a user can only remove their own devices', function () {
    $other = User::factory()->create();

    app(RemovePushSubscription::class)->handle($other, pushSub()['endpoint']);
    expect(PushSubscription::count())->toBe(1);

    app(RemovePushSubscription::class)->handle($this->user, pushSub()['endpoint']);
    expect(PushSubscription::count())->toBe(0);
});

// Settings

test('the time is set or turned off in settings', function () {
    $save = fn (array $input) => app(UpdateNotificationSettings::class)->handle($this->user, $input);

    $save(['morning_summary_enabled' => true, 'morning_summary_time' => '06:30']);
    expect($this->user->fresh()->morning_summary_time)->toStartWith('06:30');

    $save(['morning_summary_enabled' => false, 'morning_summary_time' => '']);
    expect($this->user->fresh()->morning_summary_time)->toBeNull();

    expect(fn () => $save(['morning_summary_enabled' => true, 'morning_summary_time' => '25:00']))->toThrow(ValidationException::class);
});

test('moving the time later today lets today\'s summary still come', function () {
    ($this->at)('2026-11-19 07:02');
    ($this->send)();

    app(UpdateNotificationSettings::class)->handle($this->user, ['morning_summary_enabled' => true, 'morning_summary_time' => '08:00']);
    ($this->at)('2026-11-19 08:01');

    expect(($this->send)())->toBe(1);
});

test('the Notifications settings page saves, lists devices and sends a test', function () {
    $this->actingAs($this->user);

    $this->get(route('notifications.edit'))->assertOk()->assertSee('Morning summary');

    Livewire::test('pages::settings.notifications')
        ->assertSet('morning_summary_enabled', true)
        ->assertSet('morning_summary_time', '07:00')
        ->assertSee('Sends to 1 device.')
        ->call('subscribe', pushSub('https://web.push.apple.com/phone'))
        ->assertSee('Sends to 2 devices.')
        ->set('morning_summary_time', '06:15')
        ->call('save')
        ->assertHasNoErrors()
        ->call('sendTest');

    expect($this->user->fresh()->morning_summary_time)->toStartWith('06:15');
    Notification::assertSentTo($this->user, MorningSummaryNotification::class);
});

test('without VAPID keys the page says push isn\'t set up', function () {
    config(['webpush.vapid.public_key' => null]);
    $this->actingAs($this->user);

    Livewire::test('pages::settings.notifications')->assertSee('aren\'t set up on this server yet');
});

test('"Send a test" works 3 times every 10 minutes', function () {
    $this->actingAs($this->user);
    $page = Livewire::test('pages::settings.notifications');

    $page->call('sendTest')->call('sendTest')->call('sendTest')->assertHasNoErrors();
    $page->call('sendTest')->assertHasErrors('push')->assertSee('enough tests for now');
    Notification::assertSentTimes(MorningSummaryNotification::class, 3);

    $this->travel(11)->minutes();
    $page->call('sendTest')->assertHasNoErrors();
    Notification::assertSentTimes(MorningSummaryNotification::class, 4);
});
