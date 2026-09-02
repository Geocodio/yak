import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
const RequestReReviewController = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RequestReReviewController.url(args, options),
    method: 'get',
})

RequestReReviewController.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}/re-request-review',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
RequestReReviewController.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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
RequestReReviewController.get = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RequestReReviewController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
RequestReReviewController.head = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RequestReReviewController.url(args, options),
    method: 'head',
})

export default RequestReReviewController