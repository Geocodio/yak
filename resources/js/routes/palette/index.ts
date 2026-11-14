import { queryParams, type RouteQueryOptions, type RouteDefinition } from './../../wayfinder'
/**
* @see \App\Http\Controllers\PaletteSearchController::__invoke
* @see app/Http/Controllers/PaletteSearchController.php:12
* @route '/palette/search'
*/
export const search = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: search.url(options),
    method: 'get',
})

search.definition = {
    methods: ["get","head"],
    url: '/palette/search',
} satisfies RouteDefinition<["get","head"]>

/**
* @see \App\Http\Controllers\PaletteSearchController::__invoke
* @see app/Http/Controllers/PaletteSearchController.php:12
* @route '/palette/search'
*/
search.url = (options?: RouteQueryOptions) => {
    return search.definition.url + queryParams(options)
}

/**
* @see \App\Http\Controllers\PaletteSearchController::__invoke
* @see app/Http/Controllers/PaletteSearchController.php:12
* @route '/palette/search'
*/
search.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
    url: search.url(options),
    method: 'get',
})

/**
* @see \App\Http\Controllers\PaletteSearchController::__invoke
* @see app/Http/Controllers/PaletteSearchController.php:12
* @route '/palette/search'
*/
search.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
    url: search.url(options),
    method: 'head',
})

const palette = {
    search: Object.assign(search, search),
}

export default palette