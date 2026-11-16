import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
const RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d.url(options),
    method: 'get',
})

RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d.definition = {
    methods: ["get","head"],
    url: '/threads/new/repository-access',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d.url = (options?: RouteQueryOptions) => {
    return RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/threads/new/repository-access'
*/
RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d.url(options),
    method: 'head',
})

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/api/app/v1/threads/new/repository-access'
*/
const RepositoryAccessController7aed96160e97868fc741df07b9a06cd2 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RepositoryAccessController7aed96160e97868fc741df07b9a06cd2.url(options),
    method: 'get',
})

RepositoryAccessController7aed96160e97868fc741df07b9a06cd2.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/threads/new/repository-access',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/api/app/v1/threads/new/repository-access'
*/
RepositoryAccessController7aed96160e97868fc741df07b9a06cd2.url = (options?: RouteQueryOptions) => {
    return RepositoryAccessController7aed96160e97868fc741df07b9a06cd2.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/api/app/v1/threads/new/repository-access'
*/
RepositoryAccessController7aed96160e97868fc741df07b9a06cd2.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: RepositoryAccessController7aed96160e97868fc741df07b9a06cd2.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Threads\RepositoryAccessController::__invoke
* @see app/Http/Controllers/Threads/RepositoryAccessController.php:15
* @route '/api/app/v1/threads/new/repository-access'
*/
RepositoryAccessController7aed96160e97868fc741df07b9a06cd2.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: RepositoryAccessController7aed96160e97868fc741df07b9a06cd2.url(options),
    method: 'head',
})

/**
* Multiple routes resolve to \App\Http\Controllers\Threads\RepositoryAccessController::RepositoryAccessController, so this export is a
* dictionary keyed by URI rather than a callable. Call a specific route with `RepositoryAccessController['<uri>'](...)`,
* or import the route by name from your generated `routes/` directory.
*/
const RepositoryAccessController = {
    '/threads/new/repository-access': RepositoryAccessController79d807b311dd4df09e0c3f64c45c0e0d,
    '/api/app/v1/threads/new/repository-access': RepositoryAccessController7aed96160e97868fc741df07b9a06cd2,
}

export default RepositoryAccessController