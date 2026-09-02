import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\ArtifactController::publicImage
* @see app/Http/Controllers/ArtifactController.php:55
* @route '/artifacts/public/{token}'
*/
export const publicImage = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: publicImage.url(args, options),
    method: 'get',
})

publicImage.definition = {
    methods: ["get","head"],
    url: '/artifacts/public/{token}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ArtifactController::publicImage
* @see app/Http/Controllers/ArtifactController.php:55
* @route '/artifacts/public/{token}'
*/
publicImage.url = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { token: args }
    }

    if (Array.isArray(args)) {
        args = {
            token: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        token: args.token,
    }

    return publicImage.definition.url
            .replace('{token}', parsedArgs.token.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ArtifactController::publicImage
* @see app/Http/Controllers/ArtifactController.php:55
* @route '/artifacts/public/{token}'
*/
publicImage.get = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: publicImage.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ArtifactController::publicImage
* @see app/Http/Controllers/ArtifactController.php:55
* @route '/artifacts/public/{token}'
*/
publicImage.head = (args: { token: string | number } | [token: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: publicImage.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\ArtifactController::viewer
* @see app/Http/Controllers/ArtifactController.php:35
* @route '/artifacts/{task}/viewer/{filename}'
*/
export const viewer = (args: { task: string | number | { id: string | number }, filename: string | number } | [task: string | number | { id: string | number }, filename: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: viewer.url(args, options),
    method: 'get',
})

viewer.definition = {
    methods: ["get","head"],
    url: '/artifacts/{task}/viewer/{filename}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ArtifactController::viewer
* @see app/Http/Controllers/ArtifactController.php:35
* @route '/artifacts/{task}/viewer/{filename}'
*/
viewer.url = (args: { task: string | number | { id: string | number }, filename: string | number } | [task: string | number | { id: string | number }, filename: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            task: args[0],
            filename: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
        filename: args.filename,
    }

    return viewer.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{filename}', parsedArgs.filename.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ArtifactController::viewer
* @see app/Http/Controllers/ArtifactController.php:35
* @route '/artifacts/{task}/viewer/{filename}'
*/
viewer.get = (args: { task: string | number | { id: string | number }, filename: string | number } | [task: string | number | { id: string | number }, filename: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: viewer.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ArtifactController::viewer
* @see app/Http/Controllers/ArtifactController.php:35
* @route '/artifacts/{task}/viewer/{filename}'
*/
viewer.head = (args: { task: string | number | { id: string | number }, filename: string | number } | [task: string | number | { id: string | number }, filename: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: viewer.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\ArtifactController::show
* @see app/Http/Controllers/ArtifactController.php:15
* @route '/artifacts/{task}/{filename}'
*/
export const show = (args: { task: string | number | { id: string | number }, filename: string | number } | [task: string | number | { id: string | number }, filename: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/artifacts/{task}/{filename}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\ArtifactController::show
* @see app/Http/Controllers/ArtifactController.php:15
* @route '/artifacts/{task}/{filename}'
*/
show.url = (args: { task: string | number | { id: string | number }, filename: string | number } | [task: string | number | { id: string | number }, filename: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            task: args[0],
            filename: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
        filename: args.filename,
    }

    return show.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{filename}', parsedArgs.filename.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\ArtifactController::show
* @see app/Http/Controllers/ArtifactController.php:15
* @route '/artifacts/{task}/{filename}'
*/
show.get = (args: { task: string | number | { id: string | number }, filename: string | number } | [task: string | number | { id: string | number }, filename: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\ArtifactController::show
* @see app/Http/Controllers/ArtifactController.php:15
* @route '/artifacts/{task}/{filename}'
*/
show.head = (args: { task: string | number | { id: string | number }, filename: string | number } | [task: string | number | { id: string | number }, filename: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const ArtifactController = { publicImage, viewer, show }

export default ArtifactController