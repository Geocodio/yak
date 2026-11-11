import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Stack\StackLimitController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackLimitController.php:17
* @route '/threads/{thread}/stack/limit'
*/
const StackLimitController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: StackLimitController.url(args, options),
    method: 'patch',
})

StackLimitController.definition = {
    methods: ["patch"],
    url: '/threads/{thread}/stack/limit',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Threads\Stack\StackLimitController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackLimitController.php:17
* @route '/threads/{thread}/stack/limit'
*/
StackLimitController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return StackLimitController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\StackLimitController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackLimitController.php:17
* @route '/threads/{thread}/stack/limit'
*/
StackLimitController.patch = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: StackLimitController.url(args, options),
    method: 'patch',
})

export default StackLimitController