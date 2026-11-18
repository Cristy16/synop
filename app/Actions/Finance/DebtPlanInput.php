<?php

namespace App\Actions\Finance;

use App\Actions\Schedules\ScheduleInput;
use App\Enums\Frequency;
use App\Services\InstallmentPlan;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The "Paid in" part of the debt form: one payment, or installments (D-044).
 */
class DebtPlanInput
{
    use ParsesMoney;

    public const ONE_OFF = 'one_off';

    public const INSTALLMENTS = 'installments';

    public function __construct(private InstallmentPlan $plans) {}

    /**
     * Null for a one-off debt; otherwise the checked plan.
     *
     * @param  array<string, mixed>  $input  plan, installment (text), frequency, interval | weekdays | day_of_month, first_due_on
     * @return array{installment: Money, pattern: array{frequency: Frequency, interval: int|null, weekdays: list<int>|null, day_of_month: int|null}, first_due_on: string, count: int, last: string}|null
     *
     * @throws ValidationException
     */
    public function validate(array $input, Money $principal, string $startedOn): ?array
    {
        $validated = Validator::make($input, [
            'plan' => ['nullable', Rule::in([self::ONE_OFF, self::INSTALLMENTS])],
            'due_on' => [Rule::prohibitedIf(($input['plan'] ?? null) === self::INSTALLMENTS)],
            'installment' => [Rule::requiredIf(($input['plan'] ?? null) === self::INSTALLMENTS), 'nullable', 'string', 'max:30'],
        ], [
            'due_on.prohibited' => __('A debt paid in installments has no single due date.'),
        ])->validate();

        if (($validated['plan'] ?? self::ONE_OFF) !== self::INSTALLMENTS) {
            return null;
        }

        $installment = $this->parseMoney((string) $validated['installment'], 'installment', 'positive');

        if ($installment->cents() > $principal->cents()) {
            throw ValidationException::withMessages(['installment' => __('Each payment can\'t be more than the total.')]);
        }

        // The repeat pattern uses the same checks as any schedule; its start is the first due date.
        try {
            $values = ScheduleInput::validate([
                'frequency' => $input['frequency'] ?? null,
                'interval' => $input['interval'] ?? null,
                'weekdays' => $input['weekdays'] ?? null,
                'day_of_month' => $input['day_of_month'] ?? null,
                'starts_on' => $input['first_due_on'] ?? null,
            ]);
        } catch (ValidationException $e) {
            // Here the schedule's start is the debt's first due date.
            throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(
                fn (array $messages, string $key) => [$key === 'starts_on' ? 'first_due_on' : $key => $messages]
            )->all());
        }

        if ($values['starts_on'] < $startedOn) {
            throw ValidationException::withMessages(['first_due_on' => __('The first payment can\'t be due before the debt started.')]);
        }

        $count = $this->plans->paymentCount($principal, $installment);

        if ($count > InstallmentPlan::MAX_PAYMENTS) {
            throw ValidationException::withMessages([
                'installment' => __('That makes :count payments; the most is :max. Pay more each time.', ['count' => $count, 'max' => InstallmentPlan::MAX_PAYMENTS]),
            ]);
        }

        $pattern = [
            'frequency' => $values['frequency'],
            'interval' => $values['interval'],
            'weekdays' => $values['weekdays'],
            'day_of_month' => $values['day_of_month'],
        ];

        $last = $this->plans->preview($principal, $installment, $pattern, $values['starts_on'])['last'];

        if ($last === null) {
            throw ValidationException::withMessages(['frequency' => __('This schedule never falls on a date.')]);
        }

        return ['installment' => $installment, 'pattern' => $pattern, 'first_due_on' => $values['starts_on'], 'count' => $count, 'last' => $last];
    }
}
