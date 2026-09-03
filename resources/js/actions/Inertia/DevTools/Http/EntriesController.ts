import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \Inertia\DevTools\Http\EntriesController::index
* @see vendor/inertiajs/inertia-laravel/src/DevTools/Http/EntriesController.php:17
* @route '/_inertia/devtools/entries'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/_inertia/devtools/entries',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\DevTools\Http\EntriesController::index
* @see vendor/inertiajs/inertia-laravel/src/DevTools/Http/EntriesController.php:17
* @route '/_inertia/devtools/entries'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \Inertia\DevTools\Http\EntriesController::index
* @see vendor/inertiajs/inertia-laravel/src/DevTools/Http/EntriesController.php:17
* @route '/_inertia/devtools/entries'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \Inertia\DevTools\Http\EntriesController::index
* @see vendor/inertiajs/inertia-laravel/src/DevTools/Http/EntriesController.php:17
* @route '/_inertia/devtools/entries'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

/**
* @see \Inertia\DevTools\Http\EntriesController::show
* @see vendor/inertiajs/inertia-laravel/src/DevTools/Http/EntriesController.php:37
* @route '/_inertia/devtools/entries/{id}'
*/
export const show = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/_inertia/devtools/entries/{id}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Inertia\DevTools\Http\EntriesController::show
* @see vendor/inertiajs/inertia-laravel/src/DevTools/Http/EntriesController.php:37
* @route '/_inertia/devtools/entries/{id}'
*/
show.url = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { id: args }
    }

    if (Array.isArray(args)) {
        args = {
            id: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        id: args.id,
    }

    return show.definition.url
            .replace('{id}', parsedArgs.id.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \Inertia\DevTools\Http\EntriesController::show
* @see vendor/inertiajs/inertia-laravel/src/DevTools/Http/EntriesController.php:37
* @route '/_inertia/devtools/entries/{id}'
*/
show.get = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Inertia\DevTools\Http\EntriesController::show
* @see vendor/inertiajs/inertia-laravel/src/DevTools/Http/EntriesController.php:37
* @route '/_inertia/devtools/entries/{id}'
*/
show.head = (args: { id: string | number } | [id: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const EntriesController = { index, show }

export default EntriesController