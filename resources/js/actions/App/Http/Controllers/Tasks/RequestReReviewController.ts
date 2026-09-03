import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
const RequestReReviewController = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestReReviewController.url(args, options),
    method: 'post',
})

RequestReReviewController.definition = {
    methods: ["post"],
    url: '/tasks/{task}/re-request-review',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
RequestReReviewController.url = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return RequestReReviewController.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
RequestReReviewController.post = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: RequestReReviewController.url(args, options),
    method: 'post',
})

export default RequestReReviewController