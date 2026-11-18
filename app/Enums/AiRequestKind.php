<?php

namespace App\Enums;

/**
 * ai_requests.kind: the two uses of the AI (ARCHITECTURE §7).
 */
enum AiRequestKind: string
{
    case IngredientScan = 'ingredient_scan';
    case WantVerdict = 'want_verdict';
}
