<?php

use App\Services\Threads\BackfillThreadOrigin;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(BackfillThreadOrigin::class)();
    }
};
