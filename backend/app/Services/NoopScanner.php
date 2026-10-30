<?php

namespace App\Services;

use App\Contracts\MalwareScanner;

/** Development only: nothing is scanned. Production must use ClamAvScanner. */
class NoopScanner implements MalwareScanner
{
    public function isClean(string $absolutePath): bool
    {
        return true;
    }
}
