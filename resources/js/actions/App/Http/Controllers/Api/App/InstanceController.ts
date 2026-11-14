import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\InstanceController::__invoke
* @see app/Http/Controllers/Api/App/InstanceController.php:13
* @route '/api/app/v1/instance'
*/
const InstanceController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: InstanceController.url(options),
    method: 'get',
})

InstanceController.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/instance',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\InstanceController::__invoke
* @see app/Http/Controllers/Api/App/InstanceController.php:13
* @route '/api/app/v1/instance'
*/
InstanceController.url = (options?: RouteQueryOptions) => {
    return InstanceController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\InstanceController::__invoke
* @see app/Http/Controllers/Api/App/InstanceController.php:13
* @route '/api/app/v1/instance'
*/
InstanceController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: InstanceController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\InstanceController::__invoke
* @see app/Http/Controllers/Api/App/InstanceController.php:13
* @route '/api/app/v1/instance'
*/
InstanceController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: InstanceController.url(options),
    method: 'head',
})

export default InstanceController