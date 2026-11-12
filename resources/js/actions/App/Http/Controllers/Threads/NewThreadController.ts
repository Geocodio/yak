import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:13
* @route '/threads/new'
*/
const NewThreadController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: NewThreadController.url(options),
    method: 'get',
})

NewThreadController.definition = {
    methods: ["get","head"],
    url: '/threads/new',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:13
* @route '/threads/new'
*/
NewThreadController.url = (options?: RouteQueryOptions) => {
    return NewThreadController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:13
* @route '/threads/new'
*/
NewThreadController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: NewThreadController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\NewThreadController::__invoke
* @see app/Http/Controllers/Threads/NewThreadController.php:13
* @route '/threads/new'
*/
NewThreadController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: NewThreadController.url(options),
    method: 'head',
})

export default NewThreadController