<?php

namespace App\Enums;

enum SshKeySource: string
{
    case Github = 'github';
    case Uploaded = 'uploaded';
}
