<?php

declare(strict_types=1);

namespace Helfersystem\Startseite;

use Engelsystem\Controllers\BaseController;
use Engelsystem\Http\Exceptions\HttpForbidden;
use Engelsystem\Http\Request;
use Engelsystem\Http\Response;

/**
 * Page of a mail job: the script sends one batch after the other and shows the progress,
 * without script every batch is started with the button
 */
class MailJobController extends BaseController
{
    public function __construct(protected Response $response)
    {
    }

    public function show(Request $request): Response
    {
        $job = $this->job((int) $request->input('id'));

        return $this->response->withView('startseite/mail-job', [
            'job' => $job,
            // Started from the form: the script sends right away
            'autostart' => (bool) $request->input('start') && !$job->isFinished(),
        ]);
    }

    public function step(Request $request): Response
    {
        $job = $this->job((int) $request->postData('id'));

        // The batch takes up to MailJob::BATCH_SECONDS plus the last mail
        set_time_limit(MailJob::BATCH_SECONDS + 60);
        ignore_user_abort(true);

        $ran = $job->isFinished() || $job->runBatch();

        if (request()->headers->get('X-Startseite')) {
            return $this->response
                ->withHeader('Content-Type', 'application/json')
                ->withContent((string) json_encode($job->progress() + ['busy' => !$ran]));
        }

        return $this->response->redirectTo(url('/startseite/versand', ['id' => $job->id]));
    }

    protected function job(int $id): MailJob
    {
        $job = MailJob::with('user')->findOrFail($id);
        if (!auth()->can($job->privilege)) {
            throw new HttpForbidden();
        }

        return $job;
    }
}
