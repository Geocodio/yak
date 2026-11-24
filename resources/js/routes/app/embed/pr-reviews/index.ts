import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/app/embed/pr-reviews'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/app/embed/pr-reviews',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/app/embed/pr-reviews'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/app/embed/pr-reviews'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/app/embed/pr-reviews'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\AppEmbed\PrReviewController::show
* @see app/Http/Controllers/AppEmbed/PrReviewController.php:14
* @route '/app/embed/pr-reviews/{owner}/{repo}/{prNumber}'
*/
export const show = (args: { owner: string | number, repo: string | number, prNumber: string | number } | [owner: string | number, repo: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/app/embed/pr-reviews/{owner}/{repo}/{prNumber}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\AppEmbed\PrReviewController::show
* @see app/Http/Controllers/AppEmbed/PrReviewController.php:14
* @route '/app/embed/pr-reviews/{owner}/{repo}/{prNumber}'
*/
show.url = (args: { owner: string | number, repo: string | number, prNumber: string | number } | [owner: string | number, repo: string | number, prNumber: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            owner: args[0],
            repo: args[1],
            prNumber: args[2],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        owner: args.owner,
        repo: args.repo,
        prNumber: args.prNumber,
    }

    return show.definition.url
            .replace('{owner}', parsedArgs.owner.toString())
            .replace('{repo}', parsedArgs.repo.toString())
            .replace('{prNumber}', parsedArgs.prNumber.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\AppEmbed\PrReviewController::show
* @see app/Http/Controllers/AppEmbed/PrReviewController.php:14
* @route '/app/embed/pr-reviews/{owner}/{repo}/{prNumber}'
*/
show.get = (args: { owner: string | number, repo: string | number, prNumber: string | number } | [owner: string | number, repo: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\AppEmbed\PrReviewController::show
* @see app/Http/Controllers/AppEmbed/PrReviewController.php:14
* @route '/app/embed/pr-reviews/{owner}/{repo}/{prNumber}'
*/
show.head = (args: { owner: string | number, repo: string | number, prNumber: string | number } | [owner: string | number, repo: string | number, prNumber: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const prReviews = {
    index: Object.assign(index, index),
    show: Object.assign(show, show),
}

export default prReviews