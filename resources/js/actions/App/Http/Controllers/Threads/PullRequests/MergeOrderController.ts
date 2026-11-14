import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
const MergeOrderController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: MergeOrderController.url(args, options),
    method: 'put',
})

MergeOrderController.definition = {
    methods: ["put"],
    url: '/threads/{thread}/pull-requests/order',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
MergeOrderController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return MergeOrderController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\PullRequests\MergeOrderController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/PullRequests/MergeOrderController.php:17
* @route '/threads/{thread}/pull-requests/order'
*/
MergeOrderController.put = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: MergeOrderController.url(args, options),
    method: 'put',
})

export default MergeOrderController