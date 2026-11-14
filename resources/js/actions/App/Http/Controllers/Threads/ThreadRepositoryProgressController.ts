import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryProgressController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadRepositoryProgressController.php:17
* @route '/threads/{thread}/repositories'
*/
const ThreadRepositoryProgressController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadRepositoryProgressController.url(args, options),
    method: 'get',
})

ThreadRepositoryProgressController.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/repositories',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryProgressController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadRepositoryProgressController.php:17
* @route '/threads/{thread}/repositories'
*/
ThreadRepositoryProgressController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return ThreadRepositoryProgressController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryProgressController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadRepositoryProgressController.php:17
* @route '/threads/{thread}/repositories'
*/
ThreadRepositoryProgressController.get = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ThreadRepositoryProgressController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryProgressController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Threads/ThreadRepositoryProgressController.php:17
* @route '/threads/{thread}/repositories'
*/
ThreadRepositoryProgressController.head = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ThreadRepositoryProgressController.url(args, options),
    method: 'head',
})

export default ThreadRepositoryProgressController