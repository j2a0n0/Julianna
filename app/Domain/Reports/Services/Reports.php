<?php

namespace Leantime\Domain\Reports\Services;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Cache;
use Leantime\Core\Auth\Permissions\RequiresPermission;
use Leantime\Core\Domains\BaseService;
use Leantime\Domain\Projects\Repositories\Projects as ProjectRepository;
use Leantime\Domain\Reports\Permissions\ReportsPermissions;
use Leantime\Domain\Reports\Repositories\Reports as ReportRepository;
use Leantime\Domain\Sprints\Repositories\Sprints as SprintRepository;
use Leantime\Domain\Sprints\Services\Sprints as SprintService;

/**
 * Reports service: per-project report aggregation and daily ticket-history ingestion.
 *
 * Authorization model: the three by-projectId @api reads carry a dispatch
 * #[RequiresPermission(reports.view, projectIdParam: 'projectId')] gate, authorized against the
 * REQUESTED project (closes the cross-project RPC IDOR). The system ingestion methods are not
 * RPC-reachable and run from the scheduler without a session user.
 *
 * @api
 */
class Reports extends BaseService
{
    private ProjectRepository $projectRepository;

    private SprintRepository $sprintRepository;

    private ReportRepository $reportRepository;

    private SprintService $sprintService;

    public function __construct(
        ProjectRepository $projectRepository,
        SprintRepository $sprintRepository,
        ReportRepository $reportRepository,
        SprintService $sprintService
    ) {
        $this->projectRepository = $projectRepository;
        $this->sprintRepository = $sprintRepository;
        $this->reportRepository = $reportRepository;
        $this->sprintService = $sprintService;
    }

    /**
     * Resolves which sprint burndown to display on the reports page.
     *
     * Mirrors the legacy controller selection order exactly:
     * 1. An explicitly requested sprint id (from the query string).
     * 2. Otherwise the project's current sprint.
     * 3. Otherwise the first available sprint.
     *
     * The returned 'currentSprintId' preserves the original behaviour:
     * when a sprint id is explicitly requested it is echoed back as-is
     * (even if the sprint cannot be loaded); when falling back to the
     * current/first sprint the resolved sprint object's id is used.
     * When the project has no sprints at all, both values are false.
     *
     * @param  int  $projectId  Project to resolve the burndown for.
     * @param  int|null  $requestedSprintId  Sprint id explicitly requested by the user, or null.
     * @return array{chart: false|array, currentSprintId: int|false} Burndown chart data and the resolved sprint id.
     *
     * @api
     */
    #[RequiresPermission(ReportsPermissions::VIEW, projectIdParam: 'projectId')]
    public function getSprintBurndownForReport(int $projectId, ?int $requestedSprintId): array
    {
        $allSprints = $this->sprintService->getAllSprints($projectId);

        if (count($allSprints) === 0) {
            return ['chart' => false, 'currentSprintId' => false];
        }

        $sprintChart = false;

        if ($requestedSprintId !== null) {
            $sprintObject = $this->sprintService->getSprint($requestedSprintId);
            if ($sprintObject) {
                $sprintChart = $this->sprintService->getSprintBurndown($sprintObject);
            }

            return ['chart' => $sprintChart, 'currentSprintId' => $requestedSprintId];
        }

        $currentSprint = $this->sprintService->getCurrentSprintId($projectId);

        if ($currentSprint !== false && $currentSprint !== 'all') {
            $sprintObject = $this->sprintService->getSprint((int) $currentSprint);
            if ($sprintObject) {
                $sprintChart = $this->sprintService->getSprintBurndown($sprintObject);

                return ['chart' => $sprintChart, 'currentSprintId' => $sprintObject->id];
            }

            return ['chart' => $sprintChart, 'currentSprintId' => false];
        }

        $sprintChart = $this->sprintService->getSprintBurndown($allSprints[0]);

        return ['chart' => $sprintChart, 'currentSprintId' => $allSprints[0]->id];
    }

    /**
     * Runs the daily report ingestion for the session's current project.
     *
     * Not @api: an internal web-path helper (called by the gated Reports\Controllers\Show after
     * dispatch enforcement). It reads session('currentProject'), so an RPC caller would have no
     * meaningful project binding — and it was needlessly RPC-exposed before.
     *
     * @throws BindingResolutionException
     */
    public function dailyIngestion(): void
    {
        $this->runIngestionForProject(session('currentProject'));
    }

    protected function runIngestionForProject(int $projectId): void
    {

        if (Cache::has('dailyReports-'.$projectId) === false || Cache::get('dailyReports-'.$projectId) < dtHelper()->dbNow()->endOfDay()) {

            // Check if the dailyingestion cycle was executed already. There should be one entry for backlog and one entry for current sprint (unless there is no current sprint
            // Get current Sprint Id, if no sprint available, dont run the sprint burndown

            $lastEntries = $this->reportRepository->checkLastReportEntries($projectId);

            // If we receive 2 entries we have a report already. If we have one entry then we ran the backlog one and that means there was no current sprint.
            if (count($lastEntries) == 0) {
                $currentSprint = $this->sprintRepository->getCurrentSprint($projectId);

                if ($currentSprint !== false) {
                    $sprintReport = $this->reportRepository->runTicketReport($projectId, $currentSprint->id);
                    if ($sprintReport !== false) {
                        $this->reportRepository->addReport($sprintReport);
                    }
                }

                $backlogReport = $this->reportRepository->runTicketReport($projectId, '');

                if ($backlogReport !== false) {

                    $this->reportRepository->addReport($backlogReport);

                    Cache::put('dailyReports-'.$projectId, dtHelper()->dbNow()->endOfDay(), 14400); // 4hours

                }
            }

        }
    }

    public function cronDailyIngestion(): void
    {
        $projects = $this->projectRepository->getAll();

        foreach ($projects as $project) {
            $this->runIngestionForProject($project['id']);
        }

    }

    /**
     * Returns a project's stored report history, authorized against the requested project.
     *
     * $projectId is typed int so the param is REQUIRED and non-null: a JSON-RPC caller cannot
     * pass null to make PermissionEnforcer::resolveProjectId() fall back to the session project
     * (its isset() check treats explicit null as absent), which would dodge the per-target gate.
     *
     * @api
     */
    #[RequiresPermission(ReportsPermissions::VIEW, projectIdParam: 'projectId')]
    public function getFullReport(int $projectId): false|array
    {
        return $this->reportRepository->getFullReport($projectId);
    }

    /**
     * Computes a project's current ticket report, authorized against the requested project.
     * The repository scopes by BOTH projectId and sprint, so a foreign sprint id yields no rows.
     *
     * $projectId is typed int for the same reason as getFullReport() — it keeps the dispatch gate
     * bound to the requested project (no null → session fallback). $sprintId stays mixed because
     * the empty string is the meaningful "backlog" selector.
     *
     * @throws BindingResolutionException
     *
     * @api
     */
    #[RequiresPermission(ReportsPermissions::VIEW, projectIdParam: 'projectId')]
    public function getRealtimeReport(int $projectId, $sprintId): array|bool
    {
        return $this->reportRepository->runTicketReport($projectId, $sprintId);
    }
}
