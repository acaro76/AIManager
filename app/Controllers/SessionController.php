<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Project;
use App\Models\Session;
use App\Services\BrainConsolidationService;
use App\Services\ProjectBrainService;

final class SessionController extends BaseController
{
    public function store(Request $request): never
    {
        $this->guard($request);
        $project = (new Project())->find((int) $request->input('project_id'));
        if (!$project) {
            Response::redirect('/projects');
        }
        if (Project::isArchived($project)) {
            $this->flash('Il progetto archiviato e\' in sola lettura.', '/projects/show?id=' . (int) $project['id']);
        }

        $title = trim((string) $request->input('title'));
        $sessionId = (new Session())->create([
            'project_id' => (int) $project['id'],
            'title' => $title === '' ? 'Nuova sessione' : $title,
            'description' => trim((string) $request->input('description')),
        ]);

        Response::redirect('/workspace/session?id=' . $sessionId);
    }

    public function rename(Request $request): never
    {
        $this->guard($request);
        $session = (new Session())->find((int) $request->input('session_id'));
        if (!$session) {
            Response::redirect('/projects');
        }
        $project = (new Project())->find((int) $session['project_id']);
        if (!$project || Project::isArchived($project) || (string) ($session['status'] ?? 'active') === 'archived') {
            $this->flash('Il progetto o la sessione sono archiviati e disponibili in sola lettura.', '/projects/show?id=' . (int) $session['project_id']);
        }

        $title = trim((string) $request->input('title'));
        (new Session())->rename(
            (int) $session['id'],
            $title === '' ? (string) $session['title'] : $title,
            trim((string) $request->input('description'))
        );

        $this->flash('Sessione rinominata.', '/workspace/session?id=' . (int) $session['id']);
    }

    public function archive(Request $request): never
    {
        $this->guard($request);
        $session = (new Session())->find((int) $request->input('session_id'));
        if (!$session) {
            Response::redirect('/projects');
        }

        $project = (new Project())->find((int) $session['project_id']);
        if (!$project || Project::isArchived($project) || (string) ($session['status'] ?? 'active') === 'archived') {
            $this->flash('Il progetto o la sessione sono gia archiviati.', '/projects/show?id=' . (int) $session['project_id']);
        }
        $brain = ['new' => 0, 'known' => 0, 'conflicts' => 0];
        if ($project) {
            // Il Brain lo scrive il consolidatore AI (analyzeSession): estrae solo i fatti
            // salienti dalla conversazione. Il vecchio learnFromExecutionState e' stato
            // rimosso: promuoveva note operative (ricerca web, immagine generata, errore
            // provider) a memoria residente = rumore (audit).
            $brainService = new ProjectBrainService(BrainConsolidationService::fromRoot($this->app->root));
            $brain = $brainService->analyzeSession($project, $session);
        }
        $projectArchived = false;
        $this->app->db->transaction(function () use ($session, $project, &$projectArchived): void {
            $sessions = new Session();
            $sessions->archive((int) $session['id']);
            if ($sessions->activeForProject((int) $project['id']) === []) {
                (new Project())->setStatus((int) $project['id'], 'archived');
                $projectArchived = true;
            }
        });
        $this->flash(
            sprintf(
                '%s Brain aggiornato: %d nuove, %d gia note, %d conflitti.',
                $projectArchived ? 'Ultima sessione archiviata: anche il progetto e\' ora archiviato.' : 'Sessione archiviata.',
                $brain['new'],
                $brain['known'],
                $brain['conflicts']
            ),
            $projectArchived ? '/projects?tab=archived' : '/projects/show?id=' . (int) $session['project_id']
        );
    }
}
