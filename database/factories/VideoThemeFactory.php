<?php

namespace Database\Factories;

use App\Models\VideoTheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VideoTheme>
 */
class VideoThemeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'theme' => (array) config('yak.video.theme'),
            'logo_path' => null,
            'updated_by' => null,
        ];
    }
}
