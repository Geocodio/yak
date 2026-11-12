import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::show
* @see app/Http/Controllers/Threads/ThreadDiffController.php:14
* @route '/threads/{thread}/diff'
*/
export const show = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/diff',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::show
* @see app/Http/Controllers/Threads/ThreadDiffController.php:14
* @route '/threads/{thread}/diff'
*/
show.url = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::show
* @see app/Http/Controllers/Threads/ThreadDiffController.php:14
* @route '/threads/{thread}/diff'
*/
show.get = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::show
* @see app/Http/Controllers/Threads/ThreadDiffController.php:14
* @route '/threads/{thread}/diff'
*/
show.head = (args: { thread: number | { id: number } } | [thread: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::showFile
* @see app/Http/Controllers/Threads/ThreadDiffController.php:22
* @route '/threads/{thread}/diff/files/{file}'
*/
export const showFile = (args: { thread: number | { id: number }, file: string | number } | [thread: number | { id: number }, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showFile.url(args, options),
    method: 'get',
})

showFile.definition = {
    methods: ["get","head"],
    url: '/threads/{thread}/diff/files/{file}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::showFile
* @see app/Http/Controllers/Threads/ThreadDiffController.php:22
* @route '/threads/{thread}/diff/files/{file}'
*/
showFile.url = (args: { thread: number | { id: number }, file: string | number } | [thread: number | { id: number }, file: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            thread: args[0],
            file: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: typeof args.thread === 'object'
        ? args.thread.id
        : args.thread,
        file: args.file,
    }

    return showFile.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace('{file}', parsedArgs.file.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::showFile
* @see app/Http/Controllers/Threads/ThreadDiffController.php:22
* @route '/threads/{thread}/diff/files/{file}'
*/
showFile.get = (args: { thread: number | { id: number }, file: string | number } | [thread: number | { id: number }, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: showFile.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\ThreadDiffController::showFile
* @see app/Http/Controllers/Threads/ThreadDiffController.php:22
* @route '/threads/{thread}/diff/files/{file}'
*/
showFile.head = (args: { thread: number | { id: number }, file: string | number } | [thread: number | { id: number }, file: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: showFile.url(args, options),
    method: 'head',
})

const ThreadDiffController = { show, showFile }

export default ThreadDiffController