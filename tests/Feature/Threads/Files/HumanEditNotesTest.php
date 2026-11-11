<?php

use App\Enums\SteeringMode;
use App\Models\Device;
use App\Models\PendingSteeringMessage;
use App\Models\ThreadFileEdit;
use App\Models\User;
use App\Services\Threads\Files\HumanEditNotes;
use App\Services\Threads\SteeringDelivery;

require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

beforeEach(function () {
    [$this->thread, $this->task] = threadWithTask();
    $this->user = User::factory()->create();
    $this->delivers = true;
    $this->delivery = Mockery::mock(SteeringDelivery::class);
    $this->delivery->shouldReceive('deliver')->andReturnUsing(fn () => $this->delivers);
    app()->instance(SteeringDelivery::class, $this->delivery);
});

it('wraps paths relative to the workspace with fixed copy', function () {
    expect(HumanEditNotes::wrap(['/workspace/widgets/src/a.ts', '/home/yak/.bashrc']))
        ->toBe("The person edited these files while you were working. Re-read them before you continue:\n- widgets/src/a.ts\n- /home/yak/.bashrc");
});

it('steers the agent about an edited file without including its content', function () {
    $edit = app(HumanEditNotes::class)->record($this->thread, $this->user, '/workspace/widgets/src/a.ts', hash('sha256', 'SECRET-BODY'));

    $message = PendingSteeringMessage::query()->sole();
    expect($message->text)->toBe(HumanEditNotes::wrap(['/workspace/widgets/src/a.ts']))
        ->and($message->text)->not->toContain('SECRET-BODY')
        ->and($message->mode)->toBe(SteeringMode::Steer)
        ->and($edit->noted_at)->not->toBeNull()
        ->and($edit->pending_steering_message_id)->toBe($message->id);
});

it('queues the note when no turn is running', function () {
    $this->delivers = false;

    app(HumanEditNotes::class)->record($this->thread, $this->user, '/workspace/widgets/a.ts', 'a');

    expect(PendingSteeringMessage::query()->sole()->mode)->toBe(SteeringMode::Queue);
});

it('sends one steer when the same file is saved twice before delivery', function () {
    $notes = app(HumanEditNotes::class);
    $notes->record($this->thread, $this->user, '/workspace/widgets/a.ts', 'one');
    $second = $notes->record($this->thread, $this->user, '/workspace/widgets/a.ts', 'two');

    expect(PendingSteeringMessage::query()->count())->toBe(1)
        ->and(ThreadFileEdit::query()->count())->toBe(2)
        ->and($second->noted_at)->not->toBeNull();
});

it('announces the file again once the earlier note was delivered', function () {
    $notes = app(HumanEditNotes::class);
    $notes->record($this->thread, $this->user, '/workspace/widgets/a.ts', 'one');
    PendingSteeringMessage::query()->update(['delivered_at' => now()]);
    $notes->record($this->thread, $this->user, '/workspace/widgets/a.ts', 'two');

    expect(PendingSteeringMessage::query()->count())->toBe(2);
});

it('stores the edit and sends nothing for a device-owned thread', function () {
    $this->thread->forceFill(['owner_side' => 'device', 'owner_device_id' => Device::factory()->create()->id])->save();

    $edit = app(HumanEditNotes::class)->record($this->thread, $this->user, '/workspace/widgets/a.ts', 'a');

    expect(PendingSteeringMessage::query()->count())->toBe(0)
        ->and($edit->noted_at)->toBeNull()
        ->and(ThreadFileEdit::query()->count())->toBe(1);
});
