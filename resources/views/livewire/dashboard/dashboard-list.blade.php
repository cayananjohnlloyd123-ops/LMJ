<?php

use Livewire\Volt\Component;
use App\Models\Sale;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

new class extends Component {
    public string $chartRange = '30'; // '7' or '30'

    public function getGreetingProperty(): string
    {
        $hour = now()->hour;

        return match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default    => 'Good evening',
        };
    }

    public function getUserNameProperty(): string
    {
        return Auth::user()->name ?? 'there';
    }

    // --- KPI metrics -------------------------------------------------

    public function getSalesTodayProperty(): float
    {
        return (float) Sale::withoutGlobalScopes()->whereDate('created_at', today())->sum('total_amount');
    }

    public function getSalesYesterdayProperty(): float
    {
        return (float) Sale::withoutGlobalScopes()->whereDate('created_at', today()->subDay())->sum('total_amount');
    }

    public function getSalesThisMonthProperty(): float
    {
        return (float) Sale::withoutGlobalScopes()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->sum('total_amount');
    }

    public function getSalesLastMonthProperty(): float
    {
        $lastMonth = now()->subMonthNoOverflow();
        return (float) Sale::withoutGlobalScopes()
            ->whereMonth('created_at', $lastMonth->month)
            ->whereYear('created_at', $lastMonth->year)
            ->sum('total_amount');
    }

    public function getExpensesThisMonthProperty(): float
    {
        return (float) Expense::withoutGlobalScopes()
            ->whereMonth('expense_date', now()->month)
            ->whereYear('expense_date', now()->year)
            ->sum('amount');
    }

    public function getExpensesLastMonthProperty(): float
    {
        $lastMonth = now()->subMonthNoOverflow();
        return (float) Expense::withoutGlobalScopes()
            ->whereMonth('expense_date', $lastMonth->month)
            ->whereYear('expense_date', $lastMonth->year)
            ->sum('amount');
    }

    public function getNetProfitThisMonthProperty(): float
    {
        return $this->salesThisMonth - $this->expensesThisMonth;
    }

    public function getNetProfitLastMonthProperty(): float
    {
        return $this->salesLastMonth - $this->expensesLastMonth;
    }

    public function getTransactionsTodayProperty(): int
    {
        return Sale::withoutGlobalScopes()->whereDate('created_at', today())->count();
    }

    /**
     * Percentage change helper — returns null when there's no baseline
     * to compare against (avoids misleading "+100%" off a zero base).
     */
    public function percentChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    // --- Chart data ----------------------------------------------------

    public function setChartRange(string $range): void
    {
        $this->chartRange = $range;
    }

    public function getChartDataForJs(): array
    {
        return $this->chartData;
    }

    public function getChartDataProperty(): array
    {
        $days = (int) $this->chartRange;
        $start = today()->subDays($days - 1);

        $sales = Sale::withoutGlobalScopes()
            ->selectRaw('DATE(sales.created_at) as d, SUM(sales.total_amount) as total')
            ->where('sales.created_at', '>=', $start)
            ->groupByRaw('DATE(sales.created_at)')
            ->pluck('total', 'd');

        $expenses = Expense::withoutGlobalScopes()
            ->selectRaw('expenses.expense_date as d, SUM(expenses.amount) as total')
            ->where('expenses.expense_date', '>=', $start)
            ->groupByRaw('expenses.expense_date')
            ->pluck('total', 'd');

        $labels = [];
        $salesSeries = [];
        $expensesSeries = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->copy()->addDays($i);
            $key  = $date->format('Y-m-d');

            $labels[]         = $date->format($days > 14 ? 'M j' : 'D, M j');
            $salesSeries[]    = (float) ($sales[$key] ?? 0);
            $expensesSeries[] = (float) ($expenses[$key] ?? 0);
        }

        return [
            'labels'   => $labels,
            'sales'    => $salesSeries,
            'expenses' => $expensesSeries,
        ];
    }

    // --- Recent activity -------------------------------------------------

    public function getRecentSalesProperty()
    {
        return Sale::query()->latest('created_at')->limit(5)->get();
    }

    public function getRecentExpensesProperty()
    {
        return Expense::query()->latest('expense_date')->limit(5)->get();
    }

    public function getTopCategoriesProperty()
    {
        $month = now()->month;
        $year  = now()->year;

        $sumSubquery = \DB::table('expenses')
            ->selectRaw('SUM(expenses.amount)')
            ->whereColumn('expenses.expense_category_id', 'expense_categories.id')
            ->whereMonth('expenses.expense_date', $month)
            ->whereYear('expenses.expense_date', $year);

        return ExpenseCategory::query()
            ->selectSub($sumSubquery, 'expenses_sum_amount')
            ->addSelect('expense_categories.*')
            ->having('expenses_sum_amount', '>', 0)
            ->orderByDesc('expenses_sum_amount')
            ->limit(5)
            ->get();
    }

    public function getTopCategoriesTotalProperty(): float
    {
        return (float) $this->topCategories->sum('expenses_sum_amount');
    }

    // --- AI Financial Advisor -------------------------------------------
    //
    // IMPORTANT: This call happens SERVER-SIDE (here, in PHP) using the
    // Google Gemini API, instead of directly from the browser. Calling an
    // AI API straight from client-side JS would either be blocked by CORS
    // or require shipping your API key inside the page source, where
    // anyone could read it from devtools.
    //
    // Setup:
    //   1. Go to https://aistudio.google.com/apikey
    //   2. Sign in with a Google account, click "Create API key"
    //      (no credit card required)
    //
    // Add to .env:
    //   GEMINI_API_KEY=...

    public function getAiAdvice(): array
    {
        $month            = now()->format('F Y');
        $salesToday       = $this->salesToday;
        $salesThisMonth   = $this->salesThisMonth;
        $salesLastMonth   = $this->salesLastMonth;
        $expensesThisMonth = $this->expensesThisMonth;
        $expensesLastMonth = $this->expensesLastMonth;
        $netProfit        = $this->netProfitThisMonth;

        $topCategories = $this->topCategories
            ->map(fn ($c) => ['name' => $c->name, 'amount' => (float) $c->expenses_sum_amount])
            ->values()
            ->all();

        $categoriesText = collect($topCategories)
            ->map(fn ($c) => $c['name'] . ' ₱' . number_format($c['amount'], 2))
            ->implode(', ');

        if ($categoriesText === '') {
            $categoriesText = 'none yet';
        }

        $userMessage = "Here is my business data for {$month}:\n"
            . '- Sales today: ₱' . number_format($salesToday, 2) . "\n"
            . '- Sales this month: ₱' . number_format($salesThisMonth, 2) . "\n"
            . '- Sales last month: ₱' . number_format($salesLastMonth, 2) . "\n"
            . '- Expenses this month: ₱' . number_format($expensesThisMonth, 2) . "\n"
            . '- Expenses last month: ₱' . number_format($expensesLastMonth, 2) . "\n"
            . '- Net profit this month: ₱' . number_format($netProfit, 2) . "\n"
            . "- Top expense categories: {$categoriesText}\n\n"
            . 'Give me 3 actionable tips on how to manage my money better.';

        $systemPrompt = <<<SYS
        You are a friendly financial advisor for a small business owner in the Philippines.
        Analyze the provided business data and give 3 short, specific, actionable money management tips.
        Format your response as a JSON object with this exact shape:
        {
          "summary": "one sentence overall assessment",
          "tips": [
            { "icon": "emoji", "title": "short title", "detail": "1-2 sentence actionable advice" },
            { "icon": "emoji", "title": "short title", "detail": "1-2 sentence actionable advice" },
            { "icon": "emoji", "title": "short title", "detail": "1-2 sentence actionable advice" }
          ],
          "sentiment": "positive" | "neutral" | "warning"
        }
        Respond with raw JSON only. No markdown, no backticks, no preamble.
        SYS;

        try {
            $apiKey = config('services.gemini.key');

            $response = Http::timeout(30)->post(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $apiKey,
                [
                    'system_instruction' => [
                        'parts' => [
                            ['text' => $systemPrompt],
                        ],
                    ],
                    'contents' => [
                        [
                            'role'  => 'user',
                            'parts' => [
                                ['text' => $userMessage],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'response_mime_type' => 'application/json',
                    ],
                ]
            );

            if (! $response->successful()) {
                Log::warning('AI advisor: non-200 from Gemini', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);

                return ['error' => 'Could not load advice. Please try again.'];
            }

            $text   = trim((string) $response->json('candidates.0.content.parts.0.text', ''));
            $advice = json_decode($text, true);

            if (! is_array($advice) || ! isset($advice['tips'])) {
                Log::warning('AI advisor: unexpected response shape', ['text' => $text]);

                return ['error' => 'Could not load advice. Please try again.'];
            }

            return ['advice' => $advice];
        } catch (\Throwable $e) {
            Log::error('AI advisor error: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
                'code'             => method_exists($e, 'getCode') ? $e->getCode() : null,
            ]);

            return ['error' => 'Could not load advice. Please try again.'];
        }
    }
}; ?>

@php
    // Resolve computed property here (within Blade/object scope) before
    // passing to Alpine via @js() — avoids "Using $this when not in
    // object context" caused by Volt's compilation of inline attributes.
    $chartData = $this->chartData;
@endphp

<div
    x-data="{
        chart: null,
        activeRange: '{{ $chartRange }}',
        initChart(labels, sales, expenses) {
            const ctx = document.getElementById('salesExpensesChart');
            if (!ctx) return;
            if (this.chart) { this.chart.destroy(); }
            this.chart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [
                        {
                            label: 'Sales',
                            data: sales,
                            borderColor: '#4f46e5',
                            backgroundColor: 'rgba(79, 70, 229, 0.08)',
                            tension: 0.35,
                            fill: true,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            borderWidth: 2,
                        },
                        {
                            label: 'Expenses',
                            data: expenses,
                            borderColor: '#f59e0b',
                            backgroundColor: 'rgba(245, 158, 11, 0.06)',
                            tension: 0.35,
                            fill: true,
                            pointRadius: 0,
                            pointHoverRadius: 4,
                            borderWidth: 2,
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, font: { size: 12 }, color: '#6b7280' },
                        },
                        tooltip: {
                            backgroundColor: '#111827',
                            padding: 10,
                            cornerRadius: 8,
                            callbacks: {
                                label: (item) => `${item.dataset.label}: ₱${item.formattedValue}`,
                            },
                        },
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: '#9ca3af', font: { size: 11 } } },
                        y: {
                            grid: { color: '#f3f4f6' },
                            ticks: {
                                color: '#9ca3af',
                                font: { size: 11 },
                                callback: (v) => '₱' + v.toLocaleString(),
                            },
                        },
                    },
                },
            });
        }
    }"
    x-init="initChart(@js($chartData['labels']), @js($chartData['sales']), @js($chartData['expenses']))"
    class="space-y-6"
>
    {{-- Greeting --}}
    <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold text-gray-800">
            {{ $this->greeting }}, {{ $this->userName }} 👋
        </h1>
        <p class="text-sm text-gray-500">
            Here's what's happening with your business today, {{ now()->format('l, F j, Y') }}.
        </p>
    </div>

    {{-- KPI Cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        {{-- Sales Today --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Sales Today</span>
                <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                </div>
            </div>
            <p class="text-2xl font-bold text-gray-800">₱{{ number_format($this->salesToday, 2) }}</p>
            <div class="mt-2 flex items-center gap-1 text-xs">
                @php $change = $this->percentChange($this->salesToday, $this->salesYesterday); @endphp
                @if ($change === null)
                    <span class="text-gray-400">{{ $this->transactionsToday }} transaction(s) today</span>
                @else
                    <span class="{{ $change >= 0 ? 'text-green-600' : 'text-red-500' }} font-medium inline-flex items-center gap-0.5">
                        <svg class="w-3 h-3 {{ $change < 0 ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                        {{ abs($change) }}%
                    </span>
                    <span class="text-gray-400">vs yesterday</span>
                @endif
            </div>
        </div>

        {{-- Sales This Month --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Sales This Month</span>
                <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center">
                    <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" /></svg>
                </div>
            </div>
            <p class="text-2xl font-bold text-gray-800">₱{{ number_format($this->salesThisMonth, 2) }}</p>
            <div class="mt-2 flex items-center gap-1 text-xs">
                @php $change = $this->percentChange($this->salesThisMonth, $this->salesLastMonth); @endphp
                @if ($change === null)
                    <span class="text-gray-400">No data last month</span>
                @else
                    <span class="{{ $change >= 0 ? 'text-green-600' : 'text-red-500' }} font-medium inline-flex items-center gap-0.5">
                        <svg class="w-3 h-3 {{ $change < 0 ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                        {{ abs($change) }}%
                    </span>
                    <span class="text-gray-400">vs last month</span>
                @endif
            </div>
        </div>

        {{-- Expenses This Month --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Expenses This Month</span>
                <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21l-2-2m0 0l-2-2m2 2l2-2m-2 2l-2 2M5 5h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2z" /></svg>
                </div>
            </div>
            <p class="text-2xl font-bold text-gray-800">₱{{ number_format($this->expensesThisMonth, 2) }}</p>
            <div class="mt-2 flex items-center gap-1 text-xs">
                @php $change = $this->percentChange($this->expensesThisMonth, $this->expensesLastMonth); @endphp
                @if ($change === null)
                    <span class="text-gray-400">No data last month</span>
                @else
                    <span class="{{ $change <= 0 ? 'text-green-600' : 'text-red-500' }} font-medium inline-flex items-center gap-0.5">
                        <svg class="w-3 h-3 {{ $change < 0 ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                        {{ abs($change) }}%
                    </span>
                    <span class="text-gray-400">vs last month</span>
                @endif
            </div>
        </div>

        {{-- Net Profit --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-medium text-gray-400 uppercase tracking-wide">Net Profit This Month</span>
                <div class="w-8 h-8 rounded-lg {{ $this->netProfitThisMonth >= 0 ? 'bg-green-50' : 'bg-red-50' }} flex items-center justify-center">
                    <svg class="w-4 h-4 {{ $this->netProfitThisMonth >= 0 ? 'text-green-600' : 'text-red-500' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" /></svg>
                </div>
            </div>
            <p class="text-2xl font-bold {{ $this->netProfitThisMonth >= 0 ? 'text-gray-800' : 'text-red-600' }}">
                ₱{{ number_format($this->netProfitThisMonth, 2) }}
            </p>
            <div class="mt-2 flex items-center gap-1 text-xs">
                @php $change = $this->percentChange($this->netProfitThisMonth, $this->netProfitLastMonth); @endphp
                @if ($change === null)
                    <span class="text-gray-400">No data last month</span>
                @else
                    <span class="{{ $change >= 0 ? 'text-green-600' : 'text-red-500' }} font-medium inline-flex items-center gap-0.5">
                        <svg class="w-3 h-3 {{ $change < 0 ? 'rotate-180' : '' }}" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M5.293 9.707a1 1 0 010-1.414l4-4a1 1 0 011.414 0l4 4a1 1 0 01-1.414 1.414L11 7.414V15a1 1 0 11-2 0V7.414L6.707 9.707a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
                        {{ abs($change) }}%
                    </span>
                    <span class="text-gray-400">vs last month</span>
                @endif
            </div>
        </div>
    </div>

    {{-- Chart + Top Categories --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Sales vs Expenses Chart --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-800">Sales vs Expenses</h3>
                    <p class="text-xs text-gray-400">Daily totals over time</p>
                </div>
                <div class="flex rounded-lg border border-gray-200 p-0.5 text-xs">
                    <button
                        @click="activeRange = '7'; $wire.call('setChartRange', '7').then(() => $wire.call('getChartDataForJs')).then(data => initChart(data.labels, data.sales, data.expenses))"
                        :class="activeRange === '7' ? 'bg-indigo-600 text-white' : 'text-gray-500 hover:text-gray-700'"
                        class="px-3 py-1 rounded-md font-medium transition"
                    >7D</button>
                    <button
                        @click="activeRange = '30'; $wire.call('setChartRange', '30').then(() => $wire.call('getChartDataForJs')).then(data => initChart(data.labels, data.sales, data.expenses))"
                        :class="activeRange === '30' ? 'bg-indigo-600 text-white' : 'text-gray-500 hover:text-gray-700'"
                        class="px-3 py-1 rounded-md font-medium transition"
                    >30D</button>
                </div>
            </div>
            <div class="h-72">
                <canvas id="salesExpensesChart"></canvas>
            </div>
        </div>

        {{-- Top Expense Categories --}}
        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-gray-800 mb-1">Top Expense Categories</h3>
            <p class="text-xs text-gray-400 mb-4">This month</p>

            @forelse ($this->topCategories as $category)
                @php
                    $pct = $this->topCategoriesTotal > 0
                        ? round(($category->expenses_sum_amount / $this->topCategoriesTotal) * 100)
                        : 0;
                    $colors = ['bg-indigo-500', 'bg-blue-500', 'bg-amber-500', 'bg-rose-500', 'bg-teal-500'];
                @endphp
                <div class="mb-3 last:mb-0">
                    <div class="flex items-center justify-between text-xs mb-1">
                        <span class="font-medium text-gray-700">{{ $category->name }}</span>
                        <span class="text-gray-400">₱{{ number_format($category->expenses_sum_amount, 2) }}</span>
                    </div>
                    <div class="h-1.5 w-full rounded-full bg-gray-100 overflow-hidden">
                        <div class="h-full rounded-full {{ $colors[$loop->index % count($colors)] }}" style="width: {{ $pct }}%"></div>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center">
                    <svg class="w-8 h-8 mx-auto text-gray-200 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3v-6m-3 6v-9m-2 9h10a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z" /></svg>
                    <p class="text-xs text-gray-400">No expenses recorded this month</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Recent Activity --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        {{-- Recent Sales --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-800">Recent Sales</h3>
                <a href="{{ route('sales.index') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">View all</a>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($this->recentSales as $sale)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-700 truncate">{{ $sale->invoice_no }}</p>
                            <p class="text-xs text-gray-400">{{ $sale->created_at->diffForHumans() }}</p>
                        </div>
                        <span class="text-sm font-semibold text-gray-800 shrink-0">₱{{ number_format($sale->total_amount, 2) }}</span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No sales yet.</div>
                @endforelse
            </div>
        </div>

        {{-- Recent Expenses --}}
        <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                <h3 class="text-sm font-semibold text-gray-800">Recent Expenses</h3>
                <a href="{{ route('expenses.index') }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">View all</a>
            </div>
            <div class="divide-y divide-gray-50">
                @forelse ($this->recentExpenses as $expense)
                    <div class="flex items-center justify-between px-5 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-gray-700 truncate">{{ $expense->description }}</p>
                            <p class="text-xs text-gray-400">{{ $expense->expense_date->format('M j, Y') }}</p>
                        </div>
                        <span class="text-sm font-semibold text-amber-600 shrink-0">₱{{ number_format($expense->amount, 2) }}</span>
                    </div>
                @empty
                    <div class="px-5 py-8 text-center text-sm text-gray-400">No expenses recorded yet.</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- AI Financial Advisor --}}
    <div
        x-data="{
            open: false,
            loading: false,
            advice: null,
            error: null,
            async getAdvice() {
                this.loading = true;
                this.advice = null;
                this.error = null;
                this.open = true;

                const result = await $wire.getAiAdvice();

                if (result.error) {
                    this.error = result.error;
                } else {
                    this.advice = result.advice;
                }

                this.loading = false;
            }
        }"
        class="bg-gradient-to-br from-indigo-950 to-indigo-800 rounded-xl p-5 shadow-sm"
    >
        {{-- Header row --}}
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-white/10 flex items-center justify-center shrink-0">
                    <svg class="w-5 h-5 text-indigo-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm font-semibold text-white">AI Financial Advisor</p>
                    <p class="text-xs text-indigo-300">Based on your data this month</p>
                </div>
            </div>
            <button
                @click="getAdvice()"
                :disabled="loading"
                class="flex items-center gap-1.5 px-3.5 py-1.5 rounded-lg bg-white text-indigo-700 text-xs font-semibold hover:bg-indigo-50 transition disabled:opacity-60 disabled:cursor-not-allowed shrink-0"
            >
                <template x-if="!loading">
                    <span>Get advice</span>
                </template>
                <template x-if="loading">
                    <span class="flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        Thinking…
                    </span>
                </template>
            </button>
        </div>

        {{-- Idle state --}}
        <template x-if="!open && !loading">
            <p class="mt-4 text-xs text-indigo-300/70 italic">
                Click "Get advice" to analyze your {{ now()->format('F') }} finances and get personalized tips.
            </p>
        </template>

        {{-- Loading skeleton --}}
        <template x-if="loading">
            <div class="mt-4 space-y-3 animate-pulse">
                <div class="h-3 bg-white/10 rounded w-3/4"></div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
                    <div class="h-20 bg-white/10 rounded-lg"></div>
                    <div class="h-20 bg-white/10 rounded-lg"></div>
                    <div class="h-20 bg-white/10 rounded-lg"></div>
                </div>
            </div>
        </template>

        {{-- Error --}}
        <template x-if="error">
            <p class="mt-4 text-xs text-rose-300" x-text="error"></p>
        </template>

        {{-- Advice result --}}
        <template x-if="advice && !loading">
            <div class="mt-4 space-y-3">
                {{-- Summary badge --}}
                <div class="flex items-center gap-2">
                    <span
                        class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium"
                        :class="{
                            'bg-green-500/20 text-green-300':  advice.sentiment === 'positive',
                            'bg-amber-500/20 text-amber-300':  advice.sentiment === 'neutral',
                            'bg-rose-500/20  text-rose-300':   advice.sentiment === 'warning',
                        }"
                    >
                        <span x-text="advice.sentiment === 'positive' ? '✅' : advice.sentiment === 'neutral' ? '📊' : '⚠️'"></span>
                        <span x-text="advice.summary"></span>
                    </span>
                </div>

                {{-- Tip cards --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <template x-for="(tip, i) in advice.tips" :key="i">
                        <div class="bg-white/8 hover:bg-white/12 transition rounded-lg p-3.5 border border-white/10">
                            <div class="flex items-center gap-2 mb-1.5">
                                <span class="text-lg leading-none" x-text="tip.icon"></span>
                                <span class="text-xs font-semibold text-white" x-text="tip.title"></span>
                            </div>
                            <p class="text-xs text-indigo-200 leading-relaxed" x-text="tip.detail"></p>
                        </div>
                    </template>
                </div>

                {{-- Refresh nudge --}}
                <p class="text-xs text-indigo-400 text-right">
                    <button @click="getAdvice()" class="hover:text-indigo-200 transition underline underline-offset-2">Refresh advice</button>
                </p>
            </div>
        </template>
    </div>
</div>  