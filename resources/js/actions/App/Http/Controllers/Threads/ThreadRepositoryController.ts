import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryController::update
* @see app/Http/Controllers/Threads/ThreadRepositoryController.php:24
* @route '/threads/{thread}/repositories/{repository}'
*/
export const update = (args: { thread: number | { id: number }, repository: string | number | { id: string | number } } | [thread: number | { id: number }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/threads/{thread}/repositories/{repository}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryController::update
* @see app/Http/Controllers/Threads/ThreadRepositoryController.php:24
* @route '/threads/{thread}/repositories/{repository}'
*/
update.url = (args: { thread: number | { id: number }, repository: string | number | { id: string | number } } | [thread: number | { id: number }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            repository: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
        repository: typeof args.repository === 'object'
        ? args.repository.id
        : args.repository,
    }

    return update.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryController::update
* @see app/Http/Controllers/Threads/ThreadRepositoryController.php:24
* @route '/threads/{thread}/repositories/{repository}'
*/
update.patch = (args: { thread: number | { id: number }, repository: string | number | { id: string | number } } | [thread: number | { id: number }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryController::retry
* @see app/Http/Controllers/Threads/ThreadRepositoryController.php:37
* @route '/threads/{thread}/repositories/{repository}/retry'
*/
export const retry = (args: { thread: number | { id: number }, repository: string | number | { id: string | number } } | [thread: number | { id: number }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

retry.definition = {
    methods: ["post"],
    url: '/threads/{thread}/repositories/{repository}/retry',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ThreadRepositoryController::retry
* @see app/Http/Controllers/Threads/ThreadRepositoryController.php:37
* @route '/threads/{thread}/repositories/{repository}/retry'
*/
retry.url = (args: { thread: number | { id: number }, repository: string | number | { id: string | number } } | [thread: number | { id: number }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            repository: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
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
* @see \App\Http\Controllers\Threads\ThreadRepositoryController::retry
* @see app/Http/Controllers/Threads/ThreadRepositoryController.php:37
* @route '/threads/{thread}/repositories/{repository}/retry'
*/
retry.post = (args: { thread: number | { id: number }, repository: string | number | { id: string | number } } | [thread: number | { id: number }, repository: string | number | { id: string | number } ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: retry.url(args, options),
    method: 'post',
})

const ThreadRepositoryController = { update, retry }

export default ThreadRepositoryController