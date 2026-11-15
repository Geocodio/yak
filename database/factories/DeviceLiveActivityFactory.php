<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceLiveActivity;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceLiveActivity>
 */
class DeviceLiveActivityFactory extends Factory
{
    protected $model = DeviceLiveActivity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'device_id' => Device::factory()->phone(),
            'thread_id' => Thread::factory(),
            'source' => 'app',
            'started_at' => now(),
        ];
    }
}
