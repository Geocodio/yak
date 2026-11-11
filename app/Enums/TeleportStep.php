<?php

namespace App\Enums;

enum TeleportStep: string
{
    case FetchBranch = 'fetch_branch';
    case Restore = 'restore';
    case Setup = 'setup';
    case Refresh = 'refresh';
    case SessionRestore = 'session_restore';
    case WaitTurn = 'wait_turn';
    case StopTurn = 'stop_turn';
    case CommitWip = 'commit_wip';
    case Push = 'push';
    case SyncSession = 'sync_session';
}
