import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\SharedFileController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/SharedFileController.php:29
* @route '/files/{publicId}'
*/
export const show = (args: { publicId: string | number } | [publicId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/files/{publicId}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SharedFileController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/SharedFileController.php:29
* @route '/files/{publicId}'
*/
show.url = (args: { publicId: string | number } | [publicId: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { publicId: args }
    }

    if (Array.isArray(args)) {
        args = {
            publicId: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        publicId: args.publicId,
    }

    return show.definition.url
            .replace('{publicId}', parsedArgs.publicId.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SharedFileController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/SharedFileController.php:29
* @route '/files/{publicId}'
*/
show.get = (args: { publicId: string | number } | [publicId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SharedFileController::show
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/SharedFileController.php:29
* @route '/files/{publicId}'
*/
show.head = (args: { publicId: string | number } | [publicId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\SharedFileController::raw
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/SharedFileController.php:48
* @route '/files/{publicId}/raw'
*/
export const raw = (args: { publicId: string | number } | [publicId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: raw.url(args, options),
    method: 'get',
})

raw.definition = {
    methods: ["get","head"],
    url: '/files/{publicId}/raw',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\SharedFileController::raw
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/SharedFileController.php:48
* @route '/files/{publicId}/raw'
*/
raw.url = (args: { publicId: string | number } | [publicId: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { publicId: args }
    }

    if (Array.isArray(args)) {
        args = {
            publicId: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        publicId: args.publicId,
    }

    return raw.definition.url
            .replace('{publicId}', parsedArgs.publicId.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\SharedFileController::raw
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/SharedFileController.php:48
* @route '/files/{publicId}/raw'
*/
raw.get = (args: { publicId: string | number } | [publicId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: raw.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\SharedFileController::raw
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/SharedFileController.php:48
* @route '/files/{publicId}/raw'
*/
raw.head = (args: { publicId: string | number } | [publicId: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: raw.url(args, options),
    method: 'head',
})

const SharedFileController = { show, raw }

export default SharedFileController