<?php

namespace App\Enums;

enum RepositoryRole: string
{
    case Primary = 'primary';
    case Changes = 'changes';
    case Context = 'context';
}
