import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
const PullRequestsShowController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PullRequestsShowController.url(args, options),
    method: 'get',
})

PullRequestsShowController.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/pull-requests',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
PullRequestsShowController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { thread: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
    }

    return PullRequestsShowController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
PullRequestsShowController.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: PullRequestsShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\PullRequests\PullRequestsShowController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/PullRequests/PullRequestsShowController.php:17
* @route '/threads/{thread}/pull-requests'
*/
PullRequestsShowController.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: PullRequestsShowController.url(args, options),
    method: 'head',
})

export default PullRequestsShowController