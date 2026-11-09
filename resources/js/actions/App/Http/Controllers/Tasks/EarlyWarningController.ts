import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::tag
* @see app/Http/Controllers/Tasks/EarlyWarningController.php:22
* @route '/tasks/{task}/co-owner/tag'
*/
export const tag = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: tag.url(args, options),
    method: 'post',
})

tag.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/tag',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::tag
* @see app/Http/Controllers/Tasks/EarlyWarningController.php:22
* @route '/tasks/{task}/co-owner/tag'
*/
tag.url = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return tag.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::tag
* @see app/Http/Controllers/Tasks/EarlyWarningController.php:22
* @route '/tasks/{task}/co-owner/tag'
*/
tag.post = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: tag.url(args, options),
    method: 'post',
})

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::decline
* @see app/Http/Controllers/Tasks/EarlyWarningController.php:29
* @route '/tasks/{task}/co-owner/decline'
*/
export const decline = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: decline.url(args, options),
    method: 'post',
})

decline.definition = {
    methods: ["post"],
    url: '/tasks/{task}/co-owner/decline',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::decline
* @see app/Http/Controllers/Tasks/EarlyWarningController.php:29
* @route '/tasks/{task}/co-owner/decline'
*/
decline.url = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { task: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
    }

    return decline.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\EarlyWarningController::decline
* @see app/Http/Controllers/Tasks/EarlyWarningController.php:29
* @route '/tasks/{task}/co-owner/decline'
*/
decline.post = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: decline.url(args, options),
    method: 'post',
})

const EarlyWarningController = { tag, decline }

export default EarlyWarningController