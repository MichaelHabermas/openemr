<?php

declare(strict_types=1);

namespace OpenEMR\AgentForge\Document\Schema;

enum AbnormalFlag: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case CriticalLow = 'critical_low';
    case CriticalHigh = 'critical_high';
}
