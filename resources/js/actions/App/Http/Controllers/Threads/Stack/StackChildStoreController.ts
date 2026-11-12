import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Stack\StackChildStoreController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackChildStoreController.php:20
* @route '/threads/{thread}/stack/children'
*/
const StackChildStoreController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StackChildStoreController.url(args, options),
    method: 'post',
})

StackChildStoreController.definition = {
    methods: ["post"],
    url: '/threads/{thread}/stack/children',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Stack\StackChildStoreController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackChildStoreController.php:20
* @route '/threads/{thread}/stack/children'
*/
StackChildStoreController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return StackChildStoreController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\StackChildStoreController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackChildStoreController.php:20
* @route '/threads/{thread}/stack/children'
*/
StackChildStoreController.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StackChildStoreController.url(args, options),
    method: 'post',
})

export default StackChildStoreController