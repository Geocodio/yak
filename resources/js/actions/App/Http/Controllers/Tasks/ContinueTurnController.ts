import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\ContinueTurnController::__invoke
* @see app/Http/Controllers/Tasks/ContinueTurnController.php:20
* @route '/tasks/{task}/continue'
*/
const ContinueTurnController = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ContinueTurnController.url(args, options),
    method: 'post',
})

ContinueTurnController.definition = {
    methods: ["post"],
    url: '/tasks/{task}/continue',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\ContinueTurnController::__invoke
* @see app/Http/Controllers/Tasks/ContinueTurnController.php:20
* @route '/tasks/{task}/continue'
*/
ContinueTurnController.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return ContinueTurnController.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\ContinueTurnController::__invoke
* @see app/Http/Controllers/Tasks/ContinueTurnController.php:20
* @route '/tasks/{task}/continue'
*/
ContinueTurnController.post = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: ContinueTurnController.url(args, options),
    method: 'post',
})

export default ContinueTurnController