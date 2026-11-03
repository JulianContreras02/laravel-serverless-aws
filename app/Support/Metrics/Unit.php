<?php

namespace App\Support\Metrics;

/**
 * CloudWatch metric units used by this application.
 */
enum Unit: string
{
    case Count = 'Count';
    case Milliseconds = 'Milliseconds';
}
