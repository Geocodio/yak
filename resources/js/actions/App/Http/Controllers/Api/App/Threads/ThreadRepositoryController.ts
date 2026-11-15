import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadRepositoryController::retry
* @see app/Http/Controllers/Api/App/Threads/ThreadRepositoryController.php:16
* @route '/api/app/v1/threads/{thread}/repositories/{repository}/retry'
*/
export const retry = (args: { thread: string | { public_id: string }, repository: string | number | { id: string | number } } | [thread: string | { public_id: string }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads/{thread}/repositories/{repository}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadRepositoryController::retry
* @see app/Http/Controllers/Api/App/Threads/ThreadRepositoryController.php:16
* @route '/api/app/v1/threads/{thread}/repositories/{repository}/retry'
*/
retry.url = (args: { thread: string | { public_id: string }, repository: string | number | { id: string | number } } | [thread: string | { public_id: string }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            repository: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.public_id
        : args.thread,
        repository: typeof args.repository === 'object'
        ? args.repository.id
        : args.repository,
    }

    return retry.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadRepositoryController::retry
* @see app/Http/Controllers/Api/App/Threads/ThreadRepositoryController.php:16
* @route '/api/app/v1/threads/{thread}/repositories/{repository}/retry'
*/
retry.post = (args: { thread: string | { public_id: string }, repository: string | number | { id: string | number } } | [thread: string | { public_id: string }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

const ThreadRepositoryController = { retry }

export default ThreadRepositoryController