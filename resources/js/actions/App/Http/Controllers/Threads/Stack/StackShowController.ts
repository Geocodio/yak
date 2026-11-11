import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Stack\StackShowController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackShowController.php:17
* @route '/threads/{thread}/stack'
*/
const StackShowController = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: StackShowController.url(args, options),
    method: 'get',
})

StackShowController.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/stack',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\Stack\StackShowController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackShowController.php:17
* @route '/threads/{thread}/stack'
*/
StackShowController.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return StackShowController.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\StackShowController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackShowController.php:17
* @route '/threads/{thread}/stack'
*/
StackShowController.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: StackShowController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\Stack\StackShowController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackShowController.php:17
* @route '/threads/{thread}/stack'
*/
StackShowController.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: StackShowController.url(args, options),
    method: 'head',
})

export default StackShowController