import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Tasks\TaskClarificationAnswerController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskClarificationAnswerController.php:13
* @route '/tasks/{task}/clarification-answers'
*/
const TaskClarificationAnswerController = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: TaskClarificationAnswerController.url(args, options),
    method: 'post',
})

TaskClarificationAnswerController.definition = {
    methods: ["post"],
    url: '/tasks/{task}/clarification-answers',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Tasks\TaskClarificationAnswerController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskClarificationAnswerController.php:13
* @route '/tasks/{task}/clarification-answers'
*/
TaskClarificationAnswerController.url = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions) => {
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

    return TaskClarificationAnswerController.definition.url
            .replace('{task}', parsedArgs.task.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\Tasks\TaskClarificationAnswerController::__invoke
* @see Users/codemonkey/projects/yak/.worktrees/next-gen/app/Http/Controllers/Tasks/TaskClarificationAnswerController.php:13
* @route '/tasks/{task}/clarification-answers'
*/
TaskClarificationAnswerController.post = (args: { task: string | { public_id: string } } | [task: string | { public_id: string } ] | string | { public_id: string }, options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: TaskClarificationAnswerController.url(args, options),
    method: 'post',
})

export default TaskClarificationAnswerController