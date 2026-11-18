<?php

namespace App\Enums;

/**
 * want_analyses.engine: who explained the verdict.
 */
enum AnalysisEngine: string
{
    case Ai = 'ai';
    case Rules = 'rules';
}
