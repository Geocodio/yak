import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::create
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:24
* @route '/api/app/v1/threads/new'
*/
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

create.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/new',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::create
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:24
* @route '/api/app/v1/threads/new'
*/
create.url = (options?: RouteQueryOptions) => {
    return create.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::create
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:24
* @route '/api/app/v1/threads/new'
*/
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: create.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::create
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:24
* @route '/api/app/v1/threads/new'
*/
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: create.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::store
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:32
* @route '/api/app/v1/threads'
*/
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

store.definition = {
    methods: ["post"],
    url: '/api/app/v1/threads',
} satisfies RouteDefinition<["post"]>

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::store
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:32
* @route '/api/app/v1/threads'
*/
store.url = (options?: RouteQueryOptions) => {
    return store.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\Threads\NewThreadController::store
* @see app/Http/Controllers/Api/App/Threads/NewThreadController.php:32
* @route '/api/app/v1/threads'
*/
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
    url: store.url(options),
    method: 'post',
})

const NewThreadController = { create, store }

export default NewThreadController