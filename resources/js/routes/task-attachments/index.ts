import { queryParams, type RouteQueryOptions, type RouteDefinition, applyUrlDefaults } from './../../wayfinder'
/**
* @see \App\Http\Controllers\TaskAttachmentController::__invoke
* @see app/Http/Controllers/TaskAttachmentController.php:20
* @route '/task-attachments/{attachment}'
*/
export const show = (args: { attachment: string | number | { id: string | number } } | [attachment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

show.definition = {
    methods: ["get","head"],
    url: '/task-attachments/{attachment}',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\TaskAttachmentController::__invoke
* @see app/Http/Controllers/TaskAttachmentController.php:20
* @route '/task-attachments/{attachment}'
*/
show.url = (args: { attachment: string | number | { id: string | number } } | [attachment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions) => {
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

    return show.definition.url
            .replace('{attachment}', parsedArgs.attachment.toString())
            .replace(/\/+$/, '') + queryParams(options)
}

/**
* @see \App\Http\Controllers\TaskAttachmentController::__invoke
* @see app/Http/Controllers/TaskAttachmentController.php:20
* @route '/task-attachments/{attachment}'
*/
show.get = (args: { attachment: string | number | { id: string | number } } | [attachment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: show.url(args, options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\TaskAttachmentController::__invoke
* @see app/Http/Controllers/TaskAttachmentController.php:20
* @route '/task-attachments/{attachment}'
*/
show.head = (args: { attachment: string | number | { id: string | number } } | [attachment: string | number | { id: string | number } ] | string | number | { id: string | number }, options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: show.url(args, options),
    method: 'head',
})

const taskAttachments = {
    show: Object.assign(show, show),
}

export default taskAttachments