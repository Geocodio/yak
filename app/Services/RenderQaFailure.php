<?php

namespace App\Services;

use RuntimeException;

/**
 * The rendered cut did not pass the spec §7 gate. The message is written
 * for a human: it lands in the failure notification, the PR's
 * "unavailable" line, and the video_metrics row.
 */
class RenderQaFailure extends RuntimeException {}
