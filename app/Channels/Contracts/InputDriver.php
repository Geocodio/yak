<?php

namespace App\Channels\Contracts;

use App\DataTransferObjects\TaskDescription;
use Illuminate\Http\Request;

interface InputDriver
{
    /**
     * Parse an incoming webhook or event into a normalized task description.
     */
    public function parse(Request $request): TaskDescription;
}
