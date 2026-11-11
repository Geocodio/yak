<?php

namespace App\Models\Scopes;

/**
 * A preview deployment is hidden when its task's thread is hidden, by the
 * same rules as the task itself.
 */
class VisibleDeploymentScope extends VisibleTaskChildScope {}
