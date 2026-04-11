<?php

namespace App\Jobs\Middleware;

use App\Models\DailyCost;
use Closure;
use Illuminate\Support\Facades\Log;

class EnsureDailyBudget
{
    /**
     * @param  Closure(object): void  $next
     */
    public function handle(object $job, Closure $next): void
    {
        $dailyBudget = (float) config('yak.daily_budget_usd');
        $today = now()->toDateString();

        $dailyCost = DailyCost::whereDate('date', $today)->first();
        $totalSpent = $dailyCost !== null ? (float) $dailyCost->total_usd : 0.0;

        if ($totalSpent >= $dailyBudget) {
            Log::warning('Daily budget exceeded, skipping job', [
                'daily_budget_usd' => $dailyBudget,
                'total_spent_usd' => $totalSpent,
                'job' => $job::class,
            ]);

            if (method_exists($job, 'fail')) {
                $job->fail(new \RuntimeException(
                    sprintf('Daily budget of $%.2f exceeded (spent $%.2f today)', $dailyBudget, $totalSpent)
                ));
            }

            return;
        }

        $next($job);
    }
}
