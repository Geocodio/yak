import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see app/Http/Controllers/Tasks/PullRequestImageController.php:24
* @route '/tasks/{task}/pr-images/{asset}'
*/
export const show = (args: { task: string | number | { id: string | number }, asset: string | number } | [task: string | number | { id: string | number }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}/pr-images/{asset}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see app/Http/Controllers/Tasks/PullRequestImageController.php:24
* @route '/tasks/{task}/pr-images/{asset}'
*/
show.url = (args: { task: string | number | { id: string | number }, asset: string | number } | [task: string | number | { id: string | number }, asset: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            task: args[0],
            asset: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
        asset: args.asset,
    }

    return show.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{asset}', parsedArgs.asset.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see app/Http/Controllers/Tasks/PullRequestImageController.php:24
* @route '/tasks/{task}/pr-images/{asset}'
*/
show.get = (args: { task: string | number | { id: string | number }, asset: string | number } | [task: string | number | { id: string | number }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\PullRequestImageController::__invoke
* @see app/Http/Controllers/Tasks/PullRequestImageController.php:24
* @route '/tasks/{task}/pr-images/{asset}'
*/
show.head = (args: { task: string | number | { id: string | number }, asset: string | number } | [task: string | number | { id: string | number }, asset: string | number ], options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const prImages = {
    show: Object.assign(show, show),
}

export default prImages