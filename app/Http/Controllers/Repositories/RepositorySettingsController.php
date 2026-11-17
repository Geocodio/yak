<?php

namespace App\Http\Controllers\Repositories;

use App\Http\Controllers\Controller;
use App\Http\Resources\RepositorySettingsData;
use App\Models\Repository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RepositorySettingsController extends Controller
{
    public function show(Request $request, Repository $repository, string $section): Response
    {
        return Inertia::render('Repositories/Settings/' . ucfirst($section), RepositorySettingsData::section($repository, $section, $request->user()));
    }
}
