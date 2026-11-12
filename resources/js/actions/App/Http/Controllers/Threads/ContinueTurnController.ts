import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:19
* @route '/threads/{thread}/continue'
*/
const ContinueTurnController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ContinueTurnController.url(args, options),
    method: 'post',
})

ContinueTurnController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:19
* @route '/threads/{thread}/continue'
*/
ContinueTurnController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return ContinueTurnController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ContinueTurnController::__invoke
* @see app/Http/Controllers/Threads/ContinueTurnController.php:19
* @route '/threads/{thread}/continue'
*/
ContinueTurnController.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ContinueTurnController.url(args, options),
    method: 'post',
})

export default ContinueTurnController