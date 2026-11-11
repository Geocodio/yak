import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\Stack\StackChildStoreController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackChildStoreController.php:20
* @route '/threads/{thread}/stack/children'
*/
export const store = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/threads/{thread}/stack/children',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Threads\Stack\StackChildStoreController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackChildStoreController.php:20
* @route '/threads/{thread}/stack/children'
*/
store.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\StackChildStoreController::__invoke
* @see app/Http/Controllers/Threads/Stack/StackChildStoreController.php:20
* @route '/threads/{thread}/stack/children'
*/
store.post = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Threads\Stack\ChildDiffController::__invoke
* @see app/Http/Controllers/Threads/Stack/ChildDiffController.php:16
* @route '/threads/{thread}/stack/children/{child}/diff'
*/
export const diff = (args: { thread: number | { id: number }, child: number | { id: number } } | [thread: number | { id: number }, child: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: diff.url(args, options),
    method: 'get',
})

diff.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/stack/children/{child}/diff',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\Stack\ChildDiffController::__invoke
* @see app/Http/Controllers/Threads/Stack/ChildDiffController.php:16
* @route '/threads/{thread}/stack/children/{child}/diff'
*/
diff.url = (args: { thread: number | { id: number }, child: number | { id: number } } | [thread: number | { id: number }, child: number | { id: number } ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            child: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
        child: typeof args.child === 'object'
        ? args.child.id
        : args.child,
    }

    return diff.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{child}', parsedArgs.child.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\Stack\ChildDiffController::__invoke
* @see app/Http/Controllers/Threads/Stack/ChildDiffController.php:16
* @route '/threads/{thread}/stack/children/{child}/diff'
*/
diff.get = (args: { thread: number | { id: number }, child: number | { id: number } } | [thread: number | { id: number }, child: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: diff.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\Stack\ChildDiffController::__invoke
* @see app/Http/Controllers/Threads/Stack/ChildDiffController.php:16
* @route '/threads/{thread}/stack/children/{child}/diff'
*/
diff.head = (args: { thread: number | { id: number }, child: number | { id: number } } | [thread: number | { id: number }, child: number | { id: number } ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: diff.url(args, options),
    method: 'head',
})

const children = {
    store: Object.assign(store, store),
    diff: Object.assign(diff, diff),
}

export default children