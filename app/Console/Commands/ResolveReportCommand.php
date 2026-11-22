<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Abuse\ResolveReport;
use App\Models\Report;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class ResolveReportCommand extends Command
{
    protected $signature = 'abuse:resolve-report
        {report : Public id of the report}
        {action : actioned or rejected}
        {--reason= : Qualification kept on the report}';

    protected $description = 'Record the outcome of a report.';

    public function handle(ResolveReport $action): int
    {
        $report = Report::query()
            ->where('public_id', (string) $this->argument('report'))
            ->first();

        if ($report === null) {
            $this->error('Report not found: '.$this->argument('report'));

            return self::FAILURE;
        }

        $state = strtolower((string) $this->argument('action'));
        $reason = trim((string) $this->option('reason'));

        try {
            $action->handle($report, $state, $reason === '' ? null : $reason);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Report '.$report->public_id.' resolved as '.$state.'.');

        return self::SUCCESS;
    }
}
