<?php

namespace App\Enums;

/**
 * The tabs on the Tasks page. "Today" means today in the user's timezone (rule 9).
 */
enum TaskFilter: string
{
    case Today = 'today';
    case Upcoming = 'upcoming';
    case Overdue = 'overdue';
    case NoDate = 'no-date';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Upcoming => 'Upcoming',
            self::Overdue => 'Overdue',
            self::NoDate => 'No date',
            self::Done => 'Done',
        };
    }

    public function emptyMessage(): string
    {
        return match ($this) {
            self::Today => 'Nothing due today.',
            self::Upcoming => 'Nothing coming up.',
            self::Overdue => 'Nothing overdue. Nice!',
            self::NoDate => 'No tasks without a date.',
            self::Done => 'No completed tasks yet.',
        };
    }

    /**
     * The due date a quick-added task gets on this tab (D-037): today on the
     * Today tab, no date everywhere else.
     */
    public function quickAddDueOn(string $today): ?string
    {
        return $this === self::Today ? $today : null;
    }

    /**
     * The tab a quick-added task lands in.
     */
    public function quickAddTarget(): self
    {
        return $this === self::Today ? self::Today : self::NoDate;
    }
}
