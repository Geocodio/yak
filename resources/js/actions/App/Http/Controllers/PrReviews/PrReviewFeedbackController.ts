import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/app/embed/pr-reviews'
*/
const PrReviewFeedbackController7955e80820529f1447a6f68d808ec475 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PrReviewFeedbackController7955e80820529f1447a6f68d808ec475.url(options),
    method: 'get',
})

PrReviewFeedbackController7955e80820529f1447a6f68d808ec475.definition = {
    methods: ["get","head"],
    url: '/app/embed/pr-reviews',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/app/embed/pr-reviews'
*/
PrReviewFeedbackController7955e80820529f1447a6f68d808ec475.url = (options?: RouteQueryOptions) => {
    return PrReviewFeedbackController7955e80820529f1447a6f68d808ec475.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/app/embed/pr-reviews'
*/
PrReviewFeedbackController7955e80820529f1447a6f68d808ec475.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PrReviewFeedbackController7955e80820529f1447a6f68d808ec475.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/app/embed/pr-reviews'
*/
PrReviewFeedbackController7955e80820529f1447a6f68d808ec475.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PrReviewFeedbackController7955e80820529f1447a6f68d808ec475.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/pr-reviews'
*/
const PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5.url(options),
    method: 'get',
})

PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5.definition = {
    methods: ["get","head"],
    url: '/pr-reviews',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/pr-reviews'
*/
PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5.url = (options?: RouteQueryOptions) => {
    return PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/pr-reviews'
*/
PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:22
* @route '/pr-reviews'
*/
PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5.url(options),
    method: 'head',
})

/**
* Multiple routes resolve to \App\Http\Controllers\PrReviews\PrReviewFeedbackController::PrReviewFeedbackController, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `PrReviewFeedbackController['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
const PrReviewFeedbackController = {
    '/app/embed/pr-reviews': PrReviewFeedbackController7955e80820529f1447a6f68d808ec475,
    '/pr-reviews': PrReviewFeedbackControllerd386bb3fdb6980c2076ee05a0d1f99d5,
}

export default PrReviewFeedbackController