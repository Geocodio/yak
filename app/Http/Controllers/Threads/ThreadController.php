<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadComposerData;
use App\Http\Resources\ThreadMessagesData;
use App\Http\Resources\ThreadNoticeData;
use App\Http\Resources\ThreadPageData;
use App\Models\Thread;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ThreadController extends Controller
{
    public function show(Request $request, Thread $thread): Response
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        $viewer = $request->user();

        return Inertia::render('Threads/Show', array_merge(ThreadPageData::build($thread, $viewer), [
            'messages' => ThreadMessagesData::for($thread),
            'composer' => ThreadComposerData::for($thread, $viewer),
            'notice' => ThreadNoticeData::for($thread, $viewer),
            'modelLabels' => $this->modelLabels(),
            'stackParent' => $this->stackParent($thread),
        ]));
    }

    /**
     * The catalog label of every exact model id and family alias, so a raw id reads as "Sonnet 5.5".
     *
     * @return array<string, string>
     */
    private function modelLabels(): array
    {
        $catalog = app(ClaudeModelCatalog::class);
        $labels = array_column($catalog->entries(), 'label', 'id');

        foreach ($catalog->families() as $family) {
            $labels[$family['family']] = $family['latest']['label'];
        }

        return $labels;
    }

    /**
     * @return array{id: int, name: string, title: string}|null
     */
    private function stackParent(Thread $thread): ?array
    {
        $parent = $thread->parent_thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($thread->parent_thread_id);

        return $parent === null ? null : ['id' => $parent->id, 'name' => $parent->name, 'title' => $parent->title];
    }
}
