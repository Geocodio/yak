import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\ThreadAccountController::update
* @see app/Http/Controllers/Tasks/ThreadAccountController.php:21
* @route '/tasks/{task}/thread/account'
*/
export const update = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

update.definition = {
    methods: ["put"],
    url: '/tasks/{task}/thread/account',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Tasks\ThreadAccountController::update
* @see app/Http/Controllers/Tasks/ThreadAccountController.php:21
* @route '/tasks/{task}/thread/account'
*/
update.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return update.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\ThreadAccountController::update
* @see app/Http/Controllers/Tasks/ThreadAccountController.php:21
* @route '/tasks/{task}/thread/account'
*/
update.put = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: update.url(args, options),
    method: 'put',
})

/**
* @see \App\Http\Controllers\Tasks\ThreadAccountController::updateModel
* @see app/Http/Controllers/Tasks/ThreadAccountController.php:44
* @route '/tasks/{task}/thread/model'
*/
export const updateModel = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateModel.url(args, options),
    method: 'put',
})

updateModel.definition = {
    methods: ["put"],
    url: '/tasks/{task}/thread/model',
} satisfies RouteDefinition<["put"]>

/**
* @see \App\Http\Controllers\Tasks\ThreadAccountController::updateModel
* @see app/Http/Controllers/Tasks/ThreadAccountController.php:44
* @route '/tasks/{task}/thread/model'
*/
updateModel.url = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return updateModel.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\ThreadAccountController::updateModel
* @see app/Http/Controllers/Tasks/ThreadAccountController.php:44
* @route '/tasks/{task}/thread/model'
*/
updateModel.put = (args: { task: string | number | { id: string | number } } | [task: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'put'> => ({
    url: updateModel.url(args, options),
    method: 'put',
})

const ThreadAccountController = { update, updateModel }

export default ThreadAccountController