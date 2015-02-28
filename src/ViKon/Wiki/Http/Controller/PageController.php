<?php

namespace ViKon\Wiki\Http\Controller;

use Carbon\Carbon;
use Illuminate\Http\Request;
use ViKon\Diff\Diff;
use ViKon\Wiki\Models\Page;
use ViKon\Wiki\Models\PageContent;
use ViKon\Wiki\WikiParser;

class PageController extends BaseController {

    /**
     * Show page
     *
     * @param string $url
     *
     * @return \Illuminate\View\View
     */
    public function show($url = '') {
        /** @var Page $page */
        $page = Page::where('url', $url)->first();

        $authUser = app('auth.role.user');

        if ($page !== null && !$page->draft) {
            $titleId = WikiParser::generateId($page->title);

            $editable = $authUser->hasRole('wiki.edit');
            $movable = $authUser->hasRole('wiki.move');
            $destroyable = $authUser->hasRole('wiki.destroy');

            return view(config('wiki.views.page.show'))
                ->with('editable', $editable)
                ->with('movable', $movable)
                ->with('destroyable', $destroyable)
                ->with('titleId', $titleId)
                ->with('message', \Session::get('message', null))
                ->with('page', $page);
        }

        $creatable = $authUser->hasRole('wiki.create');

        return view(config('wiki.views.page.not-exists'))
            ->with('url', $url)
            ->with('creatable', $creatable);
    }

    /**
     * @param string $url
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     */
    public function create($url = '') {
        /** @var Page $page */
        $page = Page::where('url', $url)
            ->first();

        if ($page !== null && !$page->draft) {
            return redirect()->route('wiki.edit', ['url' => $url]);
        }

        $draftExists = true;
        $page = \DB::connection()->transaction(function () use ($url, $page, &$draftExists) {
            if ($page === null) {
                $page = new Page();
                $page->url = $url;
                $page->save();
            }

            if (($pageContent = $page->userDraft()) === null) {
                $pageContent = new PageContent();
                $pageContent->draft = true;
                $pageContent->created_by_user_id = app('auth.role.user')->getUserId();
                $page->contents()->save($pageContent);

                $draftExists = false;
            }

            return $page;
        });
        $userDraft = $page->userDraft();
        $lastContent = $page->lastContent();

        return view(config('wiki.views.page.create'))
            ->with('page', $page)
            ->with('draftExists', $draftExists)
            ->with('userDraft', $userDraft)
            ->with('lastContent', $lastContent);
    }

    /**
     * Show page edit
     *
     * @param string $url
     *
     * @return \Illuminate\View\View|\Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function edit($url = '') {
        /** @var Page $page */
        $page = Page::where('url', $url)->first();

        if ($page === null || $page->draft) {
            return redirect()->route('wiki.create', ['url' => $url]);
        }

        $draftExists = true;
        $lastContent = $page->lastContent();
        \DB::connection()->transaction(function () use ($url, $page, $lastContent, &$draftExists) {

            if (($pageContent = $page->userDraft()) === null) {
                $pageContent = new PageContent();
                $pageContent->title = $lastContent->title;
                $pageContent->content = $lastContent->content;
                $pageContent->draft = true;
                $pageContent->created_by_user_id = app('auth.role.user')->getUserId();
                $page->contents()->save($pageContent);

                $draftExists = false;
            }
        });

        $userDraft = $page->userDraft();

        return view(config('wiki.views.page.edit'))
            ->with('page', $page)
            ->with('draftExists', $draftExists)
            ->with('userDraft', $userDraft)
            ->with('lastContent', $lastContent);
    }

    /**
     * Handle draft save
     *
     * @param \ViKon\Wiki\Models\Page  $page
     * @param \Illuminate\Http\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function ajaxStoreDraft(Page $page, Request $request) {
        $draftPageContent = $page->userDraft();

        if ($draftPageContent === null) {
            $draftPageContent = new PageContent();
            $draftPageContent->page_id = $page->id;
            $draftPageContent->created_by_user_id = \Auth::user()->id;
            $draftPageContent->draft = true;
        }

        $draftPageContent->title = $request->get('title', '');
        $draftPageContent->content = $request->get('content', '');
        $draftPageContent->created_at = new Carbon();

        $draftPageContent->save();

        return response()->json();
    }

    /**
     * Handle page store
     *
     * @param \ViKon\Wiki\Models\Page  $page
     * @param \Illuminate\Http\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function ajaxStore(Page $page = null, Request $request) {
        list($content, $toc, $urls) = WikiParser::parsePage($request->get('title', ''), $request->get('content', ''));

        $absoluteUrl = preg_quote(route('wiki.show') . '/', '/');
        $relativeUrl = preg_quote(str_replace(url('/'), '', route('wiki.show')) . '/', '/');

        foreach ($urls as $url) {
            if (preg_match('/^(?:' . $absoluteUrl . '|' . $relativeUrl . ')/', $url)) {
                // TODO
            }
        }

        \DB::connection()->transaction(function () use ($page, $toc, $content, $request) {

            $page->toc = $toc;
            $page->title = $request->get('title', '');
            $page->content = $content;
            $page->draft = false;
            $page->save();

            $userDraft = $page->userDraft();

            if ($userDraft === null) {
                $userDraft = new PageContent();
                $userDraft->page_id = $page->id;
                $userDraft->created_by_user_id = \Auth::user()->id;
            }

            $userDraft->draft = false;
            $userDraft->title = trim($request->get('title', ''));
            $userDraft->content = $request->get('content', '');
            $userDraft->created_at = new Carbon();

            $page->contents()
                ->save($userDraft);
        });

        \Session::flash('message', trans('wiki::page/create.alert.saved.content'));

        return response()->json();
    }

    /**
     * @param \ViKon\Wiki\Models\Page  $page
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\View\View
     */
    public function ajaxModalPreview(Page $page, Request $request) {
        $content = WikiParser::parseContent($request->get('content', ''));

        return view(config('wiki.views.page.modal.preview'))
            ->with('content', $content)
            ->with('url', $page->url);

    }

    /**
     * @param \ViKon\Wiki\Models\Page $page
     *
     * @return \Illuminate\View\View
     */
    public function ajaxModalCancel(Page $page) {
        return view(config('wiki.views.page.modal.cancel'))
            ->with('page', $page);
    }

    /**
     * @param \ViKon\Wiki\Models\Page $page
     *
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function ajaxCancel(Page $page) {
        if ($page->userDraft() !== null) {
            $page->userDraft()
                ->delete();
        }

        \Session::flash('message', trans('wiki::page/create.alert.cancelled.content'));

        return response()->json();
    }

    /**
     * @param \ViKon\Wiki\Models\Page $page
     *
     * @return \Illuminate\View\View
     */
    public function ajaxModalHistory(Page $page) {
        $contents = $page->contents()
            ->where('draft', false)
            ->orderBy('created_at', 'desc')
            ->get();

        $oldContent = '';
        for ($i = $contents->count() - 1; $i >= 0; $i--) {
            $contents[$i] = [
                'content' => $contents[$i],
                'diff'    => Diff::compare($oldContent, $contents[$i]->content),
            ];

            $oldContent = $contents[$i]['content']->content;
        }

        return view(config('wiki.views.page.modal.history'))
            ->with('page', $page)
            ->with('contents', $contents);
    }
}