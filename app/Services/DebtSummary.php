<?php

namespace App\Services;

use App\Enums\DebtDirection;
use App\Enums\DebtStatus;
use App\Models\Contact;
use App\Models\Debt;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Answers questions about a user's debts: how much is owed each way, what is
 * due soon, and debts grouped by contact. Read-only (D-031, D-041).
 */
class DebtSummary
{
    /**
     * What remains on open debts, each way.
     *
     * @return array{owed_to_me: Money, i_owe: Money}
     */
    public function totals(User $user): array
    {
        $totals = ['owed_to_me' => Money::zero(), 'i_owe' => Money::zero()];

        foreach ($this->openDebts($user)->get() as $debt) {
            $key = $debt->direction->value;
            $totals[$key] = $totals[$key]->add($debt->remaining());
        }

        return $totals;
    }

    /**
     * Open debts due within the user's window (`payments_due_days`) or overdue,
     * soonest first (PRD §2.2 "Payments Due", D-009).
     *
     * @return Collection<int, Debt>
     */
    public function dueSoon(User $user): Collection
    {
        $until = now($user->timezone)->addDays($user->payments_due_days)->toDateString();

        return $this->openDebts($user)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', $until)
            ->with('contact')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Contacts with their debts (open first, then newest), and each contact's
     * net: positive when they owe the user overall.
     *
     * @return list<array{contact: Contact, debts: Collection<int, Debt>, net: Money}>
     */
    public function byContact(User $user, bool $includeSettled = false): array
    {
        $debts = $this->withPaid(Debt::forUser($user))
            ->when(! $includeSettled, fn (Builder $q) => $q->where('status', DebtStatus::Open->value))
            ->orderByRaw("case status when 'open' then 0 else 1 end")
            ->latest('started_on')
            ->latest('id')
            ->get()
            ->groupBy('contact_id');

        return array_values(Contact::forUser($user)->orderBy('name')->get()
            ->map(function (Contact $contact) use ($debts) {
                /** @var Collection<int, Debt> $own */
                $own = $debts->get($contact->id, new Collection);

                $net = $own->reduce(fn (Money $sum, Debt $debt) => $debt->direction === DebtDirection::OwedToMe
                    ? $sum->add($debt->remaining())
                    : $sum->subtract($debt->remaining()), Money::zero());

                return ['contact' => $contact, 'debts' => $own, 'net' => $net];
            })
            ->all());
    }

    /**
     * @return Builder<Debt>
     */
    private function openDebts(User $user): Builder
    {
        return $this->withPaid(Debt::forUser($user))->where('status', DebtStatus::Open->value);
    }

    /**
     * Adds `paid_cents` (sum of non-deleted payments) to each debt in one query.
     *
     * @param  Builder<Debt>  $query
     * @return Builder<Debt>
     */
    private function withPaid(Builder $query): Builder
    {
        // A debt's payments always belong to the debt's owner, so the owner scope isn't needed
        // here; dropping it keeps this correct outside a web request too.
        return $query->withSum(['payments as paid_cents' => fn (Builder $q) => $q->withoutGlobalScope('user')], 'amount_cents');
    }
}
