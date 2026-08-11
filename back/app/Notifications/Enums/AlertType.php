<?php

namespace App\Notifications\Enums;

enum AlertType: string
{
    case ProgramTaskDue = 'program.task_due';
    case ProgramCreated = 'program.created';
    case ProgramCancelled = 'program.cancelled';
    case ProgramPdfShared = 'program.pdf_shared';
    case HealthPlanMonth = 'health_plan.month';
    case EventReminder = 'event.reminder';
}
