<?php

namespace App\Http\Controllers\AppEmbed;

use App\Http\Controllers\Controller;
use App\Http\Controllers\PrReviews\PrReviewForPrController;
use Inertia\Response;

/**
 * The web's PR review page for one pull request, addressed as owner, repository and number for the app's web view.
 */
class PrReviewController extends Controller
{
    public function show(string $owner, string $repo, int $prNumber, PrReviewForPrController $web): Response
    {
        return $web->show("{$owner}/{$repo}", $prNumber);
    }
}
