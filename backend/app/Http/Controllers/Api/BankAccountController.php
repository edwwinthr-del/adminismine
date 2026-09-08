<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bank\StoreBankAccountRequest;
use App\Http\Requests\Bank\UpdateBankAccountRequest;
use App\Http\Resources\BankAccountResource;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The accounts money moves through.
 *
 * These were three columns — `cash_amount`, `nlb_amount`, `lovcen_amount` — so
 * opening a fourth account meant a migration and an edit in forty files. They
 * are rows now, and this is where they are opened, renamed and closed.
 *
 * There is no general delete: an account with history is closed
 * (`is_active = false`) so that every movement and settlement that ever named it
 * keeps naming it (rule 3). Only an account nothing points at — one just opened
 * by mistake — can be removed outright.
 */
class BankAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = BankAccount::query()
            ->when($request->boolean('active_only'), fn ($query) => $query->active())
            ->search($request->input('search'))
            // Three existence subqueries in the one statement, rather than
            // asking each account in turn whether anything points at it.
            ->withExists([
                'lines as has_lines',
                'payments as has_payments',
                'socialAssistancePayments as has_payouts',
            ])
            ->ordered()
            ->get();

        // The same aggregate the bank page and the dashboard read, so no two
        // screens can quote different balances.
        $balances = collect(BankTransaction::accountBalances()['accounts'])->keyBy('id');

        $accounts->each(function (BankAccount $account) use ($balances): void {
            $account->setAttribute('balance', $balances[$account->id]['balance'] ?? 0.0);
            $account->setAttribute(
                'has_history',
                (bool) ($account->has_lines || $account->has_payments || $account->has_payouts),
            );
        });

        return BankAccountResource::collection($accounts);
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        $account = BankAccount::create($request->validated());

        activity()->performedOn($account)->causedBy($request->user())->log('bank_account.created');

        return (new BankAccountResource($account))->response()->setStatusCode(201);
    }

    public function show(BankAccount $bankAccount): BankAccountResource
    {
        return new BankAccountResource($bankAccount);
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): BankAccountResource
    {
        $bankAccount->update($request->validated());

        activity()->performedOn($bankAccount)->causedBy($request->user())->log('bank_account.updated');

        return new BankAccountResource($bankAccount);
    }

    /**
     * Remove an account nothing points at.
     *
     * An account that has carried money is closed instead: deleting it would
     * take the name off every movement and settlement that ever used it, and a
     * ledger that cannot say which account a figure came from is not a ledger.
     * The foreign keys are `restrictOnDelete`, so this holds at the database
     * level too — this check only makes the refusal readable.
     */
    public function destroy(Request $request, BankAccount $bankAccount): JsonResponse
    {
        if ($bankAccount->hasHistory()) {
            return response()->json([
                'message' => 'This account has movements or settlements. Close it instead of deleting it.',
                'errors' => ['id' => ['This account has movements or settlements. Close it instead of deleting it.']],
            ], 422);
        }

        $bankAccount->delete();

        activity()->performedOn($bankAccount)->causedBy($request->user())->log('bank_account.deleted');

        return response()->json(['message' => 'Account deleted.']);
    }
}
