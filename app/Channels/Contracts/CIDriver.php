<?php

namespace App\Channels\Contracts;

use App\DataTransferObjects\BuildResult;
use Illuminate\Http\Request;

interface CIDriver
{
    /**
     * Parse a build result webhook into a normalized build result.
     */
    public function parse(Request $request): BuildResult;
}
