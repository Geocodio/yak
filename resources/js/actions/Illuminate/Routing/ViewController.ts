import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../wayfinder'
/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/login'
*/
const ViewController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ViewController.url(options),
    method: 'get',
})

ViewController.definition = {
    methods: ["get","head"],
    url: '/login',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/login'
*/
ViewController.url = (options?: RouteQueryOptions) => {
    return ViewController.definition.url + queryParams(options)
}

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/login'
*/
ViewController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: ViewController.url(options),
    method: 'get',
})

/**
* @see \Illuminate\Routing\ViewController::__invoke
* @see vendor/laravel/framework/src/Illuminate/Routing/ViewController.php:32
* @route '/login'
*/
ViewController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: ViewController.url(options),
    method: 'head',
})

export default ViewController