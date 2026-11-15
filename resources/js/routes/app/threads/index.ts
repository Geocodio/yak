import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

index.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
index.url = (options?: RouteQueryOptions) => {
    return index.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: index.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\ThreadListController::__invoke
* @see app/Http/Controllers/Api/App/Threads/ThreadListController.php:12
* @route '/api/app/v1/threads'
*/
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: index.url(options),
    method: 'head',
})

const threads = {
    index: Object.assign(index, index),
}

export default threads