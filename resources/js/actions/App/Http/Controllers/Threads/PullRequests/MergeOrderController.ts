import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
const MergeOrderController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: MergeOrderController.url(args, options),
    method: 'put',
})

MergeOrderController.definition = {
    methods: ["put"],
    url: '/threads/{thread}/pull-requests/order',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
MergeOrderController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { thread: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
    }

    return MergeOrderController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
MergeOrderController.put = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: MergeOrderController.url(args, options),
    method: 'put',
})

export default MergeOrderController