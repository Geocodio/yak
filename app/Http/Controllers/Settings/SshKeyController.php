<?php

namespace App\Http\Controllers\Settings;

use App\Enums\SshKeySource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreSshKeyRequest;
use App\Http\Resources\SshKeysPageData;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Ssh\GitHubKeyDirectory;
use App\Services\Ssh\InvalidSshKey;
use App\Services\Ssh\SshKeyDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SshKeyController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Settings/SshKeys', SshKeysPageData::for($this->user($request)));
    }

    public function store(StoreSshKeyRequest $request, SshKeyDirectory $directory): RedirectResponse
    {
        try {
            $directory->addUploaded($this->user($request), $request->string('name')->toString(), $request->string('public_key')->toString());
        } catch (InvalidSshKey $exception) {
            throw ValidationException::withMessages(['public_key' => $exception->getMessage()]);
        }

        return back()->with('success', 'SSH key added.');
    }

    public function destroy(Request $request, SshKey $key, SshKeyDirectory $directory): RedirectResponse
    {
        abort_unless($key->user_id === $request->user()?->id && $key->source === SshKeySource::Uploaded, 404);

        $directory->remove($this->user($request), $key);

        return back()->with('success', 'SSH key removed.');
    }

    public function refresh(Request $request, GitHubKeyDirectory $github): RedirectResponse
    {
        $github->refresh($this->user($request), true);

        return back();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
