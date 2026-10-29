<?php

namespace App\Services;

use App\Contracts\MalwareScanner;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Uses the clamd daemon through `clamdscan` (exit 0 = clean, 1 = infected, anything else = the scan failed). */
class ClamAvScanner implements MalwareScanner
{
    public function isClean(string $absolutePath): bool
    {
        $result = Process::timeout(60)->run(['clamdscan', '--no-summary', '--fdpass', $absolutePath]);

        return match ($result->exitCode()) {
            0 => true,
            1 => false,
            default => throw new RuntimeException('clamdscan failed: '.$result->errorOutput()),
        };
    }
}
