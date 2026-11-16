import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Home\HomeController::__invoke
* @see app/Http/Controllers/Home/HomeController.php:21
* @route '/'
*/
const HomeController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: HomeController.url(options),
    method: 'get',
})

HomeController.definition = {
    methods: ["get","head"],
    url: '/',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Home\HomeController::__invoke
* @see app/Http/Controllers/Home/HomeController.php:21
* @route '/'
*/
HomeController.url = (options?: RouteQueryOptions) => {
    return HomeController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Home\HomeController::__invoke
* @see app/Http/Controllers/Home/HomeController.php:21
* @route '/'
*/
HomeController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: HomeController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Home\HomeController::__invoke
* @see app/Http/Controllers/Home/HomeController.php:21
* @route '/'
*/
HomeController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: HomeController.url(options),
    method: 'head',
})

export default HomeController