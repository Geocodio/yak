import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../../../wayfinder'
/**
* @see \App\Http\Controllers\TaskAttachmentController::__invoke
* @see app/Http/Controllers/TaskAttachmentController.php:20
* @route '/task-attachments/{attachment}'
*/
const TaskAttachmentController = (args: { attachment: number | { id: number } } | [attachment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TaskAttachmentController.url(args, options),
    method: 'get',
})

TaskAttachmentController.definition = {
    methods: ["get","head"],
    url: '/task-attachments/{attachment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\TaskAttachmentController::__invoke
* @see app/Http/Controllers/TaskAttachmentController.php:20
* @route '/task-attachments/{attachment}'
*/
TaskAttachmentController.url = (args: { attachment: number | { id: number } } | [attachment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions) => {
    if (typeof args === 'string' || typeof args === 'number') {
        args = { attachment: args }
    }

    if (typeof args === 'object' && !Array.isArray(args) && 'id' in args) {
        args = { attachment: args.id }
    }

    if (Array.isArray(args)) {
        args = {
            attachment: args[0],
        }
    }

    args = applyUrlDefaults(args)

    const parsedArgs = {
        attachment: typeof args.attachment === 'object'
        ? args.attachment.id
        : args.attachment,
    }

    return TaskAttachmentController.definition.url
            .replace('{attachment}', parsedArgs.attachment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\TaskAttachmentController::__invoke
* @see app/Http/Controllers/TaskAttachmentController.php:20
* @route '/task-attachments/{attachment}'
*/
TaskAttachmentController.get = (args: { attachment: number | { id: number } } | [attachment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: TaskAttachmentController.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\TaskAttachmentController::__invoke
* @see app/Http/Controllers/TaskAttachmentController.php:20
* @route '/task-attachments/{attachment}'
*/
TaskAttachmentController.head = (args: { attachment: number | { id: number } } | [attachment: number | { id: number } ] | number | { id: number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: TaskAttachmentController.url(args, options),
    method: 'head',
})

export default TaskAttachmentController