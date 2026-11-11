<?php

namespace App\Enums;

enum RestoreStepName: string
{
    case CreateSandbox = 'create_sandbox';
    case CheckoutBranch = 'checkout_branch';
    case RunSetupScript = 'run_setup_script';
    case RunRefreshTriggers = 'run_refresh_triggers';
    case RestoreSessionFiles = 'restore_session_files';
    case PrepareEnvironment = 'prepare_environment';

    /** 1-based position in the six step restore. */
    public function position(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }
}
