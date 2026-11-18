import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
import prImages from './pr-images'
/**
* @see \App\Http\Controllers\Tasks\TaskRedirectController::show
* @see app/Http/Controllers/Tasks/TaskRedirectController.php:19
* @route '/tasks/{task}'
*/
export const show = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\TaskRedirectController::show
* @see app/Http/Controllers/Tasks/TaskRedirectController.php:19
* @route '/tasks/{task}'
*/
show.url = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: args.task,
    }

    return show.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskRedirectController::show
* @see app/Http/Controllers/Tasks/TaskRedirectController.php:19
* @route '/tasks/{task}'
*/
show.get = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\TaskRedirectController::show
* @see app/Http/Controllers/Tasks/TaskRedirectController.php:19
* @route '/tasks/{task}'
*/
show.head = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const tasks = {
    show: Object.assign(show, show),
    prImages: Object.assign(prImages, prImages),
}

export default tasks