import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import forPr91cad2 from './for-pr'
/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::forPr
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:19
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
export const forPr = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: forPr.url(args, options),
    method: 'get',
})

forPr.definition = {
    methods: ["get","head"],
    url: '/pr-reviews/for/{repoSlug}/{prNumber}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::forPr
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:19
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
forPr.url = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions) => {
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

    return forPr.definition.url
            .replace('{repoSlug}', parsedArgs.repoSlug.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::forPr
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:19
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
forPr.get = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: forPr.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewForPrController::forPr
* @see app/Http/Controllers/PrReviews/PrReviewForPrController.php:19
* @route '/pr-reviews/for/{repoSlug}/{prNumber}'
*/
forPr.head = (args: { repoSlug: string | number, prNumber: string | number } | [repoSlug: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: forPr.url(args, options),
    method: 'head',
})

const prReviews = {
    forPr: Object.assign(forPr, forPr91cad2),
}

export default prReviews