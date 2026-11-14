import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\TaskClarificationAnswerController::__invoke
* @see app/Http/Controllers/Tasks/TaskClarificationAnswerController.php:13
* @route '/tasks/{task}/clarification-answers'
*/
export const store = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/tasks/{task}/clarification-answers',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\TaskClarificationAnswerController::__invoke
* @see app/Http/Controllers/Tasks/TaskClarificationAnswerController.php:13
* @route '/tasks/{task}/clarification-answers'
*/
store.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { task: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'public_id' in args) {
        args = { task: args.public_id }
    }

    if (Array.isArray(args)) {
        args = {
            task: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        task: typeof args.task === 'object'
        ? args.task.public_id
        : args.task,
    }

    return store.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskClarificationAnswerController::__invoke
* @see app/Http/Controllers/Tasks/TaskClarificationAnswerController.php:13
* @route '/tasks/{task}/clarification-answers'
*/
store.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(args, options),
    method: 'post',
})

const clarificationAnswers = {
    store: Object.assign(store, store),
}

export default clarificationAnswers