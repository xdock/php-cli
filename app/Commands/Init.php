<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class Init extends Command
{
    protected $signature = 'init {type=php-app : The type of application to initialize} {--force : Overwrite existing docker-compose.yml file}';

    protected $description = 'Initialize an xdock application (a docker-compose.yml file)';

    public function __construct(
        protected Filesystem $files
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ((! $this->hasOption('force') || ! $this->option('force')) && $this->files->exists('docker-compose.yml')) {
            $this->components->error('docker-compose.yml already exists.');

            return 1;
        }

        $type = $this->argument('type');

        $templateLatestVersionInfo = $this->getTemplateLatestVersionInfo();

        if (! $templateLatestVersionInfo) {
            $this->error("Template not found for {$type}");

            return 1;
        }

        $dockerComposeContent = $this->buildDockerCompose(
            $templateLatestVersionInfo['docker-compose'],
            $templateLatestVersionInfo['docker-compose-replacements'] ?? []
        );

        $this->info("Writing docker-compose.yml file");

        $this->files->put('docker-compose.yml', $dockerComposeContent);

        return 0;
    }

    protected function getTemplateLatestVersionInfo($template = 'php-app'): ?array
    {
        $this->info("Fetching latest version info for template '{$template}'");

        $latestVersions = Http::get('https://xdock.build/latest-versions.json')->json();

        return $latestVersions[$template][0] ?? null;
    }

    protected function buildDockerCompose(array $stubStruct, array $replacements): string
    {
        foreach ($replacements as $keyToReplace => $valueSpecs) {
            foreach ($valueSpecs as $valueSpec) {
                switch ($valueSpec['source']) {
                    case 'config':
                        $value = config($valueSpec['key']);

                        if ($value) {
                            $this->info("<fg=yellow>Replacing '$keyToReplace' with '$value'</>", OutputInterface::VERBOSITY_VERY_VERBOSE);

                            Arr::set($stubStruct, $keyToReplace, $value);

                            break 2;
                        }

                        $this->info("<fg=yellow>Initial replacement config value '{$valueSpec['key']}' not found</>", OutputInterface::VERBOSITY_VERY_VERBOSE);

                        break;
                    case 'project_directory_name':
                        $base = basename(base_path('/'));

                        if ($base) {
                            $this->info("<fg=yellow>Replacing '$keyToReplace' with '$base'</>", OutputInterface::VERBOSITY_VERY_VERBOSE);

                            Arr::set($stubStruct, $keyToReplace, $base);

                            break 2;
                        }

                        break;
                }
            }
        }

        $string = Yaml::dump($stubStruct, 10, 2);

        $fileLines = explode("\n", $string);

        foreach ($fileLines as $lineno => $line) {
            if (str_starts_with($line, 'formatting-') && str_ends_with($line, 'NEWLINE')) {
                $fileLines[$lineno] = "";
            }

            // image and command are not normally quoted
            if (preg_match('/^(\s+)(image|command):.*$/', $line, $matches)) {
                $fileLines[$lineno] = str_replace("'", '', $line);
            }
        }

        return implode("\n", $fileLines);
    }
}
