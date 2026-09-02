import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:21
* @route '/pr-reviews'
*/
const PrReviewFeedbackController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PrReviewFeedbackController.url(options),
    method: 'get',
})

PrReviewFeedbackController.definition = {
    methods: ["get","head"],
    url: '/pr-reviews',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:21
* @route '/pr-reviews'
*/
PrReviewFeedbackController.url = (options?: RouteQueryOptions) => {
    return PrReviewFeedbackController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:21
* @route '/pr-reviews'
*/
PrReviewFeedbackController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PrReviewFeedbackController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PrReviews\PrReviewFeedbackController::__invoke
* @see app/Http/Controllers/PrReviews/PrReviewFeedbackController.php:21
* @route '/pr-reviews'
*/
PrReviewFeedbackController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PrReviewFeedbackController.url(options),
    method: 'head',
})

export default PrReviewFeedbackController