import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:27
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/rerun'
*/
export const rerun = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerun.url(args, options),
    method: 'post',
})

rerun.definition = {
    methods: ["post"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}/rerun',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:27
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/rerun'
*/
rerun.url = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            repoSlug: args[0],
            prNumber: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        repoSlug: args.repoSlug,
        prNumber: args.prNumber,
    }

    return rerun.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::rerun
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:27
* @route '/pr-reviews/for/{repoSlug}/{prNumber}/rerun'
*/
rerun.post = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: rerun.url(args, options),
    method: 'post',
})

const forPr = {
    rerun: Object.assign(rerun, rerun),
}

export default forPr