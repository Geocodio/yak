import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../../../../../wayfinder'
/**
* @see \App\Http\Controllers\Api\App\SearchController::__invoke
* @see app/Http/Controllers/Api/App/SearchController.php:13
* @route '/api/app/v1/search'
*/
const SearchController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SearchController.url(options),
    method: 'get',
})

SearchController.definition = {
    methods: ["get","head"],
    url: '/api/app/v1/search',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\Api\App\SearchController::__invoke
* @see app/Http/Controllers/Api/App/SearchController.php:13
* @route '/api/app/v1/search'
*/
SearchController.url = (options?: RouteQueryOptions) => {
    return SearchController.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\Api\App\SearchController::__invoke
* @see app/Http/Controllers/Api/App/SearchController.php:13
* @route '/api/app/v1/search'
*/
SearchController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: SearchController.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\Api\App\SearchController::__invoke
* @see app/Http/Controllers/Api/App/SearchController.php:13
* @route '/api/app/v1/search'
*/
SearchController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: SearchController.url(options),
    method: 'head',
})

export default SearchController