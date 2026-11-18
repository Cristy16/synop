<?php

namespace App\Enums;

/**
 * activities.type.
 */
enum ActivityType: string
{
    case Quiz = 'quiz';
    case Exam = 'exam';
    case Project = 'project';
    case Assignment = 'assignment';

    public function label(): string
    {
        return match ($this) {
            self::Quiz => 'Quiz',
            self::Exam => 'Exam',
            self::Project => 'Project',
            self::Assignment => 'Assignment',
        };
    }
}
