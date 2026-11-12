import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::length
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:31
* @route '/api/cli/v1/threads/{thread}/session/length'
*/
export const length = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: length.url(args, options),
    method: 'get',
})

length.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/threads/{thread}/session/length',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::length
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:31
* @route '/api/cli/v1/threads/{thread}/session/length'
*/
length.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return length.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::length
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:31
* @route '/api/cli/v1/threads/{thread}/session/length'
*/
length.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: length.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::length
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:31
* @route '/api/cli/v1/threads/{thread}/session/length'
*/
length.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: length.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::append
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:39
* @route '/api/cli/v1/threads/{thread}/session/append'
*/
export const append = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: append.url(args, options),
    method: 'post',
})

append.definition = {
    methods: ["post"],
    url: '/api/cli/v1/threads/{thread}/session/append',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::append
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:39
* @route '/api/cli/v1/threads/{thread}/session/append'
*/
append.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return append.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::append
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:39
* @route '/api/cli/v1/threads/{thread}/session/append'
*/
append.post = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: append.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::checkpoint
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:73
* @route '/api/cli/v1/threads/{thread}/session/checkpoint'
*/
export const checkpoint = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkpoint.url(args, options),
    method: 'post',
})

checkpoint.definition = {
    methods: ["post"],
    url: '/api/cli/v1/threads/{thread}/session/checkpoint',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::checkpoint
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:73
* @route '/api/cli/v1/threads/{thread}/session/checkpoint'
*/
checkpoint.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return checkpoint.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::checkpoint
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:73
* @route '/api/cli/v1/threads/{thread}/session/checkpoint'
*/
checkpoint.post = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: checkpoint.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::file
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:91
* @route '/api/cli/v1/threads/{thread}/session/file'
*/
export const file = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: file.url(args, options),
    method: 'put',
})

file.definition = {
    methods: ["put"],
    url: '/api/cli/v1/threads/{thread}/session/file',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::file
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:91
* @route '/api/cli/v1/threads/{thread}/session/file'
*/
file.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return file.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::file
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:91
* @route '/api/cli/v1/threads/{thread}/session/file'
*/
file.put = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: file.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::files
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:115
* @route '/api/cli/v1/threads/{thread}/session/files'
*/
export const files = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: files.url(args, options),
    method: 'get',
})

files.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/threads/{thread}/session/files',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::files
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:115
* @route '/api/cli/v1/threads/{thread}/session/files'
*/
files.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return files.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::files
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:115
* @route '/api/cli/v1/threads/{thread}/session/files'
*/
files.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: files.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::files
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:115
* @route '/api/cli/v1/threads/{thread}/session/files'
*/
files.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: files.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::read
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:123
* @route '/api/cli/v1/threads/{thread}/session/read'
*/
export const read = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: read.url(args, options),
    method: 'get',
})

read.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/threads/{thread}/session/read',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::read
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:123
* @route '/api/cli/v1/threads/{thread}/session/read'
*/
read.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return read.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::read
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:123
* @route '/api/cli/v1/threads/{thread}/session/read'
*/
read.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: read.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::read
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:123
* @route '/api/cli/v1/threads/{thread}/session/read'
*/
read.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: read.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::tree
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:145
* @route '/api/cli/v1/threads/{thread}/session/tree'
*/
export const tree = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tree.url(args, options),
    method: 'get',
})

tree.definition = {
    methods: ["get","head"],
    url: '/api/cli/v1/threads/{thread}/session/tree',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::tree
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:145
* @route '/api/cli/v1/threads/{thread}/session/tree'
*/
tree.url = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { thread: args }
    }

    if (Array.isArray(args)) {
        args = {
            thread: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        thread: args.thread,
    }

    return tree.definition.url
            .replace('{thread}', parsedArgs.thread.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::tree
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:145
* @route '/api/cli/v1/threads/{thread}/session/tree'
*/
tree.get = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: tree.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\Cli\SessionFileController::tree
* @see app/Http/Controllers/Api/Cli/SessionFileController.php:145
* @route '/api/cli/v1/threads/{thread}/session/tree'
*/
tree.head = (args: { thread: string | number } | [thread: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: tree.url(args, options),
    method: 'head',
})

const SessionFileController = { length, append, checkpoint, file, files, read, tree }

export default SessionFileController