import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
const PullRequestsShowController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PullRequestsShowController.url(args, options),
    method: 'get',
})

PullRequestsShowController.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/pull-requests',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
PullRequestsShowController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return PullRequestsShowController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
PullRequestsShowController.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PullRequestsShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
PullRequestsShowController.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PullRequestsShowController.url(args, options),
    method: 'head',
})

export default PullRequestsShowController