<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlightTicket;
use App\Models\SocialAssistancePayment;
use App\Models\TravelExpense;
use App\Support\MonthPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What travel costs in a month, what is still unpaid, and — the question the
 * workbook is really asking — which ticket costs have not been written yet.
 */
class TravelSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'string', MonthPeriod::rule()],
        ]);

        $month = MonthPeriod::normalize($request->input('month', now()->toDateString()));
        [$start, $end] = MonthPeriod::range($month);

        $tickets = FlightTicket::query()->with('employee')->forMonth($month)->get();
        $expenses = TravelExpense::query()->with('employee')->forMonth($month)->get();
        $assistance = SocialAssistancePayment::query()
            ->whereBetween('payment_date', [$start, $end])
            ->get();

        $unwrittenTickets = $tickets->where('cost_status', 'not_written');
        $unwrittenExpenses = $expenses->where('cost_status', 'not_written');

        return response()->json([
            'data' => [
                'month' => $month,
                'totals' => [
                    'tickets_eur' => round((float) $tickets->sum('amount_eur'), 2),
                    'tickets_unpaid_eur' => round((float) $tickets->sum('remaining_amount'), 2),
                    'ticket_count' => $tickets->count(),
                    'expenses_eur' => round((float) $expenses->sum('amount_eur'), 2),
                    'expenses_unpaid_eur' => round((float) $expenses->sum('remaining_amount'), 2),
                    'expense_count' => $expenses->count(),
                    'social_assistance_eur' => round((float) $assistance->sum('amount_eur'), 2),
                    'social_assistance_count' => $assistance->count(),
                    'travel_cost_eur' => round(
                        (float) $tickets->sum('amount_eur')
                        + (float) $expenses->sum('amount_eur')
                        + (float) $assistance->sum('amount_eur'),
                        2,
                    ),
                ],
                'by_direction' => collect(FlightTicket::DIRECTIONS)->mapWithKeys(
                    fn (string $direction): array => [$direction => [
                        'count' => $tickets->where('direction', $direction)->count(),
                        'amount_eur' => round((float) $tickets->where('direction', $direction)->sum('amount_eur'), 2),
                    ]],
                ),
                'by_expense_type' => collect(TravelExpense::TYPES)
                    ->mapWithKeys(fn (string $type): array => [$type => [
                        'count' => $expenses->where('expense_type', $type)->count(),
                        'amount_eur' => round((float) $expenses->where('expense_type', $type)->sum('amount_eur'), 2),
                    ]])
                    ->filter(fn (array $row): bool => $row['count'] > 0),
                'alerts' => [
                    // "Unwritten" costs: bought but not yet booked to the worker.
                    'unwritten_tickets' => $unwrittenTickets->map(fn (FlightTicket $ticket): array => [
                        'flight_ticket_id' => $ticket->id,
                        'traveller_name' => $ticket->traveller_name,
                        'ticket_date' => $ticket->ticket_date->toDateString(),
                        'amount_eur' => (float) $ticket->amount_eur,
                    ])->values(),
                    'unwritten_tickets_eur' => round((float) $unwrittenTickets->sum('amount_eur'), 2),
                    'unwritten_expenses_eur' => round((float) $unwrittenExpenses->sum('amount_eur'), 2),
                    'unpaid_tickets' => $tickets->whereIn('status', ['unpaid', 'partial'])
                        ->map(fn (FlightTicket $ticket): array => [
                            'flight_ticket_id' => $ticket->id,
                            'traveller_name' => $ticket->traveller_name,
                            'ticket_date' => $ticket->ticket_date->toDateString(),
                            'remaining_eur' => (float) $ticket->remaining_amount,
                        ])->values(),
                ],
            ],
        ]);
    }
}
