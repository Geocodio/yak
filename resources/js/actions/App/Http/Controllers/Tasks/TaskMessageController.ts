import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\TaskMessageController::store
* @see app/Http/Controllers/Tasks/TaskMessageController.php:18
* @route '/tasks/{task}/messages'
*/
export const store = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/tasks/{task}/messages',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\TaskMessageController::store
* @see app/Http/Controllers/Tasks/TaskMessageController.php:18
* @route '/tasks/{task}/messages'
*/
store.url = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
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

    return store.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskMessageController::store
* @see app/Http/Controllers/Tasks/TaskMessageController.php:18
* @route '/tasks/{task}/messages'
*/
store.post = (args: { task: number | { id: number } } | [task: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const TaskMessageController = { store }

export default TaskMessageController