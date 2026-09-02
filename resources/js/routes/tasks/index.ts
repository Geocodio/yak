import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
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
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
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
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/tasks/{task}'
*/
show.get = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \Livewire\Mechanisms\HandleRouting\LivewirePageController::__invoke
* @see vendor/livewire/livewire/src/Mechanisms/HandleRouting/LivewirePageController.php:7
* @route '/tasks/{task}'
*/
show.head = (args: { task: string | number } | [task: string | number ] | string | number, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
export const reRequestReview = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: reRequestReview.url(args, options),
    method: 'get',
})

reRequestReview.definition = {
    methods: ["get","head"],
    url: '/tasks/{task}/re-request-review',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
reRequestReview.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return reRequestReview.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
reRequestReview.get = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: reRequestReview.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Tasks\RequestReReviewController::__invoke
* @see app/Http/Controllers/Tasks/RequestReReviewController.php:16
* @route '/tasks/{task}/re-request-review'
*/
reRequestReview.head = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: reRequestReview.url(args, options),
    method: 'head',
})

const tasks = {
    show: Object.assign(show, show),
    reRequestReview: Object.assign(reRequestReview, reRequestReview),
}

export default tasks