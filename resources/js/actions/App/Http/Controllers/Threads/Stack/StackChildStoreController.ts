import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Stack\StackChildStoreController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackChildStoreController.php:20
* @route '/threads/{thread}/stack/children'
*/
const StackChildStoreController = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
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
StackChildStoreController.url = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return StackChildStoreController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\StackChildStoreController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackChildStoreController.php:20
* @route '/threads/{thread}/stack/children'
*/
StackChildStoreController.post = (args: { thread: string | { public_id: string } } | [thread: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: StackChildStoreController.url(args, options),
    method: 'post',
})

export default StackChildStoreController