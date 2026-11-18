<?php

namespace App\Enums;

/**
 * wants.fetch_status: how the details got there. `pending` is unused for now:
 * links are read right away, not in the background (D-049).
 */
enum FetchStatus: string
{
    case Pending = 'pending';
    case Fetched = 'fetched';
    case Failed = 'failed';
    case Manual = 'manual';
}
