import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\QueuedMessageController::update
* @see app/Http/Controllers/Tasks/QueuedMessageController.php:20
* @route '/tasks/{task}/queued-messages/{message}'
*/
export const update = (args: { task: number | { id: number }, message: string | number } | [task: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

update.definition = {
    methods: ["patch"],
    url: '/tasks/{task}/queued-messages/{message}',
} satisfies RouteDefinition<["patch"]>

/**
* @see \App\Http\Controllers\Tasks\QueuedMessageController::update
* @see app/Http/Controllers/Tasks/QueuedMessageController.php:20
* @route '/tasks/{task}/queued-messages/{message}'
*/
update.url = (args: { task: number | { id: number }, message: string | number } | [task: number | { id: number }, message: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            task: args[0],
            message: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
        message: args.message,
    }

    return update.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{message}', parsedArgs.message.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\QueuedMessageController::update
* @see app/Http/Controllers/Tasks/QueuedMessageController.php:20
* @route '/tasks/{task}/queued-messages/{message}'
*/
update.patch = (args: { task: number | { id: number }, message: string | number } | [task: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'patch'> => ({
    url: update.url(args, options),
    method: 'patch',
})

/**
* @see \App\Http\Controllers\Tasks\QueuedMessageController::destroy
* @see app/Http/Controllers/Tasks/QueuedMessageController.php:43
* @route '/tasks/{task}/queued-messages/{message}'
*/
export const destroy = (args: { task: number | { id: number }, message: string | number } | [task: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

destroy.definition = {
    methods: ["delete"],
    url: '/tasks/{task}/queued-messages/{message}',
} satisfies RouteDefinition<["delete"]>

/**
* @see \App\Http\Controllers\Tasks\QueuedMessageController::destroy
* @see app/Http/Controllers/Tasks/QueuedMessageController.php:43
* @route '/tasks/{task}/queued-messages/{message}'
*/
destroy.url = (args: { task: number | { id: number }, message: string | number } | [task: number | { id: number }, message: string | number ], options?: RouteQueryOptions) => {
    if (Array.isArray(args)) {
        args = {
            task: args[0],
            message: args[1],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.id
        : args.task,
        message: args.message,
    }

    return destroy.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace('{message}', parsedArgs.message.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\QueuedMessageController::destroy
* @see app/Http/Controllers/Tasks/QueuedMessageController.php:43
* @route '/tasks/{task}/queued-messages/{message}'
*/
destroy.delete = (args: { task: number | { id: number }, message: string | number } | [task: number | { id: number }, message: string | number ], options?: RouteQueryOptions): RouteDefinition<'delete'> => ({
    url: destroy.url(args, options),
    method: 'delete',
})

const queuedMessages = {
    update: Object.assign(update, update),
    destroy: Object.assign(destroy, destroy),
}

export default queuedMessages