import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:19
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
export const show = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:19
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
show.url = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:19
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
show.get = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::show
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:19
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
show.head = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

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

const PrReviewForPrController = { show, rerun }

export default PrReviewForPrController