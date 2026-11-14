import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:17
* @route '/tasks/{task}/re-request-review'
*/
const RequestReReviewController = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestReReviewController.url(args, options),
    method: 'post',
})

RequestReReviewController.definition = {
    methods: ["post"],
    url: '/tasks/{task}/re-request-review',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:17
* @route '/tasks/{task}/re-request-review'
*/
RequestReReviewController.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return RequestReReviewController.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:17
* @route '/tasks/{task}/re-request-review'
*/
RequestReReviewController.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestReReviewController.url(args, options),
    method: 'post',
})

export default RequestReReviewController