<div>
    <h1 class="text-2xl font-semibold text-yak-slate mb-5">Costs</h1>

    {{-- Period Selector --}}
    <div class="flex flex-wrap gap-3 mb-8">
        <div class="flex gap-2">
            @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'] as $key => $label)
                <button
                    wire:click="setPeriod('{{ $key }}')"
                    class="px-5 py-2 rounded-[14px] text-sm font-medium transition-colors {{ $period === $key ? 'bg-yak-orange text-white hover:bg-yak-orange-warm' : 'text-yak-blue hover:bg-yak-cream-dark' }}"
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="flex gap-2 ml-auto">
            <flux:select wire:model.live="repo" class="min-w-40">
                <flux:select.option value="">All Repos</flux:select.option>
                @foreach ($this->repos as $repoOption)
                    <flux:select.option value="{{ $repoOption }}">{{ $repoOption }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="source" class="min-w-40">
                <flux:select.option value="">All Sources</flux:select.option>
                @foreach ($this->allSources as $sourceOption)
                    <flux:select.option value="{{ $sourceOption }}">{{ ucfirst($sourceOption) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    {{-- Stat Cards --}}
    @php $summary = $this->summary; @endphp
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-5 mb-8">
        <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
            <div class="text-xs font-normal text-yak-blue uppercase tracking-wider mb-2">Total Cost</div>
            <div class="text-[28px] font-semibold text-yak-slate mb-1">${{ $summary['total_cost'] }}</div>
            <div class="text-xs text-yak-blue">{{ $summary['task_count'] }} tasks</div>
        </div>
        <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
            <div class="text-xs font-normal text-yak-blue uppercase tracking-wider mb-2">Task Count</div>
            <div class="text-[28px] font-semibold text-yak-slate mb-1">{{ $summary['task_count'] }}</div>
            <div class="text-xs text-yak-blue">in period</div>
        </div>
        <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
            <div class="text-xs font-normal text-yak-blue uppercase tracking-wider mb-2">Avg Cost</div>
            <div class="text-[28px] font-semibold text-yak-slate mb-1">${{ $summary['avg_cost'] }}</div>
            <div class="text-xs text-yak-blue">per task</div>
        </div>
        <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
            <div class="text-xs font-normal text-yak-blue uppercase tracking-wider mb-2">Avg Duration</div>
            <div class="text-[28px] font-semibold text-yak-slate mb-1">{{ $summary['avg_duration'] }}</div>
            <div class="text-xs text-yak-blue">per task</div>
        </div>
        <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
            <div class="text-xs font-normal text-yak-blue uppercase tracking-wider mb-2">Success Rate</div>
            <div class="text-[28px] font-semibold text-yak-slate mb-1">{{ $summary['success_rate'] }}</div>
            <div class="text-xs text-yak-blue">of tasks</div>
        </div>
        <div class="bg-white/75 backdrop-blur-[40px] backdrop-saturate-[1.4] border border-white/60 rounded-[28px] shadow-yak p-6 text-center">
            <div class="text-xs font-normal text-yak-blue uppercase tracking-wider mb-2">Clarification Rate</div>
            <div class="text-[28px] font-semibold text-yak-slate mb-1">{{ $summary['clarification_rate'] }}</div>
            <div class="text-xs text-yak-blue">of tasks</div>
        </div>
    </div>

    {{-- 30-Day Bar Chart --}}
    @php
        $chartData = $this->chartData;
        $maxVal = $chartData->max('total_usd') ?: 1;
        $yMax = ceil($maxVal);
        if ($yMax < 1) $yMax = 1;
        $barCount = $chartData->count();
        $chartWidth = 800;
        $chartHeight = 260;
        $leftMargin = 36;
        $rightMargin = 12;
        $topY = 25;
        $bottomY = 225;
        $availWidth = $chartWidth - $leftMargin - $rightMargin;
        $barWidth = $barCount > 0 ? max(4, (int)(($availWidth / $barCount) - 2)) : 20;
        $gap = $barCount > 0 ? ($availWidth - ($barWidth * $barCount)) / max(1, $barCount) : 0;
        $isToday = fn($date) => \Carbon\Carbon::parse($date)->isToday();
    @endphp
    <div class="bg-white border border-yak-tan/40 rounded-[28px] shadow-yak p-8 mb-8">
        <h2 class="text-lg font-medium text-yak-slate mb-6">Daily Spend &mdash; {{ ucfirst($period) }} View</h2>
        <div class="w-full overflow-hidden">
            @if ($barCount > 0)
                <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" class="w-full h-auto block">
                    {{-- Y-axis labels --}}
                    @for ($i = 0; $i <= 4; $i++)
                        @php
                            $yVal = $yMax * (4 - $i) / 4;
                            $yPos = $topY + ($bottomY - $topY) * $i / 4;
                        @endphp
                        <text x="28" y="{{ $yPos + 4 }}" font-family="Outfit, sans-serif" font-size="11" fill="#6b8fa3" text-anchor="end">${{ number_format($yVal, 0) }}</text>
                        <line x1="{{ $leftMargin }}" y1="{{ $yPos }}" x2="{{ $chartWidth - $rightMargin }}" y2="{{ $yPos }}" stroke="#e8e0d2" stroke-width="0.5"/>
                    @endfor

                    {{-- Bars --}}
                    @foreach ($chartData as $index => $day)
                        @php
                            $val = (float) $day->total_usd;
                            $barHeight = $yMax > 0 ? ($val / $yMax) * ($bottomY - $topY) : 0;
                            $barHeight = max($barHeight, 2);
                            $x = $leftMargin + ($index * ($barWidth + $gap)) + ($gap / 2);
                            $y = $bottomY - $barHeight;
                            $fill = $isToday($day->date) ? '#c4744a' : '#8fb3c4';
                        @endphp
                        <rect x="{{ $x }}" y="{{ $y }}" width="{{ $barWidth }}" height="{{ $barHeight }}" rx="4" ry="4" fill="{{ $fill }}"/>
                        @if ($index % max(1, intdiv($barCount, 6)) === 0 || $index === $barCount - 1)
                            <text x="{{ $x + $barWidth / 2 }}" y="{{ $chartHeight - 12 }}" font-family="Outfit, sans-serif" font-size="11" fill="#6b8fa3" text-anchor="middle">{{ \Carbon\Carbon::parse($day->date)->format('M j') }}</text>
                        @endif
                    @endforeach
                </svg>
            @else
                <p class="text-center text-yak-blue py-12">No cost data for this period.</p>
            @endif
        </div>
    </div>

    {{-- Breakdown Table --}}
    @php $breakdown = $this->breakdown; @endphp
    <div class="bg-white border border-yak-tan/40 rounded-[28px] shadow-yak p-8">
        <h2 class="text-lg font-medium text-yak-slate mb-6">Daily Breakdown</h2>
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr>
                    <th class="bg-yak-cream-dark px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-yak-blue border-b border-yak-tan/40 first:rounded-tl-[14px]">Date</th>
                    <th class="bg-yak-cream-dark px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-yak-blue border-b border-yak-tan/40">Tasks</th>
                    <th class="bg-yak-cream-dark px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-yak-blue border-b border-yak-tan/40">Slack</th>
                    <th class="bg-yak-cream-dark px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-yak-blue border-b border-yak-tan/40">Linear</th>
                    <th class="bg-yak-cream-dark px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-yak-blue border-b border-yak-tan/40">Sentry</th>
                    <th class="bg-yak-cream-dark px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-yak-blue border-b border-yak-tan/40 last:rounded-tr-[14px]">Total Cost</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($breakdown as $row)
                    <tr class="hover:bg-yak-cream/50">
                        <td class="px-4 py-3.5 border-b border-yak-tan/25 text-yak-slate">{{ \Carbon\Carbon::parse($row->date)->format('M j') }}</td>
                        <td class="px-4 py-3.5 border-b border-yak-tan/25 text-yak-slate">{{ $row->task_count }}</td>
                        <td class="px-4 py-3.5 border-b border-yak-tan/25 text-right {{ isset($row->sources['slack']) ? 'text-yak-slate' : 'text-yak-tan' }}">
                            {{ isset($row->sources['slack']) ? '$' . number_format($row->sources['slack'], 2) : '—' }}
                        </td>
                        <td class="px-4 py-3.5 border-b border-yak-tan/25 text-right {{ isset($row->sources['linear']) ? 'text-yak-slate' : 'text-yak-tan' }}">
                            {{ isset($row->sources['linear']) ? '$' . number_format($row->sources['linear'], 2) : '—' }}
                        </td>
                        <td class="px-4 py-3.5 border-b border-yak-tan/25 text-right {{ isset($row->sources['sentry']) ? 'text-yak-slate' : 'text-yak-tan' }}">
                            {{ isset($row->sources['sentry']) ? '$' . number_format($row->sources['sentry'], 2) : '—' }}
                        </td>
                        <td class="px-4 py-3.5 border-b border-yak-tan/25 text-right font-semibold text-yak-slate">${{ number_format($row->total, 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-yak-blue">No cost data for this period.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Note about Claude Code costs --}}
    <p class="mt-4 text-xs text-yak-blue/70 text-center">
        Costs reflect routing layer API usage (Haiku/Sonnet). Claude Code usage is tracked for monitoring but covered by subscription.
    </p>
</div>
