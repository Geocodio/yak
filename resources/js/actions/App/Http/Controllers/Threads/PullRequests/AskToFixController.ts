import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\PullRequests\AskToFixController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/AskToFixController.php:19
* @route '/threads/{thread}/pull-requests/{repository}/fix'
*/
const AskToFixController = (args: { thread: number | { id: number }, repository: string | number } | [thread: number | { id: number }, repository: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: AskToFixController.url(args, options),
    method: 'post',
})

AskToFixController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/pull-requests/{repository}/fix',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\PullRequests\AskToFixController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/AskToFixController.php:19
* @route '/threads/{thread}/pull-requests/{repository}/fix'
*/
AskToFixController.url = (args: { thread: number | { id: number }, repository: string | number } | [thread: number | { id: number }, repository: string | number ], options?: RouteQueryOptions) => {
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
        repository: args.repository,
    }

    return AskToFixController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{repository}', parsedArgs.repository.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\PullRequests\AskToFixController::__invoke
* @see app/Http/Controllers/Threads/PullRequests/AskToFixController.php:19
* @route '/threads/{thread}/pull-requests/{repository}/fix'
*/
AskToFixController.post = (args: { thread: number | { id: number }, repository: string | number } | [thread: number | { id: number }, repository: string | number ], options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: AskToFixController.url(args, options),
    method: 'post',
})

export default AskToFixController